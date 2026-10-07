<?php

use Illuminate\Database\Migrations\Migration;
use Modules\AI\Models\AiProviderModel;

return new class extends Migration
{
    /**
     * Models like sora-2 were registered before video generation existed, as
     * plain "chat" models. A video model cannot answer a chat call, so retag the
     * already-registered rows (video_output only, never a default).
     */
    public function up(): void
    {
        AiProviderModel::query()
            ->where(function ($query) {
                $query->where('model_key', 'like', '%sora%')
                    ->orWhere('model_key', 'like', 'veo-%')
                    ->orWhere('model_key', 'like', '%video-generation%');
            })
            ->get()
            ->each(function (AiProviderModel $model) {
                $model->forceFill(['capabilities' => ['video_output'], 'is_default' => false])->save();
            });
    }

    public function down(): void
    {
        // Intentionally empty: the previous ["chat"] tagging was wrong.
    }
};
