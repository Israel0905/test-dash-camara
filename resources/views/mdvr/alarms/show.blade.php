@extends('layouts.mdvr')

@section('title', 'Alarma - MDVR Monitor')
@section('header', 'Detalle de Alarma')

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        #alarm-map {
            height: 300px;
            border-radius: 12px;
        }
    </style>
@endpush

@section('content')
    <div class="mb-6">
        <a href="{{ route('mdvr.alarms') }}" class="text-blue-600 hover:text-blue-800 text-sm">
            ← Volver a alarmas
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Alarm Info -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Main Info Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-start gap-4 mb-6">
                    <div
                        class="w-16 h-16 rounded-full flex items-center justify-center shrink-0
                    @if ($alarm->alarm_category === 'ADAS') bg-orange-100
                    @elseif($alarm->alarm_category === 'DSM') bg-red-100
                    @elseif($alarm->alarm_category === 'BSD') bg-yellow-100
                    @else bg-purple-100 @endif">
                        <svg class="w-8 h-8
                        @if ($alarm->alarm_category === 'ADAS') text-orange-600
                        @elseif($alarm->alarm_category === 'DSM') text-red-600
                        @elseif($alarm->alarm_category === 'BSD') text-yellow-600
                        @else text-purple-600 @endif"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                            </path>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">{{ $alarm->alarm_name ?? $alarm->alarm_type }}</h2>
                        <div class="flex items-center gap-2 mt-2">
                            <span
                                class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                            @if ($alarm->alarm_category === 'ADAS') bg-orange-100 text-orange-800
                            @elseif($alarm->alarm_category === 'DSM') bg-red-100 text-red-800
                            @elseif($alarm->alarm_category === 'BSD') bg-yellow-100 text-yellow-800
                            @else bg-purple-100 text-purple-800 @endif">
                                {{ $alarm->alarm_category }}
                            </span>
                            <span
                                class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                                Nivel {{ $alarm->alarm_level }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 mb-1">Fecha/Hora</p>
                        <p class="font-semibold text-gray-800">{{ $alarm->created_at->format('d/m/Y') }}</p>
                        <p class="text-sm text-gray-600">{{ $alarm->created_at->format('H:i:s') }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 mb-1">Velocidad</p>
                        <p class="font-semibold text-gray-800">{{ $alarm->speed ?? 'N/A' }} km/h</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 mb-1">Latitud</p>
                        <p class="font-semibold text-gray-800">
                            {{ $alarm->latitude ? number_format($alarm->latitude, 6) : 'N/A' }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 mb-1">Longitud</p>
                        <p class="font-semibold text-gray-800">
                            {{ $alarm->longitude ? number_format($alarm->longitude, 6) : 'N/A' }}</p>
                    </div>
                </div>
            </div>

            <!-- Location Map -->
            @if ($alarm->latitude && $alarm->longitude)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Ubicación del Evento</h3>
                    <div id="alarm-map"></div>
                </div>
            @endif

            <!-- Attachments -->
            @if ($alarm->attachmentFiles->count() > 0)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Archivos Adjuntos</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        @foreach ($alarm->attachmentFiles as $file)
                            <div class="border border-gray-200 rounded-lg p-4 hover:border-blue-500 transition">
                                @if ($file->isImage())
                                    <div class="aspect-video bg-gray-100 rounded mb-2 flex items-center justify-center">
                                        <img src="{{ $file->url }}" alt="{{ $file->filename }}"
                                            class="max-h-full rounded">
                                    </div>
                                @elseif($file->isVideo())
                                    <div class="aspect-video bg-gray-800 rounded mb-2 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z">
                                            </path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                @else
                                    <div class="aspect-video bg-gray-100 rounded mb-2 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                                            </path>
                                        </svg>
                                    </div>
                                @endif
                                <p class="text-sm font-medium text-gray-800 truncate">{{ $file->filename }}</p>
                                <p class="text-xs text-gray-500">{{ $file->human_file_size }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Raw Data -->
            @if ($alarm->alarm_data)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Datos Técnicos</h3>
                    <pre class="bg-gray-900 text-green-400 rounded-lg p-4 overflow-x-auto text-sm">{{ json_encode($alarm->alarm_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif
        </div>

        <!-- Device Info Sidebar -->
        <div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sticky top-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Dispositivo</h3>
                @if ($alarm->device)
                    <div class="flex items-center gap-3 mb-4">
                        <div
                            class="w-12 h-12 rounded-full flex items-center justify-center {{ $alarm->device->is_online ? 'bg-green-100' : 'bg-gray-100' }}">
                            <svg class="w-6 h-6 {{ $alarm->device->is_online ? 'text-green-600' : 'text-gray-400' }}"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                                </path>
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800">{{ $alarm->device->plate_number ?? 'Sin placa' }}</p>
                            <p class="text-sm text-gray-500">{{ $alarm->device->phone_number }}</p>
                        </div>
                    </div>
                    <a href="{{ route('mdvr.devices.show', $alarm->device) }}"
                        class="block w-full text-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Ver Dispositivo
                    </a>
                @else
                    <p class="text-gray-500">Dispositivo no encontrado</p>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        @if ($alarm->latitude && $alarm->longitude)
            const lat = {{ $alarm->latitude }};
            const lng = {{ $alarm->longitude }};

            const map = L.map('alarm-map').setView([lat, lng], 16);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            L.marker([lat, lng]).addTo(map)
                .bindPopup(`
            <strong>{{ $alarm->alarm_name ?? $alarm->alarm_type }}</strong><br>
            {{ $alarm->created_at->format('d/m/Y H:i:s') }}<br>
            Velocidad: {{ $alarm->speed ?? 'N/A' }} km/h
        `).openPopup();
        @endif
    </script>
@endpush
