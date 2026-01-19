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
        $checksum = ProtocolHelper::calculateChecksum($content);
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
     * Build message header (17 bytes for JTT808-2019)
     */
    private function buildHeader(int $messageId, int $bodyLength, string $phoneNumber, ?int $serialNumber): array
    {
        $phoneBcd = ProtocolHelper::phoneNumberToBcd($phoneNumber, 10);
        return $this->buildHeaderWithRawPhone($messageId, $bodyLength, $phoneBcd, $serialNumber);
    }

    /**
     * Build message header using raw phone bytes (17 bytes for JTT808-2019)
     */
    private function buildHeaderWithRawPhone(int $messageId, int $bodyLength, array $phoneRawBytes, ?int $serialNumber): array
    {
        // Message ID (2 bytes)
        $header = [
            ($messageId >> 8) & 0xFF,
            $messageId & 0xFF,
        ];

        // Properties (2 bytes)
        // Bit 0-9: body length, Bit 10-12: encryption (0), Bit 13: multi-packet (0), Bit 14: version flag (1)
        $properties = ($bodyLength & 0x03FF) | (1 << 14); // Version flag = 1 for JTT808-2019
        $header[] = ($properties >> 8) & 0xFF;
        $header[] = $properties & 0xFF;

        // Protocol version (1 byte) - 1 for JTT808-2019
        $header[] = 0x01;

        // Phone number (EXACTLY 10 bytes) - use raw bytes directly
        // CRITICAL: Must be exactly 10 bytes or message will be misaligned
        $phone10Bytes = array_slice($phoneRawBytes, 0, 10);
        if (count($phone10Bytes) < 10) {
            // Pad with zeros at the beginning if needed
            $phone10Bytes = array_merge(array_fill(0, 10 - count($phone10Bytes), 0x00), $phone10Bytes);
        }
        $header = array_merge($header, $phone10Bytes);

        // Serial number (2 bytes)
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
    public function buildRegistrationResponse(string $phoneNumber, int $replySerial, int $result, string $authCode = ''): array
    {
        $phoneBcd = ProtocolHelper::phoneNumberToBcd($phoneNumber, 10);
        return $this->buildRegistrationResponseWithRawPhone($phoneBcd, $replySerial, $result, $authCode);
    }

    /**
     * Build Registration Response (0x8100) using raw phone bytes
     * ULV Format per Table 3.3.2:
     * - Byte 0-1: Reply serial number (WORD)
     * - Byte 2: Result (BYTE)
     * - Byte 3: Padding (0x00)
     * - Byte 4+: Authentication code (STRING data)
     */
    public function buildRegistrationResponseWithRawPhone(array $phoneRawBytes, int $replySerial, int $result, string $authCode = ''): array
    {
        $body = [
            ($replySerial >> 8) & 0xFF,  // Byte 0
            $replySerial & 0xFF,          // Byte 1
            $result & 0xFF,               // Byte 2
            0x00,                         // Byte 3 - ULV Padding
        ];

        // Auth code starts at byte 4 per ULV Table 3.3.2
        if ($result === 0 && !empty($authCode)) {
            $authBytes = array_values(unpack('C*', $authCode));
            $body = array_merge($body, $authBytes);
        }

        return $this->buildMessageWithRawPhone(ProtocolHelper::MSG_REGISTRATION_RESPONSE, $body, $phoneRawBytes);
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
