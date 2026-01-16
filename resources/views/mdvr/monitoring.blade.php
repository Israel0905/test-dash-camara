@extends('layouts.mdvr')

@section('title', 'Monitoreo y Reacción - MDVR Monitor')
@section('header', 'Monitoreo y Reacción')

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Full screen layout adjustments */
        .main-content {
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .main-content>header {
            flex-shrink: 0;
            height: 50px;
            padding: 0 20px;
            z-index: 30;
        }

        .main-content>div {
            padding: 0 !important;
            flex-grow: 1;
            display: flex;
            overflow: hidden;
        }

        /* Map Container */
        #map-container {
            flex-grow: 1;
            position: relative;
            z-index: 10;
            background: #e5e7eb;
        }

        #map {
            width: 100%;
            height: 100%;
        }

        /* Left Sidebar - Vehicles */
        #left-panel {
            width: 320px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            z-index: 20;
        }

        /* Right Sidebar - Video */
        #right-panel {
            width: 380px;
            background: white;
            border-left: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            z-index: 20;
        }

        /* Floating Detail Card */
        #detail-card {
            position: absolute;
            top: 20px;
            left: 20px;
            width: 360px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            /* Hidden by default */
            max-height: calc(100% - 40px);
            overflow-y: auto;
        }

        /* Custom Markers */
        .vehicle-marker {
            background: #3B82F6;
            border: 2px solid #fff;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s;
        }

        .vehicle-marker.selected {
            background: #10B981;
            transform: scale(1.2);
            z-index: 1000 !important;
        }
    </style>
@endpush

