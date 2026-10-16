# Renombrado controlado del esquema PostgreSQL

## Alcance y diagnóstico

La aplicación utiliza Laravel 12.70.0, PHP 8.3.35 y PostgreSQL 16.15. La migración `2026_10_15_000003_rename_tables_and_sequences_to_spanish` renombra objetos existentes; no recrea tablas, elimina datos ni cambia columnas. Las migraciones históricas permanecen intactas.

El esquema define `work_schedules` como una jornada configurable con días, horas de inicio/fin, colación y minutos semanales; por eso se traduce como `jornadas_laborales`, no `horarios_laborales`. `attendance_events` almacena los cuatro tipos permitidos de marcación, así que el nombre preciso es `marcaciones_asistencia`.

### Tablas

| Nombre anterior | Nombre nuevo |
|---|---|
| `attendance_confirmation_outbox` | `bandeja_salida_confirmaciones_asistencia` |
| `attendance_events` | `marcaciones_asistencia` |
| `attendance_incidents` | `incidencias_asistencia` |
| `audit_logs` | `registros_auditoria` |
| `cache` | `cache_aplicacion` |
| `cache_locks` | `bloqueos_cache` |
| `correction_requests` | `solicitudes_correccion` |
| `employees` | `trabajadores` |
| `failed_jobs` | `trabajos_fallidos` |
| `jobs` | `trabajos_cola` |
| `password_reset_tokens` | `tokens_restablecimiento_clave` |
| `schedule_assignments` | `asignaciones_jornada` |
| `sessions` | `sesiones` |
| `users` | `usuarios` |
| `work_schedules` | `jornadas_laborales` |
| `migrations` | `migraciones` — procedimiento especial |

Las tablas Laravel de caché, bloqueos, sesiones, cola, trabajos fallidos y tokens de restablecimiento conservan sus columnas y datos. `job_batches` no existe y no se usa; la configuración de batching de Laravel permanece con su nombre reservado, pero no se ha creado una tabla ni se incorpora a esta transición.

### Secuencias

En PostgreSQL, la migración renombra solo secuencias que `pg_get_serial_sequence` identifica como asociadas a la columna `id`. No crea secuencias, reinicia valores ni usa `setval`.

| Nombre anterior | Nombre nuevo |
|---|---|
| `attendance_confirmation_outbox_id_seq` | `bandeja_salida_confirmaciones_asistencia_id_seq` |
| `attendance_events_id_seq` | `marcaciones_asistencia_id_seq` |
| `attendance_incidents_id_seq` | `incidencias_asistencia_id_seq` |
| `audit_logs_id_seq` | `registros_auditoria_id_seq` |
| `correction_requests_id_seq` | `solicitudes_correccion_id_seq` |
| `employees_id_seq` | `trabajadores_id_seq` |
| `failed_jobs_id_seq` | `trabajos_fallidos_id_seq` |
| `jobs_id_seq` | `trabajos_cola_id_seq` |
| `migrations_id_seq` | `migraciones_id_seq` — procedimiento especial |
| `schedule_assignments_id_seq` | `asignaciones_jornada_id_seq` |
| `users_id_seq` | `usuarios_id_seq` |
| `work_schedules_id_seq` | `jornadas_laborales_id_seq` |

`sessions`, `cache`, `cache_locks` y `password_reset_tokens` no usan secuencias. Los índices y restricciones existentes conservan sus nombres antiguos intencionalmente: PostgreSQL mantiene sus dependencias, no se reconstruyen y no se eliminan.

Las columnas y contratos no cambian: `id`, `user_id`, `employee_id`, `created_at`, claves JSON, tipos de evento, rutas y nombres de clases permanecen iguales.

## Historial especial de Laravel

La configuración de Laravel apunta ahora a `migraciones`. No renombres manualmente esa tabla ni ejecutes `migrate` en una base existente antes de trasladar el historial: Laravel podría crear un historial vacío y volver a considerar pendientes las migraciones históricas.

El comando `migrations:transition-spanish` valida columnas (`id`, `migration`, `batch`), detecta colisiones y renombra la tabla y su secuencia asociada dentro de una transacción. Los IDs, nombres de migración, lotes y valores de secuencia se conservan.

