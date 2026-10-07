<?php

namespace App\Http\Resources\General;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rating */
class RatingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->author;
        $rateable = $this->rateable;

        return [
            'id' => $this->id,
            'stars' => (float) $this->stars,
            'type' => $this->type,
            'comment' => $this->comment,
            'author' => $author === null ? null : [
                'id' => $author->getKey(),
                'type' => class_basename($this->author_type),
                'name' => $author->name ?? null,
                'phone' => $author->phone ?? null,
                'email' => $author->email ?? null,
            ],
            // null = the app itself
            'rateable' => $this->rateable_type === null ? null : [
                'id' => $this->rateable_id,
                'type' => Rating::aliasFor($this->rateable_type),
                'name' => $rateable === null
                    ? null
                    : (method_exists($rateable, 'translatedName') ? $rateable->translatedName() : ($rateable->name ?? null)),
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