@section('content')
    <!-- Left Panel: Vehicle List -->
    <div id="left-panel">
        <!-- Header/Search -->
        <div class="p-3 border-b border-gray-100 bg-gray-50">
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-bold text-gray-700">Vehículos ({{ $devices->count() }})</h3>
                <div class="flex gap-1">
                    <button class="p-1 hover:bg-gray-200 rounded text-gray-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="relative">
                <input type="text" placeholder="Búsqueda..."
                    class="w-full text-sm border border-gray-300 rounded-md py-1.5 pl-8 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                <svg class="w-4 h-4 text-gray-400 absolute left-2.5 top-2" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
            <div class="mt-2 text-xs flex gap-2">
                <button class="bg-blue-100 text-blue-700 font-medium px-2 py-0.5 rounded-full">Todos los grupos</button>
            </div>
        </div>

        <!-- List -->
        <div class="flex-1 overflow-y-auto">
            <ul class="divide-y divide-gray-100">
                @foreach ($devices as $d)
                    <li class="hover:bg-blue-50 cursor-pointer transition p-3 group device-item"
                        onclick="selectDevice({{ $d->id }}, {{ $d->latestLocation?->latitude ?? 20.65 }}, {{ $d->latestLocation?->longitude ?? -100.29 }})"
                        data-id="{{ $d->id }}">
                        <div class="flex items-start gap-3">
                            <div class="mt-1 relative">
                                <!-- Icon -->
                                <div
                                    class="w-8 h-8 rounded-full flex items-center justify-center {{ $d->is_online ? 'bg-sky-100 text-sky-600' : 'bg-gray-200 text-gray-500' }}">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </div>
                                <!-- Status dot -->
                                <span
                                    class="absolute -bottom-0.5 -right-0.5 w-3 h-3 border-2 border-white rounded-full {{ $d->is_online ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-start">
                                    <p class="font-bold text-gray-800 text-sm truncate">{{ $d->plate_number ?? 'S/P' }} -
                                        {{ $d->phone_number }}</p>
                                </div>
                                <p class="text-xs text-gray-500 truncate mt-0.5">Inicio de Viaje - Conduciendo</p>
                                <p class="text-xs text-orange-500 font-medium mt-0.5">
                                    {{ $d->latestLocation ? $d->latestLocation->speed . ' Km/h' : '0 Km/h' }}
                                    <span class="text-gray-400 mx-1">•</span>
                                    {{ $d->latestLocation ? $d->latestLocation->device_time?->format('d/m/Y - H:i') : '' }}
                                </p>
                            </div>
                            <div class="text-gray-400 group-hover:text-blue-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                                    </path>
                                </svg>
                            </div>
                        </div>
                    </li>
                @endforeach
                <!-- Dummy Items if needed for demo -->
                @for ($i = 0; $i < 5; $i++)
                    <li class="hover:bg-blue-50 cursor-pointer transition p-3 group opacity-60">
                        <div class="flex items-start gap-3">
                            <div class="mt-1 relative">
                                <div
                                    class="w-8 h-8 rounded-full flex items-center justify-center bg-gray-200 text-gray-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </div>
                                <span
                                    class="absolute -bottom-0.5 -right-0.5 w-3 h-3 border-2 border-white rounded-full bg-gray-400"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-gray-800 text-sm truncate">00{{ $i + 2 }} - FLOTA DUMMY</p>
                                <p class="text-xs text-gray-500 truncate mt-0.5">Detenido</p>
                                <p class="text-xs text-gray-500 mt-0.5">0 Km/h • 16/01/2026</p>
                            </div>
                        </div>
                    </li>
                @endfor
            </ul>
        </div>
    </div>

    <!-- Center: Map -->
    <div id="map-container">
        <div id="map"></div>

        <!-- Floating Info Card (Initially Hidden) -->
        <div id="detail-card">
            <!-- Header -->
            <div class="bg-gray-800 text-white p-3 rounded-t-lg flex justify-between items-center">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center border-2 border-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <div>
                        <h4 class="font-bold text-sm" id="card-title">208 - CASADIA</h4>
                        <p class="text-xs text-gray-300">Inicio de viaje</p>
                    </div>
                </div>
                <button onclick="document.getElementById('detail-card').style.display='none'"
                    class="text-gray-400 hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Tab Bar -->
            <div class="flex border-b border-gray-200 bg-white">
                <button class="flex-1 py-2 text-gray-600 hover:bg-gray-50 border-b-2 border-blue-500 text-blue-600">
                    <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                        </path>
                    </svg>
                </button>
                <button class="flex-1 py-2 text-gray-400 hover:bg-gray-50">
                    <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4">
                        </path>
                    </svg>
                </button>
                <button class="flex-1 py-2 text-gray-400 hover:bg-gray-50">
                    <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Content -->
            <div class="p-4 space-y-4 max-h-[400px] overflow-y-auto">
                <!-- Location Details -->
                <div>
                    <h4 class="font-bold text-xs text-gray-500 uppercase tracking-wide mb-2">Posición</h4>

                    <div class="flex gap-2 mb-3">
                        <button class="flex-1 border text-xs py-1 rounded hover:bg-gray-50">Compartir</button>
                        <button class="flex-1 border text-xs py-1 rounded hover:bg-gray-50">Seguir</button>
                    </div>

                    <p class="text-sm text-gray-800 font-medium mb-1">
                        En Carretera Libramiento Noreste de Querétaro El Marqués MX-QUE
                    </p>
                    <a href="#" class="text-xs text-blue-600 hover:underline block mb-2">20.65469, -100.29212</a>

                    <div class="grid grid-cols-1 gap-2 text-xs text-gray-600">
                        <p><strong>81 Km/h</strong> - <span class="text-gray-400">2026/01/16 - 14:11</span></p>
                        <p>Fecha de última comunicación: 14:11</p>
                        <p>Conducción continua: 1 h 3 m</p>
                        <p>Ignición: <span class="text-green-600 font-bold">Encendido</span></p>
                        <p>Paro de motor: <span class="text-gray-500">Desactivado</span></p>
                    </div>
                </div>

                <hr class="border-gray-100">

                <!-- Vehicle Details -->
                <div>
                    <h4 class="font-bold text-xs text-gray-500 uppercase tracking-wide mb-2">Vehículo</h4>
                    <div class="text-xs space-y-1 text-gray-600">
                        <div class="flex justify-between"><span>Marca:</span> <span
                                class="font-medium text-gray-800">FREIGHTLINER</span></div>
                        <div class="flex justify-between"><span>Modelo:</span> <span
                                class="font-medium text-gray-800">CASCADIA</span></div>
                        <div class="flex justify-between"><span>Año:</span> <span
                                class="font-medium text-gray-800">2025</span></div>
                        <div class="flex justify-between"><span>Placas:</span> <span
                                class="font-medium text-gray-800">WT0269</span></div>
                    </div>
                </div>

                <hr class="border-gray-100">

                <!-- Sensors -->
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold text-xs text-gray-500 uppercase tracking-wide">Sensores</h4>
                        <button class="text-gray-400"><svg class="w-3 h-3" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg></button>
                    </div>
                    <div class="grid grid-cols-4 gap-2 mb-2">
                        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-yellow-400"></span>
                            <span class="text-xs">1</span></div>
                        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-gray-600"></span> <span
                                class="text-xs">0</span></div>
                        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-gray-600"></span> <span
                                class="text-xs">0</span></div>
                        <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-yellow-400"></span>
                            <span class="text-xs">1</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Panel: Video Feeds -->
    <div id="right-panel">
        <!-- Header -->
        <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-white">
            <div>
                <h2 class="font-bold text-gray-800 text-lg" id="panel-title">208</h2>
                <p class="text-xs text-gray-500">online - Última conexión: 14:06</p>
            </div>
            <button class="text-gray-400 hover:text-gray-600" onclick="toggleRightPanel()">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                    </path>
                </svg>
            </button>
        </div>

        <!-- Video Content -->
        <div class="flex-1 overflow-y-auto p-4 space-y-6 bg-gray-50">

            @foreach ($cameras as $cam)
                <div class="bg-white p-3 rounded-lg shadow-sm border border-gray-200">
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold text-gray-700 text-sm">{{ $cam['name'] }}</h4>
                        <span class="text-xs text-gray-400">ID: {{ $cam['id'] }}</span>
                    </div>

                    <div class="aspect-video bg-black rounded-lg relative overflow-hidden group cursor-pointer">
                        @if ($cam['active'])
                            <img src="https://picsum.photos/seed/{{ $cam['id'] }}/400/225" alt="Video Feed"
                                class="w-full h-full object-cover">
                            <div
                                class="absolute top-2 left-2 bg-red-600 text-white text-[10px] px-1.5 py-0.5 rounded animate-pulse">
                                LIVE</div>

                            <!-- Controls Overlay -->
                            <div
                                class="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition duration-200">
                                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4">
                                    </path>
                                </svg>
                            </div>
                        @else
                            <div class="w-full h-full flex flex-col items-center justify-center text-gray-500">
                                <svg class="w-8 h-8 mb-1 opacity-50" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636">
                                    </path>
                                </svg>
                                <span class="text-xs">Sin señal</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="text-center mt-6">
                <button class="text-blue-600 text-sm font-medium hover:underline">Ver historial de video</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize map
        const map = L.map('map', {
            zoomControl: false // Move zoom control if needed
        }).setView([20.65469, -100.29212], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Add custom zoom control to right side
        L.control.zoom({
            position: 'topright'
        }).addTo(map);

        // Dummy Markers
        const locations = [{
                lat: 20.65469,
                lng: -100.29212,
                active: true
            },
            {
                lat: 19.4326,
                lng: -99.1332,
                active: false
            },
            {
                lat: 25.6866,
                lng: -100.3161,
                active: false
            }
        ];

        locations.forEach((loc, index) => {
            const icon = L.divIcon({
                className: 'custom-div-icon',
                html: `<div class="vehicle-marker ${loc.active ? 'selected' : ''} bg-blue-500">
                     <svg class="text-white w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>
                   </div>`,
                iconSize: [32, 32],
                iconAnchor: [16, 16]
            });

            const marker = L.marker([loc.lat, loc.lng], {
                icon: icon
            }).addTo(map);

            marker.on('click', () => {
                selectDevice(index, loc.lat, loc.lng);
            });
        });

        // Select Device Function
        function selectDevice(id, lat, lng) {
            // Show detail card
            const card = document.getElementById('detail-card');
            card.style.display = 'block';

            // Center map
            map.flyTo([lat, lng], 15);

            // Update Title (Dummy Action)
            document.getElementById('card-title').textContent = "Vehículo " + id;
            document.getElementById('panel-title').textContent = "Vehículo " + id;
        }

        function toggleRightPanel() {
            const panel = document.getElementById('right-panel');
            if (panel.style.display === 'none') {
                panel.style.display = 'flex';
            } else {
                panel.style.display = 'none';
            }
            setTimeout(() => map.invalidateSize(), 300);
        }
    </script>
@endpush
