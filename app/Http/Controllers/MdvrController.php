<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MdvrDevice;
use App\Models\MdvrDeviceLocation;
use App\Models\MdvrDeviceAlarm;
use App\Models\MdvrAttachmentFile;
use Carbon\Carbon;

class MdvrController extends Controller
{
    /**
     * Dashboard principal
     */
    public function dashboard()
    {
        $stats = [
            'total_devices' => MdvrDevice::count(),
            'online_devices' => MdvrDevice::where('is_online', true)->count(),
            'total_alarms_today' => MdvrDeviceAlarm::whereDate('created_at', today())->count(),
            'total_locations_today' => MdvrDeviceLocation::whereDate('created_at', today())->count(),
        ];

        $recentAlarms = MdvrDeviceAlarm::with('device')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $devices = MdvrDevice::withCount(['alarms', 'locations'])
            ->orderBy('is_online', 'desc')
            ->orderBy('last_heartbeat_at', 'desc')
            ->get();

        return view('mdvr.dashboard', compact('stats', 'recentAlarms', 'devices'));
    }

    /**
     * Lista de dispositivos
     */
    public function devices()
    {
        $devices = MdvrDevice::withCount(['alarms', 'locations'])
            ->with('latestLocation')
            ->orderBy('is_online', 'desc')
            ->orderBy('last_heartbeat_at', 'desc')
            ->paginate(20);

        return view('mdvr.devices.index', compact('devices'));
    }

    /**
     * Detalle de dispositivo
     */
    public function deviceShow(MdvrDevice $device)
    {
        $device->load(['latestLocation', 'alarms' => function ($q) {
            $q->orderBy('created_at', 'desc')->limit(20);
        }]);

        $locations = $device->locations()
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return view('mdvr.devices.show', compact('device', 'locations'));
    }

    /**
     * Mapa de ubicaciones
     */
    public function map()
    {
        $devices = MdvrDevice::with('latestLocation')
            ->where('is_online', true)
            ->get()
            ->filter(fn($d) => $d->latestLocation)
            ->map(fn($d) => [
                'id' => $d->id,
                'phone_number' => $d->phone_number,
                'plate_number' => $d->plate_number ?? 'Sin placa',
                'lat' => $d->latestLocation->latitude,
                'lng' => $d->latestLocation->longitude,
                'speed' => $d->latestLocation->speed,
                'direction' => $d->latestLocation->direction,
                'time' => $d->latestLocation->device_time?->format('Y-m-d H:i:s'),
                'is_online' => $d->is_online,
            ]);

        return view('mdvr.map', compact('devices'));
    }

    /**
     * Lista de alarmas
     */
    public function alarms(Request $request)
    {
        $query = MdvrDeviceAlarm::with('device');

        if ($request->filled('category')) {
            $query->where('alarm_category', $request->category);
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $alarms = $query->orderBy('created_at', 'desc')->paginate(20);

        $devices = MdvrDevice::orderBy('phone_number')->get();
        $categories = ['ADAS', 'DSM', 'BSD', 'AGGRESSIVE'];

        return view('mdvr.alarms.index', compact('alarms', 'devices', 'categories'));
    }

    /**
     * Detalle de alarma
     */
    public function alarmShow(MdvrDeviceAlarm $alarm)
    {
        $alarm->load(['device', 'attachmentFiles']);

        return view('mdvr.alarms.show', compact('alarm'));
    }

    /**
     * API: Obtener ubicaciones para mapa (JSON)
     */
    public function apiLocations()
    {
        $devices = MdvrDevice::with('latestLocation')
            ->get()
            ->filter(fn($d) => $d->latestLocation)
            ->map(fn($d) => [
                'id' => $d->id,
                'phone_number' => $d->phone_number,
                'plate_number' => $d->plate_number ?? 'Sin placa',
                'lat' => (float) $d->latestLocation->latitude,
                'lng' => (float) $d->latestLocation->longitude,
                'speed' => $d->latestLocation->speed,
                'direction' => $d->latestLocation->direction,
                'time' => $d->latestLocation->device_time?->format('Y-m-d H:i:s'),
                'is_online' => $d->is_online,
            ]);

        return response()->json($devices->values());
    }

    /**
     * API: Estadísticas del dashboard
     */
    public function apiStats()
    {
        return response()->json([
            'total_devices' => MdvrDevice::count(),
            'online_devices' => MdvrDevice::where('is_online', true)->count(),
            'offline_devices' => MdvrDevice::where('is_online', false)->count(),
            'alarms_today' => MdvrDeviceAlarm::whereDate('created_at', today())->count(),
            'alarms_week' => MdvrDeviceAlarm::where('created_at', '>=', now()->subDays(7))->count(),
            'locations_today' => MdvrDeviceLocation::whereDate('created_at', today())->count(),
            'alarm_categories' => MdvrDeviceAlarm::whereDate('created_at', today())
                ->selectRaw('alarm_category, count(*) as count')
                ->groupBy('alarm_category')
                ->pluck('count', 'alarm_category'),
        ]);
    }

    /**
     * API: Historial de ubicaciones de un dispositivo
     */
    public function apiDeviceLocations(MdvrDevice $device, Request $request)
    {
        $query = $device->locations();

        if ($request->filled('date')) {
            $query->whereDate('device_time', $request->date);
        } else {
            $query->whereDate('device_time', today());
        }

        $locations = $query->orderBy('device_time', 'asc')
            ->get()
            ->map(fn($l) => [
                'lat' => (float) $l->latitude,
                'lng' => (float) $l->longitude,
                'speed' => $l->speed,
                'time' => $l->device_time?->format('H:i:s'),
            ]);

        return response()->json($locations);
    }

    /**
     * Interfaz de Monitoreo (Tipo Enlace)
     */
    public function monitoring()
    {
        $devices = MdvrDevice::with('latestLocation')
            ->orderBy('is_online', 'desc')
            ->orderBy('last_heartbeat_at', 'desc')
            ->get();
            
        // Dummy video placeholders for UI testing
        $cameras = [
            ['id' => 1, 'name' => 'Cámara Frontal (ADASH)', 'active' => true],
            ['id' => 2, 'name' => 'Cámara de Cabina (DSM)', 'active' => true],
            ['id' => 3, 'name' => 'Vista Trasera', 'active' => false],
            ['id' => 4, 'name' => 'Carga', 'active' => false],
        ];

        return view('mdvr.monitoring', compact('devices', 'cameras'));
    }
}
