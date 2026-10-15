@extends('layouts.app')

@section('title', 'Mi historial')

@section('content')
@php($eventLabels = ['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'])
<section class="welcome" aria-labelledby="history-heading">
    <p class="eyebrow">Registro personal</p>
    <h1 id="history-heading">Mi historial</h1>
    <p class="muted">Consulta tus marcas confirmadas y abre el detalle para solicitar una revisión.</p>
    <form method="get" class="filter-row">
        <label for="from">Desde<input id="from" name="from" type="date" value="{{ request('from') }}"></label>
        <label for="to">Hasta<input id="to" name="to" type="date" value="{{ request('to') }}"></label>
        <button class="button secondary" type="submit">Filtrar</button>
    </form>
</section>
<section class="card" aria-label="Resultados del historial">
    @if ($events->isEmpty())
        <p class="empty-state">No hay marcas para este período.</p>
    @else
        <ul class="event-list">
            @foreach ($events as $event)
                <li class="event-row">
                    <span><strong><a href="{{ route('attendance-events.show', $event) }}">{{ $eventLabels[$event->event_type] }}</a></strong><small class="tabular-numbers">{{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s') }}</small><small class="tabular-numbers">Recibida por servidor: {{ $event->received_at_server->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s') }}</small></span>
                    <span class="badge synced">Sincronizada</span>
                </li>
            @endforeach
        </ul>
        {{ $events->links() }}
    @endif
</section>
@endsection
