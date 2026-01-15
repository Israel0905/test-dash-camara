<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdvrDeviceAlarm extends Model
{
    protected $table = 'mdvr_device_alarms';

    protected $fillable = [
        'device_id',
        'alarm_id',
        'alarm_type',
        'alarm_name',
        'alarm_category',
        'alarm_level',
        'latitude',
        'longitude',
        'speed',
        'alarm_identification',
        'has_attachment',
        'attachment_status',
        'alarm_data',
        'attachments',
        'device_time',
    ];

    protected $casts = [
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'has_attachment' => 'boolean',
        'alarm_data' => 'array',
        'attachments' => 'array',
        'device_time' => 'datetime',
    ];

    /**
     * Alarm categories
     */
    const CATEGORY_ADAS = 'ADAS';
    const CATEGORY_DSM = 'DSM';
    const CATEGORY_BSD = 'BSD';
    const CATEGORY_AGGRESSIVE = 'AGGRESSIVE';
    const CATEGORY_GSENSOR = 'GSENSOR';

    /**
     * Attachment statuses
     */
    const ATTACHMENT_NONE = 'none';
    const ATTACHMENT_PENDING = 'pending';
    const ATTACHMENT_UPLOADING = 'uploading';
    const ATTACHMENT_COMPLETE = 'complete';
    const ATTACHMENT_FAILED = 'failed';

    /**
     * Get the device
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MdvrDevice::class, 'device_id');
    }

    /**
     * Get attachment files
     */
    public function attachmentFiles(): HasMany
    {
        return $this->hasMany(MdvrAttachmentFile::class, 'alarm_id');
    }

    /**
     * ADAS alarm types
     */
    public static function getAdasAlarmTypes(): array
    {
        return [
            0x01 => 'Forward Collision',
            0x02 => 'Lane Departure',
            0x03 => 'Distance Too Close',
            0x04 => 'Pedestrian Collision',
        ];
    }

    /**
     * DSM alarm types
     */
    public static function getDsmAlarmTypes(): array
    {
        return [
            0x01 => 'Fatigue Driving',
            0x02 => 'Phone Call',
            0x03 => 'Smoking',
            0x04 => 'Distracted Driving',
            0x05 => 'Driver Abnormal',
            0x06 => 'Seatbelt Not Fastened',
            0x0A => 'Camera Occlusion',
            0x11 => 'Driver Change',
            0x1F => 'Infrared Blocking',
        ];
    }

    /**
     * BSD alarm types
     */
    public static function getBsdAlarmTypes(): array
    {
        return [
            0x01 => 'Rear Approach',
            0x02 => 'Left Rear Approach',
            0x03 => 'Right Rear Approach',
        ];
    }

    /**
     * Aggressive driving alarm types
     */
    public static function getAggressiveAlarmTypes(): array
    {
        return [
            0x01 => 'Emergency Acceleration',
            0x02 => 'Emergency Deceleration',
            0x03 => 'Sharp Turn',
        ];
    }

    /**
     * Get alarm name from type code
     */
    public static function getAlarmName(string $category, int $typeCode): string
    {
        $types = match ($category) {
            self::CATEGORY_ADAS => self::getAdasAlarmTypes(),
            self::CATEGORY_DSM => self::getDsmAlarmTypes(),
            self::CATEGORY_BSD => self::getBsdAlarmTypes(),
            self::CATEGORY_AGGRESSIVE => self::getAggressiveAlarmTypes(),
            default => [],
        };

        return $types[$typeCode] ?? 'Unknown';
    }

    /**
     * Scope for category
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('alarm_category', $category);
    }

    /**
     * Scope for ADAS alarms
     */
    public function scopeAdas($query)
    {
        return $query->where('alarm_category', self::CATEGORY_ADAS);
    }

    /**
     * Scope for DSM alarms
     */
    public function scopeDsm($query)
    {
        return $query->where('alarm_category', self::CATEGORY_DSM);
    }

    /**
     * Scope for pending attachments
     */
    public function scopePendingAttachments($query)
    {
        return $query->where('has_attachment', true)
                     ->where('attachment_status', self::ATTACHMENT_PENDING);
    }
}
