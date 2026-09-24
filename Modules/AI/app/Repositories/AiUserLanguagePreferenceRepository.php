<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiUserLanguagePreference;

class AiUserLanguagePreferenceRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner', 'language', 'variant'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiUserLanguagePreference $model)
    {
        $this->model = $model;
    }
}
