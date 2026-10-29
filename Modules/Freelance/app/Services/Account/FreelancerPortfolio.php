<?php

namespace Modules\Freelance\Services\Account;

use App\Services\General\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem as Item;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A freelancer's portfolio (FL-105): works with images, a video link (YouTube / Vimeo only) and an outside link, each with
 * its own visibility. A work shown beyond its owner (`public`, `clients_only`) needs the owner's confirmation that they hold
 * the rights; the admin can hide one that breaks the rules. Only a verified freelancer's portfolio is shown to others.
 */
class FreelancerPortfolio
{
    public function __construct(private readonly AuditLogger $audit, private readonly FreelanceNotifier $notifier) {}

    // -------------------------------------------------------------- the owner

    /** @return Collection<int, Item> */
    public function mine(Freelancer $freelancer): Collection
    {
        return $freelancer->portfolioItems()->with(['media', 'skills.translations', 'specialty.translations'])->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Freelancer $freelancer, array $data): Item
    {
        if ($freelancer->portfolioItems()->count() >= (int) config('freelance.profile.max_portfolio', 30)) {
            throw new FreelanceException('profile_limit', 422, ['max' => (int) config('freelance.profile.max_portfolio', 30)], ['max' => (int) config('freelance.profile.max_portfolio', 30)]);
        }

        return DB::transaction(function () use ($freelancer, $data) {
            $item = $freelancer->portfolioItems()->create($this->fields($data, null) + ['sort_order' => (int) $freelancer->portfolioItems()->max('sort_order') + 1]);
            $this->syncSkills($item, $data['skill_ids'] ?? []);

            return $item->load(['media', 'skills.translations', 'specialty.translations']);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            $item->update($this->fields($data, $item));
            if (array_key_exists('skill_ids', $data)) {
                $this->syncSkills($item, $data['skill_ids'] ?? []);
            }

            return $item->load(['media', 'skills.translations', 'specialty.translations']);
        });
    }

    public function delete(Item $item): void
    {
        $item->clearMediaCollection('images');
        $item->delete();
    }

