<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiLanguageVariant;

class AiLanguageVariantRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['language.translations'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(AiLanguageVariant $model)
    {
        $this->model = $model;
    }
}
