<?php

namespace Tests\Unit;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceSummaryService;
use App\Services\WorkDurationCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WorkDurationCalculatorTest extends TestCase
{
    public function test_calculates_work_intervals_across_midnight_and_splits_local_daily_totals(): void
    {
        $events = [
            $this->event('ENTRY', '2026-06-15T23:00:00-04:00'),
            $this->event('EXIT', '2026-06-16T07:00:00-04:00'),
        ];
        $calculator = new WorkDurationCalculator();
        $schedule = WorkSchedule::make(['break_minutes' => 30, 'break_is_unpaid' => true]);

        $result = $calculator->calculate($events, $schedule);
        $daily = $calculator->dailyMinutes($events);

        $this->assertSame(450, $result['worked_minutes']);
        $this->assertSame(60, $daily->get('2026-06-15'));
        $this->assertSame(420, $daily->get('2026-06-16'));
        $this->assertSame([], $result['alerts']);
    }

    public function test_observed_unpaid_break_is_counted_as_an_interval_not_deducted_twice(): void
    {
        $events = [
            $this->event('ENTRY', '2026-06-15T08:00:00-04:00'),
            $this->event('BREAK_START', '2026-06-15T12:00:00-04:00'),
            $this->event('BREAK_END', '2026-06-15T12:30:00-04:00'),
            $this->event('EXIT', '2026-06-15T16:30:00-04:00'),
        ];
        $schedule = WorkSchedule::make(['break_minutes' => 30, 'break_is_unpaid' => true]);

        $result = (new WorkDurationCalculator())->calculate($events, $schedule);

        $this->assertSame(480, $result['worked_minutes']);
        $this->assertSame([], $result['alerts']);
    }

    public function test_unmatched_marks_produce_an_alert_instead_of_a_final_calculation(): void
    {
        $result = (new WorkDurationCalculator())->calculate([
            $this->event('ENTRY', '2026-06-15T08:00:00-04:00'),
        ]);

        $this->assertSame(0, $result['worked_minutes']);
        $this->assertContains('Hay un intervalo de trabajo abierto que requiere revisión.', $result['alerts']);
    }

    public function test_daily_and_weekly_summary_uses_the_configured_schedule_and_flags_unreviewed_shifts(): void
    {
        $employee = Employee::make();
        $employee->setAttribute('id', 7);
        $employee->setRelation('user', User::make(['name' => 'Trabajador de prueba']));
        $schedule = WorkSchedule::make(['break_minutes' => 30, 'break_is_unpaid' => true]);
        $assignment = ScheduleAssignment::make(['valid_from' => '2026-01-01']);
        $assignment->setRelation('workSchedule', $schedule);
        $events = [
            $this->event('ENTRY', '2026-06-15T23:00:00-04:00'),
            $this->event('EXIT', '2026-06-16T07:00:00-04:00'),
        ];

        $summary = (new AttendanceSummaryService(new WorkDurationCalculator()))->summarize(
            $employee,
            $events,
            [$assignment],
            true,
        );

        $this->assertSame('2026-06-15', $summary['daily'][0]['date']);
        $this->assertNull($summary['daily'][0]['worked_minutes']);
        $this->assertTrue($summary['weekly'][0]['review_required']);
        $this->assertNotEmpty($summary['alerts']);
    }

    public function test_configured_overnight_shift_is_summarized_as_a_provisional_weekly_total(): void
    {
        $employee = Employee::make();
        $employee->setAttribute('id', 8);
        $employee->setRelation('user', User::make(['name' => 'Trabajador de prueba']));
        $schedule = WorkSchedule::make([
            'break_minutes' => 30,
            'break_is_unpaid' => true,
            'shift_start' => '23:00',
            'shift_end' => '07:00',
            'days_of_week' => [1],
        ]);
        $assignment = ScheduleAssignment::make(['valid_from' => '2026-01-01']);
        $assignment->setRelation('workSchedule', $schedule);
        $events = [
            $this->event('ENTRY', '2026-06-15T23:00:00-04:00'),
            $this->event('EXIT', '2026-06-16T07:00:00-04:00'),
        ];

        $summary = (new AttendanceSummaryService(new WorkDurationCalculator()))->summarize(
            $employee,
            $events,
            [$assignment],
            false,
        );

        $this->assertSame(450, $summary['daily'][0]['worked_minutes']);
        $this->assertSame(450, $summary['weekly'][0]['worked_minutes']);
        $this->assertFalse($summary['daily'][0]['review_required']);
        $this->assertSame([], $summary['schedule_alerts']);
    }

    private function event(string $type, string $timestamp): AttendanceEvent
    {
        return AttendanceEvent::make([
            'event_type' => $type,
            'occurred_at_device' => CarbonImmutable::parse($timestamp),
        ]);
    }
}
