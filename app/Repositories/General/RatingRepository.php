<?php

namespace App\Repositories\General;

use App\Models\Rating;
use App\Repositories\BaseRepository;

class RatingRepository extends BaseRepository
{
    protected array $with = [
        'author',
        'rateable',
    ];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(Rating $model)
    {
        $this->model = $model;
    }
}
