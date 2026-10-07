<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
