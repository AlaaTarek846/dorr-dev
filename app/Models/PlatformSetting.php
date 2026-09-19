<?php

namespace App\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

class PlatformSetting extends Model implements HasMedia
{
    use HasMediaTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'app_name',
    ];
}
