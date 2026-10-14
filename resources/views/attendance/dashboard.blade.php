@extends('layouts.app')

@section('title', 'Mis marcaciones')

@section('content')
@php($eventLabels = ['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'])
@php($incidentLabels = ['clock_drift' => 'Diferencia de reloj', 'offline_sync_delay' => 'Demora de sincronización offline', 'duplicate_device_sequence' => 'Secuencia local duplicada', 'out_of_order' => 'Evento fuera de orden', 'sequence_anomaly' => 'Secuencia de marcas anómala'])
<section class="welcome">
    <p class="eyebrow">Mi jornada</p>
    <h1>Hola, {{ $employee->user->name }}</h1>
    <p class="muted">Las marcas se guardan en este dispositivo cuando no hay conexión y se envían al recuperar internet.</p>
</section>
<section class="status-grid" aria-label="Estado de la aplicación">
    <div class="status-card"><span>Conexión</span><strong id="connection-status" role="status">Comprobando…</strong></div>
    <div class="status-card"><span>Marcas pendientes</span><strong id="pending-count" aria-live="polite">0</strong></div>
    <div class="status-card"><span>Incidencias abiertas</span><strong>{{ $incidents }}</strong></div>
    <div class="status-card"><span>Correcciones pendientes</span><strong>{{ $corrections }}</strong></div>
</section>
<section class="last-event" aria-live="polite">
    <div>
        <p class="eyebrow">Última acción registrada</p>
        <strong id="last-action" data-server-timestamp="{{ $lastEvent?->occurred_at_device->toIso8601String() ?? '' }}">{{ $lastEvent ? $eventLabels[$lastEvent->event_type] : 'Aún no hay marcas' }}</strong>
        <p id="last-action-time" class="muted">{{ $lastEvent ? $lastEvent->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') : '—' }}</p>
    </div>
    <button class="button secondary" id="sync-now" type="button">Sincronizar ahora</button>
</section>
<section class="card">
    <h2>Registrar una acción</h2>
    <p class="muted">Cada marca es un registro original inalterable. Si necesitas corregirla, solicita una revisión.</p>
    <div class="actions-grid">
        @foreach (['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'] as $type => $label)
            <button class="button mark-button" type="button" data-attendance-action="{{ $type }}">{{ $label }}</button>
        @endforeach
    </div>
    <p id="sync-message" class="notice hidden" role="status"></p>
</section>
<section class="card">
    <h2>Marcas pendientes de sincronizar</h2>
    <ul id="local-pending" class="event-list"><li class="muted">No hay marcas pendientes.</li></ul>
</section>
<section class="card">
    <h2>Incidencias para revisión</h2>
    @if ($incidentRecords->isEmpty())
        <p class="muted">No tienes incidencias abiertas.</p>
    @else
        <ul class="event-list">
            @foreach ($incidentRecords as $incident)
                <li class="event-row"><span><strong>{{ $incidentLabels[$incident->type] ?? 'Incidencia' }}{{ $incident->attendanceEvent ? ' · '.$eventLabels[$incident->attendanceEvent->event_type] : '' }}</strong><small>{{ $incident->description }}</small><small>{{ $incident->created_at->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') }}</small></span><span class="badge pending">En revisión</span></li>
            @endforeach
        </ul>
    @endif
</section>
<section class="card">
    <h2>Mis solicitudes de corrección</h2>
    @if ($correctionRequests->isEmpty())
        <p class="muted">Aún no has enviado solicitudes.</p>
    @else
        <ul class="event-list">
            @foreach ($correctionRequests as $correction)
                <li class="event-row"><span><strong>{{ $correction->attendanceEvent ? $eventLabels[$correction->attendanceEvent->event_type] : 'Marca faltante' }}</strong><small>{{ $correction->reason }}</small></span><span class="badge {{ $correction->status === 'pending' ? 'pending' : 'synced' }}">{{ $correction->status === 'pending' ? 'Pendiente' : ($correction->status === 'approved' ? 'Revisada' : 'No aprobada') }}</span></li>
            @endforeach
        </ul>
    @endif
</section>
<section class="card">
    <div class="section-heading"><h2>Marcas recientes</h2><a href="{{ route('history') }}">Ver historial</a></div>
    @if ($events->isEmpty())
        <p class="muted">Tus marcas aparecerán aquí.</p>
    @else
        <ul class="event-list">
            @foreach ($events->take(5) as $event)
                <li class="event-row">
                    <span><strong>{{ $eventLabels[$event->event_type] }}</strong><small>{{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') }}</small></span>
                    <span class="badge synced">Sincronizada</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
<section class="card correction-card">
    <h2>Solicitar una corrección</h2>
    <p class="muted">La solicitud será revisada por administración; nunca cambia la marca original.</p>
    <form method="post" action="{{ route('corrections.store') }}" class="stack">
        @csrf
        <label for="attendance_event_id">Marca relacionada (opcional)</label>
        <select id="attendance_event_id" name="attendance_event_id">
            <option value="">No hay una marca asociada</option>
            @foreach ($events as $event)
                <option value="{{ $event->id }}">{{ $eventLabels[$event->event_type] }} · {{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') }}</option>
            @endforeach
        </select>
        <label for="reason">Motivo</label>
        <textarea id="reason" name="reason" minlength="10" maxlength="2000" required></textarea>
        <label for="requested_event_type">Tipo solicitado (opcional)</label>
        <select id="requested_event_type" name="requested_event_type">
            <option value="">Sin cambio de tipo</option>
            @foreach (['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'] as $type => $label)
                <option value="{{ $type }}">{{ $label }}</option>
            @endforeach
        </select>
        <label for="requested_occurred_at">Fecha y hora propuesta (zona horaria de este dispositivo, opcional)</label>
        <input id="requested_occurred_at" name="requested_occurred_at" type="datetime-local">
        <input name="requested_timezone" type="hidden" value="America/Santiago" data-timezone-field>
        <button class="button secondary" type="submit">Enviar solicitud</button>
    </form>
</section>
<span id="account-binding" data-account-binding="{{ hash_hmac('sha256', (string) $employee->id, (string) config('app.key')) }}" hidden></span>
@endsection
