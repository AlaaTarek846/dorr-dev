<?php

namespace App\Services\General;

use App\Http\Resources\General\MobileRatingResource;
use App\Models\Rating;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Modules\User\Models\User;

/**
 * The mobile side of ratings. One row per person per thing (the app, or an entity from [Rating::RATEABLES]):
 * the first POST creates it, later POSTs update the same stars and comment.
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
        $stars = round((float) $data['stars'], 2);
        $comment = array_key_exists('comment', $data)
            ? (filled($data['comment']) ? trim((string) $data['comment']) : null)
            : null;
        $commentProvided = array_key_exists('comment', $data);

        $existing = Rating::query()->where('unique_key', $uniqueKey)->first();

        if ($existing !== null) {
            return $this->persistUpdate($existing, $stars, $comment, $commentProvided);
        }

        try {
            $rating = Rating::query()->create([
                'author_type' => $user->getMorphClass(),
                'author_id' => $user->getKey(),
                'rateable_type' => $rateable?->getMorphClass(),
                'rateable_id' => $rateable?->getKey(),
                'unique_key' => $uniqueKey,
                'stars' => $stars,
                'type' => Rating::typeForStars($stars),
                'comment' => $comment,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = Rating::query()->where('unique_key', $uniqueKey)->firstOrFail();

            return $this->persistUpdate($existing, $stars, $comment, $commentProvided);
        }

        return ApiResponse::success(new MobileRatingResource($rating), __('api.rating_saved'), 201);
    }

    private function persistUpdate(Rating $rating, float $stars, ?string $comment, bool $commentProvided): JsonResponse
    {
        $rating->stars = $stars;
        $rating->type = Rating::typeForStars($stars);
        if ($commentProvided) {
            $rating->comment = $comment;
        }
        $rating->save();

        return ApiResponse::success(new MobileRatingResource($rating->fresh()), __('api.rating_updated'));
    }

    private function resolveRateable(?string $alias, ?int $id): ?Model
    {
        if ($alias === null || $id === null) {
            return null;
        }

        $class = Rating::RATEABLES[$alias] ?? null;

        return $class !== null ? $class::query()->find($id) : null;
    }
}
