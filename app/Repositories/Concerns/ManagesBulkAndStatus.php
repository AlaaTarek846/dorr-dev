<?php

namespace App\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait ManagesBulkAndStatus
{
    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $deleted = 0;

            foreach ($ids as $id) {
                if ($this->destroy($id, $this->deleteBlockRelations ?? [])) {
                    $deleted++;
                }
            }

            return $deleted;
        });
    }

    public function changeStatus(int|string $id, bool $status): Model
    {
        $model = $this->query()->findOrFail($id);
        $model->update(['status' => $status]);

        return $this->refresh($model);
    }

    public function dropdown(): Collection
    {
        return $this->index()
            ->where('status', true)
            ->get()
            ->map(fn (Model $item) => [
                'id' => $item->id,
                'code' => $item->code ?? null,
                'name' => method_exists($item, 'translatedName')
                    ? $item->translatedName()
                    : null,
            ])
            ->values();
    }
}
