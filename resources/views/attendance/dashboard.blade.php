@extends('layouts.app')

@section('title', 'Mis marcaciones')

@section('content')
@php($eventLabels = ['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'])
@php($incidentLabels = ['clock_drift' => 'Diferencia de reloj', 'offline_sync_delay' => 'Demora de sincronización offline', 'duplicate_device_sequence' => 'Secuencia local duplicada', 'out_of_order' => 'Evento fuera de orden', 'sequence_anomaly' => 'Secuencia de marcas anómala'])
<section class="welcome">
    <p class="eyebrow">Mi jornada</p>
    <h1>Hola, {{ $employee->user->name }}</h1>
    <p class="muted">Registra lo ocurrido. Si no hay conexión, la marca queda guardada en este dispositivo.</p>
</section>
<section class="connection-strip" aria-label="Estado de conexión y sincronización">
    <div class="connection-status">
        <span class="status-indicator" aria-hidden="true"></span>
        <span class="status-label">Conexión</span>
        <strong id="connection-status" role="status">Comprobando…</strong>
    </div>
    <div class="pending-status"><span>Marcas pendientes</span><strong id="pending-count" aria-live="polite">0</strong></div>
    <div class="review-status"><span>Por revisar</span><strong>{{ $incidents + $corrections }}</strong></div>
</section>
<section class="card shift-card" aria-labelledby="last-action-heading">
    <div class="shift-card__heading">
        <div>
            <p class="eyebrow" id="last-action-heading">Última marcación recibida</p>
            <h2 id="last-action" class="shift-card__action" data-server-timestamp="{{ $lastEvent?->occurred_at_device->toIso8601String() ?? '' }}">{{ $lastEvent ? $eventLabels[$lastEvent->event_type] : 'Aún no hay marcas' }}</h2>
            <p id="last-action-time" class="muted tabular-numbers">{{ $lastEvent ? $lastEvent->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') : '—' }}</p>
        </div>
        <button class="button secondary sync-button" id="sync-now" type="button">Sincronizar ahora</button>
    </div>
</section>
<section class="card marking-card" aria-labelledby="marking-heading">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Registro</p>
            <h2 id="marking-heading">Registrar una marcación</h2>
        </div>
    </div>
    <p class="muted">Selecciona la marca que corresponda a lo ocurrido. Esta versión no sugiere automáticamente la próxima acción.</p>
    <details class="marking-picker">
        <summary class="button primary">Elegir tipo de marcación</summary>
        <div class="actions-grid">
            @foreach (['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'] as $type => $label)
                <button class="button secondary mark-button" type="button" data-attendance-action="{{ $type }}">{{ $label }}</button>
            @endforeach
        </div>
    </details>
    <p id="sync-message" class="notice hidden" role="status"></p>
</section>
<section class="summary-grid" aria-label="Información de la jornada">
    <article class="card summary-card">
        <p class="eyebrow">Resumen de hoy</p>
        <h2>Horas registradas</h2>
        <p class="muted">El resumen diario no está disponible en esta versión.</p>
    </article>
    <article class="card summary-card">
        <p class="eyebrow">Seguimiento</p>
        <h2>Revisión pendiente</h2>
        <p class="summary-count tabular-numbers">{{ $incidents }} incidencias · {{ $corrections }} solicitudes</p>
        <p class="muted">Las marcas con incidencias se conservan para revisión.</p>
    </article>
</section>
<section class="card" aria-labelledby="pending-heading">
    <div class="section-heading">
        <div><p class="eyebrow">En este dispositivo</p><h2 id="pending-heading">Pendientes de sincronizar</h2></div>
    </div>
    <ul id="local-pending" class="event-list"><li class="empty-state">No hay marcas pendientes.</li></ul>
</section>
<section class="card" id="recent-activity" aria-labelledby="activity-heading">
    <div class="section-heading">
        <div><p class="eyebrow">Actividad</p><h2 id="activity-heading">Marcas recientes</h2></div>
        <a href="{{ route('history') }}">Ver historial</a>
    </div>
    @if ($events->isEmpty())
        <p class="empty-state">Tus marcas confirmadas aparecerán aquí.</p>
    @else
        <ul class="event-list">
            @foreach ($events->take(5) as $event)
                <li class="event-row">
                    <span><strong>{{ $eventLabels[$event->event_type] }}</strong><small class="tabular-numbers">{{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') }}</small></span>
                    <a class="badge synced" href="{{ route('attendance-events.show', $event) }}">Sincronizada · Ver detalle</a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
<section class="card" aria-labelledby="incidents-heading">
    <div class="section-heading">
        <div><p class="eyebrow">Seguimiento</p><h2 id="incidents-heading">Incidencias para revisión</h2></div>
        <span class="count-pill">{{ $incidents }}</span>
    </div>
    @if ($incidentRecords->isEmpty())
        <p class="empty-state">No tienes incidencias abiertas.</p>
    @else
        <ul class="event-list">
            @foreach ($incidentRecords as $incident)
                <li class="event-row"><span><strong>{{ $incidentLabels[$incident->type] ?? 'Incidencia' }}{{ $incident->attendanceEvent ? ' · '.$eventLabels[$incident->attendanceEvent->event_type] : '' }}</strong><small>{{ $incident->description }}</small><small class="tabular-numbers">{{ $incident->created_at->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') }}</small></span><span class="badge pending">En revisión</span></li>
            @endforeach
        </ul>
    @endif
</section>
<section class="card" aria-labelledby="corrections-heading">
    <div class="section-heading"><div><p class="eyebrow">Solicitudes</p><h2 id="corrections-heading">Mis correcciones</h2></div><span class="count-pill">{{ $corrections }}</span></div>
    @if ($correctionRequests->isEmpty())
        <p class="empty-state">Aún no has enviado solicitudes.</p>
    @else
        <ul class="event-list">
            @foreach ($correctionRequests as $correction)
                <li class="event-row"><span><strong>{{ $correction->attendanceEvent ? $eventLabels[$correction->attendanceEvent->event_type] : 'Marca faltante' }}</strong><small>{{ $correction->reason }}</small></span><span class="badge {{ $correction->status === 'pending' ? 'pending' : 'synced' }}">{{ $correction->status === 'pending' ? 'Pendiente' : ($correction->status === 'approved' ? 'Revisada' : 'No aprobada') }}</span></li>
            @endforeach
        </ul>
    @endif
</section>
<section class="card correction-card" aria-labelledby="correction-form-heading">
    <p class="eyebrow">Solicitud</p>
    <h2 id="correction-form-heading">Solicitar una corrección</h2>
    <p class="muted">Administración revisará la solicitud; la marca original no se modifica.</p>
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
