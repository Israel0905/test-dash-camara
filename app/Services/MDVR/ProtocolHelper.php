<?php

namespace App\Services\MDVR;

/**
 * Protocol Helper for JTT808/JTT1078 MDVR Protocol
 *
 * Handles message parsing, escaping, checksum calculation, and data conversions
 */
class ProtocolHelper
{
    const START_DELIMITER = 0x7E;

    const ESCAPE_CHAR = 0x7D;

    /**
     * Message IDs - Device to Server
     */
    const MSG_DEVICE_GENERAL_RESPONSE = 0x0001;

    const MSG_HEARTBEAT = 0x0002;

    const MSG_REGISTRATION = 0x0100;

    const MSG_AUTHENTICATION = 0x0102;

    const MSG_LOCATION_REPORT = 0x0200;

    const MSG_LOCATION_BATCH = 0x0704;

    const MSG_MULTIMEDIA_EVENT = 0x0800;

    const MSG_MULTIMEDIA_DATA = 0x0801;

    const MSG_DRIVER_INFO = 0x0702;

    const MSG_TRANSPARENT_DATA = 0x0900;

    const MSG_RESOURCE_LIST_RESPONSE = 0x1205;

    const MSG_ALARM_ATTACHMENT_INFO = 0x1210;

    const MSG_FILE_INFO = 0x1211;

    const MSG_FILE_COMPLETE = 0x1212;

    const MSG_PASSENGER_DATA = 0x1005;

    const MSG_VEHICLE_INFO_RESPONSE = 0x4040;

    /**
     * Message IDs - Server to Device
     */
    const MSG_SERVER_GENERAL_RESPONSE = 0x8001;

    const MSG_REGISTRATION_RESPONSE = 0x8100;

    const MSG_TRANSPARENT_DATA_DOWN = 0x8900;

    const MSG_VIDEO_REQUEST = 0x9101;

    const MSG_VIDEO_CONTROL = 0x9102;

    const MSG_PLAYBACK_REQUEST = 0x9201;

    const MSG_PLAYBACK_CONTROL = 0x9202;

    const MSG_QUERY_RESOURCES = 0x9205;

    const MSG_FILE_UPLOAD_INSTRUCTION = 0x9206;

    const MSG_FILE_UPLOAD_CONTROL = 0x9207;

    const MSG_ALARM_ATTACHMENT_REQUEST = 0x9208;

    const MSG_FILE_COMPLETE_RESPONSE = 0x9212;

    const MSG_TERMINAL_CONTROL = 0x8105;

    const MSG_TEXT_MESSAGE = 0x8300;

    const MSG_QUERY_VEHICLE_INFO = 0xB040;

    const MSG_PARAM_CONFIG = 0xB050;

    /**
     * Additional Info IDs for Location Report
     */
    const ADDINFO_MILEAGE = 0x01;

    const ADDINFO_FUEL = 0x02;

    const ADDINFO_SPEED_RECORDER = 0x03;

    const ADDINFO_VIDEO_ALARM = 0x14;

    const ADDINFO_VIDEO_LOSS = 0x15;

    const ADDINFO_MEMORY_FAULT = 0x17;

    const ADDINFO_ABNORMAL_DRIVING = 0x18;

    const ADDINFO_EXTENDED_VEHICLE = 0x25;

    const ADDINFO_IO_STATUS = 0x2A;

    const ADDINFO_SIGNAL_STRENGTH = 0x30;

    const ADDINFO_GNSS_SATELLITES = 0x31;

    const ADDINFO_ADAS_ALARM = 0x64;

    const ADDINFO_DSM_ALARM = 0x65;

    const ADDINFO_BSD_ALARM = 0x67;

    const ADDINFO_AGGRESSIVE_DRIVING = 0x70;

    const ADDINFO_MDVR_STATUS = 0xEF;

    const ADDINFO_GSENSOR_ALARM = 0xE1;

    const ADDINFO_TEMPERATURE = 0xE4;

    const ADDINFO_AUX_FUEL = 0xEC;

