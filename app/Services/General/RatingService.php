<?php

namespace App\Services\General;

use App\Http\Resources\General\RatingResource;
use App\Repositories\General\RatingRepository;
use App\Services\BaseService;

/**
 * The dashboard side of ratings: list, show, delete (the people's own ratings are never edited).
 */
class RatingService extends BaseService
{
    protected ?string $resource = RatingResource::class;

    public function __construct(RatingRepository $repository)
    {
        parent::__construct($repository);
    }
}
