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
        // 0x0704 es Carga por Lotes de Posición (Batch Location)
        // Estructura: [Count(2)] [Tipo(1)] [Len(2)] [Data...] ...
        // Pero primero necesitamos convertir el Hex a binario
        $data = hex2bin($this->bodyHex);
        $pos = 0;
        $len = strlen($data);

        // 1. Número de items (Word)
        if ($len < 2) return;
        $count = unpack('n', substr($data, $pos, 2))[1];
        $pos += 2;

        // 2. Tipo de ubicación (Byte) - 0: Normal, 1: Alarma (normalmente 0)
        if ($pos + 1 > $len) return;
        $locationType = ord($data[$pos]);
        $pos += 1;

        \Illuminate\Support\Facades\Log::info("ProcessMdvrLocation: Phone {$this->phone} | Items: $count | Type: $locationType");

        for ($i = 0; $i < $count; $i++) {
            if ($pos + 2 > $len) break;
            
            // Longitud del bloque de ubicación (Word)
            $itemLen = unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;

            if ($pos + $itemLen > $len) break;

            // Extraer bloque de ubicación (JTT808 0x0200 Standard Body)
            // Se asume que el bloque interno sigue la estructura de 0x0200 (28 bytes mínimo)
            $geoData = substr($data, $pos, $itemLen);
            $this->parseLocationItem($geoData);
            
            $pos += $itemLen;
        }
    }

    private function parseLocationItem($binaryData)
    {
        if (strlen($binaryData) < 28) return;

        // Estructura 0x0200:
        // [Alarm:4] [Status:4] [Lat:4] [Lon:4] [Alt:2] [Speed:2] [Dir:2] [Time:6]
        
        $fields = unpack('Nalarm/Nstatus/Nlat/Nlon/nalt/nspeed/ndir', substr($binaryData, 0, 26));
        
        $status = $fields['status'];
        $lat = $fields['lat'] / 1000000;
        $lon = $fields['lon'] / 1000000;

        // Bit 2: 0=North, 1=South (South is negative)
        if ($status & 0x00000004) {
            $lat = $lat * -1;
        }

        // Bit 3: 0=East, 1=West (West is negative)
        // Para México (Oeste), este bit debería estar en 1.
        if ($status & 0x00000008) {
            $lon = $lon * -1;
        }
        
        $speed = $fields['speed'] / 10; // En km/h
        
        // Time BCD (6 bytes)
        $timeRaw = substr($binaryData, 24, 6);
        $timeStr = implode('', unpack('H*', $timeRaw)); // "240123164454"
        $datetime = sprintf('20%s-%s-%s %s:%s:%s',
            substr($timeStr, 0, 2), substr($timeStr, 2, 2), substr($timeStr, 4, 2),
            substr($timeStr, 6, 2), substr($timeStr, 8, 2), substr($timeStr, 10, 2)
        );

        \Illuminate\Support\Facades\Log::info("   -> GPS: Lat: $lat, Lon: $lon, Speed: $speed, Time: $datetime");
    }
}
