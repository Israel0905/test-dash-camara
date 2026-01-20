<?php

namespace App\Services\MDVR;

/**
 * Message Builder for JT/T 808-2019 / JT/T 1078
 * Compatible with Ultravision MDVR
 */
class MessageBuilder
{
    private int $serialNumber = 0;

    /**
     * Build message using phone number string
     */
    public function buildMessage(
        int $messageId,
        array $body,
        string $phoneNumber,
        ?int $replySerialNumber = null
    ): array {
        $phoneBcd = ProtocolHelper::phoneNumberToBcd($phoneNumber, 10);
        return $this->buildMessageWithRawPhone($messageId, $body, $phoneBcd, $replySerialNumber);
    }

    /**
     * Build message using raw phone bytes (exact identity)
     */
    public function buildMessageWithRawPhone(
        int $messageId,
        array $body,
        array $phoneRawBytes,
        ?int $replySerialNumber = null
    ): array {
        $header = $this->buildHeader2019($messageId, count($body), $phoneRawBytes, $replySerialNumber);
        $content = array_merge($header, $body);

        // Debug (optional)
        /*
        echo PHP_EOL . "HEX: " . ProtocolHelper::bytesToHexString($content) . PHP_EOL;
        */

        // Checksum
        $checksum = ProtocolHelper::calculateChecksum($content);
        $content[] = $checksum;

        // Escape
        $escaped = ProtocolHelper::escape($content);

        // Wrap
        return array_merge(
            [ProtocolHelper::START_DELIMITER],
            $escaped,
            [ProtocolHelper::START_DELIMITER]
        );
    }

    /**
     * JT/T 808-2019 Header
     * MsgID(2) + Props(2) + Version(1) + Phone(10) + Serial(2)
     */
    private function buildHeader2019(
        int $messageId,
        int $bodyLength,
        array $phoneRawBytes,
        ?int $serialNumber
    ): array {
        $header = [];

        // Message ID
        $header[] = ($messageId >> 8) & 0xFF;
        $header[] = $messageId & 0xFF;

        // Properties (bit14 = 1 for 2019)
        $properties = 0x4000 | ($bodyLength & 0x03FF);
        $header[] = ($properties >> 8) & 0xFF;
        $header[] = $properties & 0xFF;

        // Protocol version
        $header[] = 0x01;

        // Phone (exact 10 bytes BCD)
        $phone10 = array_pad(array_slice($phoneRawBytes, -10), 10, 0x00);
        $header = array_merge($header, $phone10);

        // Serial
        $serial = $serialNumber ?? $this->nextSerial();
        $header[] = ($serial >> 8) & 0xFF;
        $header[] = $serial & 0xFF;

        return $header;
    }

    private function nextSerial(): int
    {
        $this->serialNumber = ($this->serialNumber + 1) & 0xFFFF;
        return $this->serialNumber;
    }

    /**
     * General Response (0x8001)
     */
    public function buildGeneralResponse(
        string $phoneNumber,
        int $replySerial,
        int $replyMessageId,
        int $result = 0
    ): array {
        $body = [
            ($replySerial >> 8) & 0xFF,
            $replySerial & 0xFF,
            ($replyMessageId >> 8) & 0xFF,
            $replyMessageId & 0xFF,
            $result & 0xFF,
        ];

        return $this->buildMessage(
            ProtocolHelper::MSG_SERVER_GENERAL_RESPONSE,
            $body,
            $phoneNumber
        );
    }

    /**
     * Registration Response (0x8100)
     * AuthCode WITHOUT length byte (Ultravision)
     */
    public function buildRegistrationResponse(
        string $phoneNumber,
        int $replySerial,
        string $authCode
    ): array {
        $body = [
            ($replySerial >> 8) & 0xFF,
            $replySerial & 0xFF,
            0x00, // success
        ];

        foreach (unpack('C*', $authCode) as $b) {
            $body[] = $b;
        }

        return $this->buildMessage(0x8100, $body, $phoneNumber);
    }

    /**
     * Registration Response using raw phone
     */
    public function buildRegistrationResponseWithRawPhone(
        array $phoneRawBytes,
        int $replySerial,
        int $result,
        string $authCode = ''
    ): array {
        $body = [
            ($replySerial >> 8) & 0xFF,
            $replySerial & 0xFF,
            $result & 0xFF,
        ];

        if ($result === 0 && $authCode !== '') {
            foreach (unpack('C*', $authCode) as $b) {
                $body[] = $b;
            }
        }

        return $this->buildMessageWithRawPhone(0x8100, $body, $phoneRawBytes);
    }

    /**
     * Terminal Control (0x8105)
     */
    public function buildTerminalControl(string $phoneNumber, int $command): array
    {
        return $this->buildMessage(
            ProtocolHelper::MSG_TERMINAL_CONTROL,
            [$command & 0xFF],
            $phoneNumber
        );
    }

    /**
     * Text Message (0x8300)
     */
    public function buildTextMessage(
        string $phoneNumber,
        string $message,
        int $flag = 0x09,
        int $textType = 1
    ): array {
        $body = [$flag & 0xFF, $textType & 0xFF];
        $body = array_merge(
            $body,
            unpack('C*', mb_convert_encoding($message, 'GBK', 'UTF-8'))
        );

        return $this->buildMessage(
            ProtocolHelper::MSG_TEXT_MESSAGE,
            $body,
            $phoneNumber
        );
    }

    /**
     * Convert message to bytes
     */
    public static function toBytes(array $message): string
    {
        return pack('C*', ...$message);
    }

    /**
     * Convert message to HEX
     */
    public static function toHex(array $message): string
    {
        return ProtocolHelper::bytesToHexString($message);
    }
}
