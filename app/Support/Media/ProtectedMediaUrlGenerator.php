<?php

namespace App\Support\Media;

use Modules\Chat\Support\GroupMedia;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * Public files keep their plain URL. A chat file kept on the private disk (educational and work
 * groups — docs/groups_tasks.md GR-008) gets a short-lived signed link instead, tied to whoever
 * asked, and checked again on every download (GroupMedia).
 */
class ProtectedMediaUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        return GroupMedia::signedUrl($this->media) ?? parent::getUrl();
    }
}
