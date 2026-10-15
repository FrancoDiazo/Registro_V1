@extends('layouts.app')

@section('title', 'Administración')

@section('content')
@php($eventLabels = ['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'])
@php($incidentLabels = ['clock_drift' => 'Diferencia de reloj', 'offline_sync_delay' => 'Demora de sincronización offline', 'duplicate_device_sequence' => 'Secuencia local duplicada', 'out_of_order' => 'Evento fuera de orden', 'sequence_anomaly' => 'Secuencia de marcas anómala'])
<section class="welcome">
    <p class="eyebrow">Administración</p>
    <h1>Panel de asistencia</h1>
    <p class="muted">Los reportes muestran marcas recibidas. Revisa las incidencias y solicitudes antes de cualquier interpretación.</p>
</section>
<section class="card admin-section" id="employees" aria-labelledby="employees-heading">
    <div class="section-heading"><div><p class="eyebrow">Personas</p><h2 id="employees-heading">Trabajadores</h2></div></div>
    <form method="post" action="{{ route('admin.employees.store') }}" class="form-grid">
        @csrf
        <label>Nombre<input name="name" maxlength="255" required></label>
        <label>Correo<input name="email" type="email" maxlength="255" required></label>
        <label>Identificador laboral<input name="employee_number" maxlength="64" required></label>
        <label>Contraseña inicial (mínimo 12 caracteres)<input name="password" type="password" minlength="12" required></label>
        <button class="button primary" type="submit">Crear trabajador</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Trabajador</th><th>Identificador</th><th>Estado</th></tr></thead><tbody>
        @forelse ($employees as $employee)
            <tr><td data-label="Trabajador">{{ $employee->user->name }}<small>{{ $employee->user->email }}</small></td><td data-label="Identificador">{{ $employee->employee_number }}</td><td data-label="Estado"><span>{{ $employee->status === 'active' ? 'Activo' : 'Inactivo' }}</span><form method="post" action="{{ route('admin.employees.status', $employee) }}">@csrf<input type="hidden" name="status" value="{{ $employee->status === 'active' ? 'inactive' : 'active' }}"><button class="button secondary" type="submit">{{ $employee->status === 'active' ? 'Desactivar' : 'Activar' }}</button></form></td></tr>
        @empty
            <tr><td colspan="3">No hay trabajadores.</td></tr>
        @endforelse
    </tbody></table></div>
</section>
<section class="card admin-section" id="schedules" aria-labelledby="schedules-heading">
    <div class="section-heading"><div><p class="eyebrow">Planificación</p><h2 id="schedules-heading">Jornadas y vigencias</h2></div></div>
    <form method="post" action="{{ route('admin.schedules.store') }}" class="form-grid">
        @csrf
        <label>Nombre de jornada<input name="name" maxlength="255" required></label>
        <label>Minutos semanales pactados (opcional)<input name="expected_minutes_per_week" type="number" min="0" max="10080"></label>
        <label>Colación configurada (minutos)<input name="break_minutes" type="number" min="0" max="1440" value="0" required></label>
        <label>Inicio de turno<input name="shift_start" type="time"></label>
        <label>Fin de turno<input name="shift_end" type="time"></label>
        <fieldset class="days-picker"><legend>Días de la semana</legend>@foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'] as $day => $label)<label class="check-row"><input name="days_of_week[]" type="checkbox" value="{{ $day }}">{{ $label }}</label>@endforeach</fieldset>
        <label class="check-row"><input type="hidden" name="break_is_unpaid" value="0"><input name="break_is_unpaid" type="checkbox" value="1"> La colación configurada es no pagada</label>
        <button class="button primary" type="submit">Crear jornada</button>
    </form>
    <form method="post" action="{{ route('admin.assignments.store') }}" class="form-grid assignment-form">
        @csrf
        <label>Trabajador<select name="employee_id" required>@foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->user->name }} · {{ $employee->employee_number }}</option>@endforeach</select></label>
        <label>Jornada<select name="work_schedule_id" required>@foreach ($schedules as $schedule)<option value="{{ $schedule->id }}">{{ $schedule->name }}</option>@endforeach</select></label>
        <label>Vigente desde<input name="valid_from" type="date" required></label>
        <label>Vigente hasta (opcional)<input name="valid_until" type="date"></label>
        <button class="button secondary" type="submit">Asignar jornada</button>
    </form>
    <ul class="event-list">@forelse ($assignments as $assignment)<li class="event-row"><span><strong>{{ $assignment->employee->user->name }}</strong><small>{{ $assignment->workSchedule->name }} · {{ $assignment->valid_from->format('d/m/Y') }}–{{ $assignment->valid_until?->format('d/m/Y') ?? 'vigente' }}</small></span></li>@empty<li>No hay asignaciones.</li>@endforelse</ul>
