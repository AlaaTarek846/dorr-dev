<?php

namespace Modules\Wallet\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Exceptions\WithdrawalException;
use Modules\Wallet\Models\WithdrawalMethod;
use Modules\Wallet\Support\OwnerType;

/**
 * An owner's saved payout destinations. Every query is scoped to the owner in
 * code — there is no way to address someone else's method by id.
 */
class WithdrawalMethodService
{
    /**
     * @return Collection<int, WithdrawalMethod>
     */
    public function list(Model $owner): Collection
    {
        return $this->scope($owner)->orderByDesc('is_favorite')->orderBy('id')->get();
    }

    /**
     * @param  array{type: string, label?: string|null, data: array<string, mixed>, is_favorite?: bool}  $attributes
     */
    public function create(Model $owner, array $attributes): WithdrawalMethod
    {
        return DB::transaction(function () use ($owner, $attributes) {
            $first = ! $this->scope($owner)->exists();

            $method = WithdrawalMethod::query()->create([
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
                'type' => $attributes['type'],
                'label' => $attributes['label'] ?? null,
                'data' => $attributes['data'],
                // The very first method is the favourite by default.
                'is_favorite' => $first || ($attributes['is_favorite'] ?? false),
            ]);

            if ($method->is_favorite) {
                $this->unfavouriteOthers($owner, $method);
            }

            return $method;
        });
    }

    /**
     * The whole `data` is replaced — the owner re-enters full details, since
     * the app only ever received the masked version.
     *
     * @param  array{type: string, label?: string|null, data: array<string, mixed>, is_favorite?: bool}  $attributes
     */
    public function update(Model $owner, int $id, array $attributes): WithdrawalMethod
    {
        return DB::transaction(function () use ($owner, $id, $attributes) {
            $method = $this->find($owner, $id);

            $method->update([
                'type' => $attributes['type'],
                'label' => $attributes['label'] ?? null,
                'data' => $attributes['data'],
            ]);

            if ($attributes['is_favorite'] ?? false) {
                $this->makeFavorite($owner, $method);
            }

            return $method->refresh();
        });
    }

    public function makeFavorite(Model $owner, WithdrawalMethod $method): WithdrawalMethod
    {
        return DB::transaction(function () use ($owner, $method) {
            $method->update(['is_favorite' => true]);
            $this->unfavouriteOthers($owner, $method);

            return $method->refresh();
        });
    }

    /**
     * Soft delete: requests already made keep pointing at the method they used.
     */
    public function delete(Model $owner, int $id): void
    {
        $this->find($owner, $id)->delete();
    }

    public function find(Model $owner, int $id): WithdrawalMethod
    {
        return $this->scope($owner)->find($id) ?? throw WithdrawalException::notFound();
    }

    private function unfavouriteOthers(Model $owner, WithdrawalMethod $keep): void
    {
        $this->scope($owner)->whereKeyNot($keep->id)->update(['is_favorite' => false]);
    }

    private function scope(Model $owner): Builder
    {
        return WithdrawalMethod::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey());
    }
}
