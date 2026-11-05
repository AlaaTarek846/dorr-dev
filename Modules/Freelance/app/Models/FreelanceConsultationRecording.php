<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One recorded part of a consultation's video session (FL-L16).
 *
 *   recording ──room closed / stopped──▶ processing ──LiveKit saved it──▶ moving ──copied to our disk──▶ stored ──a week on──▶ deleted
 *                                                   └─LiveKit failed──▶ failed
 */
class FreelanceConsultationRecording extends Model
{
    public const DISK = 'local';

    public const RECORDING = 'recording';

    public const PROCESSING = 'processing';

    public const MOVING = 'moving';

    public const STORED = 'stored';

    public const FAILED = 'failed';

    public const DELETED = 'deleted';

    protected $table = 'freelance_consultation_recordings';

    protected $fillable = [
        'uuid', 'consultation_id', 'part', 'status', 'egress_id', 'bucket_key', 'file_path', 'size_bytes', 'duration_seconds',
        'started_at', 'stopped_at', 'stored_at', 'error',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceConsultationRecording $r) => $r->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'stopped_at' => 'datetime', 'stored_at' => 'datetime', 'part' => 'integer', 'size_bytes' => 'integer', 'duration_seconds' => 'integer'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConsultation::class, 'consultation_id');
    }
}
