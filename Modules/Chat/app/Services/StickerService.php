<?php

namespace Modules\Chat\Services;

use App\Support\LocaleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatSticker;
use Modules\Chat\Models\ChatStickerPack;
use Modules\Chat\Models\ChatUserSticker;
use Modules\Chat\Support\ParticipantType;

/**
 * Dorr's own sticker packs (the admin makes them): the list people pick from, the admin's
 * catalogue, and the data a sent sticker message keeps.
 */
class StickerService
{
    private const CACHE_KEY = 'chat.sticker_packs';

    /**
     * Active packs with their stickers, in the admin's order — cached (every sticker panel asks).
     *
     * @return list<array<string, mixed>>
     */
    public function packs(): array
    {
        $locale = app()->getLocale();

        return Cache::rememberForever(self::CACHE_KEY.'.'.$locale, fn () => ChatStickerPack::query()->active()
            ->with(['translations', 'media', 'stickers.media'])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (ChatStickerPack $p) => $p->stickers->isNotEmpty())
            ->map(fn (ChatStickerPack $p) => [
                'id' => $p->id,
                'name' => $p->translatedName(),
                'cover' => $p->coverUrl(),
                'stickers' => $p->stickers->map(fn (ChatSticker $s) => $s->present())->values()->all(),
            ])->values()->all());
    }

    /**
     * What a sticker message stores.
     *
     * @return array<string, mixed>
     */
    public function meta(int $stickerId): array
    {
        $sticker = ChatSticker::query()->with(['media', 'pack'])->find($stickerId);

        if ($sticker === null || ! $sticker->pack?->status || $sticker->imageUrl() === null) {
            throw new ChatException('sticker_not_found', 422);
        }

        return ['source' => 'pack', 'kind' => 'sticker'] + $sticker->present();
    }

    // ---------------------------------------------------------------- my stickers

    /** How many stickers one person can keep in "My stickers". */
    public const MINE_LIMIT = 200;

    /**
     * The stickers I made, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(Model $me): array
    {
        return ChatUserSticker::query()
            ->where('owner_type', ParticipantType::aliasFor($me))->where('owner_id', $me->getKey())
            ->with('media')->latest('id')->get()
            ->map(fn (ChatUserSticker $s) => $s->present())->values()->all();
    }

    /**
     * Keep a sticker I made from a photo (the app cuts it out and sends a 512×512 transparent image).
     *
     * @return array<string, mixed>
     */
    public function addMine(Model $me, UploadedFile $image, ?string $emoji = null): array
    {
        $type = ParticipantType::aliasFor($me);
        $count = ChatUserSticker::query()->where('owner_type', $type)->where('owner_id', $me->getKey())->count();
        if ($count >= self::MINE_LIMIT) {
            throw new ChatException('my_stickers_full', 422, ['limit' => self::MINE_LIMIT]);
        }

        $sticker = ChatUserSticker::query()->create(['owner_type' => $type, 'owner_id' => $me->getKey(), 'emoji' => $emoji]);
        [$width, $height] = @getimagesize($image->getRealPath()) ?: [null, null];
        $sticker->addMedia($image)->usingFileName('sticker.'.strtolower($image->getClientOriginalExtension() ?: 'webp'))
            ->withCustomProperties(['width' => $width, 'height' => $height])
            ->toMediaCollection(ChatUserSticker::IMAGE);

        return $sticker->load('media')->present();
    }

    /**
     * Take one out of "My stickers" — hidden, not erased: chats that have it keep showing it.
     */
    public function removeMine(Model $me, int $id): void
    {
        $this->ownSticker($me, $id)->delete();
    }

    /**
     * What a message with one of my stickers stores.
     *
     * @return array<string, mixed>
     */
    public function myMeta(Model $me, int $id): array
    {
        $sticker = $this->ownSticker($me, $id)->load('media');
        if ($sticker->imageUrl() === null) {
            throw new ChatException('sticker_not_found', 422);
        }

        return ['source' => 'mine', 'kind' => 'sticker'] + $sticker->present();
    }

    private function ownSticker(Model $me, int $id): ChatUserSticker
    {
        $sticker = ChatUserSticker::query()->find($id);
        if ($sticker === null || ! $sticker->isOwnedBy(ParticipantType::aliasFor($me), (int) $me->getKey())) {
            throw new ChatException('sticker_not_found', 422);
        }

        return $sticker;
    }

    // ---------------------------------------------------------------- admin

    /**
     * @param  array<string, mixed>  $data  sort_order, status, translations[{locale, name}]
     */
    public function savePack(?ChatStickerPack $pack, array $data, ?UploadedFile $cover): ChatStickerPack
    {
        $pack = DB::transaction(function () use ($pack, $data, $cover) {
            $pack ??= new ChatStickerPack;
            $pack->fill(collect($data)->except('translations')->all())->save();

            foreach ($data['translations'] ?? [] as $row) {
                $pack->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }
            if ($cover !== null) {
                $pack->setSingleMedia(ChatStickerPack::COVER, $cover);
            }

            return $pack;
        });

        $this->flush();

        return $pack->load(['translations', 'media', 'stickers.media']);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function addStickers(ChatStickerPack $pack, array $files, ?string $emoji = null): ChatStickerPack
    {
        $order = (int) $pack->stickers()->max('sort_order');

        foreach ($files as $file) {
            $sticker = $pack->stickers()->create(['emoji' => $emoji, 'sort_order' => ++$order]);
            [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];
            $sticker->addMedia($file)->usingFileName('sticker.'.strtolower($file->getClientOriginalExtension() ?: 'webp'))
                ->withCustomProperties(['width' => $width, 'height' => $height])
                ->toMediaCollection(ChatSticker::IMAGE);
        }

        $this->flush();

        return $pack->load(['translations', 'media', 'stickers.media']);
    }

    public function updateSticker(ChatSticker $sticker, array $data): void
    {
        $sticker->update(array_intersect_key($data, array_flip(['emoji', 'sort_order'])));
        $this->flush();
    }

    public function deleteSticker(ChatSticker $sticker): void
    {
        $sticker->cleanMedia();
        $sticker->delete();
        $this->flush();
    }

    public function deletePack(ChatStickerPack $pack): void
    {
        $pack->stickers->each(fn (ChatSticker $s) => $s->cleanMedia());
        $pack->cleanMedia();
        $pack->delete();
        $this->flush();
    }

    /**
     * Sent stickers keep working after a pack changes (their messages store their own URL).
     */
    public function flush(): void
    {
        foreach (LocaleResolver::supported() as $locale) {
            Cache::forget(self::CACHE_KEY.'.'.$locale);
        }
    }
}
