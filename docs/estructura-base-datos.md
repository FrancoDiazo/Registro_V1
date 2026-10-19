# Estructura de la base de datos

La aplicación usa PostgreSQL. Este documento refleja el esquema vigente con tablas en español; las columnas mantienen sus nombres técnicos originales. Los tipos exactos y restricciones se definen en las migraciones de `database/migrations`.

## Tablas del dominio

| Tabla | Propósito | Columnas |
|---|---|---|
| `usuarios` | Cuentas de acceso, RUT único de autenticación, rol y estado. | `id`, `name`, `email`, `rut` (nullable, único entre todas las cuentas), `email_verified_at`, `password`, `role`, `is_active`, `remember_token`, `created_at`, `updated_at` |
| `trabajadores` | Perfil laboral asociado a una cuenta. | `id`, `user_id`, `employee_number` (RUT del trabajador), `status`, `created_at`, `updated_at` |
| `jornadas_laborales` | Definición configurable de jornada y colación. | `id`, `name`, `timezone`, `expected_minutes_per_week`, `break_minutes`, `break_is_unpaid`, `days_of_week`, `shift_start`, `shift_end`, `is_active`, `created_at`, `updated_at` |
| `asignaciones_jornada` | Jornada asignada a un trabajador durante un período. | `id`, `employee_id`, `work_schedule_id`, `valid_from`, `valid_until`, `created_at`, `updated_at` |
| `marcaciones_asistencia` | Eventos originales de asistencia, append-only desde la aplicación. | `id`, `event_uuid`, `employee_id`, `event_type`, `occurred_at_device`, `device_offset_minutes`, `client_timezone`, `received_at_server`, `device_id`, `device_event_seq`, `offline`, `created_at`, `updated_at` |
| `incidencias_asistencia` | Anomalías que requieren revisión. | `id`, `employee_id`, `attendance_event_id`, `type`, `description`, `status`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at` |
| `solicitudes_correccion` | Solicitudes y resoluciones separadas de la marca original. | `id`, `employee_id`, `attendance_event_id`, `requested_by`, `reason`, `requested_event_type`, `requested_occurred_at`, `requested_timezone`, `status`, `resolved_by`, `resolved_at`, `resolution_note`, `created_at`, `updated_at` |
| `registros_auditoria` | Registro de acciones administrativas relevantes. | `id`, `user_id`, `action`, `subject_type`, `subject_id`, `metadata`, `created_at` |
| `bandeja_salida_confirmaciones_asistencia` | Estado y reintentos de una confirmación de correo por marca. | `id`, `attendance_event_id`, `status`, `attempt_count`, `last_attempt_at`, `sent_at`, `last_error`, `created_at`, `updated_at` |

## Relaciones principales

```text
usuarios 1 ── 0..1 trabajadores
trabajadores 1 ── N asignaciones_jornada
jornadas_laborales 1 ── N asignaciones_jornada
trabajadores 1 ── N marcaciones_asistencia
trabajadores 1 ── N incidencias_asistencia
marcaciones_asistencia 1 ── N incidencias_asistencia (opcional por incidencia)
trabajadores 1 ── N solicitudes_correccion
marcaciones_asistencia 1 ── N solicitudes_correccion (opcional por solicitud)
marcaciones_asistencia 1 ── 0..1 bandeja_salida_confirmaciones_asistencia
usuarios 1 ── N registros_auditoria (opcional por registro)
usuarios 1 ── N solicitudes_correccion (solicitante/resolutor)
usuarios 1 ── N incidencias_asistencia (revisor)
```

Las claves foráneas usan `user_id`, `employee_id`, `work_schedule_id`, `attendance_event_id`, `requested_by`, `resolved_by` y `reviewed_by`. La bandeja impone unicidad en `attendance_event_id`. `event_uuid` es único para permitir sincronización idempotente. `usuarios.rut` es el identificador autenticable único para trabajadores y administradores. `trabajadores.employee_number` conserva el RUT laboral del trabajador; la migración de backfill valida que coincida, lo normaliza y lo copia a `usuarios.rut`. Una discrepancia o colisión detiene la migración sin completar cambios. El comando de solo lectura `attendance:validate-user-ruts` permite revisar esos datos antes del despliegue. Las marcas originales no se editan ni eliminan desde la aplicación; las solicitudes y revisiones se guardan por separado.

## Tablas internas de Laravel

| Tabla | Propósito | Columnas |
|---|---|---|
| `tokens_restablecimiento_clave` | Tokens temporales de restablecimiento de contraseña. | `email`, `token`, `created_at` |
| `sesiones` | Sesiones del navegador almacenadas en PostgreSQL. | `id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity` |
| `cache_aplicacion` | Valores del caché con driver database. | `key`, `value`, `expiration` |
| `bloqueos_cache` | Bloqueos atómicos del caché con driver database. | `key`, `owner`, `expiration` |
| `trabajos_cola` | Trabajos pendientes de la cola database. | `id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at` |
| `trabajos_fallidos` | Trabajos cuyo procesamiento agotó los intentos. | `id`, `uuid`, `connection`, `queue`, `payload`, `exception`, `failed_at` |
| `migraciones` | Historial de migraciones ya ejecutadas. | `id`, `migration`, `batch` |

La configuración de `job_batches` de Laravel no implica que exista esa tabla: no se ha creado ni se usa batching en este proyecto. `cache_aplicacion`, `bloqueos_cache`, `sesiones` y `tokens_restablecimiento_clave` tienen claves propias, no secuencias numéricas.

## Claves y secuencias

Las tablas de dominio y de cola con columna `id` autoincremental tienen estas secuencias PostgreSQL:

```text
bandeja_salida_confirmaciones_asistencia_id_seq
marcaciones_asistencia_id_seq
incidencias_asistencia_id_seq
registros_auditoria_id_seq
solicitudes_correccion_id_seq
trabajadores_id_seq
trabajos_fallidos_id_seq
trabajos_cola_id_seq
migraciones_id_seq
asignaciones_jornada_id_seq
usuarios_id_seq
jornadas_laborales_id_seq
```

No hay secuencia para `sesiones`, `cache_aplicacion`, `bloqueos_cache` ni `tokens_restablecimiento_clave`. La migración de traducción renombró las secuencias existentes sin reiniciar sus valores. Para el mapa de nombres anterior y el procedimiento de actualización/reversión, consulta [renombrado-esquema.md](./renombrado-esquema.md).

## Ver el esquema desde Docker

Desde PowerShell, en la raíz del repositorio:

```powershell
docker compose exec db sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
```

En `psql`, usa `\dt` para listar las tablas, `\d trabajadores` para ver una tabla y `\q` para salir. No consultes ni compartas las columnas `password`, `remember_token`, `payload` o los tokens.
