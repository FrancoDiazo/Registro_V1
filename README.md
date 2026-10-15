# Asistencia PWA — MVP técnico

Aplicación Laravel para registrar eventos laborales desde un navegador móvil, con cola offline local e idempotencia al sincronizar. **Es un MVP técnico y no afirma ser un sistema oficial de asistencia ni estar autorizado para uso oficial en Chile.** Antes de sustituir el registro vigente, la empresa debe validar requisitos aplicables con RR. HH. y la Dirección del Trabajo.

## Requisitos

- Docker Desktop con Docker Compose v2 (forma recomendada de ejecutar la aplicación localmente).
- Para ejecutar sin Docker: PHP 8.2 o posterior con las extensiones de Laravel y PostgreSQL (`pdo_pgsql`), Composer 2 y PostgreSQL 14 o posterior.
- Node.js 20 o posterior y npm, únicamente para ejecutar las pruebas del cliente offline fuera de Docker.
- HTTPS para la PWA en teléfonos. `localhost` se considera un contexto seguro en navegadores modernos.

## Ejecución con Docker

El stack Compose ejecuta Laravel/PHP y PostgreSQL en contenedores, conserva la base de datos en un volumen y monta el código para facilitar cambios durante el desarrollo. No necesitas instalar PHP, Composer ni PostgreSQL en el equipo anfitrión.

```powershell
Copy-Item .env.example .env
```

Edita `.env`: asigna un valor aleatorio a `DB_PASSWORD` (solo para desarrollo local) y configura `APP_KEY`. Si todavía no tienes una clave, deja `APP_KEY` vacía, construye la imagen y genérala desde el contenedor:

```powershell
docker compose build app
docker compose run --rm --no-deps app php artisan key:generate
docker compose up --build -d
docker compose ps
```

La aplicación estará disponible en `http://localhost:8000`. El contenedor espera a que PostgreSQL esté listo y ejecuta `php artisan migrate --force` al arrancar. Para crear el primer administrador:

```powershell
docker compose exec app php artisan attendance:make-administrator
```

Los comandos interactivos de Artisan, Composer y las pruebas PHP se ejecutan dentro del contenedor de aplicación:

```powershell
docker compose exec app php artisan migrate:status
docker compose exec app composer test
docker compose --profile test run --rm node-tests
```

`docker compose down` detiene los servicios y conserva los datos de PostgreSQL. **`docker compose down --volumes` elimina el volumen de base de datos y sus datos**; úsalo solo si quieres borrar deliberadamente la base local. Esta configuración utiliza el servidor integrado de Artisan y está pensada para desarrollo local, no para exponer directamente a internet. Para producción se requiere, entre otras cosas, un servidor web/proxy con HTTPS, secretos seguros, configuración endurecida y una estrategia de despliegue/respaldos.

