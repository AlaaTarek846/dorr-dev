<?php

namespace Modules\AI\Tests\Feature\Concerns;

use App\Enums\UserStatus;
use App\Models\Flag;
use App\Models\Language;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiRoutingEngine;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared setup of the routing screens' catalog tests (intents, learned phrases, routing policies, gateways,
 * routing rules): two stored languages, an admin holding only the permissions a test names, and the routing engine.
 */
trait BuildsAiRoutingScreens
{
    /** @var list<string> */
    protected array $catalogActions = ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'];

    protected function seedStoredLanguages(): void
    {
        $flag = Flag::create(['code' => 'sa']);

        foreach ([['ar', 'rtl', true], ['en', 'ltr', false]] as [$code, $direction, $default]) {
            Language::create([
                'code' => $code, 'direction' => $direction, 'is_default_website' => $default, 'is_default_dashboard' => $default,
                'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id,
            ]);
        }
    }

    /**
     * @param  list<string>|null  $actions  of $group (null = every catalog action)
     * @param  list<string>  $extra  full permission names of other groups
     */
    protected function actingAsScreenAdmin(string $group, ?array $actions = null, array $extra = []): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = [...array_map(fn ($action) => "{$group}.{$action}", $actions ?? $this->catalogActions), ...$extra];

        foreach ([...array_map(fn ($action) => "{$group}.{$action}", $this->catalogActions), ...$extra] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }

        $admin = Admin::query()->create([
            'name' => 'Routing Admin', 'email' => 'routing-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]);
        $admin->givePermissionTo($names);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    protected function chatOwner(): User
    {
        return User::query()->create([
            'name' => 'Chat', 'email' => 'chat-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]);
    }

    /** A provider the engine may route to (enabled, with a key). */
    protected function usableProvider(string $key = 'openai'): AiProvider
    {
        app(AiProviderRepository::class)->ensureDefaults();

        $provider = AiProvider::query()->where('key', $key)->firstOrFail();
        $provider->update(['is_enabled' => true, 'api_key' => 'sk-test']);

        return $provider->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveRouting(string $message = 'hello there'): array
    {
        return app(AiRoutingEngine::class)->resolve($this->chatOwner(), $message, null, app(AiProviderRepository::class));
    }
}
