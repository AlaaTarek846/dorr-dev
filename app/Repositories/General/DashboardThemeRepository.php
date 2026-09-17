<?php

namespace App\Repositories\General;

use App\Enums\Status;
use App\Exceptions\ConflictException;
use App\Models\DashboardTheme;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Model;

class DashboardThemeRepository extends TranslatableRepository
{
    /**
     * @var list<string>
     */
    protected array $with = ['translations', 'translation'];

    /**
     * @var list<string>
     */
    protected array $deleteBlockRelations = ['preferences'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    public function __construct(DashboardTheme $model)
    {
        $this->model = $model;
    }

    public function changeStatus(int|string $id, bool $status): DashboardTheme
    {
        /** @var DashboardTheme $theme */
        $theme = $this->query()->findOrFail($id);

        if ($theme->is_default && ! $status) {
            throw new ConflictException(__('api.dashboard_theme_default_inactive'));
        }

        $theme->update(['status' => $status ? Status::Active : Status::Inactive]);

        return $this->refresh($theme);
    }

    protected function beforeDestroy(Model $model): void
    {
        /** @var DashboardTheme $model */
        if ($model->is_default) {
            throw new ConflictException(
                __('api.dashboard_theme_default_delete'),
                409,
                null,
                'is_default',
            );
        }

        parent::beforeDestroy($model);
    }

    /**
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return array_merge(parent::reservedPayloadKeys(), [
            'preview_image',
            'remove_preview_image',
        ]);
    }
}
