<?php

namespace App\Http\Resources\General;

use Illuminate\Database\Eloquent\Model;

final class ReferralPartyResource
{
    /**
     * @return array{id: int|string, type: string, name: string|null, phone: string|null, email: string|null}
     */
    public static function make(string $type, int|string $id, ?Model $model): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'name' => $model->name ?? null,
            'phone' => $model->phone ?? null,
            'email' => $model->email ?? null,
        ];
    }
}