    /**
     * Escape data for transmission
     * 0x7E -> 0x7D 0x02
     * 0x7D -> 0x7D 0x01
     */
    public static function escape(array $data): array
    {
        $result = [];
        foreach ($data as $byte) {
            if ($byte === self::ESCAPE_CHAR) {
                $result[] = self::ESCAPE_CHAR;
                $result[] = 0x01;
            } elseif ($byte === self::START_DELIMITER) {
                $result[] = self::ESCAPE_CHAR;
                $result[] = 0x02;
            } else {
                $result[] = $byte;
            }
        }

        return $result;
    }

    /**
     * Unescape received data
     * 0x7D 0x02 -> 0x7E
     * 0x7D 0x01 -> 0x7D
     */
    public static function unescape(array $data): array
    {
        $result = [];
        $i = 0;
        while ($i < count($data)) {
            if ($data[$i] === self::ESCAPE_CHAR && isset($data[$i + 1])) {
                if ($data[$i + 1] === 0x01) {
                    $result[] = self::ESCAPE_CHAR;
                    $i += 2;
                } elseif ($data[$i + 1] === 0x02) {
                    $result[] = self::START_DELIMITER;
                    $i += 2;
                } else {
                    $result[] = $data[$i];
                    $i++;
                }
            } else {
                $result[] = $data[$i];
                $i++;
            }
        }

        return $result;
    }

    /**
     * Calculate XOR checksum
     */
    public static function calculateChecksum(array $data): int
    {
        $checksum = 0;
        foreach ($data as $byte) {
            $checksum ^= $byte;
        }

        return $checksum & 0xFF;
    }

    /**
     * Verify checksum of a message
     */
    public static function verifyChecksum(array $data): bool
    {
        if (count($data) < 2) {
            return false;
        }
        $receivedChecksum = array_pop($data);
        $calculatedChecksum = self::calculateChecksum($data);

        return $receivedChecksum === $calculatedChecksum;
    }

    /**
     * Convert BCD bytes to string
     * Example: [0x20, 0x24, 0x01, 0x15] -> "20240115"
     */
    public static function bcdToString(array $bcd): string
    {
        $result = '';
        foreach ($bcd as $byte) {
            $result .= sprintf('%02X', $byte);
        }

        return $result;
    }

    /**
     * Convert string to BCD bytes
     * Example: "20240115" -> [0x20, 0x24, 0x01, 0x15]
     */
    public static function stringToBcd(string $str, int $length): array
    {
        // Pad with zeros if needed
        $str = str_pad($str, $length * 2, '0', STR_PAD_LEFT);
        $result = [];
        for ($i = 0; $i < strlen($str); $i += 2) {
            $result[] = hexdec(substr($str, $i, 2));
        }

        return array_slice($result, 0, $length);
    }

    /**
     * Convert phone number string to BCD bytes
     *
     * @param  int  $bytes  6 for JTT808-2011/2013, 10 for JTT808-2019
     */
    public static function phoneNumberToBcd(string $phone, int $bytes = 10): array
    {
        // Pad to required digits (bytes * 2)
        $digits = $bytes * 2;
        $phone = str_pad($phone, $digits, '0', STR_PAD_LEFT);

        return self::stringToBcd($phone, $bytes);
    }

    /**
     * Convert BCD[10] to phone number string
     */
    public static function bcdToPhoneNumber(array $bcd): string
    {
        return ltrim(self::bcdToString($bcd), '0');
    }

    /**
     * Parse hex string to byte array
     * Example: "7E 01 02 7E" -> [0x7E, 0x01, 0x02, 0x7E]
     */
    public static function hexStringToBytes(string $hex): array
    {
        $hex = preg_replace('/\s+/', '', $hex);
        $bytes = [];
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $bytes[] = hexdec(substr($hex, $i, 2));
        }

