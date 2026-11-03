<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stay of one side in a consultation's video session (FL-L16): when they came in and when they left. */
class FreelanceConsultationAttendance extends Model
{
    protected $table = 'freelance_consultation_attendance';

    protected $fillable = ['consultation_id', 'side', 'joined_at', 'left_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConsultation::class, 'consultation_id');
    }
}
