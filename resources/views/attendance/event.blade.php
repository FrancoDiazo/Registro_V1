@extends('layouts.app')

@section('title', 'Detalle de marcación')

@section('content')
@php($eventLabels = ['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'])
<section class="welcome" aria-labelledby="event-heading">
    <p class="eyebrow">Marca original inmutable</p>
    <h1 id="event-heading">{{ $eventLabels[$event->event_type] }}</h1>
    <p class="muted">Este registro original no se puede modificar ni eliminar.</p>
</section>
<section class="card" aria-label="Información de la marcación">
    <dl class="detail-list">
        <dt>Hora capturada en el dispositivo</dt><dd>{{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s T') }}</dd>
        <dt>Offset capturado</dt><dd>{{ $event->device_offset_minutes }} minutos respecto de UTC</dd>
        <dt>Zona horaria declarada</dt><dd>{{ $event->client_timezone }}</dd>
        <dt>Hora de recepción del servidor</dt><dd>{{ $event->received_at_server->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s T') }}</dd>
        <dt>Capturada sin conexión</dt><dd>{{ $event->offline ? 'Sí' : 'No' }}</dd>
        <dt>UUID del evento</dt><dd class="uuid">{{ $event->event_uuid }}</dd>
    </dl>
    <h2 id="request-review-heading">Solicitar revisión</h2>
    <form method="post" action="{{ route('corrections.store') }}" class="stack">
        @csrf
        <input type="hidden" name="attendance_event_id" value="{{ $event->id }}">
        <input name="requested_timezone" type="hidden" value="America/Santiago" data-timezone-field>
        <label for="reason">Motivo</label>
        <textarea id="reason" name="reason" minlength="10" maxlength="2000" required></textarea>
        <button class="button secondary" type="submit">Enviar solicitud de corrección</button>
    </form>
</section>
@endsection
