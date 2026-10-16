# Correo, confirmaciones y recuperación de contraseña

## Alcance y requisitos

La aplicación usa Laravel 12.70, PHP 8.3, el broker de contraseñas de Laravel y su cola de base de datos. El proyecto no define un proveedor de correo institucional: el equipo de TI debe entregar los parámetros SMTP autorizados antes de enviar mensajes fuera del entorno local.

No se debe poner una contraseña real, token SMTP ni secreto en el repositorio, imágenes Docker, capturas, tickets o mensajes de error. Las credenciales reales van solo en `.env` local, ignorado por Git, o en el gestor de secretos aprobado para despliegue. Mantén `APP_DEBUG=false` fuera del entorno local y protege los logs.

## Variables de entorno

`.env.example` incluye valores ilustrativos no secretos. En `.env` local (o en el gestor de secretos de despliegue) configura:

| Variable | Significado |
|---|---|
| `MAIL_MAILER` | `log` para desarrollo local o `smtp` para el proveedor autorizado. |
| `MAIL_HOST` | Host SMTP entregado por TI. |
| `MAIL_PORT` | Puerto SMTP entregado por TI, comúnmente 587 para STARTTLS o 465 para TLS implícito; confirmar con TI. |
| `MAIL_SCHEME` | `smtp` para STARTTLS/negociación TLS del servidor, o `smtps` para TLS implícito; TI debe confirmar el valor. |
| `MAIL_USERNAME` | Usuario SMTP, si el proveedor lo exige. |
| `MAIL_PASSWORD` | Secreto SMTP; nunca debe copiarse a documentación ni al repositorio. |
| `MAIL_FROM_ADDRESS` | Dirección remitente institucional autorizada por TI. `hello@example.com` en el ejemplo es un marcador y debe reemplazarse. |
| `MAIL_FROM_NAME` | Nombre autorizado que verá la persona destinataria. El ejemplo usa el nombre de la aplicación. |
| `MAIL_TIMEOUT` | Timeout SMTP opcional, en segundos. |
| `MAIL_EHLO_DOMAIN` | Dominio EHLO opcional si TI exige configurarlo. |
| `QUEUE_CONNECTION` | `database` para persistir y procesar las confirmaciones. |
| `DB_QUEUE` | Nombre de cola; por defecto `default`. |
| `DB_QUEUE_RETRY_AFTER` | Tiempo en segundos antes de recuperar un trabajo reservado; debe superar el timeout máximo del worker. |

No inventes el host, usuario, remitente ni política de TLS de la organización. Solicita a TI el host y puerto, modo de cifrado, usuario/autenticación, restricciones de red o allowlist, remitente institucional permitido, límites de envío y cualquier dominio EHLO requerido.

Al cambiar `.env`, reinicia la aplicación y el worker para que Laravel recargue la configuración:

```powershell
docker compose restart app queue
```

En un despliegue con configuración cacheada, ejecuta el procedimiento de despliegue aprobado para regenerar la configuración y reiniciar todos los workers después de cambiar secretos SMTP. No imprimas `.env` para comprobarlo.

## Probar localmente sin SMTP real

El valor inicial es `MAIL_MAILER=log`: la aplicación no se conecta a un proveedor externo. Los correos se escriben en `storage/logs/laravel.log`. Los enlaces de restablecimiento contienen tokens temporales; ese log debe mantenerse privado y eliminarse de forma segura después de las pruebas. No uses el mailer `log` en producción.

Para recibir mensajes en una herramienta local de captura de correo, configura el host/puerto de un servidor SMTP de desarrollo aprobado y usa `MAIL_MAILER=smtp`; no reutilices credenciales ni destinatarios reales. Las pruebas automatizadas emplean `Notification::fake()`, `Mail::fake()` y `Queue::fake()` y no envían correo real.

## Cola de confirmaciones

Compose levanta el servicio `queue` y procesa trabajos de la cola `database`. Las migraciones crean `jobs`, `failed_jobs` y la tabla `attendance_confirmation_outbox`; no borres la outbox ni los trabajos pendientes manualmente.

