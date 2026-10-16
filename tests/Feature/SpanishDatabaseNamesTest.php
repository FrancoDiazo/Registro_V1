<?php

namespace Tests\Feature;

use App\Models\AttendanceConfirmation;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpanishDatabaseNamesTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'bandeja_salida_confirmaciones_asistencia',
        'marcaciones_asistencia',
        'incidencias_asistencia',
        'registros_auditoria',
        'cache_aplicacion',
        'bloqueos_cache',
        'solicitudes_correccion',
        'trabajadores',
        'trabajos_fallidos',
        'trabajos_cola',
        'migraciones',
        'tokens_restablecimiento_clave',
        'asignaciones_jornada',
        'sesiones',
        'usuarios',
        'jornadas_laborales',
    ];

    private const SEQUENCES = [
        'attendance_confirmation_outbox_id_seq' => 'bandeja_salida_confirmaciones_asistencia_id_seq',
        'attendance_events_id_seq' => 'marcaciones_asistencia_id_seq',
        'attendance_incidents_id_seq' => 'incidencias_asistencia_id_seq',
        'audit_logs_id_seq' => 'registros_auditoria_id_seq',
        'correction_requests_id_seq' => 'solicitudes_correccion_id_seq',
        'employees_id_seq' => 'trabajadores_id_seq',
        'failed_jobs_id_seq' => 'trabajos_fallidos_id_seq',
        'jobs_id_seq' => 'trabajos_cola_id_seq',
        'migrations_id_seq' => 'migraciones_id_seq',
        'schedule_assignments_id_seq' => 'asignaciones_jornada_id_seq',
        'users_id_seq' => 'usuarios_id_seq',
        'work_schedules_id_seq' => 'jornadas_laborales_id_seq',
    ];

    public function test_fresh_migration_uses_the_spanish_tables_and_framework_configuration(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Falta la tabla {$table}.");
        }

        foreach ([
            'attendance_confirmation_outbox',
            'attendance_events',
            'attendance_incidents',
            'audit_logs',
            'cache',
            'cache_locks',
            'correction_requests',
            'employees',
            'failed_jobs',
            'jobs',
            'migrations',
            'password_reset_tokens',
            'schedule_assignments',
            'sessions',
            'users',
            'work_schedules',
        ] as $oldTable) {
            $this->assertFalse(Schema::hasTable($oldTable), "La tabla antigua {$oldTable} sigue presente.");
        }

        $this->assertSame('migraciones', config('database.migrations.table'));
        $this->assertSame('tokens_restablecimiento_clave', config('auth.passwords.users.table'));
        $this->assertSame('cache_aplicacion', config('cache.stores.database.table'));
        $this->assertSame('bloqueos_cache', config('cache.stores.database.lock_table'));
        $this->assertSame('sesiones', config('session.table'));
        $this->assertSame('trabajos_cola', config('queue.connections.database.table'));
        $this->assertSame('trabajos_fallidos', config('queue.failed.table'));
        $this->assertSame('usuarios', (new User())->getTable());
        $this->assertSame('trabajadores', (new Employee())->getTable());
        $this->assertSame('marcaciones_asistencia', (new AttendanceEvent())->getTable());

        if (DB::getDriverName() === 'pgsql') {
            $sequenceNames = DB::table('pg_sequences')
                ->where('schemaname', 'public')
                ->pluck('sequencename')
                ->all();
            foreach (self::SEQUENCES as $renamedSequence) {
                $this->assertContains($renamedSequence, $sequenceNames);
            }
            $this->assertCount(12, array_intersect($sequenceNames, array_values(self::SEQUENCES)));
        }
    }

    public function test_table_and_sequence_rename_is_reversible_without_losing_records_or_relations(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $schedule = WorkSchedule::create(['name' => 'Jornada de prueba']);
        $assignment = ScheduleAssignment::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'valid_from' => '2026-01-01',
        ]);
        $event = AttendanceEvent::create([
            'event_uuid' => 'f0b34be2-d1ca-49df-b8d3-40a46ab1c859',
            'employee_id' => $employee->id,
            'event_type' => 'ENTRY',
            'occurred_at_device' => now()->utc(),
            'device_offset_minutes' => -180,
            'client_timezone' => 'America/Santiago',
            'received_at_server' => now()->utc(),
            'offline' => true,
        ]);
        $confirmation = AttendanceConfirmation::create(['attendance_event_id' => $event->id]);
        DB::table('incidencias_asistencia')->insert([
            'employee_id' => $employee->id,
            'attendance_event_id' => $event->id,
            'type' => 'test',
            'description' => 'Incidencia de prueba',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('solicitudes_correccion')->insert([
            'employee_id' => $employee->id,
            'attendance_event_id' => $event->id,
            'requested_by' => $user->id,
            'reason' => 'Solicitud de prueba',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('registros_auditoria')->insert([
            'user_id' => $user->id,
            'action' => 'test',
            'created_at' => now(),
        ]);
        DB::table('cache_aplicacion')->insert(['key' => 'test', 'value' => 'cache-value', 'expiration' => 1]);
        DB::table('bloqueos_cache')->insert(['key' => 'test-lock', 'owner' => 'owner', 'expiration' => 1]);
        DB::table('tokens_restablecimiento_clave')->insert([
            'email' => $user->email,
            'token' => 'hashed-token',
            'created_at' => now(),
        ]);
        DB::table('sesiones')->insert([
            'id' => 'test-session',
            'user_id' => $user->id,
            'payload' => 'session-payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('trabajos_cola')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('trabajos_fallidos')->insert([
            'uuid' => 'd1f0f232-9c44-48fb-96f2-4738f0e45c8c',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);
        $historyCount = DB::table('migraciones')->count();
        $userSequenceValue = DB::getDriverName() === 'pgsql'
            ? (int) DB::scalar('SELECT last_value FROM usuarios_id_seq')
            : null;
        $sequenceState = DB::getDriverName() === 'pgsql'
            ? DB::table('pg_sequences')->where('schemaname', 'public')->pluck('last_value', 'sequencename')->all()
            : [];

        $migration = require database_path('migrations/2026_10_15_000003_rename_tables_and_sequences_to_spanish.php');
        $migration->down();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('attendance_events'));
        $this->assertSame($historyCount, DB::table('migraciones')->count());
        if (DB::getDriverName() === 'pgsql') {
            $sequenceNames = DB::table('pg_sequences')->where('schemaname', 'public')->pluck('sequencename')->all();
            foreach (array_diff(array_keys(self::SEQUENCES), ['migrations_id_seq']) as $oldSequence) {
                $this->assertContains($oldSequence, $sequenceNames);
            }
            $this->assertContains('migraciones_id_seq', $sequenceNames);
        }

        $migration->up();

        $this->assertSame($historyCount, DB::table('migraciones')->count());
        $this->assertSame($user->id, User::findOrFail($user->id)->id);
        $this->assertSame($employee->id, $user->fresh()->employee->id);
        $this->assertSame($schedule->id, $assignment->fresh()->workSchedule->id);
        $this->assertSame($event->id, $employee->fresh()->attendanceEvents->sole()->id);
        $this->assertSame($event->id, $confirmation->fresh()->attendanceEvent->id);
        $this->assertDatabaseHas('cache_aplicacion', ['key' => 'test', 'value' => 'cache-value']);
        $this->assertDatabaseHas('bloqueos_cache', ['key' => 'test-lock']);
        $this->assertDatabaseHas('tokens_restablecimiento_clave', ['email' => $user->email]);
        $this->assertDatabaseHas('sesiones', ['id' => 'test-session', 'user_id' => $user->id]);
        $this->assertDatabaseCount('trabajos_cola', 1);
        $this->assertDatabaseCount('trabajos_fallidos', 1);
        $this->assertDatabaseCount('incidencias_asistencia', 1);
        $this->assertDatabaseCount('solicitudes_correccion', 1);
        $this->assertDatabaseCount('registros_auditoria', 1);

        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame($userSequenceValue, (int) DB::scalar('SELECT last_value FROM usuarios_id_seq'));
            $renamedSequenceState = DB::table('pg_sequences')
                ->where('schemaname', 'public')
                ->pluck('last_value', 'sequencename')
                ->all();
            foreach (self::SEQUENCES as $oldName => $newName) {
                $this->assertSame($sequenceState[$newName], $renamedSequenceState[$newName], "Cambió el estado de {$oldName}.");
            }
            $nextUser = User::factory()->create();
            $this->assertGreaterThan($userSequenceValue, $nextUser->id);
        }
    }

    public function test_migration_repository_transition_preserves_history_and_has_a_dry_run(): void
    {
        config(['database.migrations.table' => 'migrations']);
        $this->artisan('migrations:transition-spanish --simulate')->assertExitCode(1);
        config(['database.migrations.table' => 'migraciones']);

        $before = DB::table('migraciones')->orderBy('id')->get(['id', 'migration', 'batch'])->toJson();
        $historySequenceValue = DB::getDriverName() === 'pgsql'
            ? DB::table('pg_sequences')->where('schemaname', 'public')->where('sequencename', 'migraciones_id_seq')->value('last_value')
            : null;

        $this->artisan('migrations:transition-spanish --reverse --simulate')->assertExitCode(0);
        $this->artisan('migrations:transition-spanish --check')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('migraciones'));
        $this->assertFalse(Schema::hasTable('migrations'));

        $this->artisan('migrations:transition-spanish --reverse')->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('migraciones'));
        $this->artisan('migrations:transition-spanish --check')->assertExitCode(1);
        $this->assertSame($before, DB::table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->toJson());
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame($historySequenceValue, DB::table('pg_sequences')->where('schemaname', 'public')->where('sequencename', 'migrations_id_seq')->value('last_value'));
        }

        $this->artisan('migrations:transition-spanish')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('migraciones'));
        $this->assertSame($before, DB::table('migraciones')->orderBy('id')->get(['id', 'migration', 'batch'])->toJson());
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame($historySequenceValue, DB::table('pg_sequences')->where('schemaname', 'public')->where('sequencename', 'migraciones_id_seq')->value('last_value'));
        }
    }
}
