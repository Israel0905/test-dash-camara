<?php

namespace App\Services\MDVR;

/**
 * Message Builder for JTT808/JTT1078 Protocol
 *
 * Builds response messages to send to MDVR devices
 */
class MessageBuilder
{
    private string $serverPhoneNumber;

    private int $serialNumber = 0;

    public function __construct(string $serverPhoneNumber = '00000000000000000000')
    {
        $this->serverPhoneNumber = $serverPhoneNumber;
    }

    /**
     * Build a complete message with delimiters
     */
    public function buildMessage(int $messageId, array $body, string $phoneNumber, ?int $replySerialNumber = null): array
    {
        $phoneBcd = ProtocolHelper::phoneNumberToBcd($phoneNumber, 10);
        return $this->buildMessageWithRawPhone($messageId, $body, $phoneBcd, $replySerialNumber);
    }

    /**
     * Build a complete message using raw phone bytes (preserves exact device identity)
     */
    public function buildMessageWithRawPhone(int $messageId, array $body, array $phoneRawBytes, ?int $replySerialNumber = null): array
    {
        $header = $this->buildHeaderWithRawPhone($messageId, count($body), $phoneRawBytes, $replySerialNumber);
        $content = array_merge($header, $body);

        // Calculate checksum
        $checksumStr = ProtocolHelper::bytesToHexString($content);
        $totalBytes = count($content);

        echo PHP_EOL;
        echo "╔════════════ STRICT OFFSET DEBUG (JTT808-2019) ════════════╗" . PHP_EOL;
        echo "║ Full Hex : {$checksumStr}" . PHP_EOL;
        echo "╠═══════════════════════════════════════════════════════════╣" . PHP_EOL;

        // Analyze offsets (Assumes Standard 2019 Header is 17 bytes)
        // Header: 0-3 (MsgId+Props)
        echo "║ [00-03] Header Fijo    : " . ProtocolHelper::bytesToHexString(array_slice($content, 0, 4)) . PHP_EOL;
        // Version: 4
        echo "║ [04]    Version Byte   : " . sprintf("%02X", $content[4]) . " (Expect 01)" . PHP_EOL;
        // Phone: 5-14
        echo "║ [05-14] Phone No       : " . ProtocolHelper::bytesToHexString(array_slice($content, 5, 10)) . PHP_EOL;
        // Server Serial: 15-16
        echo "║ [15-16] Server Serial  : " . ProtocolHelper::bytesToHexString(array_slice($content, 15, 2)) . PHP_EOL;

        // Body Starts at 17
        echo "╠══════════════════════ BODY (Start 17) ════════════════════╣" . PHP_EOL;
        if ($totalBytes > 17) {
            // Reply Serial (2 bytes)
            echo "║ [17-18] Reply Serial   : " . ProtocolHelper::bytesToHexString(array_slice($content, 17, 2)) . PHP_EOL;
            // Result (1 byte)
            if (isset($content[19])) {
                echo "║ [19]    Result Code    : " . sprintf("%02X", $content[19]) . " (Expect 00)" . PHP_EOL;
            }
            // Auth Len (1 byte) - Standard
            if (isset($content[20])) {
                echo "║ [20]    Auth Code Len  : " . sprintf("%02X", $content[20]) . PHP_EOL;
            }
            // Auth Code
            if ($totalBytes > 21) {
                echo "║ [21+]   Auth Code      : " . ProtocolHelper::bytesToHexString(array_slice($content, 21)) . PHP_EOL;
            }
        }

        $checksum = ProtocolHelper::calculateChecksum($content);
        echo "╠═══════════════════════════════════════════════════════════╣" . PHP_EOL;
        echo "║ Calc Checksum : " . sprintf("%02X", $checksum) . PHP_EOL;
        echo "╚═══════════════════════════════════════════════════════════╝" . PHP_EOL;

        $content[] = $checksum;

        // Escape content
        $escaped = ProtocolHelper::escape($content);

        // Add delimiters
        return array_merge(
            [ProtocolHelper::START_DELIMITER],
            $escaped,
            [ProtocolHelper::START_DELIMITER]
        );
    }


