<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiSiteRequestMessage extends Model
{
    public const FROM_ADMIN = 'admin';

    public const FROM_CUSTOMER = 'customer';

    public const FROM_SYSTEM = 'system';

    protected $table = 'ai_site_request_messages';

    protected $fillable = ['request_id', 'sender', 'sender_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
