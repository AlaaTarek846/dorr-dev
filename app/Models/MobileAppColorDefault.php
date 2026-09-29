<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppColorDefault extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'is_active',
        'light_tokens',
        'dark_tokens',
        'light_gradients',
        'dark_gradients',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'light_tokens' => 'array',
            'dark_tokens' => 'array',
            'light_gradients' => 'array',
            'dark_gradients' => 'array',
        ];
    }
}