    /**
     * Build message header using raw phone bytes (JTT808-2019: 17+ bytes total)
     * Format: MsgID(2) + Props(2) + Version(1) + Phone(10) + Serial(2)
     */
    private function buildHeaderWithRawPhone(int $messageId, int $bodyLength, array $phoneRawBytes, ?int $serialNumber): array
    {
        // Message ID (2 bytes)
        $header = [
            ($messageId >> 8) & 0xFF,
            $messageId & 0xFF,
        ];

        // Propiedades (2 bytes)
        // Bit 14: Siempre 1 para versión 2019
        // Bits 0-9: Longitud real del cuerpo
        $properties = 0x4000 | ($bodyLength & 0x03FF);

        $header[] = ($properties >> 8) & 0xFF;
        $header[] = $properties & 0xFF;

        // Protocol version (1 byte)
        $header[] = 0x01;

        // Forzar 10 bytes de teléfono (BCD)
        $phone10Bytes = array_pad(array_slice($phoneRawBytes, -10), -10, 0x00);
        $header = array_merge($header, $phone10Bytes);

        // Serial del servidor (2 bytes)
        $serial = $serialNumber ?? $this->getNextSerialNumber();
        $header[] = ($serial >> 8) & 0xFF;
        $header[] = $serial & 0xFF;

        return $header;
    }

    /**
     * Get next serial number
     */
    private function getNextSerialNumber(): int
    {
        $this->serialNumber = ($this->serialNumber + 1) & 0xFFFF;

        return $this->serialNumber;
    }

    /**
     * Build General Response (0x8001)
     */
    public function buildGeneralResponse(string $phoneNumber, int $replySerial, int $replyMessageId, int $result = 0): array
    {
        $body = [
            ($replySerial >> 8) & 0xFF,
            $replySerial & 0xFF,
            ($replyMessageId >> 8) & 0xFF,
            $replyMessageId & 0xFF,
            $result & 0xFF, // 0: success, 1: failure, 2: message error, 3: not supported
        ];

        return $this->buildMessage(ProtocolHelper::MSG_SERVER_GENERAL_RESPONSE, $body, $phoneNumber);
    }

    /**
     * Build Registration Response (0x8100)
     * Format:
     * - Byte 0-1: Reply serial number (WORD)
     * - Byte 2: Result (BYTE) - 0x00 for success
     * - Byte 3+: Authentication code (STRING)
     */

    public function buildRegistrationResponseWithRawPhone(array $phoneRawBytes, int $replySerial, int $result, string $authCode = ''): array
    {
        // 1. Construir el Cuerpo (BODY)
        $body = [
            ($replySerial >> 8) & 0xFF, // WORD: Serial del mensaje del equipo
            $replySerial & 0xFF,
            $result & 0xFF,             // BYTE: 0 para éxito
        ];

        if ($result === 0) {
            $authBytes = array_values(unpack('C*', $authCode));
            $body[] = count($authBytes); // BYTE: Longitud exacta (Ej: 12 -> 0x0C)
            $body = array_merge($body, $authBytes);
        }

        // 2. Construir el mensaje completo
        // Asegúrate de que buildMessageWithRawPhone use count($body) para el header
        return $this->buildMessageWithRawPhone(0x8100, $body, $phoneRawBytes);
    }


