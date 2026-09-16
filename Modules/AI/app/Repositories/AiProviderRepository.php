<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Models\AiProvider;

class AiProviderRepository extends BaseRepository
{
    protected array $orderBy = [
        'id' => 'asc',
    ];

    public function __construct(AiProvider $model)
    {
        $this->model = $model;
    }

    /**
     * Return every configured provider, creating the default rows the
     * first time this is called so the screen always has all providers
     * available even before any seeder has run.
     *
     * @return Collection<int, AiProvider>
     */
    public function all(): Collection
    {
        $this->ensureDefaults();

        return $this->query()->orderBy('id')->get();
    }

    public function findByKey(string $key): AiProvider
    {
        $this->ensureDefaults();

        return $this->query()->where('key', $key)->firstOrFail();
    }

    public function updateByKey(string $key, array $data): AiProvider
    {
        $provider = $this->findByKey($key);
        $provider->update($this->prepareData($data));

        return $provider->refresh();
    }

    /**
     * Mark exactly one provider as the default used for the user-facing
     * chat, clearing the flag on every other row.
     */
    public function setDefault(string $key): AiProvider
    {
        return DB::transaction(function () use ($key) {
            $this->model->newQuery()->where('is_default', true)->update(['is_default' => false]);

            $provider = $this->query()->where('key', $key)->firstOrFail();
            $provider->update(['is_default' => true]);

            return $provider->refresh();
        });
    }

    /**
     * The provider the chat feature should actually use: the explicit
     * default when one is set, or the single enabled-and-configured
     * provider when there is exactly one - otherwise null (nothing
     * configured, or more than one enabled without an explicit default).
     */
    public function resolveActiveForChat(): ?AiProvider
    {
        $usable = $this->all()->filter(fn (AiProvider $provider) => $provider->isUsableForChat());

        if ($usable->isEmpty()) {
            return null;
        }

        $default = $usable->firstWhere('is_default', true);

        if ($default) {
            return $default;
        }

        return $usable->count() === 1 ? $usable->first() : null;
    }

    public function ensureDefaults(): void
    {
        foreach (AiProviderKey::cases() as $providerKey) {
            $config = config("ai.providers.{$providerKey->value}", []);

            $this->model->newQuery()->firstOrCreate(
                ['key' => $providerKey->value],
                [
                    'name' => Arr::get($config, 'name', $providerKey->label()),
                    'is_enabled' => false,
                    'model' => Arr::get($config, 'default_model'),
                    'temperature' => 0.7,
                ],
            );
        }
    }

    protected function prepareData(array $data): array
    {
        // `key` identifies the row and must never be mass-assigned away.
        return Arr::except(parent::prepareData($data), ['key']);
    }
}
