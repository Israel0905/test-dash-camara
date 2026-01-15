<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdvrAttachmentFile extends Model
{
    protected $table = 'mdvr_attachment_files';

    protected $fillable = [
        'alarm_id',
        'device_id',
        'filename',
        'file_path',
        'file_type',
        'file_size',
        'alarm_number',
        'is_complete',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
    ];

    /**
     * File types
     */
    const TYPE_IMAGE = 'IMAGE';
    const TYPE_AUDIO = 'AUDIO';
    const TYPE_VIDEO = 'VIDEO';
    const TYPE_TEXT = 'TEXT';
    const TYPE_OTHER = 'OTHER';

    /**
     * Get the device
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MdvrDevice::class, 'device_id');
    }

    /**
     * Get the alarm
     */
    public function alarm(): BelongsTo
    {
        return $this->belongsTo(MdvrDeviceAlarm::class, 'alarm_id');
    }

    /**
     * Get file type from code
     */
    public static function getFileTypeFromCode(int $code): string
    {
        return match ($code) {
            0 => self::TYPE_IMAGE,
            1 => self::TYPE_AUDIO,
            2 => self::TYPE_VIDEO,
            3 => self::TYPE_TEXT,
            default => self::TYPE_OTHER,
        };
    }

    /**
     * Get file type code
     */
    public static function getFileTypeCode(string $type): int
    {
        return match ($type) {
            self::TYPE_IMAGE => 0,
            self::TYPE_AUDIO => 1,
            self::TYPE_VIDEO => 2,
            self::TYPE_TEXT => 3,
            default => 4,
        };
    }

    /**
     * Check if file is an image
     */
    public function isImage(): bool
    {
        return $this->file_type === self::TYPE_IMAGE;
    }

    /**
     * Check if file is a video
     */
    public function isVideo(): bool
    {
        return $this->file_type === self::TYPE_VIDEO;
    }

    /**
     * Scope for complete files
     */
    public function scopeComplete($query)
    {
        return $query->where('is_complete', true);
    }

    /**
     * Scope for videos
     */
    public function scopeVideos($query)
    {
        return $query->where('file_type', self::TYPE_VIDEO);
    }

    /**
     * Scope for images
     */
    public function scopeImages($query)
    {
        return $query->where('file_type', self::TYPE_IMAGE);
    }

    /**
     * Get full file URL
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->file_path || !$this->is_complete) {
            return null;
        }

        // This would need to be adjusted based on how you serve files
        return url('storage/mdvr/' . $this->file_path);
    }

    /**
     * Get human readable file size
     */
    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
