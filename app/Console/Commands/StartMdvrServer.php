<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 Nativo para Ultravision N6 (Debug Mode)';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($socket, $address, $port)) {
            $this->error("Error: No se pudo enlazar al puerto $port");
            return;
        }

        socket_listen($socket);
        $this->info("=====================================================");
        $this->info("[MDVR] SERVIDOR INICIADO EN EL PUERTO $port");
        $this->info("=====================================================");

        $clients = [$socket];

        while (true) {
            $read = $clients;
            $write = $except = null;

            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        socket_getpeername($newSocket, $ip);
                        $this->warn("[CONN] Nueva conexión desde: $ip");
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input === false || $input === '') {
                            $key = array_search($s, $clients);
                            unset($clients[$key]);
                            socket_close($s);
                            $this->error("[DISC] Cliente desconectado");
                            continue;
                        }

                        $this->handleRawInput($s, $input);
                    }
                }
            }
        }
    }

    private function handleRawInput($socket, $input)
    {
        $bytes = array_values(unpack('C*', $input));
        $hex = $this->bytesToHex($bytes);

        $this->line("<fg=gray>[RECV RAW]</> $hex");

        // 1. Unescape (Eliminar procesamiento de escape 0x7D)
        $unescaped = [];
        for ($i = 0; $i < count($bytes); $i++) {
            if ($bytes[$i] === 0x7D) {
                if (isset($bytes[$i + 1]) && $bytes[$i + 1] === 0x01) {
                    $unescaped[] = 0x7D;
                    $i++;
                } elseif (isset($bytes[$i + 1]) && $bytes[$i + 1] === 0x02) {
                    $unescaped[] = 0x7E;
                    $i++;
                }
            } else {
                $unescaped[] = $bytes[$i];
            }
        }

        // 2. Validar estructura básica
        if (count($unescaped) < 15 || $unescaped[0] !== 0x7E || end($unescaped) !== 0x7E) {
            $this->error("[ERR] Paquete malformado o incompleto");
            return;
        }

        // 3. Extraer contenido (sin los delimitadores 7E)
        $content = array_slice($unescaped, 1, -1);
        $checkCodeReceived = array_pop($content);

        // 4. Verificar Checksum (XOR)
        $checksum = 0;
        foreach ($content as $b) {
            $checksum ^= $b;
        }

        if ($checksum !== $checkCodeReceived) {
            $this->error("[ERR] Checksum fallido. Calculado: " . sprintf('%02X', $checksum) . " Recibido: " . sprintf('%02X', $checkCodeReceived));
            return;
        }

        // 5. PARSEAR HEADER SEGÚN MANUAL 2019 (Tabla 2.2.2)
        $msgId = ($content[0] << 8) | $content[1];
        $attr = ($content[2] << 8) | $content[3];
        $isVersion2019 = ($attr >> 14) & 0x01;
        $bodyLen = $attr & 0x03FF;

        $ptr = 4;
        $version = 0;
        if ($isVersion2019) {
            $version = $content[$ptr];
            $ptr++; // Byte de versión
        }

        // Detectar si el teléfono es de 6 o 10 bytes (Tu log muestra 6 aunque sea 2019)
        // El serial siempre son los últimos 2 bytes del header.
        // Si el body empieza después, restamos.
        // HEADER = MsgID(2) + Attr(2) + [Ver(1)] + Phone(?) + Serial(2)
        $phoneLen = ($isVersion2019) ? (count($content) - $bodyLen - 7) : (count($content) - $bodyLen - 6);
        // Ajuste forzado: Si el manual dice 10 pero vienen menos, lo detectamos:
        $phoneRaw = array_slice($content, $ptr, $phoneLen);
        $ptr += $phoneLen;

        $serial = ($content[$ptr] << 8) | $content[$ptr + 1];
        $ptr += 2;

        $body = array_slice($content, $ptr);

        $this->info(sprintf(
            "[MSG] ID: 0x%04X | Serial: %d | BodyLen: %d | Ver: %d | Phone: %s",
            $msgId,
            $serial,
            $bodyLen,
            $version,
            $this->bytesToHex($phoneRaw)
        ));

        // 6. LOGICA DE RESPUESTA
        if ($msgId === 0x0100) {
            $this->sendRegistrationResponse($socket, $phoneRaw, $serial);
        } elseif ($msgId === 0x0102) {
            $this->sendGeneralResponse($socket, $phoneRaw, $serial, 0x0102);
            $this->info("<fg=green;options=bold>[OK] EQUIPO AUTENTICADO EXITOSAMENTE!</>");
        }
    }

    private function sendRegistrationResponse($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";
        $this->comment("[WORK] Generando respuesta de registro 0x8100...");

        // CUERPO (Tabla 3.3.2)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            0x00, // Resultado 0 = Éxito
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        $this->buildAndSend($socket, 0x8100, $phoneRaw, $body);
    }

    private function sendGeneralResponse($socket, $phoneRaw, $terminalSerial, $replyId)
    {
        // CUERPO 0x8001 (Tabla 3.1.2)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            0x00, // OK
        ];
        $this->buildAndSend($socket, 0x8001, $phoneRaw, $body);
    }

    private function buildAndSend($socket, $msgId, $phoneRaw, $body)
    {
        $bodyLen = count($body);
        $properties = (1 << 14) | $bodyLen; // Bit 14 para modo 2019

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($properties >> 8) & 0xFF,
            $properties & 0xFF,
            0x01, // Protocol Version
        ];

        // Usamos el teléfono tal cual lo mandó el equipo (Asumimos 6 bytes del log)
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $serverSerial = 1;
        $header[] = ($serverSerial >> 8) & 0xFF;
        $header[] = $serverSerial & 0xFF;
        $serverSerial++;

        $full = array_merge($header, $body);

        // Checksum
        $checksum = 0;
        foreach ($full as $b) {
            $checksum ^= $b;
        }
        $full[] = $checksum;

        // Escape Processing
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

        $binary = pack('C*', ...$final);
        socket_write($socket, $binary, strlen($binary));

        $this->info("<fg=cyan>[SEND]</> 0x" . sprintf('%04X', $msgId) . ": " . $this->bytesToHex($final));
    }

    private function bytesToHex($bytes)
    {
        return implode(' ', array_map(function ($b) {
            return sprintf('%02X', $b);
        }, $bytes));
    }
}
