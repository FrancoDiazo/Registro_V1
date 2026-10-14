<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AttendanceIncident;
use App\Models\CorrectionRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_sync_attendance(): void
    {
        $this->postJson('/api/attendance/sync', ['events' => []])->assertUnauthorized();
    }

    public function test_administrator_cannot_submit_a_worker_attendance_batch(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)
            ->postJson('/api/attendance/sync', ['events' => [['event_uuid' => 'not-an-event']]])
            ->assertForbidden();
    }

    public function test_event_uuid_is_idempotent_and_the_client_cannot_select_an_employee(): void
    {
        [$user, $employee] = $this->worker();
        [, $otherEmployee] = $this->worker();
        $event = $this->eventPayload($employee, 'ENTRY');
        $event['employee_id'] = $otherEmployee->id;

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$event]])
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$event]])
            ->assertOk()->assertJsonPath('results.0.status', 'already_received');

        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseHas('attendance_events', ['event_uuid' => $event['event_uuid'], 'employee_id' => $employee->id]);
    }

    public function test_duplicate_events_in_one_batch_return_an_individual_confirmation_for_each_copy(): void
    {
        [$user, $employee] = $this->worker();
        $event = $this->eventPayload($employee, 'ENTRY');

        $this->actingAs($user)->postJson('/api/attendance/sync', [
            'events' => [$event, $event],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'already_received');

        $this->assertDatabaseCount('attendance_events', 1);
    }

    public function test_duplicate_device_sequence_is_preserved_and_flagged_for_review(): void
    {
        [$user, $employee] = $this->worker();
        $first = $this->eventPayload($employee, 'ENTRY');
        $duplicateSequence = $this->eventPayload($employee, 'EXIT');
        $duplicateSequence['device_event_seq'] = $first['device_event_seq'];

        $this->actingAs($user)->postJson('/api/attendance/sync', [
            'events' => [$first, $duplicateSequence],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'accepted');

        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_events', ['event_uuid' => $first['event_uuid']]);
        $this->assertDatabaseHas('attendance_incidents', [
            'attendance_event_id' => AttendanceEvent::where('event_uuid', $duplicateSequence['event_uuid'])->value('id'),
            'type' => 'duplicate_device_sequence',
        ]);
    }

    public function test_batch_sync_returns_each_event_result_and_invalid_types_are_retained_as_errors(): void
    {
        [$user, $employee] = $this->worker();
        $valid = $this->eventPayload($employee, 'ENTRY');
        $invalid = $this->eventPayload($employee, 'UNRECOGNIZED');

        $response = $this->actingAs($user)->postJson('/api/attendance/sync', [
            'events' => [$valid, $invalid],
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'error');
        $this->assertDatabaseCount('attendance_events', 1);
    }

    public function test_invalid_device_timestamp_without_an_offset_is_rejected(): void
    {
        [$user, $employee] = $this->worker();
        $event = $this->eventPayload($employee, 'ENTRY');
        $event['occurred_at_device'] = now()->format('Y-m-d H:i:s');

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$event]])
            ->assertOk()->assertJsonPath('results.0.status', 'error');
        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_server_stores_the_device_offset_separately_from_the_utc_instant(): void
    {
        [$user, $employee] = $this->worker();
        $deviceTime = now('America/Santiago')->subMinute();
        $event = $this->eventPayload($employee, 'ENTRY', $deviceTime);
        $event['occurred_at_device'] = $deviceTime->toIso8601String();

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$event]])
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $stored = AttendanceEvent::firstOrFail();
        $this->assertSame(intdiv($deviceTime->getOffset(), 60), $stored->device_offset_minutes);
        $this->assertSame($deviceTime->utc()->toIso8601String(), $stored->occurred_at_device->utc()->toIso8601String());
    }

    public function test_out_of_order_events_are_preserved_and_create_an_incident(): void
    {
        [$user, $employee] = $this->worker();
        $later = $this->eventPayload($employee, 'EXIT', now()->subMinute());
        $later['device_event_seq'] = 2;
        $earlier = $this->eventPayload($employee, 'ENTRY', now()->subMinutes(2));
        $earlier['device_event_seq'] = 1;

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$later, $earlier]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'accepted');

        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_incidents', [
            'employee_id' => $employee->id,
            'type' => 'out_of_order',
            'status' => 'open',
        ]);
    }

    public function test_anomalous_sequences_are_preserved_and_flagged_for_review(): void
    {
        [$user, $employee] = $this->worker();
        $entry = $this->eventPayload($employee, 'ENTRY', now()->subMinutes(2));
        $breakEnd = $this->eventPayload($employee, 'BREAK_END', now()->subMinute());

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$entry, $breakEnd]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'accepted');

        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_incidents', [
            'attendance_event_id' => AttendanceEvent::where('event_uuid', $breakEnd['event_uuid'])->value('id'),
            'type' => 'sequence_anomaly',
        ]);
    }

    public function test_a_first_event_without_a_possible_entry_is_kept_as_an_incident(): void
    {
        [$user, $employee] = $this->worker();
        $exit = $this->eventPayload($employee, 'EXIT');

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$exit]])
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseHas('attendance_incidents', [
            'employee_id' => $employee->id,
            'attendance_event_id' => AttendanceEvent::where('event_uuid', $exit['event_uuid'])->value('id'),
            'type' => 'sequence_anomaly',
        ]);
    }

    public function test_delayed_offline_event_is_preserved_with_a_review_incident(): void
    {
        [$user, $employee] = $this->worker();
        $event = $this->eventPayload($employee, 'ENTRY', now()->subHours(2));

        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$event]])
            ->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $this->assertDatabaseHas('attendance_events', ['event_uuid' => $event['event_uuid'], 'offline' => true]);
        $this->assertDatabaseHas('attendance_incidents', [
            'attendance_event_id' => AttendanceEvent::where('event_uuid', $event['event_uuid'])->value('id'),
            'type' => 'offline_sync_delay',
        ]);
    }

    public function test_worker_cannot_list_another_workers_attendance(): void
    {
        [$owner] = $this->worker();
        [, $otherEmployee] = $this->worker();
        $event = $this->eventPayload($otherEmployee, 'ENTRY');
        $otherEvent = AttendanceEvent::create($event + [
            'employee_id' => $otherEmployee->id,
            'occurred_at_device' => $event['occurred_at_device'],
            'received_at_server' => now()->utc(),
        ]);

        $this->actingAs($owner)->get('/history')->assertOk()->assertDontSee($otherEmployee->user->name);
        $this->get('/attendance-events/'.$otherEvent->id)->assertForbidden();
        $this->assertFalse($owner->can('view', AttendanceEvent::first()));
    }

    public function test_worker_can_request_a_correction_without_changing_the_original_event(): void
    {
        [$user, $employee] = $this->worker();
        $payload = $this->eventPayload($employee, 'ENTRY');
        $this->actingAs($user)->postJson('/api/attendance/sync', ['events' => [$payload]])->assertOk();
        $event = AttendanceEvent::firstOrFail();
        $originalTime = $event->occurred_at_device->toIso8601String();

        $this->post('/correction-requests', [
            'attendance_event_id' => $event->id,
            'reason' => 'La hora registrada no coincide con el inicio de mi turno.',
            'requested_event_type' => 'ENTRY',
            'requested_occurred_at' => now('America/Santiago')->subMinutes(10)->format('Y-m-d\TH:i'),
            'requested_timezone' => 'America/Santiago',
        ])->assertRedirect();

        $this->assertDatabaseCount('correction_requests', 1);
        $correction = CorrectionRequest::firstOrFail();
        $this->assertSame($user->id, $correction->requested_by);
        $this->assertSame('pending', $correction->status);

        $administrator = User::factory()->administrator()->create();
        $this->actingAs($administrator)->post('/admin/corrections/'.$correction->id.'/resolve', [
            'status' => 'approved',
            'resolution_note' => 'Revisada con la información disponible.',
        ])->assertRedirect();

        $this->assertDatabaseHas('correction_requests', [
            'id' => $correction->id,
            'status' => 'approved',
            'resolved_by' => $administrator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'action' => 'correction.resolved',
            'subject_id' => $correction->id,
        ]);
        $this->assertSame($originalTime, $event->fresh()->occurred_at_device->toIso8601String());
    }

    public function test_original_attendance_events_cannot_be_updated_or_deleted(): void
    {
        [, $employee] = $this->worker();
        $event = AttendanceEvent::create($this->eventPayload($employee, 'ENTRY') + [
            'employee_id' => $employee->id,
            'received_at_server' => now()->utc(),
        ]);

        try {
            $event->update(['event_type' => 'EXIT']);
            $this->fail('La marca original se pudo modificar.');
        } catch (\LogicException $exception) {
            $this->assertSame('Las marcas originales son inmutables.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('La marca original se pudo eliminar.');
        } catch (\LogicException $exception) {
            $this->assertSame('Las marcas originales no se pueden eliminar.', $exception->getMessage());
        }

        $this->assertDatabaseHas('attendance_events', ['id' => $event->id, 'event_type' => 'ENTRY']);
    }

    public function test_worker_cannot_request_a_correction_for_another_employee(): void
    {
        [$user] = $this->worker();
        [, $otherEmployee] = $this->worker();
        $event = AttendanceEvent::create($this->eventPayload($otherEmployee, 'ENTRY') + [
            'employee_id' => $otherEmployee->id,
            'received_at_server' => now()->utc(),
        ]);

        $this->actingAs($user)->post('/correction-requests', [
            'attendance_event_id' => $event->id,
            'reason' => 'Solicito revisar la hora de esta marca.',
        ])->assertSessionHasErrors('attendance_event_id');
    }

    private function worker(): array
    {
        $user = User::factory()->create();

        return [$user, Employee::factory()->create(['user_id' => $user->id])];
    }

    private function eventPayload(Employee $employee, string $type, mixed $occurredAt = null): array
    {
        return [
            'event_uuid' => fake()->uuid(),
            'event_type' => $type,
            'occurred_at_device' => ($occurredAt ?? now()->subMinute())->utc()->toIso8601String(),
            'client_timezone' => 'America/Santiago',
            'device_id' => '8fc770c8-53bf-4ca0-a092-baa748b1b905',
            'device_event_seq' => random_int(1, 1000000),
            'offline' => true,
            'account_binding' => hash_hmac('sha256', (string) $employee->id, (string) config('app.key')),
        ];
    }
}
