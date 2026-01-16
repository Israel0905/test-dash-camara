<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MdvrDevice;
use App\Models\MdvrDeviceLocation;
use App\Models\MdvrDeviceAlarm;
use Carbon\Carbon;

class SimulateMdvrData extends Command
{
    protected $signature = 'mdvr:simulate {--deviceId=}';
    protected $description = 'Simulate MDVR data movement and alarms';

    public function handle()
    {
        $phone = '123456789012';
        $device = MdvrDevice::firstOrCreate(
            ['phone_number' => $phone],
            [
                'plate_number' => 'TEST-01',
                'terminal_id' => 'T12345',
                'is_online' => true,
                'last_heartbeat_at' => now(),
            ]
        );

        $this->info("Simulando datos para vehículo: {$device->plate_number}");
        
        // Coordenadas de ruta simulada (Querétaro example)
        $route = [
            [20.5880, -100.3880],
            [20.5920, -100.3900],
            [20.5950, -100.3920],
            [20.6000, -100.3950],
            [20.6050, -100.3980],
            [20.6100, -100.4000],
            [20.6150, -100.4050],
            [20.6200, -100.4100],
        ];

        foreach ($route as $index => $coords) {
            $lat = $coords[0];
            $lng = $coords[1];
            $speed = rand(60, 90);
            
            // Crear ubicación
            $loc = MdvrDeviceLocation::create([
                'device_id' => $device->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'speed' => $speed,
                'direction' => rand(0, 360),
                'altitude' => 1800,
                'status_flags' => 0,
                'device_time' => now(),
                'acc_on' => true,
            ]);

            // Actualizar dispositivo
            $device->update([
                'is_online' => true,
                'last_heartbeat_at' => now(),
            ]);

            $this->info("Moviendo... Lat: {$lat}, Lng: {$lng}, Vel: {$speed} km/h");
            
            // Simular alarma aleatoria
            if ($index === 3) {
                MdvrDeviceAlarm::create([
                    'device_id' => $device->id,
                    'alarm_type' => 0x65, // ADAS warning
                    'alarm_category' => 'ADAS',
                    'alarm_name' => 'Colisión Frontal',
                    'alarm_level' => 1,
                    'start_time' => now(),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'speed' => $speed,
                    'has_attachment' => false,
                ]);
                $this->error("🚨 ALARMA SIMULADA: Colisión Frontal");
            }

            sleep(2); // Esperar 2 segundos entre puntos
        }

        $this->info("Simulación terminada.");
    }
}
