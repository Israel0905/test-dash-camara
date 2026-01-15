<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdvrDevice extends Model
{
    protected $table = 'mdvr_devices';

    protected $fillable = [
        'phone_number',
        'terminal_id',
        'manufacturer_id',
        'terminal_model',
        'plate_number',
        'plate_color',
        'imei',
        'firmware_version',
        'auth_code',
        'is_online',
        'last_heartbeat_at',
        'registered_at',
        'last_ip',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    /**
     * Get device locations
     */
    public function locations(): HasMany
    {
        return $this->hasMany(MdvrDeviceLocation::class, 'device_id');
    }

    /**
     * Get device alarms
     */
    public function alarms(): HasMany
    {
        return $this->hasMany(MdvrDeviceAlarm::class, 'device_id');
    }

    /**
     * Get attachment files
     */
    public function attachmentFiles(): HasMany
    {
        return $this->hasMany(MdvrAttachmentFile::class, 'device_id');
    }

    /**
     * Get latest location
     */
    public function latestLocation()
    {
        return $this->hasOne(MdvrDeviceLocation::class, 'device_id')->latestOfMany();
    }

    /**
     * Scope for online devices
     */
    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    /**
     * Scope for offline devices
     */
    public function scopeOffline($query)
    {
        return $query->where('is_online', false);
    }

    /**
     * Update online status
     */
    public function updateOnlineStatus(bool $online, ?string $ip = null): void
    {
        $this->is_online = $online;
        if ($online) {
            $this->last_heartbeat_at = now();
            if ($ip) {
                $this->last_ip = $ip;
            }
        }
        $this->save();
    }

    /**
     * Find or create device by phone number
     */
    public static function findOrCreateByPhone(string $phoneNumber, array $attributes = []): self
    {
        return static::firstOrCreate(
            ['phone_number' => $phoneNumber],
            array_merge(['registered_at' => now()], $attributes)
        );
    }
}
