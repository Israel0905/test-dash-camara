@extends('layouts.mdvr')

@section('title', 'Alarmas - MDVR Monitor')
@section('header', 'Alarmas')

@section('content')
    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                <select name="category"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Todas</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}" {{ request('category') == $cat ? 'selected' : '' }}>
                            {{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Dispositivo</label>
                <select name="device_id"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Todos</option>
                    @foreach ($devices as $d)
                        <option value="{{ $d->id }}" {{ request('device_id') == $d->id ? 'selected' : '' }}>
                            {{ $d->plate_number ?? $d->phone_number }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit"
                    class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    Filtrar
                </button>
                <a href="{{ route('mdvr.alarms') }}"
                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                    Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- Alarms List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">
                    Mostrando <strong>{{ $alarms->count() }}</strong> de <strong>{{ $alarms->total() }}</strong> alarmas
                </span>
            </div>
        </div>

        <div class="divide-y divide-gray-100">
            @forelse($alarms as $alarm)
                <a href="{{ route('mdvr.alarms.show', $alarm) }}" class="block p-4 hover:bg-gray-50 transition">
                    <div class="flex items-start gap-4">
                        <div
                            class="w-12 h-12 rounded-full flex items-center justify-center shrink-0
                    @if ($alarm->alarm_category === 'ADAS') bg-orange-100
                    @elseif($alarm->alarm_category === 'DSM') bg-red-100
                    @elseif($alarm->alarm_category === 'BSD') bg-yellow-100
                    @else bg-purple-100 @endif">
                            @if ($alarm->alarm_category === 'ADAS')
                                <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            @elseif($alarm->alarm_category === 'DSM')
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            @elseif($alarm->alarm_category === 'BSD')
                                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                </svg>
                            @else
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                                    </path>
                                </svg>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h3 class="font-semibold text-gray-800">{{ $alarm->alarm_name ?? $alarm->alarm_type }}
                                    </h3>
                                    <p class="text-sm text-gray-500">
                                        {{ $alarm->device?->plate_number ?? ($alarm->device?->phone_number ?? 'Dispositivo desconocido') }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                @if ($alarm->alarm_category === 'ADAS') bg-orange-100 text-orange-800
                                @elseif($alarm->alarm_category === 'DSM') bg-red-100 text-red-800
                                @elseif($alarm->alarm_category === 'BSD') bg-yellow-100 text-yellow-800
                                @else bg-purple-100 text-purple-800 @endif">
                                        {{ $alarm->alarm_category }}
                                    </span>
                                    @if ($alarm->has_attachment)
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-1">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                                                </path>
                                            </svg>
                                            Video
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                                <span class="flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    {{ $alarm->created_at->format('d/m/Y H:i:s') }}
                                </span>
                                @if ($alarm->speed)
                                    <span class="flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                        {{ $alarm->speed }} km/h
                                    </span>
                                @endif
                                @if ($alarm->latitude && $alarm->longitude)
                                    <span class="flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                                            </path>
                                        </svg>
                                        {{ number_format($alarm->latitude, 4) }},
                                        {{ number_format($alarm->longitude, 4) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                            </path>
                        </svg>
                    </div>
                </a>
            @empty
                <div class="p-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-gray-500">No se encontraron alarmas</p>
                    <p class="text-sm text-gray-400 mt-2">Intenta ajustar los filtros o espera a que los dispositivos
                        reporten eventos</p>
                </div>
            @endforelse
        </div>

        @if ($alarms->hasPages())
            <div class="p-6 border-t border-gray-100">
                {{ $alarms->withQueryString()->links() }}
            </div>
        @endif
    </div>
@endsection
