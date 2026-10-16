<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\AttendanceConfirmation;
use App\Jobs\SendAttendanceConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('about', function (): void {
    $this->comment('MVP técnico de registro de asistencia; no es un sistema oficial autorizado.');
})->purpose('Mostrar información de la aplicación');

Artisan::command('attendance:make-administrator', function (): int {
    $name = $this->ask('Nombre de la persona administradora');
    $email = strtolower((string) $this->ask('Correo electrónico'));
    $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)');
    $validator = Validator::make(compact('name', 'email', 'password'), [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:usuarios,email'],
        'password' => ['required', 'string', 'min:12', 'max:255'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }

    User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'role' => 'administrator',
    ]);
    $this->info('Cuenta administradora creada.');

    return self::SUCCESS;
})->purpose('Crear la primera cuenta administradora de forma interactiva');

Artisan::command('attendance:retry-confirmations', function (): int {
    $confirmations = AttendanceConfirmation::with('attendanceEvent.employee.user')
        ->whereIn('status', ['pending', 'failed'])
        ->get();
    $queued = 0;

    foreach ($confirmations as $confirmation) {
        $user = $confirmation->attendanceEvent->employee->user;
        if (! $user->email_verified_at || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if ($confirmation->status === 'failed') {
            $confirmation->update([
                'status' => 'pending',
                'attempt_count' => 0,
                'last_error' => null,
            ]);
        }

        SendAttendanceConfirmation::dispatch($confirmation->id);
        $queued++;
    }

    $this->info("Se solicitaron {$queued} confirmaciones pendientes o fallidas.");

    return self::SUCCESS;
})->purpose('Reintentar confirmaciones por correo sin recrear marcas');

Artisan::command('migrations:transition-spanish {--reverse : Restaurar el nombre inglés del historial} {--simulate : Validar sin modificar la base} {--check : Fallar si la transición aún es necesaria}', function (): int {
    if (config('database.migrations.table') !== 'migraciones') {
        $this->error('La configuración cargada aún no apunta a migraciones; limpia la caché de configuración antes de continuar.');

        return self::FAILURE;
    }

    $reverse = (bool) $this->option('reverse');
    $simulate = (bool) $this->option('simulate');
    $check = (bool) $this->option('check');
    $source = $reverse ? 'migraciones' : 'migrations';
    $target = $reverse ? 'migrations' : 'migraciones';
    $sourceExists = Schema::hasTable($source);
    $targetExists = Schema::hasTable($target);

    if ($sourceExists && $targetExists) {
        $this->error("Existen ambas tablas {$source} y {$target}; no se realizó ningún cambio.");

        return self::FAILURE;
    }

    if ($sourceExists && ! $targetExists && $check) {
        $this->error("La tabla {$source} necesita transición antes de ejecutar migraciones. Ejecuta migrations:transition-spanish durante la ventana de mantenimiento.");

        return self::FAILURE;
    }

    if (! $sourceExists && $targetExists) {
        $columnsValid = Schema::hasColumns($target, ['id', 'migration', 'batch']);
        if (! $columnsValid) {
            $this->error("La tabla {$target} no tiene la estructura esperada del historial de Laravel.");

            return self::FAILURE;
        }

        if (DB::getDriverName() === 'pgsql' && Schema::hasColumn($target, 'id')) {
            $sequence = DB::selectOne(
                "SELECT c.relname AS name
                FROM pg_class c
                WHERE c.oid = pg_get_serial_sequence(format('%I.%I', current_schema(), ?::text), 'id')::regclass",
                [$target],
            );
            if ($sequence && $sequence->name !== $target.'_id_seq') {
                $this->error("La secuencia asociada a {$target}.id tiene un nombre inesperado: {$sequence->name}.");

                return self::FAILURE;
            }
        }

        $this->info("El historial ya está en {$target}; no se requiere transición.");

        return self::SUCCESS;
    }

    if (! $sourceExists) {
        if ($reverse) {
            $this->error('No se encontró la tabla de historial para revertir.');

            return self::FAILURE;
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
            'password_reset_tokens',
            'schedule_assignments',
            'sessions',
            'users',
            'work_schedules',
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
            'tokens_restablecimiento_clave',
            'asignaciones_jornada',
            'sesiones',
            'usuarios',
            'jornadas_laborales',
        ] as $applicationTable) {
            if (Schema::hasTable($applicationTable)) {
                $this->error("Se encontró {$applicationTable} sin una tabla de historial; no se asumirá que es una instalación nueva.");

                return self::FAILURE;
            }
        }

        $this->info('No existe historial previo; parece una instalación nueva. No se realizó ningún cambio.');

        return self::SUCCESS;
    }

    if (! Schema::hasColumns($source, ['id', 'migration', 'batch'])) {
        $this->error("La tabla {$source} no tiene la estructura esperada del historial de Laravel.");

        return self::FAILURE;
    }

    $before = DB::table($source)->orderBy('id')->get(['id', 'migration', 'batch']);
    $sequenceChange = null;
    if (DB::getDriverName() === 'pgsql' && Schema::hasColumn($source, 'id')) {
        $sequence = DB::selectOne(
            "SELECT c.relname AS name
            FROM pg_class c
            WHERE c.oid = pg_get_serial_sequence(format('%I.%I', current_schema(), ?::text), 'id')::regclass",
            [$source],
        );
        if ($sequence && $sequence->name !== $target.'_id_seq') {
            $sequenceExists = DB::table('pg_class')
                ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_class.relnamespace')
                ->where('pg_namespace.nspname', DB::scalar('SELECT current_schema()'))
                ->where('relname', $target.'_id_seq')
                ->where('relkind', 'S')
                ->exists();
            if ($sequenceExists) {
                $this->error("La secuencia {$target}_id_seq ya existe; no se realizó ningún cambio.");

                return self::FAILURE;
            }

            $sequenceChange = [
                (string) DB::scalar('SELECT current_schema()'),
                $sequence->name,
                $target.'_id_seq',
            ];
        }
    }

    if ($simulate) {
        $sequenceText = $sequenceChange ? " y la secuencia {$sequenceChange[1]} se renombraría a {$sequenceChange[2]}" : '';
        $this->info("Simulación correcta: {$source} se renombraría a {$target}{$sequenceText}; {$before->count()} registros y sus lotes se conservarán.");

        return self::SUCCESS;
    }

    DB::transaction(function () use ($source, $target, $before, $sequenceChange): void {
        Schema::rename($source, $target);
        if ($sequenceChange) {
            [$schema, $fromSequence, $toSequence] = $sequenceChange;
            $quote = static fn (string $identifier): string => '"'.str_replace('"', '""', $identifier).'"';
            DB::statement(sprintf(
                'ALTER SEQUENCE %s.%s RENAME TO %s',
                $quote($schema),
                $quote($fromSequence),
                $quote($toSequence),
            ));
        }

        $after = DB::table($target)->orderBy('id')->get(['id', 'migration', 'batch']);
        if ($before->toJson() !== $after->toJson()) {
            throw new RuntimeException('La validación del historial renombrado no coincide; la transacción será revertida.');
        }
    });

    $this->info("Historial renombrado de {$source} a {$target}; {$before->count()} registros y lotes conservados.");

    return self::SUCCESS;
})->purpose('Renombrar de forma controlada el repositorio de migraciones de Laravel');
