<?php

namespace Modules\AI\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AI\Repositories\AiProviderRepository;

class AIDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $repository = app(AiProviderRepository::class);
        $repository->ensureDefaults();

        $this->seedGroqTestKey($repository);
    }

    /**
     * Local development convenience: if AI_GROQ_SEED_API_KEY is set in the
     * environment, save it on the Groq provider and enable it, so a fresh
     * migrate/seed doesn't require re-entering it by hand from the admin
     * screen every time. Does nothing when the env var is absent (e.g. in
     * any environment other than the developer's own machine).
     */
    protected function seedGroqTestKey(AiProviderRepository $repository): void
    {
        $apiKey = env('AI_GROQ_SEED_API_KEY');

        if (blank($apiKey)) {
            return;
        }

        $repository->updateByKey('groq', [
            'api_key' => $apiKey,
            'is_enabled' => true,
            'model' => config('ai.providers.groq.default_model'),
            'temperature' => 0.7,
        ]);

        // Also make it the active chat model, so the user-facing chat works
        // right away instead of failing with "no active provider" until an
        // admin picks one by hand from the AI settings screen.
        $repository->setDefault('groq');
    }
}
