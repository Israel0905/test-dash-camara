@extends('layouts.mdvr')

@section('title', 'Mapa - MDVR Monitor')
@section('header', 'Mapa de Ubicaciones')

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        #map {
            height: calc(100vh - 180px);
            border-radius: 12px;
        }

        .vehicle-marker {
            background: #3B82F6;
            border: 3px solid #fff;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .vehicle-marker.offline {
            background: #9CA3AF;
        }

        .vehicle-marker svg {
            width: 20px;
            height: 20px;
            color: white;
        }

        .leaflet-popup-content-wrapper {
            border-radius: 12px;
        }
    </style>
@endpush

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-2 text-sm text-gray-600">
                    <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                    En línea: <strong id="online-count">0</strong>
                </span>
                <span class="flex items-center gap-2 text-sm text-gray-600">
                    <span class="w-3 h-3 bg-gray-400 rounded-full"></span>
                    Offline: <strong id="offline-count">0</strong>
                </span>
            </div>
            <button onclick="centerMap()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 transition">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                </svg>
                Centrar Mapa
            </button>
        </div>
        <div id="map"></div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize map centered on Mexico
        const map = L.map('map').setView([23.6345, -102.5528], 5);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        let markers = {};
        let initialDevices = @json($devices);

        // Custom icon
        function createIcon(isOnline) {
            return L.divIcon({
                className: 'custom-marker',
                html: `<div class="vehicle-marker ${isOnline ? '' : 'offline'}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
            </div>`,
                iconSize: [36, 36],
                iconAnchor: [18, 18]
            });
        }

        function updateMarkers(devices) {
            let online = 0,
                offline = 0;

            devices.forEach(device => {
                if (device.is_online) online++;
                else offline++;

                const popup = `
                <div class="p-2">
                    <h3 class="font-bold text-gray-800">${device.plate_number}</h3>
                    <p class="text-sm text-gray-600">${device.phone_number}</p>
                    <hr class="my-2">
                    <p class="text-sm"><strong>Velocidad:</strong> ${device.speed} km/h</p>
                    <p class="text-sm"><strong>Dirección:</strong> ${device.direction}°</p>
                    <p class="text-sm"><strong>Última actualización:</strong> ${device.time || 'N/A'}</p>
                    <a href="/mdvr/devices/${device.id}" class="inline-block mt-2 text-blue-600 text-sm hover:underline">Ver detalles →</a>
                </div>
            `;

                if (markers[device.id]) {
                    markers[device.id].setLatLng([device.lat, device.lng]);
                    markers[device.id].setIcon(createIcon(device.is_online));
                    markers[device.id].setPopupContent(popup);
                } else {
                    markers[device.id] = L.marker([device.lat, device.lng], {
                            icon: createIcon(device.is_online)
                        })
                        .addTo(map)
                        .bindPopup(popup);
                }
            });

            document.getElementById('online-count').textContent = online;
            document.getElementById('offline-count').textContent = offline;
        }

        function centerMap() {
            if (Object.keys(markers).length > 0) {
                const group = L.featureGroup(Object.values(markers));
                map.fitBounds(group.getBounds().pad(0.1));
            }
        }

        // Initial load
        updateMarkers(initialDevices);
        if (initialDevices.length > 0) {
            setTimeout(centerMap, 500);
        }

        // Auto-refresh every 10 seconds
        setInterval(async () => {
            try {
                const response = await fetch('{{ route('mdvr.api.locations') }}');
                const devices = await response.json();
                updateMarkers(devices);
            } catch (e) {
                console.error('Error fetching locations:', e);
            }
        }, 10000);
    </script>
@endpush