    /**
     * Build Query Resources Request (0x9205)
     */
    public function buildQueryResourcesRequest(
        string $phoneNumber,
        int $channel,
        string $startTime, // YYMMDDHHMMSS
        string $endTime,   // YYMMDDHHMMSS
        int $resourceType = 0, // 0: audio+video, 1: audio, 2: video
        int $streamType = 0,   // 0: all, 1: main, 2: sub
        int $storageType = 0   // 0: all
    ): array {
        $body = [
            $channel & 0xFF, // Channel number
        ];

        // Start time (6 bytes BCD)
        $body = array_merge($body, ProtocolHelper::stringToBcd($startTime, 6));

        // End time (6 bytes BCD)
        $body = array_merge($body, ProtocolHelper::stringToBcd($endTime, 6));

        // Alarm flags (8 bytes) - 0 means search all
        $body = array_merge($body, [0, 0, 0, 0, 0, 0, 0, 0]);

        // Resource type, stream type, storage type
        $body[] = $resourceType & 0xFF;
        $body[] = $streamType & 0xFF;
        $body[] = $storageType & 0xFF;

        return $this->buildMessage(ProtocolHelper::MSG_QUERY_RESOURCES, $body, $phoneNumber);
    }

    /**
     * Build Alarm Attachment Request (0x9208)
     */
    public function buildAlarmAttachmentRequest(
        string $phoneNumber,
        string $attachmentServerIp,
        int $tcpPort,
        int $udpPort,
        array $alarmIdentification, // 16 bytes
        string $alarmNumber         // 32 bytes unique number
    ): array {
        $ipBytes = array_values(unpack('C*', $attachmentServerIp));

        $body = [
            count($ipBytes) & 0xFF, // IP length
        ];
        $body = array_merge($body, $ipBytes);

        // TCP port (2 bytes)
        $body[] = ($tcpPort >> 8) & 0xFF;
        $body[] = $tcpPort & 0xFF;

        // UDP port (2 bytes)
        $body[] = ($udpPort >> 8) & 0xFF;
        $body[] = $udpPort & 0xFF;

        // Alarm identification (16 bytes)
        $body = array_merge($body, array_slice($alarmIdentification, 0, 16));

        // Alarm number (32 bytes)
        $alarmNumBytes = array_values(unpack('C*', str_pad($alarmNumber, 32, "\x00")));
        $body = array_merge($body, array_slice($alarmNumBytes, 0, 32));

        // Reserved (16 bytes)
        $body = array_merge($body, array_fill(0, 16, 0));

        return $this->buildMessage(ProtocolHelper::MSG_ALARM_ATTACHMENT_REQUEST, $body, $phoneNumber);
    }

    /**
     * Build Video Request (0x9101)
     */
    public function buildVideoRequest(
        string $phoneNumber,
        string $serverIp,
        int $tcpPort,
        int $udpPort,
        int $channel,
        int $dataType = 0, // 0: audio+video, 1: video, 2: intercom, 3: monitor
        int $streamType = 0 // 0: main, 1: sub
    ): array {
        $ipBytes = array_values(unpack('C*', $serverIp));

        $body = [
            count($ipBytes) & 0xFF, // IP length
        ];
        $body = array_merge($body, $ipBytes);

        // TCP port (2 bytes)
        $body[] = ($tcpPort >> 8) & 0xFF;
        $body[] = $tcpPort & 0xFF;

        // UDP port (2 bytes)
        $body[] = ($udpPort >> 8) & 0xFF;
        $body[] = $udpPort & 0xFF;

        // Channel, data type, stream type
        $body[] = $channel & 0xFF;
        $body[] = $dataType & 0xFF;
        $body[] = $streamType & 0xFF;

        return $this->buildMessage(ProtocolHelper::MSG_VIDEO_REQUEST, $body, $phoneNumber);
    }

