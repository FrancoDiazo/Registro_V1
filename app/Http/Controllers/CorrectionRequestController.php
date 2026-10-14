<?php

namespace App\Http\Controllers;

use App\Models\CorrectionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CorrectionRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $employee->status === 'active', 403);

        $data = $request->validate([
            'attendance_event_id' => [
                'nullable',
                'integer',
                Rule::exists('attendance_events', 'id')->where('employee_id', $employee->id),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'requested_event_type' => ['nullable', Rule::in(config('attendance.allowed_event_types'))],
            'requested_occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'requested_timezone' => ['nullable', 'required_with:requested_occurred_at', 'timezone'],
        ]);
        $requestedAt = isset($data['requested_occurred_at'])
            ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['requested_occurred_at'], $data['requested_timezone'])->utc()
            : null;

        CorrectionRequest::create([
            ...$data,
            'requested_occurred_at' => $requestedAt,
            'requested_timezone' => $requestedAt ? $data['requested_timezone'] : null,
            'employee_id' => $employee->id,
            'requested_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Tu solicitud de corrección quedó pendiente de revisión.');
    }
}
