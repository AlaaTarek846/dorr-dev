<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a URL looks like as a card (title, text, picture, site) — fetched once and shared by every
 * message that links to it. `failed` = the page gave nothing usable, so no card is shown.
 */
class ChatLinkPreview extends Model
{
    protected $fillable = ['url_hash', 'url', 'title', 'description', 'image', 'site_name', 'failed', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'failed' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    public function card(): ?array
    {
        if ($this->failed) {
            return null;
        }

        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'site_name' => $this->site_name,
        ];
    }
}