    /**
     * Build Playback Request (0x9201)
     */
    public function buildPlaybackRequest(
        string $phoneNumber,
        string $serverIp,
        int $tcpPort,
        int $udpPort,
        int $channel,
        string $startTime, // YYMMDDHHMMSS
        string $endTime,   // YYMMDDHHMMSS
        int $dataType = 0,     // 0: audio+video
        int $streamType = 0,   // 0: main
        int $storageType = 0,  // 0: all
        int $playbackMode = 0, // 0: normal
        int $speedMultiplier = 0xFF // 0xFF: fastest download
    ): array {
        $ipBytes = array_values(unpack('C*', $serverIp));

        $body = [
            count($ipBytes) & 0xFF,
        ];
        $body = array_merge($body, $ipBytes);

        // Ports
        $body[] = ($tcpPort >> 8) & 0xFF;
        $body[] = $tcpPort & 0xFF;
        $body[] = ($udpPort >> 8) & 0xFF;
        $body[] = $udpPort & 0xFF;

        // Channel and types
        $body[] = $channel & 0xFF;
        $body[] = $dataType & 0xFF;
        $body[] = $streamType & 0xFF;
        $body[] = $storageType & 0xFF;
        $body[] = $playbackMode & 0xFF;
        $body[] = $speedMultiplier & 0xFF;

        // Times
        $body = array_merge($body, ProtocolHelper::stringToBcd($startTime, 6));
        $body = array_merge($body, ProtocolHelper::stringToBcd($endTime, 6));

        return $this->buildMessage(ProtocolHelper::MSG_PLAYBACK_REQUEST, $body, $phoneNumber);
    }

    /**
     * Build Terminal Control (0x8105)
     */
    public function buildTerminalControl(string $phoneNumber, int $command): array
    {
        // Commands: 0x70: cut oil, 0x71: restore oil, 0x72: cut circuit, 0x73: restore circuit, 0x74: restart
        $body = [$command & 0xFF];

        return $this->buildMessage(ProtocolHelper::MSG_TERMINAL_CONTROL, $body, $phoneNumber);
    }

    /**
     * Build Text Message (0x8300)
     */
    public function buildTextMessage(string $phoneNumber, string $message, int $flag = 0x09, int $textType = 1): array
    {
        $body = [
            $flag & 0xFF,
            $textType & 0xFF,
        ];

        // Convert message to GBK encoding (or just UTF-8 bytes for now)
        $messageBytes = array_values(unpack('C*', mb_convert_encoding($message, 'GBK', 'UTF-8')));
        $body = array_merge($body, $messageBytes);

        return $this->buildMessage(ProtocolHelper::MSG_TEXT_MESSAGE, $body, $phoneNumber);
    }

    /**
     * Build File Upload Complete Response (0x9212)
     */
    public function buildFileCompleteResponse(
        string $phoneNumber,
        string $filename,
        int $fileType,
        int $result,
        array $retransmitPackets = []
    ): array {
        $filenameBytes = array_values(unpack('C*', $filename));

        $body = [
            count($filenameBytes) & 0xFF,
        ];
        $body = array_merge($body, $filenameBytes);
        $body[] = $fileType & 0xFF;
        $body[] = $result & 0xFF; // 0x00: complete, 0x01: need retransmit
        $body[] = count($retransmitPackets) & 0xFF;

        // Retransmit packet info (if any)
        foreach ($retransmitPackets as $packet) {
            // Each packet has offset (4 bytes) and length (4 bytes)
            $body[] = ($packet['offset'] >> 24) & 0xFF;
            $body[] = ($packet['offset'] >> 16) & 0xFF;
            $body[] = ($packet['offset'] >> 8) & 0xFF;
            $body[] = $packet['offset'] & 0xFF;
            $body[] = ($packet['length'] >> 24) & 0xFF;
            $body[] = ($packet['length'] >> 16) & 0xFF;
            $body[] = ($packet['length'] >> 8) & 0xFF;
            $body[] = $packet['length'] & 0xFF;
        }

        return $this->buildMessage(ProtocolHelper::MSG_FILE_COMPLETE_RESPONSE, $body, $phoneNumber);
    }

    /**
     * Convert message array to binary string for sending
     */
    public static function toBytes(array $message): string
    {
        return pack('C*', ...$message);
    }

    /**
     * Convert message to hex string for debugging
     */
    public static function toHex(array $message): string
    {
        return ProtocolHelper::bytesToHexString($message);
    }
}
