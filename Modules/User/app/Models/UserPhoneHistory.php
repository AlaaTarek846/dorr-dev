<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A permanent, append-only record of a completed phone-number change. Never updated or deleted —
 * old financial rows and transfer receipts keep meaning ("this went to +9665... at the time"), and a
 * transfer lookup can warn the sender when the recipient's number changed recently.
 */
class UserPhoneHistory extends Model
{
    protected $table = 'user_phone_history';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'old_phone', 'new_phone', 'ip_address', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
