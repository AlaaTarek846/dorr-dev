<?php

namespace App\Services\General;

use App\Http\Resources\General\MobileRatingResource;
use App\Models\Rating;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\User;

/**
 * The mobile side of ratings. A person rates a given thing (the app, or an entity from [Rating::RATEABLES]) once:
 * a second attempt is refused, and the app shows the rating they already gave.
 */
class MobileRatingService
{
    /**
     * Whether this user already rated the thing, and the rating they gave. `data` is always an object
     * (`{ rated: bool, rating: {...}|null }`) so a client can read it the same way both times.
     */
    public function mine(User $user, ?string $rateableType, ?int $rateableId): JsonResponse
    {
        $rating = Rating::query()
            ->where('unique_key', Rating::uniqueKeyFor($user, $this->resolveRateable($rateableType, $rateableId)))
            ->first();

        return ApiResponse::success([
            'rated' => $rating !== null,
            'rating' => $rating !== null ? new MobileRatingResource($rating) : null,
        ], __('api.retrieved'));
    }

    /**
     * @param  array{stars: float|int, comment?: string|null, rateable_type?: string|null, rateable_id?: int|null}  $data
     */
    public function submit(User $user, array $data): JsonResponse
    {
        $rateable = $this->resolveRateable($data['rateable_type'] ?? null, $data['rateable_id'] ?? null);
        $uniqueKey = Rating::uniqueKeyFor($user, $rateable);

        if (Rating::query()->where('unique_key', $uniqueKey)->exists()) {
            throw $this->alreadyRated();
        }

        try {
            $rating = Rating::query()->create([
                'author_type' => $user->getMorphClass(),
                'author_id' => $user->getKey(),
                'rateable_type' => $rateable?->getMorphClass(),
                'rateable_id' => $rateable?->getKey(),
                'unique_key' => $uniqueKey,
                'stars' => round((float) $data['stars'], 2),
                'type' => Rating::typeForStars((float) $data['stars']),
                'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two taps at once: the database key decides.
            throw $this->alreadyRated();
        }

        return ApiResponse::success(new MobileRatingResource($rating), __('api.rating_saved'), 201);
    }

    private function resolveRateable(?string $alias, ?int $id): ?Model
    {
        if ($alias === null || $id === null) {
            return null;
        }

        $class = Rating::RATEABLES[$alias] ?? null;

        return $class !== null ? $class::query()->find($id) : null;
    }

    private function alreadyRated(): ValidationException
    {
        return ValidationException::withMessages([
            'rating' => [__('api.rating_already_submitted')],
        ]);
    }
}
