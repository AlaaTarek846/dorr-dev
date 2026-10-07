<?php

namespace Modules\Chat\Services;

use App\Repositories\General\LanguageRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCategory;
use Modules\Chat\Models\ChatPortal;
use Modules\Chat\Models\ChatPortalView;
use Modules\Chat\Support\ParticipantType;

/**
 * Merchant portals (docs/remaining_chat.md ج.3): anyone adds their website as a portal (as many
 * as they like); a paid period puts it on the portals page — searchable, grouped by category, the
 * most viewed first. When the period ends the portal stays theirs, off the page until renewed.
 */
class PortalService
{
    /** Portals one person may have (listed or not). */
    public const MAX_PER_OWNER = 20;

    public function __construct(private readonly ChatAiService $ai) {}

    /**
     * The portals page: listed portals, grouped by category (in the admin's order, "other" last),
     * the most viewed first in each. A search looks at the names and descriptions in every language.
     *
     * @return list<array<string, mixed>>
     */
    public function directory(?string $search, ?int $categoryId): array
    {
        $search = trim((string) $search);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $portals = ChatPortal::query()->listed()
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->when($search !== '', fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('name', 'like', $like)->orWhere('description', 'like', $like)))
            ->with(['translations', 'media', 'category.translations', 'category.media'])
            ->orderByDesc('views_count')->orderBy('id')
            ->limit(500)
            ->get();

        $categories = ChatCategory::query()->active()->with(['translations', 'media'])->orderBy('sort_order')->orderBy('id')->get();

        $groups = [];
        foreach ($categories as $category) {
            $rows = $portals->where('category_id', $category->id)->values();
            if ($rows->isNotEmpty()) {
                $groups[] = ['category' => $category->brief(), 'portals' => $rows->map(fn ($p) => $this->present($p))->all()];
            }
        }

        $rest = $portals->reject(fn ($p) => $categories->contains('id', $p->category_id))->values();
        if ($rest->isNotEmpty()) {
            $groups[] = ['category' => null, 'portals' => $rest->map(fn ($p) => $this->present($p))->all()];
        }

        return $groups;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function mine(Model $me): Collection
    {
        return ChatPortal::query()->ownedBy($me)
            ->with(['translations', 'media', 'category.translations', 'category.media'])
            ->latest('id')->get()
            ->map(fn (ChatPortal $p) => $this->present($p, true));
    }

    /**
     * @param  array{website_url?: string, category_id?: int|null, translations?: list<array{locale: string, name: string, description?: string|null}>}  $data
     */
    public function save(Model $me, ?ChatPortal $portal, array $data, ?UploadedFile $logo): ChatPortal
    {
        if ($portal !== null && ! $portal->isOwnedBy($me)) {
            throw new ChatException('portal_not_found', 404);
        }

        if ($portal === null && ChatPortal::query()->ownedBy($me)->count() >= self::MAX_PER_OWNER) {
            throw new ChatException('portal_limit', 422, ['max' => self::MAX_PER_OWNER]);
        }

        if (array_key_exists('category_id', $data) && $data['category_id'] !== null
            && ! ChatCategory::query()->active()->whereKey($data['category_id'])->exists()) {
            throw new ChatException('category_not_found', 422);
        }

        return DB::transaction(function () use ($me, $portal, $data, $logo) {
            $portal ??= new ChatPortal([
                'uuid' => (string) Str::uuid(),
                'owner_type' => ParticipantType::aliasFor($me),
                'owner_id' => $me->getKey(),
                'status' => true,
            ]);

            $portal->fill(collect($data)->only(['website_url', 'category_id'])->all())->save();

            $allowed = LanguageRepository::storableLocaleCodes();
            foreach ($data['translations'] ?? [] as $row) {
                $locale = strtolower((string) $row['locale']);
                if ($allowed !== [] && ! in_array($locale, $allowed, true)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $portal->translations()->where('locale', $locale)->delete();

                    continue;
                }
                $portal->translations()->updateOrCreate(['locale' => $locale], [
                    'name' => mb_substr($name, 0, 120),
                    'description' => isset($row['description']) ? mb_substr(trim((string) $row['description']), 0, 2000) : null,
                ]);
            }

            if (! $portal->translations()->exists()) {
                throw new ChatException('portal_name_required', 422);
            }

            if ($logo !== null) {
                $portal->setSingleMedia(ChatPortal::LOGO, $logo);
            }

            return $portal->refresh()->load(['translations', 'media', 'category.translations', 'category.media']);
        });
    }

    public function delete(Model $me, ChatPortal $portal): void
    {
        if (! $portal->isOwnedBy($me)) {
            throw new ChatException('portal_not_found', 404);
        }

        $portal->delete();
    }

    /**
     * Someone opens a portal: one view per person per day, and its website to open.
     */
    public function open(Model $me, ChatPortal $portal): ChatPortal
    {
        if (! $portal->isListed() && ! $portal->isOwnedBy($me)) {
            throw new ChatException('portal_not_found', 404);
        }

        if (! $portal->isOwnedBy($me)) {
            try {
                ChatPortalView::query()->create([
                    'chat_portal_id' => $portal->id,
                    'viewer_type' => ParticipantType::aliasFor($me),
                    'viewer_id' => $me->getKey(),
                    'viewed_on' => now()->toDateString(),
                ]);
                $portal->increment('views_count');
            } catch (QueryException) {
                // Already counted today.
            }
        }

        return $portal->refresh()->load(['translations', 'media', 'category.translations', 'category.media']);
    }

    /**
     * The name and description written in one language, in all the app's other languages — for
     * the merchant to look over before saving.
     *
     * @param  array{name: string, description?: string|null}  $fields
     * @return array<string, array{name: string, description: ?string}>
     */
    public function translate(string $from, array $fields): array
    {
        $to = array_values(array_filter(LanguageRepository::storableLocaleCodes(), fn ($code) => $code !== $from));

        if ($to === []) {
            return [];
        }

        return $this->ai->translateFields(array_filter(['name' => $fields['name'] ?? '', 'description' => $fields['description'] ?? null], fn ($v) => $v !== null && $v !== ''), $from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatPortal $portal, bool $mine = false): array
    {
        $row = [
            'id' => $portal->uuid,
            'name' => $portal->translatedName(),
            'description' => $portal->translated('description'),
            'logo' => $portal->logoUrl(),
            'website_url' => $portal->website_url,
            'category' => $portal->category?->brief(),
            'views_count' => $portal->views_count,
        ];

        if ($mine) {
            $row += [
                'status' => $portal->status,
                'is_listed' => $portal->isListed(),
                'listed_until' => $portal->listed_until?->toIso8601String(),
                'translations' => $portal->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name, 'description' => $t->description])->values()->all(),
            ];
        }

        return $row;
    }
}
