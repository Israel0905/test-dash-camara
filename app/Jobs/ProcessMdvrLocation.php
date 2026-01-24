<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMdvrLocation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $phone;

    public $bodyHex;

    /**
     * Create a new job instance.
     */
    public function __construct($phone, $bodyHex)
    {
        $this->phone = $phone;
        $this->bodyHex = $bodyHex;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Según tu log 0x0704 Batch Location:
        // Latitude está en los bytes 11-14 del item (hex chars 22 a 29)
        // Longitude está en los bytes 15-18 del item (hex chars 30 a 37)

        // 1. Extraer Latitud (Ej: 01367268 -> 20.345448)
        $latHex = substr($this->bodyHex, 22, 8);
        $lat = hexdec($latHex) / 1000000;

        // 2. Extraer Longitud (Ej: 06202480 -> 102.771840)
        $lonHex = substr($this->bodyHex, 30, 8);
        $lon = hexdec($lonHex) / 1000000;

        // En Ocotlán, México, la longitud es negativa
        $lon = $lon * -1;

        \Illuminate\Support\Facades\Log::info("MDVR $this->phone in Ocotlán: Lat $lat, Lon $lon");

        // 3. Guardar en tu tabla de posiciones (Optimizado: Solo última ubicación)
        \Illuminate\Support\Facades\DB::table('device_locations')->updateOrInsert(
            ['device_id' => $this->phone], // Buscamos por ID
            [
                'latitude' => $lat,
                'longitude' => $lon,
                'updated_at' => now(),
                // 'created_at' => now(), // Opcional: Si quieres mantener la fecha original de creación, muévelo al primer array si la tabla lo permite, o déjalo aquí para "refrescar" todo.
            ]
        );
    }
}
