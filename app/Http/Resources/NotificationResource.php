<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the in-app notification list, in the *viewer's* language.
 *
 * The table holds a translation key + variables (or, for messages typed in the dashboard,
 * one text per language), so the same row reads correctly for an Arabic viewer and an English
 * one — the language of whoever triggered it is irrelevant. Falls back to the default
 * language, then to any language present, so a missing translation never shows an empty row.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'title' => $this->resolve_text($data['title'] ?? '', $data['variables'] ?? []),
            'message' => $this->resolve_text($data['message'] ?? '', $data['variables'] ?? []),
            'image' => $data['image'] ?? '',
            'event' => $data['event'] ?? null,
            'data' => $data['data'] ?? [],
            'type' => $data['type'] ?? null,
            'created_at' => $data['timeDate'] ?? $this->created_at?->format('Y-m-d H:i'),
            // Unambiguous instant (UTC) — a client works out "5 minutes ago" from this, not from the display string.
            'created_at_iso' => $this->created_at?->toISOString(),
            'read_at' => $this->read_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  string|array<string, string>  $text  a translation key, or ['ar' => '…', 'en' => '…']
     * @param  array<string, mixed>  $variables
     */
    private function resolve_text(string|array $text, array $variables): string
    {
        if ($text === '' || $text === []) {
            return '';
        }

        if (is_array($text)) {
            $locale = app()->getLocale();
            $fallback = config('app.fallback_locale', 'en');

            return (string) ($text[$locale] ?? $text[$fallback] ?? reset($text) ?: '');
        }

        return (string) __('notifications.'.$text, $variables);
    }
}
