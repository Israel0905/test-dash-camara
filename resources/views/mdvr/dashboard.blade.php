@extends('layouts.mdvr')

@section('title', 'Dashboard - MDVR Monitor')
@section('header', 'Dashboard')

@section('content')
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Total Devices -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Total Dispositivos</p>
                    <p class="text-3xl font-bold text-gray-800">{{ $stats['total_devices'] }}</p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z">
                        </path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Online Devices -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">En Línea</p>
                    <p class="text-3xl font-bold text-green-600">{{ $stats['online_devices'] }}</p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z">
                        </path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Alarms Today -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Alarmas Hoy</p>
                    <p class="text-3xl font-bold text-red-600">{{ $stats['total_alarms_today'] }}</p>
                </div>
                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                        </path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Locations Today -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Ubicaciones Hoy</p>
                    <p class="text-3xl font-bold text-purple-600">{{ $stats['total_locations_today'] }}</p>
                </div>
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Devices List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="p-6 border-b border-gray-100">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Dispositivos</h3>
                    <a href="{{ route('mdvr.devices') }}" class="text-sm text-blue-600 hover:underline">Ver todos</a>
                </div>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($devices->take(5) as $device)
                    <div class="p-4 hover:bg-gray-50 transition">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-10 h-10 rounded-full flex items-center justify-center {{ $device->is_online ? 'bg-green-100' : 'bg-gray-100' }}">
                                    <svg class="w-5 h-5 {{ $device->is_online ? 'text-green-600' : 'text-gray-400' }}"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                                        </path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800">
                                        {{ $device->plate_number ?? $device->phone_number }}</p>
                                    <p class="text-sm text-gray-500">{{ $device->phone_number }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $device->is_online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    {{ $device->is_online ? 'Online' : 'Offline' }}
                                </span>
                                @if ($device->last_heartbeat_at)
                                    <p class="text-xs text-gray-400 mt-1">{{ $device->last_heartbeat_at->diffForHumans() }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-gray-500">
                        <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z">
                            </path>
                        </svg>
                        <p>No hay dispositivos registrados</p>
                        <p class="text-sm mt-2">Los dispositivos aparecerán cuando se conecten al servidor</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Alarms -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="p-6 border-b border-gray-100">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Alarmas Recientes</h3>
                    <a href="{{ route('mdvr.alarms') }}" class="text-sm text-blue-600 hover:underline">Ver todas</a>
                </div>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($recentAlarms as $alarm)
                    <a href="{{ route('mdvr.alarms.show', $alarm) }}" class="block p-4 hover:bg-gray-50 transition">
                        <div class="flex items-start gap-3">
                            <div
                                class="w-10 h-10 rounded-full flex items-center justify-center shrink-0
                        @if ($alarm->alarm_category === 'ADAS') bg-orange-100
                        @elseif($alarm->alarm_category === 'DSM') bg-red-100
                        @elseif($alarm->alarm_category === 'BSD') bg-yellow-100
                        @else bg-purple-100 @endif">
                                <svg class="w-5 h-5
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
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-medium text-gray-800">
                                            {{ $alarm->alarm_name ?? $alarm->alarm_type }}</p>
                                        <p class="text-sm text-gray-500">
                                            {{ $alarm->device?->plate_number ?? $alarm->device?->phone_number }}</p>
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
                                <p class="text-xs text-gray-400 mt-1">{{ $alarm->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center text-gray-500">
                        <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p>No hay alarmas recientes</p>
                        <p class="text-sm mt-2">Las alarmas aparecerán cuando los dispositivos las reporten</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
