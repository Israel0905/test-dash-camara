@extends('layouts.mdvr')

@section('title', 'Dispositivos - MDVR Monitor')
@section('header', 'Dispositivos')

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600">
                        Total: <strong>{{ $devices->total() }}</strong> dispositivos
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="location.reload()"
                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                        Actualizar
                    </button>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Dispositivo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Última
                            Conexión</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Ubicaciones</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alarmas
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($devices as $device)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4">
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
                                        <p class="font-medium text-gray-800">{{ $device->plate_number ?? 'Sin placa' }}</p>
                                        <p class="text-sm text-gray-500">{{ $device->phone_number }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $device->is_online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    <span
                                        class="w-1.5 h-1.5 rounded-full mr-1.5 {{ $device->is_online ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                    {{ $device->is_online ? 'Online' : 'Offline' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                @if ($device->last_heartbeat_at)
                                    {{ $device->last_heartbeat_at->format('d/m/Y H:i:s') }}
                                    <br><span
                                        class="text-xs text-gray-400">{{ $device->last_heartbeat_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-gray-400">Nunca</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                    {{ number_format($device->locations_count) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    {{ number_format($device->alarms_count) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <a href="{{ route('mdvr.devices.show', $device) }}"
                                    class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    Ver detalles →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z">
                                    </path>
                                </svg>
                                <p class="text-gray-500">No hay dispositivos registrados</p>
                                <p class="text-sm text-gray-400 mt-2">Los dispositivos aparecerán cuando se conecten al
                                    servidor MDVR</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($devices->hasPages())
            <div class="p-6 border-t border-gray-100">
                {{ $devices->links() }}
            </div>
        @endif
    </div>
@endsection