```powershell
docker compose ps
docker compose logs --tail 80 queue
docker compose restart queue
docker compose exec app php artisan attendance:retry-confirmations
```

El worker intenta cada confirmación hasta tres veces con pausas. Si no puede enviarla, conserva la marca original y deja la confirmación con estado `failed` y un mensaje de error genérico, sin registrar la excepción del proveedor. `attendance:retry-confirmations` vuelve a poner en cola confirmaciones pendientes/fallidas cuyo trabajador aún tenga correo verificado; no recrea marcas. Tras cambiar ajustes SMTP, reinicia `queue` antes del reintento.

Cada marca aceptada con dirección verificada crea una fila única de outbox. Las repeticiones del mismo `event_uuid` no crean otra marca ni otra fila. El trabajo usa la clave de outbox para evitar trabajos concurrentes duplicados y no vuelve a enviar una fila marcada `sent`. En caso de caída abrupta del proceso tras una aceptación ambigua del servidor SMTP, ningún sistema SMTP ofrece por sí solo entrega exactamente-una-vez: revisa los logs autorizados del proveedor antes de ordenar un reintento manual.

## Recuperación de contraseña

1. En `/login`, selecciona **¿Olvidaste tu contraseña?** e ingresa el correo asociado a la cuenta.
2. La respuesta es genérica tanto para una cuenta inexistente como para un correo no verificado; la ruta tiene límite de solicitudes y el broker de Laravel aplica su propio throttle.
3. Si el correo existe y tiene `email_verified_at`, Laravel envía un enlace con token guardado como hash, expiración configurada de 60 minutos y throttle de 60 segundos. El token se consume al restablecer la contraseña.
4. Abre el enlace y define una nueva contraseña de al menos 12 caracteres, repitiéndola para confirmar.
5. Un enlace inválido, expirado o reutilizado se rechaza; solicita uno nuevo.

### Cuentas sin correo verificado

El formulario administrativo actual no verifica automáticamente la propiedad del correo. Si una persona no recibe el enlace, no existe un correo asociado o el correo aún no fue verificado, un administrador autorizado debe confirmar la identidad y la propiedad del buzón usando el procedimiento corporativo aprobado. El responsable de identidad/datos debe registrar o verificar el correo y actualizar `email_verified_at` mediante el proceso de cambio de datos controlado y auditable aprobado por TI; después la persona puede volver a solicitar el enlace. No marques `email_verified_at` solo porque alguien conoce o escribe una dirección: este MVP no implementa un flujo de verificación por correo ni ofrece una acción de verificación en el panel.

## Confirmación de marcaciones

- El servidor crea la marca y la fila única de outbox dentro de la transacción que acepta un evento nuevo. La cola solo envía después del commit.
- La confirmación incluye tipo de marca, hora del dispositivo presentada en `APP_PRESENTATION_TIMEZONE`, y estado registrado/sincronizado. Para marcas offline indica que se capturaron sin conexión y llegaron tras recuperar la conectividad.
- Solo se generan confirmaciones cuando el trabajador tiene una dirección de correo verificada. El destinatario es la dirección del usuario asociado al empleado; el cliente no puede elegir el destinatario.
- La confirmación de correo es accesoria: un error de envío no revierte ni elimina la marcación. La outbox mantiene el estado para reintento.
- La garantía idempotente de la aplicación impide crear más de una fila de confirmación por marca y evita reenvíos cuando la fila ya figura `sent`. La entrega SMTP de extremo a extremo no puede prometerse exactamente-una-vez ante una caída entre la aceptación del proveedor y el registro local; verifica el estado del proveedor antes de reintentar en ese caso.

## Pruebas

Ejecuta el subconjunto de recuperación y marcaciones en el contenedor:

```powershell
docker compose --profile test run --rm php-tests php vendor/bin/phpunit tests/Feature/PasswordResetTest.php --no-coverage
docker compose --profile test run --rm php-tests php vendor/bin/phpunit tests/Feature/AttendanceSyncTest.php --no-coverage
```

No configures SMTP real para ejecutar pruebas.
