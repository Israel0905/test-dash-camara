<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrVideoServer extends Command
{
    protected $signature = 'mdvr:video {--port=8809}';
    protected $description = 'Servidor JT/T 1078 para recibir Video - Puerto Secundario';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($socket, $address, $port)) {
            $this->error("Error: Puerto $port ocupado.");
            return;
        }

        socket_listen($socket);
        $this->info('=====================================================');
        $this->info("[VIDEO SERVER] ESCUCHANDO EN PUERTO $port");
        $this->info('=====================================================');

        $clients = [$socket];
        $totalBytes = 0;

        while (true) {
            $read = $clients;
            $write = $except = null;
            
            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        if ($newSocket) {
                            $clients[] = $newSocket;
                            socket_getpeername($newSocket, $ip);
                            $this->warn("[CONN] Conexión de video desde: $ip");
                        }
                    } else {
                        $input = @socket_read($s, 65535);
                        if ($input) {
                            $bytes = strlen($input);
                            $totalBytes += $bytes;
                            $rawHex = strtoupper(bin2hex(substr($input, 0, 50))); // Solo primeros 50 bytes
                            
                            $this->line("\n<fg=magenta>[VIDEO DATA]</> Recibidos: $bytes bytes (Total: $totalBytes bytes)");
                            $this->line("<fg=gray>Preview HEX:</> " . implode(' ', str_split($rawHex, 2)) . "...");
                            
                            // Detectar tipo de datos
                            $firstByte = ord($input[0]);
                            if ($firstByte === 0x30) {
                                $this->info("   ↳ Tipo: RTP Video Stream (0x30)");
                            } elseif ($firstByte === 0x31) {
                                $this->info("   ↳ Tipo: RTP Audio Stream (0x31)");
                            } elseif ($firstByte === 0x7E) {
                                $this->comment("   ↳ Tipo: JTT808 Control Message");
                                // Si es mensaje de control, responder
                                $this->handleControlMessage($s, $input);
                            } else {
                                $this->comment("   ↳ Tipo: Raw Data (0x" . sprintf('%02X', $firstByte) . ")");
                            }
                        } else {
                            @socket_close($s);
                            $key = array_search($s, $clients);
                            if ($key !== false) unset($clients[$key]);
                            $this->error('[DESC] Conexión de video cerrada.');
                        }
                    }
                }
            }
        }
    }

    private function handleControlMessage($socket, $input)
    {
        $bytes = array_values(unpack('C*', $input));
        
        // Unescape
        $data = [];
        for ($i = 0; $i < count($bytes); $i++) {
            if ($bytes[$i] === 0x7D && isset($bytes[$i + 1])) {
                if ($bytes[$i + 1] === 0x01) { $data[] = 0x7D; $i++; }
                elseif ($bytes[$i + 1] === 0x02) { $data[] = 0x7E; $i++; }
            } else {
                $data[] = $bytes[$i];
            }
        }

        if (count($data) < 15) return;

        $payload = array_slice($data, 1, -2);
        $msgId = ($payload[0] << 8) | $payload[1];
        $phoneRaw = array_slice($payload, 5, 10);
        $devSerial = ($payload[15] << 8) | $payload[16];

        $this->info("   [CTRL] MSG: 0x" . sprintf('%04X', $msgId) . " | Serial: $devSerial");

        // Enviar ACK genérico
        $this->sendAck($socket, $phoneRaw, $devSerial, $msgId);
    }

    private function sendAck($socket, $phoneRaw, $devSerial, $replyMsgId)
    {
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00,
        ];

        $bodyLen = count($body);
        $attr = 0x4000 | $bodyLen;

        $header = [0x80, 0x01, ($attr >> 8) & 0xFF, $attr & 0xFF, 0x01];
        foreach ($phoneRaw as $b) { $header[] = $b; }
        
        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);
        $cs = 0;
        foreach ($full as $byte) { $cs ^= $byte; }
        $full[] = $cs;

        $final = [0x7E];
        foreach ($full as $b) {
            if ($b === 0x7E) { $final[] = 0x7D; $final[] = 0x02; }
            elseif ($b === 0x7D) { $final[] = 0x7D; $final[] = 0x01; }
            else { $final[] = $b; }
        }
        $final[] = 0x7E;

        @socket_write($socket, pack('C*', ...$final));
        $this->line("<fg=green>   [ACK SENT]</> 0x8001");
    }
}
