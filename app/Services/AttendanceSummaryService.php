<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AttendanceSummaryService
{
    private const VALID_TRANSITIONS = [
        'ENTRY' => ['BREAK_START', 'EXIT'],
        'BREAK_START' => ['BREAK_END'],
        'BREAK_END' => ['EXIT'],
        'EXIT' => ['ENTRY'],
    ];

    public function __construct(private readonly WorkDurationCalculator $calculator)
    {
    }

    /**
     * @param iterable<AttendanceEvent> $events
     * @param iterable<ScheduleAssignment> $assignments
     * @return array{daily: list<array>, weekly: list<array>, alerts: list<string>, schedule_alerts: list<string>}
     */
    public function summarize(
        Employee $employee,
        iterable $events,
        iterable $assignments,
        bool $hasOutstandingIssue,
        string $timezone = 'America/Santiago',
    ): array {
        $ordered = collect($events)->sortBy([
            ['occurred_at_device', 'asc'],
            ['id', 'asc'],
        ])->values();
        $daily = [];
        $alerts = [];
        $scheduleAlerts = [];
        $shift = collect();

        foreach ($ordered as $event) {
            if ($event->event_type === 'ENTRY') {
                if ($shift->isNotEmpty()) {
                    $this->flagIncompleteShift($employee, $shift, $daily, $alerts, $timezone);
                }
                $shift = collect([$event]);
                continue;
            }

            if ($shift->isEmpty()) {
                $alerts[] = "{$event->event_type}: falta una entrada previa para completar el intervalo.";
                $date = $event->occurred_at_device->setTimezone($timezone)->toDateString();
                $this->addDay($daily, $employee, $date, null, true);
                continue;
            }

            $shift->push($event);
            if ($event->event_type === 'EXIT') {
                $this->summarizeShift(
                    $employee,
                    $shift,
                    $assignments,
                    $daily,
                    $alerts,
                    $scheduleAlerts,
                    $hasOutstandingIssue,
                    $timezone,
                );
                $shift = collect();
            }
        }

        if ($shift->isNotEmpty()) {
            $this->flagIncompleteShift($employee, $shift, $daily, $alerts, $timezone);
        }

        ksort($daily);
        $weekly = [];
        foreach ($daily as $day => $row) {
            $week = CarbonImmutable::parse($day, $timezone)->format('o-\WW');
            $key = "{$row['employee_id']}:{$week}";
            $weekly[$key] ??= [
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'week' => $week,
                'worked_minutes' => 0,
                'review_required' => false,
            ];
            if ($row['worked_minutes'] !== null) {
                $weekly[$key]['worked_minutes'] += $row['worked_minutes'];
            }
            $weekly[$key]['review_required'] = $weekly[$key]['review_required'] || $row['review_required'];
        }

        return [
            'daily' => array_values($daily),
            'weekly' => array_values($weekly),
            'alerts' => array_values(array_unique($alerts)),
            'schedule_alerts' => array_values(array_unique($scheduleAlerts)),
        ];
    }

    private function summarizeShift(
        Employee $employee,
        Collection $shift,
        iterable $assignments,
        array &$daily,
        array &$alerts,
        array &$scheduleAlerts,
        bool $hasOutstandingIssue,
        string $timezone,
    ): void {
        $startDate = $shift->first()->occurred_at_device->setTimezone($timezone)->toDateString();
        $assignment = collect($assignments)->first(function (ScheduleAssignment $candidate) use ($startDate): bool {
            return $candidate->valid_from->toDateString() <= $startDate
                && (! $candidate->valid_until || $candidate->valid_until->toDateString() >= $startDate);
        });
        /** @var WorkSchedule|null $schedule */
        $schedule = $assignment?->workSchedule;
        $result = $this->calculator->calculate($shift, $schedule);
        $sequenceIsValid = $this->hasValidSequence($shift);
        $shiftScheduleAlerts = $this->scheduleAlerts($shift, $schedule, $timezone);
        $reviewRequired = $hasOutstandingIssue || ! $sequenceIsValid || $result['alerts'] !== [] || $shiftScheduleAlerts !== [];
        foreach ($result['alerts'] as $alert) {
            $alerts[] = "{$startDate}: {$alert}";
        }
        if (! $sequenceIsValid) {
            $alerts[] = "{$startDate}: la secuencia de esta jornada requiere revisión.";
        }
        if ($hasOutstandingIssue) {
            $alerts[] = "{$startDate}: hay incidencias o solicitudes pendientes para este trabajador.";
        }
        foreach ($shiftScheduleAlerts as $scheduleAlert) {
            $scheduleAlerts[] = "{$startDate}: {$scheduleAlert}";
        }

        $this->addDay($daily, $employee, $startDate, $reviewRequired ? null : $result['worked_minutes'], $reviewRequired);
    }

    private function flagIncompleteShift(
        Employee $employee,
        Collection $shift,
        array &$daily,
        array &$alerts,
        string $timezone,
    ): void {
        $startDate = $shift->first()->occurred_at_device->setTimezone($timezone)->toDateString();
        $alerts[] = "{$startDate}: hay una jornada sin salida y requiere revisión.";
        $this->addDay($daily, $employee, $startDate, null, true);
    }

    private function addDay(array &$daily, Employee $employee, string $date, ?int $minutes, bool $reviewRequired): void
    {
        $key = "{$employee->id}:{$date}";
        $daily[$key] ??= [
            'employee_id' => $employee->id,
            'employee_name' => $employee->user->name,
            'date' => $date,
            'worked_minutes' => 0,
            'shift_count' => 0,
            'review_required' => false,
        ];
        if ($minutes !== null) {
            $daily[$key]['worked_minutes'] += $minutes;
            $daily[$key]['shift_count']++;
        }
        $daily[$key]['review_required'] = $daily[$key]['review_required'] || $reviewRequired;
    }

    private function hasValidSequence(Collection $shift): bool
    {
        $previous = null;
        foreach ($shift as $event) {
            if ($previous && ! in_array($event->event_type, self::VALID_TRANSITIONS[$previous] ?? [], true)) {
                return false;
            }
            $previous = $event->event_type;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function scheduleAlerts(Collection $shift, ?WorkSchedule $schedule, string $timezone): array
    {
        if (! $schedule) {
            return [];
        }

        $entry = $shift->first()->occurred_at_device->setTimezone($timezone);
        $exit = $shift->last()->occurred_at_device->setTimezone($timezone);
        $alerts = [];
        if ($schedule->days_of_week && ! in_array($entry->dayOfWeekIso, $schedule->days_of_week, true)) {
            $alerts[] = 'La entrada ocurrió en un día no incluido en la jornada configurada.';
        }
        if ($schedule->shift_start && $entry->format('H:i') !== substr((string) $schedule->shift_start, 0, 5)) {
            $alerts[] = 'La hora de entrada difiere de la hora configurada; requiere revisión.';
        }
        if ($schedule->shift_end && $exit->format('H:i') !== substr((string) $schedule->shift_end, 0, 5)) {
            $alerts[] = 'La hora de salida difiere de la hora configurada; requiere revisión.';
        }

        return $alerts;
    }
}