    /** @param  list<int>  $ids  the freelancer's works in their new order */
    public function reorder(Freelancer $freelancer, array $ids): void
    {
        $own = $freelancer->portfolioItems()->pluck('id')->all();
        if (array_diff($ids, $own) !== [] || count($ids) !== count(array_unique($ids))) {
            throw new FreelanceException('portfolio_order_invalid', 422);
        }
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                Item::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function addImage(Item $item, UploadedFile $file): Media
    {
        if ($item->getMedia('images')->count() >= (int) config('freelance.profile.max_portfolio_images', 10)) {
            throw new FreelanceException('profile_limit', 422, ['max' => (int) config('freelance.profile.max_portfolio_images', 10)], ['max' => (int) config('freelance.profile.max_portfolio_images', 10)]);
        }

        return $item->addImage($file);
    }

    public function clearAll(Freelancer $freelancer): void
    {
        $freelancer->portfolioItems()->get()->each(fn (Item $i) => $this->delete($i));
    }

    // -------------------------------------------------------------- the admin

    public function hide(Item $item, string $reason, int $adminId): Item
    {
        $item->forceFill(['hidden_by_admin_at' => now(), 'hidden_reason' => mb_substr($reason, 0, 255), 'hidden_by_admin_id' => $adminId])->save();
        $this->audit->record('freelancer.portfolio_hidden', $item, null, ['reason' => $reason, 'admin' => $adminId]);
        $this->notifier->notify($item->freelancer, 'portfolio.hidden', ['title' => $item->title, 'reason' => $reason], ['portfolio_item' => $item->uuid]);

        return $item;
    }

    public function unhide(Item $item, int $adminId): Item
    {
        $item->forceFill(['hidden_by_admin_at' => null, 'hidden_reason' => null, 'hidden_by_admin_id' => null])->save();
        $this->audit->record('freelancer.portfolio_unhidden', $item, null, ['admin' => $adminId]);

        return $item;
    }

    // -------------------------------------------------------------- what others see

    /**
     * The works of a freelancer a viewer may see: the public ones, and for a signed-in client the clients-only ones too —
     * never a hidden one, and only for a verified freelancer.
     *
     * @return Collection<int, Item>
     */
    public function visibleTo(Freelancer $freelancer, bool $client): Collection
    {
        if ($freelancer->status !== FreelancerStatus::Verified) {
            return collect();
        }

        return $freelancer->portfolioItems()->with(['media', 'skills.translations', 'specialty.translations'])
            ->whereIn('visibility', $client ? [Item::PUBLIC, Item::CLIENTS_ONLY] : [Item::PUBLIC])
            ->whereNull('hidden_by_admin_at')->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    public function present(Item $item, bool $owner): array
    {
        $images = $item->getMedia('images')->map(fn (Media $m) => [
            'id' => $m->id,
            'name' => $m->file_name,
            'size' => $m->size,
            // The owner downloads through the signed-in endpoint; anyone else gets a link that expires.
            'path' => $owner ? "profile/portfolio/{$item->id}/images/{$m->id}" : null,
            'url' => $owner ? null : URL::temporarySignedRoute('api.freelance.portfolio.image', now()->addMinutes((int) config('freelance.portfolio.link_minutes', 30)), ['media' => $m->id]),
        ])->values()->all();

        return [
            'id' => $owner ? $item->id : null,
            'uuid' => $item->uuid,
            'title' => $item->title,
            'description' => $item->description,
            'completed_on' => $item->completed_on?->toDateString(),
            'specialty' => $item->specialty === null ? null : ['id' => $item->specialty->id, 'name' => $item->specialty->translatedName()],
            'skills' => $item->skills->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            'images' => $images,
            'external_url' => $item->external_url,
            'video' => $item->video_provider === null ? null : ['provider' => $item->video_provider, 'embed_url' => $item->videoEmbedUrl()],
        ] + ($owner ? [
            'visibility' => $item->visibility,
            'rights_confirmed' => $item->rights_confirmed_at !== null,
            'sort_order' => $item->sort_order,
            'hidden' => $item->isHidden(),
            'hidden_reason' => $item->hidden_reason,
        ] : []);
    }

    // -------------------------------------------------------------- internals

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data, ?Item $current): array
    {
        $out = array_intersect_key($data, array_flip(['title', 'completed_on', 'external_url']));
        if (array_key_exists('description', $data)) {
            // Plain text only: tags are removed, line breaks kept.
            $out['description'] = $data['description'] === null ? null : (trim(strip_tags((string) $data['description'])) ?: null);
        }
        if (array_key_exists('title', $out)) {
            $out['title'] = trim(strip_tags((string) $out['title']));
        }

        $specialtyId = array_key_exists('specialty_id', $data) ? $data['specialty_id'] : $current?->freelance_specialty_id;
        if ($specialtyId !== null && ! FreelanceSpecialty::query()->where('status', true)->whereKey($specialtyId)->exists() && (int) $specialtyId !== (int) $current?->freelance_specialty_id) {
            throw new FreelanceException('portfolio_specialty_invalid', 422);
        }
        if (array_key_exists('specialty_id', $data)) {
            $out['freelance_specialty_id'] = $specialtyId;
        }

        if (array_key_exists('video_url', $data)) {
            [$out['video_provider'], $out['video_id']] = $this->parseVideo($data['video_url']);
        }

        // Shown beyond the owner only with the rights confirmed.
        $visibility = $data['visibility'] ?? $current?->visibility ?? Item::PRIVATE;
        $confirmed = array_key_exists('rights_confirmed', $data) ? (bool) $data['rights_confirmed'] : $current?->rights_confirmed_at !== null;
        if ($visibility !== Item::PRIVATE && ! $confirmed) {
            throw new FreelanceException('portfolio_rights_required', 422);
        }
        $out['visibility'] = $visibility;
        if ($confirmed && $current?->rights_confirmed_at === null) {
            $out['rights_confirmed_at'] = now();
        } elseif (! $confirmed) {
            $out['rights_confirmed_at'] = null;
        }

        return $out;
    }

    /** @param  list<int>  $ids */
    private function syncSkills(Item $item, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 10) {
            throw new FreelanceException('profile_limit', 422, ['max' => 10], ['max' => 10]);
        }
        $valid = FreelanceSkill::query()->whereIn('id', $ids)->where('status', FreelanceSkill::ACTIVE)->pluck('id')->all();
        if (array_diff($ids, $valid) !== []) {
            throw new FreelanceException('profile_skill_invalid', 422, ['ids' => implode(', ', array_diff($ids, $valid))], ['ids' => array_values(array_diff($ids, $valid))]);
        }
        $item->skills()->sync($ids);
    }

    /** @return array{0: ?string, 1: ?string} provider and id; only YouTube and Vimeo links are taken */
    private function parseVideo(?string $url): array
    {
        if ($url === null || trim($url) === '') {
            return [null, null];
        }
        $parts = parse_url(trim($url));
        $host = strtolower(preg_replace('/^www\./', '', (string) ($parts['host'] ?? '')));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $id = match (true) {
            in_array($host, ['youtube.com', 'm.youtube.com'], true) && $path === '/watch' => ['youtube', $query['v'] ?? null],
            in_array($host, ['youtube.com', 'm.youtube.com'], true) && preg_match('#^/(?:shorts|embed)/([\w-]{11})#', $path, $m) === 1 => ['youtube', $m[1]],
            $host === 'youtu.be' && preg_match('#^/([\w-]{11})#', $path, $m) === 1 => ['youtube', $m[1]],
            $host === 'vimeo.com' && preg_match('#^/(\d{6,12})#', $path, $m) === 1 => ['vimeo', $m[1]],
            default => [null, null],
        };

        if ($id[0] === null || ! is_string($id[1]) || ! preg_match($id[0] === 'vimeo' ? '/^\d{6,12}$/' : '/^[\w-]{11}$/', $id[1]) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new FreelanceException('portfolio_video_invalid', 422);
        }

        return $id;
    }
}
