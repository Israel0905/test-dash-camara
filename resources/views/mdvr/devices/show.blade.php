@extends('layouts.mdvr')

@section('title', 'Dispositivo ' . ($device->plate_number ?? $device->phone_number) . ' - MDVR Monitor')
@section('header', 'Detalles del Dispositivo')

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        #device-map {
            height: 400px;
            border-radius: 12px;
        }
    </style>
@endpush

@section('content')
    <div class="mb-6">
        <a href="{{ route('mdvr.devices') }}" class="text-blue-600 hover:text-blue-800 text-sm">
            ← Volver a dispositivos
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Device Info -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4 mb-6">
                <div
                    class="w-16 h-16 rounded-full flex items-center justify-center {{ $device->is_online ? 'bg-green-100' : 'bg-gray-100' }}">
                    <svg class="w-8 h-8 {{ $device->is_online ? 'text-green-600' : 'text-gray-400' }}" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                        </path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">{{ $device->plate_number ?? 'Sin placa' }}</h2>
                    <p class="text-gray-500">{{ $device->phone_number }}</p>
                    <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-2 {{ $device->is_online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $device->is_online ? 'Online' : 'Offline' }}
                    </span>
                </div>
            </div>

            <div class="space-y-4">
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">Terminal ID</span>
                    <span class="font-medium text-gray-800">{{ $device->terminal_id ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">Fabricante</span>
                    <span class="font-medium text-gray-800">{{ $device->manufacturer_id ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">Modelo</span>
                    <span class="font-medium text-gray-800">{{ $device->terminal_model ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">IMEI</span>
                    <span class="font-medium text-gray-800">{{ $device->imei ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">Firmware</span>
                    <span class="font-medium text-gray-800">{{ $device->firmware_version ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">Última IP</span>
                    <span class="font-medium text-gray-800">{{ $device->last_ip ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-500">Registrado</span>
                    <span
                        class="font-medium text-gray-800">{{ $device->registered_at?->format('d/m/Y H:i') ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-2">
                    <span class="text-gray-500">Último Heartbeat</span>
                    <span
                        class="font-medium text-gray-800">{{ $device->last_heartbeat_at?->format('d/m/Y H:i:s') ?? 'Nunca' }}</span>
                </div>
            </div>
        </div>

        <!-- Map and Location -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Current Location -->
            @if ($device->latestLocation)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Última Ubicación</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-xs text-gray-500">Velocidad</p>
                            <p class="text-xl font-bold text-gray-800">{{ $device->latestLocation->speed }} <span
                                    class="text-sm font-normal">km/h</span></p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-xs text-gray-500">Dirección</p>
                            <p class="text-xl font-bold text-gray-800">{{ $device->latestLocation->direction }}°</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-xs text-gray-500">Altitud</p>
                            <p class="text-xl font-bold text-gray-800">{{ $device->latestLocation->altitude }} <span
                                    class="text-sm font-normal">m</span></p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-xs text-gray-500">ACC</p>
                            <p
                                class="text-xl font-bold {{ $device->latestLocation->acc_on ? 'text-green-600' : 'text-red-600' }}">
                                {{ $device->latestLocation->acc_on ? 'ON' : 'OFF' }}
                            </p>
                        </div>
                    </div>
                    <div id="device-map"></div>
                </div>
            @else
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    </svg>
                    <p class="text-gray-500">Sin ubicación registrada</p>
                </div>
            @endif

            <!-- Recent Alarms -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Alarmas Recientes</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse($device->alarms as $alarm)
                        <a href="{{ route('mdvr.alarms.show', $alarm) }}" class="block p-4 hover:bg-gray-50 transition">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="font-medium text-gray-800">{{ $alarm->alarm_name ?? $alarm->alarm_type }}</p>
                                    <p class="text-sm text-gray-500">{{ $alarm->created_at->format('d/m/Y H:i:s') }}</p>
                                </div>
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                            @if ($alarm->alarm_category === 'ADAS') bg-orange-100 text-orange-800
                            @elseif($alarm->alarm_category === 'DSM') bg-red-100 text-red-800
                            @elseif($alarm->alarm_category === 'BSD') bg-yellow-100 text-yellow-800
                            @else bg-purple-100 text-purple-800 @endif">
                                    {{ $alarm->alarm_category }}
                                </span>
                            </div>
                        </a>
                    @empty
                        <div class="p-8 text-center text-gray-500">
                            <p>No hay alarmas para este dispositivo</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        @if ($device->latestLocation)
            const lat = {{ $device->latestLocation->latitude }};
            const lng = {{ $device->latestLocation->longitude }};

            const map = L.map('device-map').setView([lat, lng], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            L.marker([lat, lng]).addTo(map)
                .bindPopup(`
            <strong>{{ $device->plate_number ?? $device->phone_number }}</strong><br>
            Velocidad: {{ $device->latestLocation->speed }} km/h<br>
            Última actualización: {{ $device->latestLocation->device_time?->format('d/m/Y H:i:s') }}
        `).openPopup();

            // Draw route from locations
            const locations = @json($locations->map(fn($l) => [$l->latitude, $l->longitude]));
            if (locations.length > 1) {
                L.polyline(locations, {
                    color: '#3B82F6',
                    weight: 3,
                    opacity: 0.7
                }).addTo(map);
            }
        @endif
    </script>
@endpush
