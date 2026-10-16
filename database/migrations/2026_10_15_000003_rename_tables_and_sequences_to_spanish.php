<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'attendance_confirmation_outbox' => 'bandeja_salida_confirmaciones_asistencia',
        'attendance_events' => 'marcaciones_asistencia',
        'attendance_incidents' => 'incidencias_asistencia',
        'audit_logs' => 'registros_auditoria',
        'cache' => 'cache_aplicacion',
        'cache_locks' => 'bloqueos_cache',
        'correction_requests' => 'solicitudes_correccion',
        'employees' => 'trabajadores',
        'failed_jobs' => 'trabajos_fallidos',
        'jobs' => 'trabajos_cola',
        'password_reset_tokens' => 'tokens_restablecimiento_clave',
        'schedule_assignments' => 'asignaciones_jornada',
        'sessions' => 'sesiones',
        'users' => 'usuarios',
        'work_schedules' => 'jornadas_laborales',
    ];

    public function up(): void
    {
        $this->rename($this->tables);
    }

    public function down(): void
    {
        $this->rename(array_flip($this->tables));
    }

    private function rename(array $tables): void
    {
        $tableChanges = [];
        foreach ($tables as $from => $to) {
            $fromExists = Schema::hasTable($from);
            $toExists = Schema::hasTable($to);

            if ($fromExists === $toExists) {
                throw new RuntimeException("No se puede renombrar {$from} a {$to}: estado de tablas ambiguo.");
            }

            if ($fromExists) {
                $tableChanges[] = [$from, $to];
            }
        }

        $sequenceChanges = $this->sequenceChanges($tableChanges);

        DB::transaction(function () use ($tableChanges, $sequenceChanges): void {
            foreach ($tableChanges as [$from, $to]) {
                Schema::rename($from, $to);
            }

            foreach ($sequenceChanges as [$schema, $from, $to]) {
                DB::statement(sprintf(
                    'ALTER SEQUENCE %s.%s RENAME TO %s',
                    $this->quoteIdentifier($schema),
                    $this->quoteIdentifier($from),
                    $this->quoteIdentifier($to),
                ));
            }
        });
    }

    private function sequenceChanges(array $tableChanges): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $schema = (string) DB::scalar('SELECT current_schema()');
        $changes = [];
        foreach ($tableChanges as [$from, $to]) {
            if (! Schema::hasColumn($from, 'id')) {
                continue;
            }

            $sequence = DB::selectOne(
                "SELECT c.relname AS name
                FROM pg_class c
                WHERE c.oid = pg_get_serial_sequence(format('%I.%I', current_schema(), ?::text), 'id')::regclass",
                [$from],
            );
            if (! $sequence) {
                continue;
            }

            $fromSequence = $sequence->name;
            $toSequence = $to.'_id_seq';
            if ($fromSequence === $toSequence) {
                continue;
            }

            $targetExists = DB::table('pg_class')
                ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_class.relnamespace')
                ->where('relname', $toSequence)
                ->where('pg_namespace.nspname', $schema)
                ->where('relkind', 'S')
                ->exists();
            if ($targetExists) {
                throw new RuntimeException("La secuencia {$schema}.{$toSequence} ya existe.");
            }

            $changes[] = [$schema, $fromSequence, $toSequence];
        }

        return $changes;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
