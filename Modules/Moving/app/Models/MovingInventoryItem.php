<?php

namespace Modules\Moving\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/** One numbered piece of the load (REQ-029): its photo before loading (`before`) and after delivery (`after`). */
class MovingInventoryItem extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $table = 'moving_inventory_items';

    protected $fillable = ['uuid', 'request_id', 'number', 'label', 'trip_no', 'condition_note', 'delivered_note'];

    protected static function booted(): void
    {
        static::creating(fn (self $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