</section>
<section class="card admin-section" id="reports" aria-labelledby="reports-heading">
    <div class="section-heading"><div><p class="eyebrow">Consulta</p><h2 id="reports-heading">Reportes y registros</h2></div><a class="button secondary export-link" href="{{ route('admin.reports.export', array_filter(['employee_id' => request('employee_id'), 'from' => $reportFrom, 'to' => $reportTo])) }}">Exportar marcas CSV</a></div>
    <form method="get" class="filter-row">
        <label for="employee-filter">Trabajador</label><select id="employee-filter" name="employee_id"><option value="">Todos</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->user->name }}</option>@endforeach</select>
        <label for="admin-from">Desde</label><input id="admin-from" name="from" type="date" value="{{ request('from') }}">
        <label for="admin-to">Hasta</label><input id="admin-to" name="to" type="date" value="{{ request('to') }}">
        <button class="button secondary" type="submit">Consultar</button>
    </form>
    <p class="muted">Período del informe: {{ $reportFrom }} a {{ $reportTo }}. La jornada se agrupa por la fecha local de su entrada; la semana ISO es una agrupación técnica. El informe siempre es provisional y no sustituye la revisión de RR. HH.</p>
    <h3>Resumen diario</h3>
    <div class="table-wrap"><table><thead><tr><th>Trabajador</th><th>Fecha</th><th>Jornadas completas</th><th>Tiempo observado</th><th>Estado</th></tr></thead><tbody>
        @forelse ($dailyReports as $day)
            <tr><td data-label="Trabajador">{{ $day['employee_name'] }}</td><td data-label="Fecha" class="tabular-numbers">{{ $day['date'] }}</td><td data-label="Jornadas completas" class="tabular-numbers">{{ $day['shift_count'] }}</td><td data-label="Tiempo observado">{{ $day['review_required'] ? 'Revisión requerida' : intdiv($day['worked_minutes'], 60).' h '.($day['worked_minutes'] % 60).' min' }}</td><td data-label="Estado">{{ $day['review_required'] ? 'Provisional · revisar' : 'Provisional' }}</td></tr>
        @empty<tr><td colspan="5">No hay intervalos completos en el período.</td></tr>@endforelse
    </tbody></table></div>
    <h3>Resumen semanal</h3>
    <div class="table-wrap"><table><thead><tr><th>Trabajador</th><th>Semana ISO</th><th>Tiempo observado</th><th>Estado</th></tr></thead><tbody>
        @forelse ($weeklyReports as $week)
            <tr><td data-label="Trabajador">{{ $week['employee_name'] }}</td><td data-label="Semana ISO" class="tabular-numbers">{{ $week['week'] }}</td><td data-label="Tiempo observado">{{ $week['review_required'] ? 'Revisión requerida' : intdiv($week['worked_minutes'], 60).' h '.($week['worked_minutes'] % 60).' min' }}</td><td data-label="Estado">{{ $week['review_required'] ? 'Provisional · revisar' : 'Provisional' }}</td></tr>
        @empty<tr><td colspan="4">No hay datos semanales en el período.</td></tr>@endforelse
    </tbody></table></div>
    @if ($reportAlerts)
        <h3>Alertas de cálculo</h3>
        <ul>@foreach ($reportAlerts as $alert)<li>{{ $alert }}</li>@endforeach</ul>
    @endif
    @if ($scheduleAlerts)
        <h3>Alertas de horario</h3>
        <p class="muted">Una diferencia con la jornada configurada es informativa y requiere revisión; no determina por sí sola un incumplimiento.</p>
        <ul>@foreach ($scheduleAlerts as $alert)<li>{{ $alert }}</li>@endforeach</ul>
    @endif
    <h3 id="attendance-records">Marcas recientes</h3>
    <div class="table-wrap"><table><thead><tr><th>Trabajador</th><th>Tipo</th><th>Hora local</th><th>Hora servidor local</th><th>Origen</th></tr></thead><tbody>
        @forelse ($events as $event)<tr><td data-label="Trabajador">{{ $event->employee->user->name }}</td><td data-label="Tipo">{{ $eventLabels[$event->event_type] }}</td><td data-label="Hora local" class="tabular-numbers">{{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s') }}</td><td data-label="Hora servidor local" class="tabular-numbers">{{ $event->received_at_server->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s') }}</td><td data-label="Origen">{{ $event->offline ? 'Sin conexión' : 'En línea' }}</td></tr>@empty<tr><td colspan="5">No hay marcas.</td></tr>@endforelse
    </tbody></table></div>
</section>
<section class="card admin-section" id="incidents" aria-labelledby="incidents-heading">
    <div class="section-heading"><div><p class="eyebrow">Revisión</p><h2 id="incidents-heading">Incidencias abiertas</h2></div></div>
    <ul class="event-list">
        @forelse ($incidents as $incident)
            <li class="event-row"><span><strong>{{ $incident->employee->user->name }} · {{ $incidentLabels[$incident->type] ?? 'Incidencia' }}</strong><small>{{ $incident->description }}</small><small>{{ $incident->created_at->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i') }}</small></span><form method="post" action="{{ route('admin.incidents.review', $incident) }}">@csrf<button class="button secondary" type="submit">Marcar revisada</button></form></li>
        @empty<li>No hay incidencias abiertas.</li>@endforelse
    </ul>
</section>
<section class="card admin-section" id="corrections" aria-labelledby="corrections-heading">
    <div class="section-heading"><div><p class="eyebrow">Revisión</p><h2 id="corrections-heading">Solicitudes de corrección</h2></div></div>
    <ul class="event-list">
        @forelse ($corrections as $correction)
            <li class="event-row"><span><strong>{{ $correction->employee->user->name }} · {{ $correction->attendanceEvent ? $eventLabels[$correction->attendanceEvent->event_type] : 'Marca faltante' }}</strong><small>{{ $correction->reason }}</small><small>Propuesta: {{ $correction->requested_event_type ? $eventLabels[$correction->requested_event_type] : '—' }} {{ $correction->requested_occurred_at?->timezone($correction->requested_timezone ?? config('attendance.presentation_timezone'))->format('d/m/Y H:i') ?? '' }} {{ $correction->requested_timezone ?? '' }}</small></span>
                <form method="post" action="{{ route('admin.corrections.resolve', $correction) }}" class="stack compact">@csrf<label>Nota de resolución<textarea name="resolution_note" minlength="3" maxlength="2000" required></textarea></label><div class="inline-actions"><button class="button secondary" name="status" value="approved">Aprobar solicitud</button><button class="button danger" name="status" value="rejected">Rechazar</button></div></form>
            </li>
        @empty<li>No hay solicitudes pendientes.</li>@endforelse
    </ul>
</section>
@endsection
