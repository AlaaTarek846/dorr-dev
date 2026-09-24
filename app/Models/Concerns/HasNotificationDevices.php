<?php

namespace App\Models\Concerns;

use App\Models\NotificationDevice;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For accounts that get push notifications on their phones (users, providers).
 * The account's `locale` column (kept fresh by the remember-locale middleware) is
 * the language a real-time message is composed in; push carries every language at once.
 */
trait HasNotificationDevices
{
    public function notificationDevices(): MorphMany
    {
        return $this->morphMany(NotificationDevice::class, 'owner');
    }
}
