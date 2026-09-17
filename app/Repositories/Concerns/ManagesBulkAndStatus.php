<?php

namespace App\Repositories\Concerns;

use App\Exceptions\ConflictException;
use App\Support\BulkDelete\BulkDeleteResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait ManagesBulkAndStatus
{
    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids): BulkDeleteResult
    {
        return DB::transaction(function () use ($ids) {
            $result = new BulkDeleteResult;

            foreach ($ids as $id) {
                try {
                    if ($this->destroy($id, $this->deleteBlockRelations)) {
                        $result->deleted++;
                    }
                } catch (ConflictException $exception) {
                    $result->addSkipped(
                        $id,
                        $exception->reason ?? 'conflict',
                        $exception->getMessage(),
                        isset($exception->context['relation'])
                            ? (string) $exception->context['relation']
                            : null,
                    );
                } catch (ModelNotFoundException) {
                    $result->addSkipped($id, 'not_found', __('api.not_found'));
                }
            }

            return $result;
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
