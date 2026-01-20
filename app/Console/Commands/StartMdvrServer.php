<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Debug Header';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, $address, $port);
        socket_listen($socket);
        $this->info("=====================================================");
        $this->info("[MDVR] Servidor iniciado en $port");
        $this->info("=====================================================");

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
                            $this->error("[DISC] Cliente desconectado");
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $bytes = array_values(unpack('C*', $input));

        // 1. Unescape
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

        // Payload sin 7E y sin Checksum
        $payload = array_slice($data, 1, -2);

        // Según manual 2019: ID(2) + Attr(2) + Ver(1) + Phone(10) + Serial(2) = 17 bytes de Header
        $msgId = ($payload[0] << 8) | $payload[1];
        $terminalSerial = ($payload[15] << 8) | $payload[16];
        $phoneRaw = array_slice($payload, 5, 10);

        $this->line("<fg=gray>[RECV RAW]</> " . $this->bytesToHex($bytes));
        $this->info("[MSG] ID: 0x" . sprintf('%04X', $msgId) . " | Serial: $terminalSerial | Phone: " . $this->bytesToHex($phoneRaw));

        if ($msgId === 0x0100) {
            $this->respondRegistration($socket, $phoneRaw, $terminalSerial);
        }
    }

    private function respondRegistration($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";

        // 1. CUERPO (Tabla 3.3.2)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            0x00, // Success
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        // 2. HEADER (Tabla 2.2.2)
        $bodyLen = count($body);
        $attr = (1 << 14) | $bodyLen; // Protocolo 2019 habilitado

        $header = [
            0x81,
            0x00,                          // Msg ID
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF), // Attr
            0x01,                                // Version
        ];

        // El teléfono debe ocupar 10 bytes exactos
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial++;

        // --- IMPRESIÓN DE DEBUG PARA VALIDACIÓN ---
        $this->comment("--- Detalle del Header de Respuesta ---");
        $this->line("Header (17 bytes): " . $this->bytesToHex($header));
        $this->line("Cuerpo (" . count($body) . " bytes): " . $this->bytesToHex($body));
        // ------------------------------------------

        $full = array_merge($header, $body);

        // Checksum
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;

        // Escape
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

        socket_write($socket, pack('C*', ...$final));
        $this->info("[SEND FINAL] " . $this->bytesToHex($final));
    }

    private function bytesToHex($bytes)
    {
        return implode(' ', array_map(fn($b) => sprintf('%02X', $b), $bytes));
    }
}
