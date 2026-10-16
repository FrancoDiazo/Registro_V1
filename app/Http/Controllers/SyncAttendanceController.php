<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\AttendanceConfirmation;
use App\Models\AttendanceIncident;
use App\Jobs\SendAttendanceConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SyncAttendanceController extends Controller
{
    private const VALID_TRANSITIONS = [
        'ENTRY' => ['BREAK_START', 'EXIT'],
        'BREAK_START' => ['BREAK_END'],
        'BREAK_END' => ['EXIT'],
        'EXIT' => ['ENTRY'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $batch = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
        ])['events'];
        $employee = $request->user()->employee;

        if (! $employee || $employee->status !== 'active') {
            return response()->json(['message' => 'No existe un perfil de trabajador activo.'], 403);
        }

        $results = [];
        foreach ($batch as $index => $event) {
            $results[] = $this->receiveEvent($employee->id, $index, $event);
        }

        return response()->json(['results' => $results]);
    }

    private function receiveEvent(int $employeeId, int $index, mixed $event): array
    {
        $validator = Validator::make(is_array($event) ? $event : [], [
            'event_uuid' => ['required', 'uuid'],
            'event_type' => ['required', Rule::in(config('attendance.allowed_event_types'))],
            'occurred_at_device' => [
                'required',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
            ],
            'client_timezone' => ['required', 'timezone'],
            'device_id' => ['nullable', 'uuid'],
            'device_event_seq' => ['nullable', 'integer', 'min:1'],
            'offline' => ['required', 'boolean'],
            'account_binding' => ['required', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return [
                'index' => $index,
                'event_uuid' => is_array($event) ? ($event['event_uuid'] ?? null) : null,
                'status' => 'error',
                'errors' => $validator->errors(),
            ];
        }

        $data = $validator->validated();
        $expectedBinding = hash_hmac('sha256', (string) $employeeId, (string) config('app.key'));
        if (! hash_equals($expectedBinding, $data['account_binding'])) {
            return [
                'index' => $index,
                'event_uuid' => $data['event_uuid'],
                'status' => 'error',
                'errors' => ['account_binding' => ['Inicia sesión con la cuenta que registró este evento pendiente.']],
            ];
        }

        if (AttendanceEvent::where('event_uuid', $data['event_uuid'])->exists()) {
            $sameOwner = AttendanceEvent::where('event_uuid', $data['event_uuid'])
                ->where('employee_id', $employeeId)
                ->exists();
            if ($sameOwner) {
                $stored = AttendanceEvent::where('event_uuid', $data['event_uuid'])->firstOrFail();
                $this->dispatchPendingConfirmation($stored->id, $employeeId);
            }

            return [
                'index' => $index,
                'event_uuid' => $data['event_uuid'],
                'status' => $sameOwner ? 'already_received' : 'error',
                ...($sameOwner ? [] : ['errors' => ['event_uuid' => ['El identificador ya está asociado a otro trabajador.']]]),
            ];
        }

        $occurredAt = CarbonImmutable::parse($data['occurred_at_device'])->utc();
        $receivedAt = CarbonImmutable::now('UTC');

        $savedEvent = DB::transaction(function () use ($employeeId, $data, $occurredAt, $receivedAt): ?array {
            DB::table('trabajadores')->where('id', $employeeId)->lockForUpdate()->first();

            $inserted = DB::table('marcaciones_asistencia')->insertOrIgnore([
                'event_uuid' => $data['event_uuid'],
                'employee_id' => $employeeId,
                'event_type' => $data['event_type'],
                'occurred_at_device' => $occurredAt,
                'device_offset_minutes' => intdiv(CarbonImmutable::parse($data['occurred_at_device'])->getOffset(), 60),
                'client_timezone' => $data['client_timezone'],
                'received_at_server' => $receivedAt,
                'device_id' => $data['device_id'] ?? null,
                'device_event_seq' => $data['device_event_seq'] ?? null,
                'offline' => $data['offline'],
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
            if ($inserted === 0) {
                return null;
            }

            $savedEvent = AttendanceEvent::where('event_uuid', $data['event_uuid'])->firstOrFail();
            $this->recordIncidents($savedEvent, $occurredAt, $receivedAt);
            $user = $savedEvent->employee->user;
            $confirmation = $user->email_verified_at
                ? AttendanceConfirmation::create(['attendance_event_id' => $savedEvent->id])
                : null;

            return [
                'event_id' => $savedEvent->id,
                'confirmation_id' => $confirmation?->id,
            ];
        });

        if ($savedEvent === null) {
            $stored = AttendanceEvent::where('event_uuid', $data['event_uuid'])->first();

            if ($stored?->employee_id === $employeeId) {
                $this->dispatchPendingConfirmation($stored->id, $employeeId);

                return ['index' => $index, 'event_uuid' => $data['event_uuid'], 'status' => 'already_received'];
            }

            return [
                'index' => $index,
                'event_uuid' => $data['event_uuid'],
                'status' => 'error',
                'errors' => ['event_uuid' => ['El evento duplicado o su secuencia de dispositivo no es válida.']],
            ];
        }

        if ($savedEvent['confirmation_id']) {
            SendAttendanceConfirmation::dispatch($savedEvent['confirmation_id']);
        }

        return ['index' => $index, 'event_uuid' => $data['event_uuid'], 'status' => 'accepted'];
    }

    private function dispatchPendingConfirmation(int $eventId, int $employeeId): void
    {
        $event = AttendanceEvent::with('employee.user')
            ->whereKey($eventId)
            ->where('employee_id', $employeeId)
            ->first();
        $user = $event?->employee?->user;
        if (! $event || ! $user?->email_verified_at || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $confirmation = AttendanceConfirmation::firstOrCreate(
            ['attendance_event_id' => $event->id],
            ['status' => 'pending'],
        );
        if ($confirmation->status === 'pending') {
            SendAttendanceConfirmation::dispatch($confirmation->id);
        }
    }

    private function recordIncidents(AttendanceEvent $event, CarbonImmutable $occurredAt, CarbonImmutable $receivedAt): void
    {
        $driftMinutes = abs($occurredAt->diffInMinutes($receivedAt));
        $clockThreshold = config('attendance.clock_drift_minutes');
        $deviceTimeInFuture = $occurredAt->greaterThan($receivedAt->addMinutes($clockThreshold));
        if ($deviceTimeInFuture || $driftMinutes > $clockThreshold) {
            $offlineDelay = $event->offline && ! $deviceTimeInFuture;
            AttendanceIncident::create([
                'employee_id' => $event->employee_id,
                'attendance_event_id' => $event->id,
                'type' => $offlineDelay ? 'offline_sync_delay' : 'clock_drift',
                'description' => $offlineDelay
                    ? "La marca offline llegó al servidor {$driftMinutes} minutos después de la hora declarada; puede deberse a falta de conexión o a diferencia del reloj y requiere revisión."
                    : "La diferencia entre la hora del dispositivo y la recepción supera {$driftMinutes} minutos.",
            ]);
        }

        $duplicateDeviceSequence = $event->device_id && $event->device_event_seq
            ? AttendanceEvent::where('employee_id', $event->employee_id)
                ->where('device_id', $event->device_id)
                ->where('device_event_seq', $event->device_event_seq)
                ->where('id', '<>', $event->id)
                ->exists()
            : false;
        if ($duplicateDeviceSequence) {
            AttendanceIncident::create([
                'employee_id' => $event->employee_id,
                'attendance_event_id' => $event->id,
                'type' => 'duplicate_device_sequence',
                'description' => 'El identificador de secuencia local está repetido. Ambas marcas se conservaron para revisión.',
            ]);
        }

        $laterSequenceArrivedFirst = $event->device_id && $event->device_event_seq
            ? AttendanceEvent::where('employee_id', $event->employee_id)
                ->where('device_id', $event->device_id)
                ->where('id', '<', $event->id)
                ->where('device_event_seq', '>', $event->device_event_seq)
                ->exists()
            : false;
        if ($laterSequenceArrivedFirst) {
            AttendanceIncident::create([
                'employee_id' => $event->employee_id,
                'attendance_event_id' => $event->id,
                'type' => 'out_of_order',
                'description' => 'El orden de recepción no coincide con la secuencia local del dispositivo.',
            ]);
        }

        $ordered = AttendanceEvent::where('employee_id', $event->employee_id)
            ->orderBy('occurred_at_device')
            ->orderBy('id')
            ->get(['id', 'event_type']);
        $position = $ordered->search(fn (AttendanceEvent $candidate): bool => $candidate->id === $event->id);
        $previous = $position > 0 ? $ordered->get($position - 1) : null;
        $next = $ordered->get($position + 1);
        $invalidPrevious = ! $previous && $event->event_type !== 'ENTRY';
        $invalidPrevious = $invalidPrevious
            || ($previous && ! in_array($event->event_type, self::VALID_TRANSITIONS[$previous->event_type] ?? [], true));
        $invalidNext = $next && ! in_array($next->event_type, self::VALID_TRANSITIONS[$event->event_type] ?? [], true);

        if ($invalidPrevious || $invalidNext) {
            AttendanceIncident::create([
                'employee_id' => $event->employee_id,
                'attendance_event_id' => $event->id,
                'type' => 'sequence_anomaly',
                'description' => 'La secuencia de marcas requiere revisión. El evento original se conservó.',
            ]);
        }
    }
}
