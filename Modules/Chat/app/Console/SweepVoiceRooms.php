<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Services\VoiceRoomService;

/** Voice rooms (spec 91): members gone without a heartbeat are put out. Every minute. */
class SweepVoiceRooms extends Command
{
    protected $signature = 'chat:voice-rooms-sweep';

    protected $description = 'Take members who stopped answering out of the voice rooms';

    public function handle(VoiceRoomService $rooms): int
    {
        $this->info($rooms->sweep().' stale voice room member(s) removed.');

        return self::SUCCESS;
    }
}
