<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WorkDurationCalculator
{
    /**
     * @param iterable<AttendanceEvent> $events
     * @return array{worked_seconds: int, worked_minutes: int, alerts: list<string>}
     */
    public function calculate(iterable $events, ?WorkSchedule $schedule = null): array
    {
        $ordered = collect($events)->sortBy([
            ['occurred_at_device', 'asc'],
            ['id', 'asc'],
        ])->values();
        $workedSeconds = 0;
        $workStartedAt = null;
        $breakObserved = false;
        $alerts = [];

        foreach ($ordered as $event) {
            $occurredAt = $event->occurred_at_device;

            if ($event->event_type === 'ENTRY') {
                if ($workStartedAt !== null) {
                    $alerts[] = 'Hay una entrada sin una salida previa.';
                } else {
                    $workStartedAt = $occurredAt;
                }
            } elseif ($event->event_type === 'BREAK_START') {
                $breakObserved = true;
                if ($workStartedAt === null) {
                    $alerts[] = 'El inicio de colación no tiene una entrada previa.';
                } else {
                    $workedSeconds += $this->secondsBetween($workStartedAt, $occurredAt);
                    $workStartedAt = null;
                }
            } elseif ($event->event_type === 'BREAK_END') {
                $breakObserved = true;
                if ($workStartedAt !== null) {
                    $alerts[] = 'El fin de colación no tiene un inicio previo.';
                } else {
                    $workStartedAt = $occurredAt;
                }
            } elseif ($event->event_type === 'EXIT') {
                if ($workStartedAt === null) {
                    $alerts[] = 'La salida no tiene una entrada o fin de colación previo.';
                } else {
                    $workedSeconds += $this->secondsBetween($workStartedAt, $occurredAt);
                    $workStartedAt = null;
                }
            }
        }

        if ($workStartedAt !== null) {
            $alerts[] = 'Hay un intervalo de trabajo abierto que requiere revisión.';
        }

        if ($schedule?->break_is_unpaid && ! $breakObserved) {
            $workedSeconds = max(0, $workedSeconds - ($schedule->break_minutes * 60));
        }

        return [
            'worked_seconds' => $workedSeconds,
            'worked_minutes' => intdiv($workedSeconds, 60),
            'alerts' => array_values(array_unique($alerts)),
        ];
    }

    /**
     * Split already validated intervals at local midnight to produce daily totals.
     *
     * @param iterable<AttendanceEvent> $events
     * @return Collection<string, int>
     */
    public function dailyMinutes(iterable $events, string $timezone = 'America/Santiago'): Collection
    {
        $ordered = collect($events)->sortBy([
            ['occurred_at_device', 'asc'],
            ['id', 'asc'],
        ])->values();
        $totals = collect();
        $startedAt = null;
        $insideBreak = false;

        foreach ($ordered as $event) {
            $at = $event->occurred_at_device->setTimezone($timezone);
            if ($event->event_type === 'ENTRY' || $event->event_type === 'BREAK_END') {
                $startedAt = $at;
                $insideBreak = false;
            } elseif ($event->event_type === 'BREAK_START' && $startedAt && ! $insideBreak) {
                $this->addSplitInterval($totals, $startedAt, $at);
                $startedAt = null;
                $insideBreak = true;
            } elseif ($event->event_type === 'EXIT' && $startedAt && ! $insideBreak) {
                $this->addSplitInterval($totals, $startedAt, $at);
                $startedAt = null;
            }
        }

        return $totals->map(fn (int $seconds): int => intdiv($seconds, 60));
    }

    private function addSplitInterval(Collection $totals, CarbonImmutable $start, CarbonImmutable $end): void
    {
        while ($start->lessThan($end)) {
            $midnight = $start->addDay()->startOfDay();
            $segmentEnd = $end->lessThan($midnight) ? $end : $midnight;
            $date = $start->toDateString();
            $totals->put($date, $totals->get($date, 0) + $this->secondsBetween($start, $segmentEnd));
            $start = $segmentEnd;
        }
    }

    private function secondsBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }
}
