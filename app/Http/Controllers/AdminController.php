<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\AttendanceIncident;
use App\Models\AuditLog;
use App\Models\CorrectionRequest;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use App\Support\RutConflict;
use App\Services\AttendanceSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function index(Request $request, AttendanceSummaryService $summaryService): View|RedirectResponse
    {
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:trabajadores,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $eventsQuery = AttendanceEvent::with('employee.user')->orderByDesc('occurred_at_device');
        $presentationTimezone = config('attendance.presentation_timezone');
        if (! empty($filters['employee_id'])) {
            $eventsQuery->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['from'])) {
            $eventsQuery->where('occurred_at_device', '>=', CarbonImmutable::parse($filters['from'], $presentationTimezone)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $eventsQuery->where('occurred_at_device', '<=', CarbonImmutable::parse($filters['to'], $presentationTimezone)->endOfDay()->utc());
        }

        $reportFrom = CarbonImmutable::parse($filters['from'] ?? now($presentationTimezone)->subDays(6)->toDateString(), $presentationTimezone)->startOfDay();
        $reportTo = CarbonImmutable::parse($filters['to'] ?? now($presentationTimezone)->toDateString(), $presentationTimezone)->endOfDay();
        if ($reportTo->lessThan($reportFrom)) {
            return back()->withErrors(['to' => 'La fecha final debe ser posterior o igual a la inicial.'])->withInput();
        }
        if ($reportFrom->diffInDays($reportTo) > 366) {
            return back()->withErrors(['to' => 'El período del reporte no puede superar 366 días.'])->withInput();
        }
        $reportEmployees = Employee::with(['user', 'scheduleAssignments.workSchedule'])
            ->when(! empty($filters['employee_id']), fn ($query) => $query->whereKey($filters['employee_id']))
            ->orderBy('employee_number')
            ->get();
        $reportEvents = AttendanceEvent::with('employee.user')
            ->whereBetween('occurred_at_device', [$reportFrom->utc(), $reportTo->utc()])
            ->when(! empty($filters['employee_id']), fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->orderBy('occurred_at_device')
            ->get()
            ->groupBy('employee_id');
        $summaries = ['daily' => [], 'weekly' => [], 'alerts' => [], 'schedule_alerts' => []];
        foreach ($reportEmployees as $employee) {
            $hasOutstandingIssue = $employee->attendanceIncidents()->where('status', 'open')->exists()
                || $employee->correctionRequests()->where('status', 'pending')->exists();
            $summary = $summaryService->summarize(
                $employee,
                $reportEvents->get($employee->id, collect()),
                $employee->scheduleAssignments,
                $hasOutstandingIssue,
                $presentationTimezone,
            );
            $summaries['daily'] = [...$summaries['daily'], ...$summary['daily']];
            $summaries['weekly'] = [...$summaries['weekly'], ...$summary['weekly']];
            $summaries['alerts'] = [...$summaries['alerts'], ...$summary['alerts']];
            $summaries['schedule_alerts'] = [...$summaries['schedule_alerts'], ...$summary['schedule_alerts']];
        }

        return view('admin.dashboard', [
            'employees' => Employee::with('user')->orderBy('employee_number')->get(),
            'schedules' => WorkSchedule::where('is_active', true)->orderBy('name')->get(),
            'assignments' => ScheduleAssignment::with(['employee.user', 'workSchedule'])->latest('valid_from')->limit(100)->get(),
            'events' => $eventsQuery->limit(100)->get(),
            'incidents' => AttendanceIncident::with(['employee.user', 'attendanceEvent'])->where('status', 'open')->latest()->limit(100)->get(),
            'corrections' => CorrectionRequest::with(['employee.user', 'attendanceEvent', 'requester'])->where('status', 'pending')->latest()->limit(100)->get(),
            'dailyReports' => $summaries['daily'],
            'weeklyReports' => $summaries['weekly'],
            'reportAlerts' => $summaries['alerts'],
            'scheduleAlerts' => $summaries['schedule_alerts'],
            'reportFrom' => $reportFrom->toDateString(),
            'reportTo' => $reportTo->toDateString(),
        ]);
    }

    public function createEmployee(Request $request): RedirectResponse
    {
        $input = $request->all();
        if (is_string($input['employee_number'] ?? null)) {
            $input['employee_number'] = ChileanRut::normalize($input['employee_number']);
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:usuarios,email'],
            'employee_number' => [
                'required',
                'string',
                'max:10',
                new ValidChileanRut(),
                'unique:usuarios,rut',
            ],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ])->validate();

        try {
            DB::transaction(function () use ($data, $request): void {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'rut' => $data['employee_number'],
                    'password' => Hash::make($data['password']),
                    'role' => 'worker',
                ]);

                $employee = Employee::create([
                    'user_id' => $user->id,
                    'employee_number' => $data['employee_number'],
                ]);
                $this->audit($request, 'employee.created', $employee);
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            if (! RutConflict::isUniqueViolation($exception)) {
                throw $exception;
            }

            return back()
                ->withErrors(['employee_number' => 'Este RUT ya está asociado a otra cuenta.'])
                ->withInput($request->except('password'));
        }

        return back()->with('status', 'Trabajador creado.');
    }

    public function updateEmployeeStatus(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->user->role === 'worker', 403);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        DB::transaction(function () use ($employee, $data, $request): void {
            $employee->update(['status' => $data['status']]);
            $employee->user()->update(['is_active' => $data['status'] === 'active']);
            $this->audit($request, 'employee.status_changed', $employee);
        });

        return back()->with('status', 'Estado del trabajador actualizado.');
    }

    public function createSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expected_minutes_per_week' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'break_is_unpaid' => ['required', 'boolean'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7'],
        ]);
        $data['days_of_week'] = array_map('intval', $data['days_of_week'] ?? []);
        DB::transaction(function () use ($data, $request): void {
            $schedule = WorkSchedule::create([
            ...$data,
            'timezone' => config('attendance.presentation_timezone'),
            'is_active' => true,
            ]);
            $this->audit($request, 'schedule.created', $schedule);
        });

        return back()->with('status', 'Jornada configurada.');
    }

    public function assignSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:trabajadores,id'],
            'work_schedule_id' => ['required', 'integer', Rule::exists('jornadas_laborales', 'id')->where('is_active', true)],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $overlap = ScheduleAssignment::where('employee_id', $data['employee_id'])
            ->where(function ($query) use ($data): void {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $data['valid_from']);
            })
            ->where(function ($query) use ($data): void {
                if (! empty($data['valid_until'])) {
                    $query->whereDate('valid_from', '<=', $data['valid_until']);
                }
            })
            ->exists();
        if ($overlap) {
            return back()->withErrors(['employee_id' => 'El trabajador ya tiene una asignación vigente durante ese período.'])->withInput();
        }

        DB::transaction(function () use ($data, $request): void {
            $assignment = ScheduleAssignment::create($data);
            $this->audit($request, 'schedule.assigned', $assignment);
        });

        return back()->with('status', 'Asignación de jornada creada.');
    }

    public function resolveCorrection(Request $request, CorrectionRequest $correction): RedirectResponse
    {
        abort_unless($correction->status === 'pending', 409);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'resolution_note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        DB::transaction(function () use ($correction, $data, $request): void {
            $correction->update([
                ...$data,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now()->utc(),
            ]);
            $this->audit($request, 'correction.resolved', $correction);
        });

        return back()->with('status', 'Solicitud revisada. La marca original no fue modificada.');
    }

    public function reviewIncident(Request $request, AttendanceIncident $incident): RedirectResponse
    {
        abort_unless($incident->status === 'open', 409);
        DB::transaction(function () use ($incident, $request): void {
            $incident->update([
                'status' => 'reviewed',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now()->utc(),
            ]);
            $this->audit($request, 'incident.reviewed', $incident);
        });

        return back()->with('status', 'Incidencia revisada.');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:trabajadores,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = AttendanceEvent::with('employee.user')->orderBy('occurred_at_device');
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('occurred_at_device', '>=', CarbonImmutable::parse($filters['from'], config('attendance.presentation_timezone'))->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at_device', '<=', CarbonImmutable::parse($filters['to'], config('attendance.presentation_timezone'))->endOfDay()->utc());
        }

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['trabajador', 'identificador', 'tipo', 'hora_dispositivo_utc', 'offset_dispositivo_minutos', 'hora_servidor_utc', 'estado', 'incidencias_abiertas']);
            foreach ($query->cursor() as $event) {
                fputcsv($stream, [
                    $event->employee->user->name,
                    $event->employee->employee_number,
                    $event->event_type,
                    $event->occurred_at_device->utc()->toIso8601String(),
                    $event->device_offset_minutes,
                    $event->received_at_server->utc()->toIso8601String(),
                    'Sincronizada',
                    AttendanceIncident::where('attendance_event_id', $event->id)->where('status', 'open')->count(),
                ]);
            }
            fclose($stream);
        }, 'marcaciones.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function audit(Request $request, string $action, object $subject): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'created_at' => now()->utc(),
        ]);
    }
}