- `php artisan migrations:transition-spanish --simulate`: simula el traslado inglés → español sin escribir.
- `php artisan migrations:transition-spanish`: efectúa el traslado inglés → español.
- `php artisan migrations:transition-spanish --reverse --simulate`: simula el traslado español → inglés.
- `php artisan migrations:transition-spanish --reverse`: efectúa el traslado español → inglés.
- `php artisan migrations:transition-spanish --check`: comprueba que el historial ya está en español; falla si todavía requiere transición.

El contenedor ejecuta primero `--check` y solo después `migrate --force`. Por eso, al detectar un historial antiguo no aplicará migraciones ni iniciará la web; muestra el paso de transición requerido.

Una instalación vacía no necesita el comando: Laravel crea `migraciones`, ejecuta las migraciones históricas sin cambios y luego la migración nueva renombra las tablas y secuencias del esquema.

## Actualización de una base existente

Realiza este procedimiento en una ventana de mantenimiento. Ensáyalo primero con una copia restaurada en PostgreSQL aislado.

1. **Respaldo verificado.** Conserva una copia fuera del host/volumen que contiene la base y verifica que pueda restaurarse en PostgreSQL aislado. Ejemplo para PowerShell y Docker:

   ```powershell
   New-Item -ItemType Directory -Force C:\Backups
   docker compose exec -T db sh -lc 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB" -f /tmp/asistencia-pre-cambio.dump && pg_restore --list /tmp/asistencia-pre-cambio.dump >/dev/null'
   docker compose cp db:/tmp/asistencia-pre-cambio.dump C:\Backups\asistencia-pre-cambio.dump
   docker compose --profile test up -d postgres-tests
   docker compose cp C:\Backups\asistencia-pre-cambio.dump postgres-tests:/tmp/asistencia-pre-cambio.dump
   docker compose exec -T postgres-tests createdb -U asistencia_test asistencia_respaldo_validacion
   docker compose exec -T postgres-tests pg_restore -U asistencia_test --no-owner --exit-on-error -d asistencia_respaldo_validacion /tmp/asistencia-pre-cambio.dump
   ```

   Confirma que la restauración se completó sin errores y consulta los conteos de tablas en esa base aislada. Conserva el archivo de respaldo conforme a la política de TI; no lo guardes en el repositorio.
2. **Pausa.** Detén la web, los workers y tareas programadas para impedir nuevas escrituras:

   ```powershell
   docker compose stop app queue
   ```

   Mantén PostgreSQL activo.
3. **Construye el código nuevo** sin arrancar la aplicación:

   ```powershell
   docker compose build app queue
   docker compose run --rm --no-deps app php artisan config:clear
   ```

4. **Simula** la transición usando el contenedor actualizado:

   ```powershell
   docker compose run --rm --no-deps app php artisan migrations:transition-spanish --simulate
   ```

   Si reporta ambas tablas del historial, estructura inesperada o colisión de secuencias, detente y resuelve la causa antes de ejecutar el cambio.
5. **Traslada el historial**:

   ```powershell
   docker compose run --rm --no-deps app php artisan migrations:transition-spanish
   ```

6. **Inicia la aplicación.** El arranque verifica el historial y ejecuta las migraciones pendientes, incluida la migración reversible de tablas y secuencias:

   ```powershell
   docker compose up -d app queue
   docker compose ps
   docker compose exec app php artisan migrate:status
   ```

7. **Valida integridad y funcionalidad** con las consultas de esta guía y las pruebas de PostgreSQL aislado antes de terminar mantenimiento.

Actualiza cualquier variable de entorno opcional que sobrescriba nombres de tablas: `DB_CACHE_TABLE`, `DB_CACHE_LOCK_TABLE`, `DB_QUEUE_TABLE`, `DB_FAILED_JOBS_TABLE`, `SESSION_TABLE` y `AUTH_PASSWORD_RESET_TOKEN_TABLE`. Los nombres de esas variables son claves de configuración conservadas; solo sus valores de tabla cambian.

## Consultas de validación

Ejecútalas con `psql` en la base correspondiente. No muestran datos personales:

```sql
SELECT current_database(), version();

SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
ORDER BY table_name;

SELECT schemaname, sequencename, last_value
FROM pg_sequences
WHERE schemaname = 'public'
ORDER BY sequencename;

SELECT table_name, column_name, column_default
FROM information_schema.columns
WHERE table_schema = 'public'
  AND column_name = 'id'
ORDER BY table_name;

SELECT conrelid::regclass AS tabla, conname, pg_get_constraintdef(oid)
FROM pg_constraint
WHERE connamespace = 'public'::regnamespace
ORDER BY tabla::text, conname;

SELECT indexrelid::regclass AS indice, pg_get_indexdef(indexrelid)
FROM pg_index
WHERE indrelid::regclass::text LIKE 'public.%'
ORDER BY indice::text;

SELECT id, migration, batch FROM migraciones ORDER BY id;
```

Comprueba las asociaciones IDENTITY/SERIAL sin asumir nombres de columnas:

```sql
SELECT pg_get_serial_sequence('public.usuarios', 'id');
SELECT pg_get_serial_sequence('public.trabajadores', 'id');
SELECT pg_get_serial_sequence('public.marcaciones_asistencia', 'id');
SELECT pg_get_serial_sequence('public.migraciones', 'id');
```

Para comparar el número de filas antes y después, registra estos resultados **sin exportar contenido**:

```sql
SELECT 'bandeja_salida_confirmaciones_asistencia' AS tabla, count(*) FROM bandeja_salida_confirmaciones_asistencia
UNION ALL SELECT 'marcaciones_asistencia', count(*) FROM marcaciones_asistencia
UNION ALL SELECT 'incidencias_asistencia', count(*) FROM incidencias_asistencia
UNION ALL SELECT 'registros_auditoria', count(*) FROM registros_auditoria
UNION ALL SELECT 'cache_aplicacion', count(*) FROM cache_aplicacion
UNION ALL SELECT 'bloqueos_cache', count(*) FROM bloqueos_cache
UNION ALL SELECT 'solicitudes_correccion', count(*) FROM solicitudes_correccion
UNION ALL SELECT 'trabajadores', count(*) FROM trabajadores
UNION ALL SELECT 'trabajos_fallidos', count(*) FROM trabajos_fallidos
UNION ALL SELECT 'trabajos_cola', count(*) FROM trabajos_cola
UNION ALL SELECT 'migraciones', count(*) FROM migraciones
UNION ALL SELECT 'tokens_restablecimiento_clave', count(*) FROM tokens_restablecimiento_clave
UNION ALL SELECT 'asignaciones_jornada', count(*) FROM asignaciones_jornada
UNION ALL SELECT 'sesiones', count(*) FROM sesiones
UNION ALL SELECT 'usuarios', count(*) FROM usuarios
UNION ALL SELECT 'jornadas_laborales', count(*) FROM jornadas_laborales
ORDER BY tabla;
```

El resultado esperado es exactamente esas 16 tablas y las 12 secuencias listadas arriba. Compara también los valores `last_value` antes/después y verifica que nuevas inserciones generen IDs mayores que el estado preservado.

## Reversión

No reviertas código y configuración mientras la aplicación nueva esté atendiendo tráfico.

1. Detén aplicación, workers y tareas; mantén la base activa.
2. Si la migración de nombres se aplicó, ejecuta `php artisan migrate:rollback --step=1` con el código/configuración nuevos. Su `down()` restaura las 15 tablas y sus secuencias a los nombres anteriores; el historial sigue llamándose `migraciones`.
3. Traslada el historial y su secuencia:

   ```powershell
   docker compose run --rm --no-deps app php artisan migrations:transition-spanish --reverse --simulate
   docker compose run --rm --no-deps app php artisan migrations:transition-spanish --reverse
   ```

4. Despliega la versión anterior, cuya configuración espera `migrations`, y verifica tablas, secuencias, lotes y datos.

La comprobación de arranque del código nuevo fallará si se inicia tras revertir el historial, evitando que se cree un historial español vacío. No uses `migrate:fresh`, `migrate:refresh`, `TRUNCATE`, `DROP DATABASE`, `setval` ni eliminación de volúmenes para esta operación.

## Pruebas

SQLite sirve para pruebas funcionales rápidas. La suite PostgreSQL está aislada, usa un servicio efímero PostgreSQL 16 sin puerto publicado y no toca la base de desarrollo:

```powershell
docker compose --profile test run --rm php-tests
docker compose --profile test run --rm php-postgres-tests
```

La prueba PostgreSQL comprueba instalación vacía, secuencias, preservación de filas/relaciones, secuencias nuevas sin colisión, reversión del renombrado y transición reversible del historial.
