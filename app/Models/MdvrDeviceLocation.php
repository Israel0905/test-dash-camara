<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdvrDeviceLocation extends Model
{
    protected $table = 'mdvr_device_locations';

    protected $fillable = [
        'device_id',
        'latitude',
        'longitude',
        'altitude',
        'speed',
        'direction',
        'mileage',
        'acc_on',
        'located',
        'vehicle_running',
        'alarm_flags',
        'status_flags',
        'signal_strength',
        'satellites',
        'additional_info',
        'device_time',
    ];

    protected $casts = [
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'speed' => 'decimal:1',
        'acc_on' => 'boolean',
        'located' => 'boolean',
        'vehicle_running' => 'boolean',
        'additional_info' => 'array',
        'device_time' => 'datetime',
    ];

    /**
     * Get the device
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MdvrDevice::class, 'device_id');
    }

    /**
     * Check if has any alarm
     */
    public function hasAlarm(): bool
    {
        return $this->alarm_flags > 0;
    }

    /**
     * Get alarm names
     */
    public function getAlarmNamesAttribute(): array
    {
        $alarms = [];
        $flags = $this->alarm_flags;

        $alarmMap = [
            0x01 => 'SOS',
            0x02 => 'Overspeed',
            0x04 => 'Fatigue Driving',
            0x10 => 'GNSS Fault',
            0x20 => 'GNSS Disconnected',
            0x40 => 'GNSS Short Circuit',
            0x100 => 'Power Off',
            0x20000000 => 'Collision',
        ];

        foreach ($alarmMap as $bit => $name) {
            if ($flags & $bit) {
                $alarms[] = $name;
            }
        }

        return $alarms;
    }

    /**
     * Scope for within time range
     */
    public function scopeInTimeRange($query, $start, $end)
    {
        return $query->whereBetween('device_time', [$start, $end]);
    }

    /**
     * Scope for with alarms
     */
    public function scopeWithAlarms($query)
    {
        return $query->where('alarm_flags', '>', 0);
    }
}
