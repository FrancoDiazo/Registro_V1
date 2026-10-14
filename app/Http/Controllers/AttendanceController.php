<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $employee->status === 'active', 403);

        $events = $employee->attendanceEvents()
            ->orderByDesc('occurred_at_device')
            ->limit(100)
            ->get();

        $lastEvent = $events->first();
        $incidentRecords = $employee->attendanceIncidents()
            ->with('attendanceEvent')
            ->where('status', 'open')
            ->latest()
            ->limit(20)
            ->get();
        $incidents = $employee->attendanceIncidents()->where('status', 'open')->count();
        $correctionRequests = $employee->correctionRequests()->with('attendanceEvent')->latest()->limit(20)->get();
        $corrections = $employee->correctionRequests()->where('status', 'pending')->count();

        return view('attendance.dashboard', compact(
            'employee',
            'events',
            'lastEvent',
            'incidents',
            'incidentRecords',
            'corrections',
            'correctionRequests',
        ));
    }

    public function history(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $employee->status === 'active', 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $presentationTimezone = config('attendance.presentation_timezone');
        $query = $employee->attendanceEvents()->orderByDesc('occurred_at_device');
        if (! empty($filters['from'])) {
            $query->where('occurred_at_device', '>=', CarbonImmutable::parse($filters['from'], $presentationTimezone)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at_device', '<=', CarbonImmutable::parse($filters['to'], $presentationTimezone)->endOfDay()->utc());
        }

        return view('attendance.history', ['events' => $query->paginate(50)->withQueryString()]);
    }

    public function show(Request $request, AttendanceEvent $event): View
    {
        $this->authorize('view', $event);

        return view('attendance.event', compact('event'));
    }
}
