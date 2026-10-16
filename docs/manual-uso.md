# Manual de uso — Asistencia PWA

## 1. Propósito y alcance

Esta aplicación es un MVP técnico para registrar eventos de asistencia desde un navegador móvil o de escritorio. No afirma ser un sistema oficial de asistencia ni estar autorizado para uso oficial en Chile. Antes de reemplazar el registro vigente, la empresa debe validar los requisitos aplicables con RR. HH. y la Dirección del Trabajo.

El sistema conserva las marcas originales. Una solicitud de corrección se registra y revisa por separado; no modifica ni elimina la marca original.

## 2. Requisitos y puesta en marcha local

La forma recomendada de ejecutar la aplicación es con Docker Desktop y Docker Compose v2. No es necesario instalar PHP, Composer ni PostgreSQL en Windows.

1. Abre PowerShell y entra a la carpeta del proyecto:

   ```powershell
   Set-Location C:\Proyectos\asistencia-pwa
   ```

2. Si aún no existe `.env`, créalo desde el ejemplo:

   ```powershell
   Copy-Item .env.example .env
   ```

   Configura `DB_PASSWORD` con una contraseña local de desarrollo y `APP_KEY` siguiendo las instrucciones del `README.md`. No compartas el archivo `.env`.

3. Inicia los servicios:

   ```powershell
   docker compose up --build -d
   docker compose ps
   ```

   Espera a que `app` figure como activo y `db` como saludable. Compose ejecuta las migraciones al iniciar la aplicación.

