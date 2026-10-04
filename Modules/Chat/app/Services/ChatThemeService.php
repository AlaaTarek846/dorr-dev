<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatTheme;

/**
 * Chat themes: the admin's catalogue, the list people pick from, and which one a conversation
 * shows (the person's pick when it's still active, else the default).
 */
class ChatThemeService
{
    private const CACHE_KEY = 'chat.themes.active';

    /** Read once per request (the service is scoped): a chat list asks for every row. */
    private ?Collection $active = null;

    /**
     * Active themes, in the admin's order — cached, every chat screen needs them.
     *
     * @return Collection<int, ChatTheme>
     */
    public function active(): Collection
    {
        return $this->active ??= Cache::rememberForever(self::CACHE_KEY, fn () => ChatTheme::query()->active()
            ->with(['translations', 'media'])
            ->orderBy('sort_order')->orderBy('id')
            ->get());
    }

    /**
     * The theme a conversation is drawn with for me.
     */
    public function resolve(?int $themeId): ?ChatTheme
    {
        $themes = $this->active();

        return ($themeId !== null ? $themes->firstWhere('id', $themeId) : null)
            ?? $themes->firstWhere('is_default', true);
    }

    public function isActive(int $themeId): bool
    {
        return $this->active()->contains('id', $themeId);
    }

    // ---------------------------------------------------------------- my own look (per chat)

    /** Default dimming over my own wallpaper, so the bubbles stay readable. */
    public const DEFAULT_DIM = 25;

    /**
     * What a conversation is drawn with for me: the picked (or default) theme, with my own
     * wallpaper / colours / dim laid over it when I made some. Null = Dorr's own look.
     *
     * @param  array<string, mixed>|null  $custom
     * @return array<string, mixed>|null
     */
    public function appliedFor(?int $themeId, ?array $custom): ?array
    {
        $base = $this->resolve($themeId)?->present();
        $custom = $this->clean($custom);
        if ($custom === []) {
            return $base;
        }

        $wallpaper = isset($custom['wallpaper_path']) ? Storage::disk('public')->url($custom['wallpaper_path']) : ($base['wallpaper'] ?? null);
        $background = $custom['background_color'] ?? $base['background_color'] ?? null;

        return [
            'id' => 0,
            'name' => null,
            'wallpaper' => $wallpaper,
            'background_color' => $background,
            'sender_color' => $custom['sender_color'] ?? $base['sender_color'] ?? null,
            'receiver_color' => $custom['receiver_color'] ?? $base['receiver_color'] ?? null,
            // A colour background decides by itself; a photo keeps the base theme's choice.
            'is_dark' => isset($custom['background_color']) ? self::isDark($custom['background_color']) : (bool) ($base['is_dark'] ?? false),
            'is_default' => false,
            'is_custom' => true,
            'dim' => (int) ($custom['dim'] ?? (isset($custom['wallpaper_path']) ? self::DEFAULT_DIM : 0)),
        ];
    }

    /**
     * My custom look as the app edits it (the picture as a URL, not a storage path).
     *
     * @param  array<string, mixed>|null  $custom
     * @return array<string, mixed>|null
     */
    public function presentCustom(?array $custom): ?array
    {
        $custom = $this->clean($custom);
        if ($custom === []) {
            return null;
        }

        return [
            'wallpaper' => isset($custom['wallpaper_path']) ? Storage::disk('public')->url($custom['wallpaper_path']) : null,
            'sender_color' => $custom['sender_color'] ?? null,
            'receiver_color' => $custom['receiver_color'] ?? null,
            'background_color' => $custom['background_color'] ?? null,
            'dim' => isset($custom['dim']) ? (int) $custom['dim'] : null,
        ];
    }

    /**
     * Apply a settings change to my custom look: null drops all of it (and its picture); keys
     * present are set (a colour set to null goes back to the theme's); `wallpaper: null` removes
     * the picture. The picture itself only comes from [storeWallpaper].
     *
     * @param  array<string, mixed>|null  $changes
     * @return array<string, mixed>|null
     */
    public function mergeCustom(ChatParticipant $participant, ?array $changes): ?array
    {
        $current = $this->clean($participant->custom_theme);

        if ($changes === null) {
            $this->deleteWallpaper($current);

            return null;
        }

        foreach (['sender_color', 'receiver_color', 'background_color', 'dim'] as $key) {
            if (array_key_exists($key, $changes)) {
                $current[$key] = $changes[$key];
            }
        }
        if (array_key_exists('wallpaper', $changes) && $changes['wallpaper'] === null) {
            $this->deleteWallpaper($current);
            unset($current['wallpaper_path']);
        }

        $current = $this->clean($current);

        return $current === [] ? null : $current;
    }

    /**
     * Keep my new wallpaper for this chat (the old one is deleted) and return the custom look with it.
     *
     * @return array<string, mixed>
     */
    public function storeWallpaper(ChatParticipant $participant, UploadedFile $image): array
    {
        $current = $this->clean($participant->custom_theme);
        $this->deleteWallpaper($current);

        $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg');
        $current['wallpaper_path'] = $image->storeAs('chat/wallpapers/'.$participant->id, Str::uuid().'.'.$extension, 'public');
        $current['dim'] ??= self::DEFAULT_DIM;

        return $current;
    }

    /**
     * @param  array<string, mixed>|null  $custom
     * @return array<string, mixed>
     */
    private function clean(?array $custom): array
    {
        return array_filter(
            array_intersect_key($custom ?? [], array_flip(['wallpaper_path', 'sender_color', 'receiver_color', 'background_color', 'dim'])),
            fn ($v) => $v !== null && $v !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function deleteWallpaper(array $custom): void
    {
        if (! empty($custom['wallpaper_path'])) {
            Storage::disk('public')->delete($custom['wallpaper_path']);
        }
    }

    private static function isDark(string $hex): bool
    {
        $h = ltrim($hex, '#');
        [$r, $g, $b] = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 < 0.5;
    }

    // ---------------------------------------------------------------- admin

    /**
     * @param  array<string, mixed>  $data  fields + `translations` [{locale, name}]
     */
    public function save(?ChatTheme $theme, array $data, ?UploadedFile $wallpaper, bool $removeWallpaper = false): ChatTheme
    {
        $theme = DB::transaction(function () use ($theme, $data, $wallpaper, $removeWallpaper) {
            $theme ??= new ChatTheme;
            $theme->fill(collect($data)->except('translations')->all());

            if ($theme->is_default) {
                $theme->status = true; // the default must be pickable
            }

            $theme->save();

            if ($theme->is_default) {
                ChatTheme::query()->whereKeyNot($theme->id)->where('is_default', true)->update(['is_default' => false]);
            }

            foreach ($data['translations'] ?? [] as $row) {
                $theme->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }

            if ($wallpaper !== null) {
                $theme->setSingleMedia(ChatTheme::WALLPAPER, $wallpaper);
            } elseif ($removeWallpaper) {
                $theme->clearMediaCollection(ChatTheme::WALLPAPER);
            }

            return $theme;
        });

        $this->flush();

        return $theme->load(['translations', 'media']);
    }

    public function setStatus(ChatTheme $theme, bool $status): ChatTheme
    {
        $theme->update(['status' => $status, 'is_default' => $status && $theme->is_default]);
        $this->flush();

        return $theme;
    }

    /**
     * Conversations that used it fall back to the default (resolve() ignores unknown ids).
     */
    public function delete(ChatTheme $theme): void
    {
        $theme->cleanMedia();
        $theme->delete();
        $this->flush();
    }

    public function flush(): void
    {
        $this->active = null;
        Cache::forget(self::CACHE_KEY);
    }
}