        return $bytes;
    }

    /**
     * Convert byte array to hex string
     */
    public static function bytesToHexString(array $bytes, bool $withSpaces = true): string
    {
        $separator = $withSpaces ? ' ' : '';

        return implode($separator, array_map(fn ($b) => sprintf('%02X', $b), $bytes));
    }

    /**
     * Parse a complete message (with delimiters)
     * Returns: ['header' => [...], 'body' => [...], 'valid' => bool]
     */
    public static function parseMessage(array $rawBytes): ?array
    {
        // Check delimiters
        if (count($rawBytes) < 2) {
            return null;
        }

        if ($rawBytes[0] !== self::START_DELIMITER || $rawBytes[count($rawBytes) - 1] !== self::START_DELIMITER) {
            return null;
        }

        // Remove delimiters
        $data = array_slice($rawBytes, 1, -1);

        // Unescape
        $data = self::unescape($data);

        // Verify checksum
        if (! self::verifyChecksum($data)) {
            return null;
        }

        // Remove checksum
        array_pop($data);

        // Parse header (17 bytes minimum for JTT808-2019)
        if (count($data) < 17) {
            return null;
        }

        $header = self::parseHeader(array_slice($data, 0, 17));
        $bodyLength = $header['bodyLength'];

        // Check if message has packet encapsulation (multi-packet)
        $headerLength = 17;
        if ($header['isMultiPacket']) {
            $headerLength = 21;
            $header['totalPackets'] = ($data[17] << 8) | $data[18];
            $header['packetNumber'] = ($data[19] << 8) | $data[20];
        }

        $body = array_slice($data, $headerLength);

        return [
            'header' => $header,
            'body' => $body,
            'valid' => true,
        ];
    }

    /**
     * Parse message header (17 bytes for JTT808-2019)
     */
    public static function parseHeader(array $headerBytes): array
    {
        $messageId = ($headerBytes[0] << 8) | $headerBytes[1];
        $properties = ($headerBytes[2] << 8) | $headerBytes[3];
        $protocolVersion = $headerBytes[4];
        $phoneNumber = self::bcdToPhoneNumber(array_slice($headerBytes, 5, 10));
        $serialNumber = ($headerBytes[15] << 8) | $headerBytes[16];

        // Parse properties
        $bodyLength = $properties & 0x03FF; // bits 0-9
        $encryption = ($properties >> 10) & 0x07; // bits 10-12
        $isMultiPacket = ($properties >> 13) & 0x01; // bit 13
        $versionFlag = ($properties >> 14) & 0x01; // bit 14

        return [
            'messageId' => $messageId,
            'messageIdHex' => sprintf('0x%04X', $messageId),
            'properties' => $properties,
            'bodyLength' => $bodyLength,
            'encryption' => $encryption,
            'isMultiPacket' => (bool) $isMultiPacket,
            'versionFlag' => $versionFlag,
            'protocolVersion' => $protocolVersion,
            'phoneNumber' => $phoneNumber,
            'serialNumber' => $serialNumber,
        ];
    }

    /**
     * Parse location basic info (28 bytes)
     */
    public static function parseLocationBasicInfo(array $data): array
    {
        if (count($data) < 28) {
            return [];
        }

        $alarmFlags = ($data[0] << 24) | ($data[1] << 16) | ($data[2] << 8) | $data[3];
        $status = ($data[4] << 24) | ($data[5] << 16) | ($data[6] << 8) | $data[7];
        $latitude = ($data[8] << 24) | ($data[9] << 16) | ($data[10] << 8) | $data[11];
        $longitude = ($data[12] << 24) | ($data[13] << 16) | ($data[14] << 8) | $data[15];
        $altitude = ($data[16] << 8) | $data[17];
        $speed = ($data[18] << 8) | $data[19];
        $direction = ($data[20] << 8) | $data[21];
        $time = self::bcdToString(array_slice($data, 22, 6));

        // Parse status bits
        $accOn = ($status & 0x01) === 1;
        $located = (($status >> 1) & 0x01) === 1;
        $southLatitude = (($status >> 2) & 0x01) === 1;
        $westLongitude = (($status >> 3) & 0x01) === 1;
        $vehicleRunning = (($status >> 22) & 0x01) === 1;

        // Parse alarm flags
        $alarms = [];
        if ($alarmFlags & 0x01) {
            $alarms[] = 'SOS';
        }
        if ($alarmFlags & 0x02) {
            $alarms[] = 'OVERSPEED';
        }
        if ($alarmFlags & 0x04) {
            $alarms[] = 'FATIGUE';
        }
        if ($alarmFlags & 0x10) {
            $alarms[] = 'GNSS_FAULT';
        }
        if ($alarmFlags & 0x20) {
            $alarms[] = 'GNSS_DISCONNECTED';
        }
        if ($alarmFlags & 0x40) {
            $alarms[] = 'GNSS_SHORT';
        }
        if ($alarmFlags & 0x100) {
            $alarms[] = 'POWER_OFF';
        }
        if ($alarmFlags & 0x20000000) {
            $alarms[] = 'COLLISION';
        }

        // Calculate actual lat/lng
        $latValue = $latitude / 1000000.0;
        $lngValue = $longitude / 1000000.0;
        if ($southLatitude) {
            $latValue = -$latValue;
        }
        if ($westLongitude) {
            $lngValue = -$lngValue;
        }

        return [
            'alarmFlags' => $alarmFlags,
            'alarms' => $alarms,
            'status' => $status,
            'latitude' => $latValue,
            'longitude' => $lngValue,
            'altitude' => $altitude,
            'speed' => $speed / 10.0, // Convert to km/h
            'direction' => $direction,
            'time' => $time,
            'accOn' => $accOn,
            'located' => $located,
            'vehicleRunning' => $vehicleRunning,
        ];
    }

    /**
     * Parse location additional info
     */
    public static function parseLocationAdditionalInfo(array $data): array
    {
        $additionalInfo = [];
        $offset = 28; // After basic info

        while ($offset < count($data)) {
            if ($offset + 2 > count($data)) {
                break;
            }

            $infoId = $data[$offset];
            $infoLength = $data[$offset + 1];
            $offset += 2;

            if ($offset + $infoLength > count($data)) {
                break;
            }

            $infoData = array_slice($data, $offset, $infoLength);
            $offset += $infoLength;

            $additionalInfo[$infoId] = self::parseAdditionalInfoItem($infoId, $infoData);
        }

        return $additionalInfo;
    }

    /**
     * Parse individual additional info item
     */
    private static function parseAdditionalInfoItem(int $id, array $data): array
    {
        switch ($id) {
            case self::ADDINFO_MILEAGE:
                $value = ($data[0] << 24) | ($data[1] << 16) | ($data[2] << 8) | $data[3];

                return ['type' => 'mileage', 'value' => $value / 10.0, 'unit' => 'km'];

            case self::ADDINFO_FUEL:
                $value = ($data[0] << 8) | $data[1];

                return ['type' => 'fuel', 'value' => $value / 10.0, 'unit' => 'L'];

            case self::ADDINFO_SIGNAL_STRENGTH:
                return ['type' => 'signal', 'value' => $data[0], 'unit' => '%'];

            case self::ADDINFO_GNSS_SATELLITES:
                return ['type' => 'satellites', 'value' => $data[0]];

            case self::ADDINFO_ADAS_ALARM:
                return self::parseAdasAlarm($data);

            case self::ADDINFO_DSM_ALARM:
                return self::parseDsmAlarm($data);

            case self::ADDINFO_BSD_ALARM:
                return self::parseBsdAlarm($data);

            case self::ADDINFO_AGGRESSIVE_DRIVING:
                return self::parseAggressiveDrivingAlarm($data);

            case self::ADDINFO_GSENSOR_ALARM:
                $alarmTypes = ['RAPID_ACCEL', 'SUDDEN_DECEL', 'SHARP_TURN'];
                $type = isset($alarmTypes[$data[0] - 1]) ? $alarmTypes[$data[0] - 1] : 'UNKNOWN';

                return ['type' => 'gsensor_alarm', 'alarmType' => $type];

            default:
                return ['type' => 'unknown', 'id' => $id, 'raw' => $data];
        }
    }

    /**
     * Parse ADAS (Advanced Driver Assistance System) alarm - ID 0x64
     */
    private static function parseAdasAlarm(array $data): array
    {
        if (count($data) < 47) {
            return ['type' => 'adas_alarm', 'error' => 'insufficient_data'];
        }

        $alarmId = ($data[0] << 24) | ($data[1] << 16) | ($data[2] << 8) | $data[3];
        $alarmType = $data[5];
        $alarmLevel = $data[6];
        $speed = $data[12];
        $latitude = (($data[15] << 24) | ($data[16] << 16) | ($data[17] << 8) | $data[18]) / 1000000.0;
        $longitude = (($data[19] << 24) | ($data[20] << 16) | ($data[21] << 8) | $data[22]) / 1000000.0;

        $alarmTypes = [
            0x01 => 'FORWARD_COLLISION',
            0x02 => 'LANE_DEPARTURE',
            0x03 => 'DISTANCE_TOO_CLOSE',
            0x04 => 'PEDESTRIAN_COLLISION',
        ];

        return [
            'type' => 'adas_alarm',
            'alarmId' => $alarmId,
            'alarmType' => $alarmTypes[$alarmType] ?? 'UNKNOWN',
            'alarmTypeCode' => $alarmType,
            'alarmLevel' => $alarmLevel,
            'speed' => $speed,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Parse DSM (Driver Status Monitoring) alarm - ID 0x65
     */
    private static function parseDsmAlarm(array $data): array
    {
        if (count($data) < 47) {
            return ['type' => 'dsm_alarm', 'error' => 'insufficient_data'];
        }

        $alarmId = ($data[0] << 24) | ($data[1] << 16) | ($data[2] << 8) | $data[3];
        $alarmType = $data[5];
        $alarmLevel = $data[6];
        $fatigueLevel = $data[7];
        $speed = $data[12];
        $latitude = (($data[15] << 24) | ($data[16] << 16) | ($data[17] << 8) | $data[18]) / 1000000.0;
        $longitude = (($data[19] << 24) | ($data[20] << 16) | ($data[21] << 8) | $data[22]) / 1000000.0;

        $alarmTypes = [
            0x01 => 'FATIGUE_DRIVING',
            0x02 => 'PHONE_CALL',
            0x03 => 'SMOKING',
            0x04 => 'DISTRACTED_DRIVING',
            0x05 => 'DRIVER_ABNORMAL',
            0x06 => 'SEATBELT_NOT_FASTENED',
            0x0A => 'CAMERA_OCCLUSION',
            0x11 => 'DRIVER_CHANGE',
            0x1F => 'INFRARED_BLOCKING',
        ];

        return [
            'type' => 'dsm_alarm',
            'alarmId' => $alarmId,
            'alarmType' => $alarmTypes[$alarmType] ?? 'UNKNOWN',
            'alarmTypeCode' => $alarmType,
            'alarmLevel' => $alarmLevel,
            'fatigueLevel' => $fatigueLevel,
            'speed' => $speed,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Parse BSD (Blind Spot Detection) alarm - ID 0x67
     */
    private static function parseBsdAlarm(array $data): array
    {
        if (count($data) < 41) {
            return ['type' => 'bsd_alarm', 'error' => 'insufficient_data'];
        }

        $alarmId = ($data[0] << 24) | ($data[1] << 16) | ($data[2] << 8) | $data[3];
        $alarmType = $data[5];
        $speed = $data[6];
        $latitude = (($data[9] << 24) | ($data[10] << 16) | ($data[11] << 8) | $data[12]) / 1000000.0;
        $longitude = (($data[13] << 24) | ($data[14] << 16) | ($data[15] << 8) | $data[16]) / 1000000.0;

        $alarmTypes = [
            0x01 => 'REAR_APPROACH',
            0x02 => 'LEFT_REAR_APPROACH',
            0x03 => 'RIGHT_REAR_APPROACH',
        ];

        return [
            'type' => 'bsd_alarm',
            'alarmId' => $alarmId,
            'alarmType' => $alarmTypes[$alarmType] ?? 'UNKNOWN',
            'alarmTypeCode' => $alarmType,
            'speed' => $speed,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Parse Aggressive Driving alarm - ID 0x70
     */
    private static function parseAggressiveDrivingAlarm(array $data): array
    {
        if (count($data) < 47) {
            return ['type' => 'aggressive_alarm', 'error' => 'insufficient_data'];
        }

        $alarmId = ($data[0] << 24) | ($data[1] << 16) | ($data[2] << 8) | $data[3];
        $alarmType = $data[5];
        $speed = $data[12];
        $latitude = (($data[15] << 24) | ($data[16] << 16) | ($data[17] << 8) | $data[18]) / 1000000.0;
        $longitude = (($data[19] << 24) | ($data[20] << 16) | ($data[21] << 8) | $data[22]) / 1000000.0;

        $alarmTypes = [
            0x01 => 'EMERGENCY_ACCELERATION',
            0x02 => 'EMERGENCY_DECELERATION',
            0x03 => 'SHARP_TURN',
        ];

        return [
            'type' => 'aggressive_driving_alarm',
            'alarmId' => $alarmId,
            'alarmType' => $alarmTypes[$alarmType] ?? 'UNKNOWN',
            'alarmTypeCode' => $alarmType,
            'speed' => $speed,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }
}
