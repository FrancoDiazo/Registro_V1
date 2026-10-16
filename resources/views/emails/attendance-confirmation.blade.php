<!doctype html>
<html lang="es">
<body>
    <h1>Confirmación de marcación</h1>
    <p>Tipo de marcación: <strong>{{ $eventType }}</strong></p>
    <p>Fecha y hora registrada: <strong>{{ $occurredAt }}</strong></p>
    <p>Estado: Registrada y sincronizada.</p>
    @if ($wasOffline)
        <p>Esta marca se guardó sin conexión en el dispositivo y se sincronizó después de recuperar la conexión.</p>
    @endif
</body>
</html>
