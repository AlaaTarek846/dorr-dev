<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'name', 'description', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