## Instalación local

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
```

Crea una base de datos y una cuenta PostgreSQL de aplicación. En una instalación local puede usarse `createdb asistencia`; configura en `.env` `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`. No uses cuentas reales de trabajadores para pruebas.

```powershell
php artisan migrate
php artisan attendance:make-administrator
npm install
npm test
composer test
php artisan serve
```

El comando de administración solicita nombre, correo y contraseña sin almacenarla en el repositorio. Los empleados se crean desde el panel administrativo. La contraseña inicial debe comunicarse de forma segura y cambiarse según la política de la organización.

Para probar la PWA desde otro dispositivo de la red, publica el servidor detrás de un proxy HTTPS de desarrollo o de una URL HTTPS confiable; exponer `artisan serve` por HTTP en una IP local no habilita service workers en la mayoría de los navegadores.

## Configuración y operación

- El sistema visual aplicado y sus limitaciones de recursos están descritos en [docs/sistema-visual.md](docs/sistema-visual.md).
- La aplicación y PostgreSQL trabajan con timestamps en `UTC` (`APP_TIMEZONE=UTC`); `APP_PRESENTATION_TIMEZONE=America/Santiago` define la zona horaria de presentación.
- Las marcas guardan por separado el instante capturado por el dispositivo (normalizado a UTC), el offset numérico original validado, la zona IANA declarada y la recepción UTC del servidor. El reloj del teléfono no se considera infalible.
- Evita rotar `APP_KEY` mientras haya eventos pendientes en dispositivos: la vinculación seudónima local se deriva de esa clave y tendría que renovarse después de iniciar sesión nuevamente.
- Configura `ATTENDANCE_CLOCK_DRIFT_MINUTES` para ajustar el umbral técnico de alerta. Una marca offline que llega después del umbral crea una incidencia de demora/sincronización —no una conclusión de error del reloj— porque el servidor no puede distinguir ambas causas por sí solo.
- Las sesiones usan cookies HTTP-only, SameSite Lax y cifrado de sesión. En producción usa HTTPS, `APP_DEBUG=false`, una clave `APP_KEY` segura y cookies `SESSION_SECURE_COOKIE=true`.
- No hay geolocalización, biometría ni reconocimiento facial.

## Modo offline

El usuario debe iniciar sesión y abrir la PWA en línea al menos una vez para descargar su interfaz y registrar una vinculación seudónima de cuenta. El service worker precarga solamente `offline.html`, CSS, JavaScript y el manifest. **No almacena en caché datos personales ni respuestas de API.** Los eventos pendientes se guardan en IndexedDB junto con UUID, tipo, hora local con offset, zona horaria, identificador seudónimo del dispositivo, secuencia y estado local `pending`.

Al abrir la aplicación o volver la conexión, la cola envía lotes de hasta 50 marcas al endpoint autenticado `POST /api/attendance/sync`. También hay reintentos progresivos mientras la página permanezca abierta y conectada. El navegador conserva cada marca local hasta que el servidor responde `accepted` o `already_received`; un cierre de sesión, caducidad de sesión, rechazo o error no confirma ni borra el evento. No se depende de Background Sync.

El service worker no intercepta ni almacena en caché escrituras o respuestas API. Si se borran los datos del navegador antes de confirmar la sincronización, las marcas únicamente locales pueden perderse. Una cola perteneciente a otra cuenta no se asocia al trabajador conectado: el servidor comprueba una vinculación seudónima firmada con la clave de la aplicación.

En dispositivos compartidos, cierra la sesión antes de entregarlos a otra persona. El MVP vincula cada evento a la cuenta que lo capturó, pero no implementa un PIN offline ni bloqueo de pantalla; quien pueda desbloquear el mismo navegador podría ver las marcas locales pendientes.

## Jornadas, cálculos y revisión

Las jornadas y asignaciones son configurables, tienen fechas de vigencia y permiten hora de inicio/fin; una hora de fin anterior a la de inicio puede describir un turno que cruza medianoche. El servicio de cálculo suma intervalos completos ENTRY/EXIT e interrupciones BREAK_START/BREAK_END. Solo descuenta la colación fija cuando está configurada como no pagada y no hay una marca observada de colación. Los resúmenes agrupan el turno en la fecha local de entrada y usan semana ISO, sin definir reglas laborales.

Un intervalo abierto o incoherente genera alertas; el servidor conserva eventos duplicados de secuencia, marcas fuera de orden o con anomalías para revisión. Las solicitudes de corrección se resuelven en registros y auditoría separados: nunca modifican el evento original. Los reportes atribuyen cada turno al día local de su entrada y agrupan semanas con semana ISO, solo como convención técnica; las discrepancias respecto del horario configurado son alertas informativas, no reglas de cumplimiento. Los reportes/CSV contienen eventos observados y no son un cálculo definitivo de horas ni de nómina; las reglas de jornada y horas extraordinarias requieren validación de RR. HH. El MVP no cierra períodos ni ejecuta cambios de nómina.

## Pruebas

- `composer test`: autenticación/autorización, sincronización por lotes, idempotencia, validación de eventos/offset, detección de secuencias anómalas y recepción fuera de orden, aislamiento entre trabajadores, correcciones append-only y cálculo de intervalos/colación/medianoche.
- `npm test`: conservación de la cola al perder conexión, reintento tras recuperar conectividad y borrado local solo tras confirmación individual del servidor.

Las pruebas PHP usan SQLite en memoria y son complementarias a ejecutar migraciones y validar concurrencia en PostgreSQL. No se incluyen datos personales ni credenciales reales en seeds.

## Limitaciones conocidas

- La interfaz no incluye cambio/restablecimiento de contraseña ni autenticación multifactor; la administración crea cuentas y puede desactivarlas.
- No hay modo offline utilizable antes de iniciar sesión en línea en ese dispositivo. La sincronización requiere que el trabajador vuelva a autenticarse si la sesión expiró.
- Las diferencias entre reloj local y servidor son heurísticas técnicas; no prueban la hora real de trabajo. Los eventos recibidos se conservan para revisión.
- La vista CSV lista marcas recibidas y sus incidencias; no determina cumplimiento normativo, horas extraordinarias, pagos ni cierre de períodos.
- La bandeja resume turnos completos por fecha local de entrada (semana ISO como agrupación técnica); las marcas offline que todavía no llegaron al servidor no pueden aparecer en un reporte. Todo resultado se presenta como provisional.
- La cuenta del primer administrador se provisiona interactivamente; no existe una contraseña predeterminada ni un usuario sembrado.
