<?php

namespace Modules\Sports\Listeners;

use Modules\Sports\Events\MatchChanged;
use Modules\Sports\Services\SportsNotifier;

class SendSportsAlerts
{
    public function __construct(private readonly SportsNotifier $notifier) {}

    public function handle(MatchChanged $event): void
    {
        $this->notifier->changed($event->match, $event->changes);
    }
}
