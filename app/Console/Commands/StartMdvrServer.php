<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, $address, $port);
        socket_listen($socket);
        $this->info("[MDVR] Servidor iniciado en $port");

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                        $this->warn("[CONN] Nueva conexión");
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) $this->processBuffer($s, $input);
                        else {
                            socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $bytes = array_values(unpack('C*', $input));

        // 1. Quitar escapes 0x7D
        $data = [];
        for ($i = 0; $i < count($bytes); $i++) {
            if ($bytes[$i] === 0x7D && isset($bytes[$i + 1])) {
                if ($bytes[$i + 1] === 0x01) {
                    $data[] = 0x7D;
                    $i++;
                } elseif ($bytes[$i + 1] === 0x02) {
                    $data[] = 0x7E;
                    $i++;
                }
            } else {
                $data[] = $bytes[$i];
            }
        }

        if (count($data) < 15) return;

        // Quitar 7E inicial y final y el Checksum (último byte antes del 7E)
        $payload = array_slice($data, 1, -2);

        // HEADER 2019: ID(2) + ATTR(2) + VER(1) + PHONE(10) + SERIAL(2) = 17 bytes
        $msgId = ($payload[0] << 8) | $payload[1];
        $terminalSerial = ($payload[15] << 8) | $payload[16];
        $phoneRaw = array_slice($payload, 5, 10);

        $this->line("<fg=gray>[RECV]</> ID: 0x" . sprintf('%04X', $msgId) . " | Serial: $terminalSerial");

        if ($msgId === 0x0100) {
            $this->respondRegistration($socket, $phoneRaw, $terminalSerial);
        }
    }

    private function respondRegistration($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";

        // 1. CUERPO (Tabla 3.3.2): Serial Terminal (2) + Resultado (1) + Auth Code (Str)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            0x00, // 0: Success
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        // 2. PROPIEDADES (Tabla 2.2.2.1)
        $bodyLen = count($body);
        $attr = (1 << 14) | $bodyLen; // Bit 14: Version 2019, Bits 0-9: Len

        // 3. HEADER (Tabla 2.2.2)
        $header = [
            0x81,
            0x00,              // Message ID
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF), // Message Body Attributes
            0x01,                    // Protocol Version (2019)
        ];

        // TELÉFONO: El manual dice BCD[10]. 
        // Si phoneRaw ya trae 10 bytes del RECV, los usamos directo.
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        // SERIAL DEL MENSAJE (Del Servidor)
        // Nota: El equipo puede ser sensible a que este serial sea 0 o empiece en 1.
        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial++;

        // 4. PAQUETE COMPLETO
        $full = array_merge($header, $body);

        // 5. CHECK CODE (XOR de todos los bytes entre 7E)
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;

        // 6. ESCAPE (Tabla 2.2.1)
        $final = [0x7E];
        foreach ($full as $b) {
            if ($b === 0x7E) {
                $final[] = 0x7D;
                $final[] = 0x02;
            } elseif ($b === 0x7D) {
                $final[] = 0x7D;
                $final[] = 0x01;
            } else {
                $final[] = $b;
            }
        }
        $final[] = 0x7E;

        // 7. ENVÍO
        $binary = pack('C*', ...$final);
        socket_write($socket, $binary, strlen($binary));

        $this->info("[SEND] " . implode(' ', array_map(fn($b) => sprintf('%02X', $b), $final)));
    }
}
