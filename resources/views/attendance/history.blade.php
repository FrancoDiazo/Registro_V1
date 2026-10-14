@extends('layouts.app')

@section('title', 'Mi historial')

@section('content')
@php($eventLabels = ['ENTRY' => 'Entrada', 'BREAK_START' => 'Inicio de colación', 'BREAK_END' => 'Fin de colación', 'EXIT' => 'Salida'])
<section class="welcome">
    <p class="eyebrow">Registro personal</p>
    <h1>Mi historial</h1>
    <form method="get" class="filter-row">
        <label for="from">Desde</label><input id="from" name="from" type="date" value="{{ request('from') }}">
        <label for="to">Hasta</label><input id="to" name="to" type="date" value="{{ request('to') }}">
        <button class="button secondary" type="submit">Filtrar</button>
    </form>
</section>
<section class="card">
    @if ($events->isEmpty())
        <p class="muted">No hay marcas para este período.</p>
    @else
        <ul class="event-list">
            @foreach ($events as $event)
                <li class="event-row">
                    <span><strong><a href="{{ route('attendance-events.show', $event) }}">{{ $eventLabels[$event->event_type] }}</a></strong><small>{{ $event->occurred_at_device->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s') }}</small><small>Recibida por servidor: {{ $event->received_at_server->timezone(config('attendance.presentation_timezone'))->format('d/m/Y H:i:s') }}</small></span>
                    <span class="badge synced">Sincronizada</span>
                </li>
            @endforeach
        </ul>
        {{ $events->links() }}
    @endif
</section>
@endsection
