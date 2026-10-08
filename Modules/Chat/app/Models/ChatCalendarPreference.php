<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** My DORR Calendar choices (spec 201, 202, 204, 207). */
class ChatCalendarPreference extends Model
{
    /** What the calendar can show — each only when I leave it on. */
    public const SOURCES = ['events', 'moments', 'personal', 'tasks', 'reminders', 'discover', 'sports'];

    /** DORR Today's sections, in their default order. */
    public const SECTIONS = ['next', 'events', 'tasks', 'reminders', 'moments', 'around'];

    protected $fillable = ['owner_type', 'owner_id', 'sources', 'default_reminders', 'all_day_reminders', 'respect_quiet', 'today_sections', 'personalised'];

    protected function casts(): array
    {
        return [
            'sources' => 'array', 'default_reminders' => 'array', 'all_day_reminders' => 'array', 'respect_quiet' => 'boolean',
            'today_sections' => 'array', 'personalised' => 'boolean',
        ];
    }

    public function sourceOn(string $source): bool
    {
        return (bool) (($this->sources ?? [])[$source] ?? true);
    }

    /** @return list<int> */
    public function defaultReminders(bool $allDay): array
    {
        return array_values(array_map('intval', ($allDay ? $this->all_day_reminders : $this->default_reminders) ?? ($allDay ? [0] : [30])));
    }

    /**
     * Sections in my order — new ones at the end — and which I hid.
     *
     * @return array{order: list<string>, hidden: list<string>}
     */
    public function sections(): array
    {
        $saved = $this->today_sections ?? [];
        $order = array_values(array_unique(array_merge(array_intersect($saved['order'] ?? [], self::SECTIONS), self::SECTIONS)));

        return ['order' => $order, 'hidden' => array_values(array_intersect($saved['hidden'] ?? [], self::SECTIONS))];
    }
}