4. Abre [http://localhost:8000](http://localhost:8000). La raíz redirige a login si no hay sesión o al panel correspondiente si ya iniciaste sesión.

Para detener los contenedores sin borrar los datos locales:

```powershell
docker compose down
```

No uses `docker compose down --volumes` a menos que quieras borrar deliberadamente la base de datos local.

## 3. Primera cuenta administradora

No hay usuarios, contraseñas predeterminadas ni datos de trabajadores precargados. Desde PowerShell, en la carpeta del proyecto y con los servicios iniciados, ejecuta:

```powershell
docker compose exec app php artisan attendance:make-administrator
```

El comando solicita nombre, correo y contraseña de al menos 12 caracteres. La contraseña no se imprime en pantalla. Inicia sesión en `http://localhost:8000/login` con ese correo y contraseña.

La primera cuenta administradora también puede crearse antes de levantar el stack, usando `docker compose run --rm --no-deps app php artisan attendance:make-administrator`, siempre que la base de datos esté disponible y las migraciones ya se hayan ejecutado.

## 4. Inicio y cierre de sesión

1. En la pantalla **Iniciar sesión**, escribe el correo y la contraseña asignados.
2. **Mantener sesión** es opcional. En un dispositivo compartido, no lo selecciones y cierra sesión al terminar.
3. El administrador llega al panel de administración; el trabajador, a su pantalla de marcación.
4. Para cerrar sesión, abre **Mi cuenta** y pulsa **Cerrar sesión**.

El MVP permite recuperar una contraseña desde **¿Olvidaste tu contraseña?** si la cuenta tiene un correo verificado asociado. No ofrece cambio de contraseña desde una sesión activa ni autenticación multifactor. Si la cuenta no tiene correo verificado, administración debe resolverlo según el procedimiento corporativo documentado en [correo-y-recuperacion.md](./correo-y-recuperacion.md).

## 5. Uso del trabajador

### Registrar una marca

En **Marcación**:

1. Revisa el estado de conexión, las marcas pendientes y el contador **Por revisar**.
2. Pulsa **Elegir tipo de marcación**.
3. Selecciona lo que ocurrió: **Entrada**, **Inicio de colación**, **Fin de colación** o **Salida**.
4. La aplicación registra la hora del dispositivo, su zona horaria y el estado de conexión. La hora del teléfono no se considera infalible.
5. En línea, la marca se envía al servidor. La marca queda confirmada cuando el servidor la acepta o confirma que ya la había recibido.

La aplicación no elige automáticamente la próxima acción ni bloquea una marca por una secuencia de jornada. Si faltan marcas, hay horas anómalas o aparecen eventos fuera de orden, se conservan y pueden generar una incidencia para revisión.

### Estado de una marca

- **Pendiente de sincronizar**: existe solo en la cola local del navegador y todavía no tiene confirmación del servidor.
- **Sincronizada**: el servidor confirmó la recepción. La pantalla muestra las marcas locales pendientes y las recibidas en actividad/historial.
- **Por revisar / incidencia**: el sistema conservó el evento, pero detectó algo que requiere revisión, por ejemplo diferencia de reloj, demora de sincronización offline, secuencia local repetida o marcas fuera de orden.

El contador de pendientes corresponde a la cola del navegador y la cuenta actualmente vinculada. La actividad reciente y el historial muestran eventos recibidos por el servidor; no reemplazan la lista de pendientes locales.

### Usar la aplicación sin conexión

El modo offline requiere preparar previamente ese dispositivo:

1. Con internet, abre la aplicación e inicia sesión con la cuenta del trabajador.
2. Mantén abierta la aplicación hasta que cargue. Así el navegador instala el service worker y guarda los recursos básicos, y el dispositivo queda vinculado a esa cuenta.
3. Cuando el navegador indique **Sin conexión**, selecciona el tipo de marca. La pantalla confirma que quedó guardada en el dispositivo y pendiente.
4. Al volver la conexión, deja la aplicación abierta o vuelve a abrirla con la misma cuenta. El sistema reintenta automáticamente; también puedes usar **Sincronizar ahora**.
5. Espera la confirmación de sincronización. Una marca con error, una sesión caducada o un rechazo permanece en la cola; no la vuelvas a ingresar solo porque sigue pendiente. Revisa el mensaje y vuelve a autenticarte cuando corresponda.

La sincronización se realiza en lotes de hasta 50 marcas. No depende de Background Sync; el navegador debe abrir la aplicación y disponer de conexión para intentar el envío.

**Importante:** no borres los datos del sitio/navegador mientras existan marcas pendientes. Si se eliminan los datos locales antes de que el servidor confirme la recepción, esas marcas pueden perderse. En un dispositivo compartido, cierra sesión antes de entregarlo a otra persona. Las marcas pendientes de otra cuenta no se envían con la sesión actual.

Para instalarla en la pantalla de inicio del teléfono, abre la aplicación desde una dirección HTTPS confiable y usa la opción de instalación/agregado que ofrece el navegador. `localhost` es válido para desarrollo en el mismo equipo; para acceder desde otro dispositivo se necesita HTTPS. El servicio offline no está disponible antes de iniciar sesión en línea al menos una vez en ese dispositivo.

### Historial personal y correcciones

1. Abre **Historial** desde la navegación inferior.
2. Opcionalmente filtra por fechas **Desde** y **Hasta**; los resultados se presentan en hora local `America/Santiago`.
3. Abre **Ver detalle** para revisar hora capturada, offset, zona declarada, hora de recepción del servidor, condición offline e identificador del evento.
4. Para reportar una marca faltante o incorrecta, usa **Solicitar una corrección**. Indica un motivo de al menos 10 caracteres; puedes asociar una marca, sugerir otro tipo y/o proponer fecha y hora.
5. La solicitud queda pendiente para administración. Su envío no cambia el evento original ni crea por sí mismo una marca corregida.

### Recuperar contraseña y recibir confirmaciones

- En el inicio de sesión selecciona **¿Olvidaste tu contraseña?** e ingresa el correo asociado. La pantalla siempre muestra el mismo mensaje para no revelar si la cuenta existe. Si el correo está verificado, se envía un enlace temporal de un solo uso; define una contraseña nueva de al menos 12 caracteres.
- Tras aceptar una marcación, la aplicación programa una confirmación por correo. Las marcas offline generan ese correo solo después de ser aceptadas por el servidor. El correo indica tipo, hora local, estado sincronizado y, cuando corresponde, que la captura se hizo sin conexión.
- Si el correo no llega, revisa con administración que el correo asociado esté verificado. No reingreses la marca: el envío puede reintentarse desde la cola sin crear un evento adicional. La administración técnica debe consultar la guía [correo y recuperación](./correo-y-recuperacion.md).

## 6. Uso de administración

El panel es accesible solo a cuentas administradoras activas. En escritorio ofrece navegación lateral; en celular organiza los mismos módulos verticalmente.

### Trabajadores

- Crea un trabajador con nombre, correo, identificador laboral único y contraseña inicial de al menos 12 caracteres.
- Comparte la contraseña inicial por un canal seguro.
- Consulta el listado y cambia el estado entre activo e inactivo. Al desactivarlo, también se desactiva la cuenta de acceso.
- No existe edición general del perfil, restablecimiento de contraseña ni gestión de cuentas administradoras desde este panel.

### Jornadas y asignaciones

1. En **Jornadas y vigencias**, crea una jornada con nombre.
2. Configura, según corresponda, minutos semanales pactados, minutos de colación, si la colación fija es no pagada, horario de inicio/fin y días de la semana.
3. Asigna la jornada a un trabajador con fecha **Vigente desde** y, opcionalmente, **Vigente hasta**.
4. El sistema rechaza rangos de fechas superpuestos para el mismo trabajador.

La hora de fin anterior a la hora de inicio puede representar un turno que cruza medianoche. El panel permite crear y asignar jornadas, pero no editar ni eliminar jornadas o asignaciones ya creadas. Valida las configuraciones con RR. HH.; no hay una política laboral predeterminada en el sistema.

### Marcas, informes y CSV

- En **Reportes y registros**, filtra por trabajador y rango de fechas. De forma predeterminada se usa un período reciente.
- Consulta las marcas recibidas, la hora informada por el dispositivo, la recepción del servidor y si se capturaron offline.
- Revisa los resúmenes diarios y semanales: agrupan eventos completos según la fecha local de entrada y semana ISO.
- Usa **Exportar marcas CSV** para descargar los eventos observados que coinciden con los filtros.

Los informes son **provisionales**. Las marcas offline todavía no recibidas no aparecen. Los intervalos incompletos, incidencias abiertas o solicitudes pendientes requieren revisión; el CSV lista marcas, no es una liquidación ni un cálculo definitivo. El MVP no cierra períodos, determina horas extraordinarias según legislación ni actualiza nómina.

### Incidencias y solicitudes

- En **Incidencias abiertas**, revisa descripción, trabajador y evento relacionado. **Marcar revisada** registra quién y cuándo revisó la incidencia; no elimina ni cambia el evento.
- En **Solicitudes de corrección**, revisa motivo, marca asociada y propuesta. Registra una nota y elige **Aprobar solicitud** o **Rechazar**. La decisión queda separada y auditada; incluso al aprobar, la marca original se conserva sin alteraciones.

## 7. Funcionalidades implementadas

| Área | Disponible |
|---|---|
| Acceso | Inicio y cierre de sesión; roles de trabajador y administrador; altas de trabajadores; activación y desactivación. |
| Marcación | Entrada, inicio y fin de colación, y salida; registro de hora del dispositivo y recepción del servidor por separado. |
| Offline/PWA | Recursos básicos preparados por service worker; cola IndexedDB; indicador de conectividad y pendientes; reintentos al abrir y al recuperar conectividad; sincronización manual; lotes idempotentes y confirmación individual. |
| Revisión | Conservación de eventos anómalos; incidencias por diferencias de reloj, demora offline, secuencia duplicada, orden incorrecto y anomalías; solicitudes de corrección auditables. |
| Trabajador | Panel personal, actividad reciente, detalle de una marca, historial por fechas, contador de pendientes e incidencias y solicitudes propias. |
| Administración | Gestión básica de trabajadores, creación de jornadas, asignaciones con vigencia, consulta de marcas y reportes, bandeja de incidencias y solicitudes, exportación de marcas CSV. |
| Cálculos técnicos | Intervalos observados, colación configurada, turnos que cruzan medianoche, agrupación diaria/semanal provisional y alertas de discrepancias de horario. |
| Seguridad y privacidad | Acceso por rol, validación del servidor, protección CSRF, limitación de solicitudes sensibles, consultas personales restringidas, eventos originales no editables desde la aplicación y sin GPS ni biometría. |
| Interfaz | Blade responsive en español, navegación móvil, soporte de teclado y estados explícitos de conectividad y sincronización. |

## 8. Funciones no disponibles y límites

- No hay recuperación/cambio de contraseña en pantalla, MFA, restablecimiento por correo ni administración de otros administradores.
- No se sugieren automáticamente las marcas ni se valida/bloquea en pantalla la secuencia laboral.
- No hay resumen diario de horas para el trabajador.
- No se editan ni eliminan marcas originales, trabajadores, jornadas o asignaciones desde el panel.
- No hay autenticación offline inicial, PIN offline ni bloqueo de la cola local por PIN. La sesión debe prepararse en línea; una sesión expirada requiere autenticarse otra vez para sincronizar.
- No se garantiza persistencia si el usuario borra el almacenamiento del navegador o si el dispositivo pierde sus datos antes de confirmar el envío.
- Los cálculos e informes no certifican cumplimiento, remuneraciones, horas extraordinarias, cierres de período ni cambios de nómina.
- No incluye geolocalización, biometría ni reconocimiento facial.
- El despliegue Docker descrito es para desarrollo local. No expongas directamente `artisan serve` a internet como si fuera un despliegue productivo.

## 9. Resolución rápida de problemas

### `no configuration file provided`

Compose no encontró `compose.yaml` porque PowerShell está en otra carpeta:

```powershell
Set-Location C:\Proyectos\asistencia-pwa
docker compose ps
```

### El contenedor `app` no está corriendo

Desde la carpeta del proyecto:

```powershell
docker compose up -d
docker compose ps
docker compose logs --tail 80 app
```

Abre `http://localhost:8000/login` cuando `app` esté activo y la base `db` figure saludable.

### No puedo entrar o no existe una cuenta administradora

- Si todavía no creaste un administrador, usa el comando de la sección 3.
- Comprueba que usas el correo y contraseña asignados; no hay credenciales por defecto.
- La contraseña no se puede recuperar desde la interfaz.

### Las marcas siguen pendientes

- Comprueba que el navegador vuelve a tener conexión con el servidor.
- Abre la aplicación e inicia sesión con la misma cuenta que capturó esas marcas.
- Usa **Sincronizar ahora** y conserva la página abierta hasta ver confirmación.
- Si el servidor rechaza un evento o muestra una incidencia, guarda el mensaje y solicita revisión. No borres los datos del sitio ni vuelvas a crear la marca como sustitución automática.

## 10. Ayuda técnica y operación

Comandos útiles desde `C:\Proyectos\asistencia-pwa`:

```powershell
docker compose ps
docker compose logs --tail 80 app
docker compose exec app php artisan migrate:status
docker compose exec app php artisan attendance:make-administrator
docker compose down
```

La configuración local guarda PostgreSQL en el volumen `postgres_data`. Protege `.env`, respalda la base de datos y no uses información real de trabajadores para pruebas. Para producción hacen falta evaluación legal y de privacidad, HTTPS, endurecimiento, gestión segura de secretos, respaldos y una estrategia de despliegue que no forman parte de este MVP.
