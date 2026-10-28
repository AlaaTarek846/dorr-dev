<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;

class AiArCapture extends Model
{
    protected $table = 'ai_ar_captures';

    protected $fillable = ['experience_id', 'kind', 'file_path', 'consent_recorded', 'expires_at'];

    protected function casts(): array
    {
        return ['consent_recorded' => 'boolean', 'expires_at' => 'datetime'];
    }
}
