<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiTrialControl;

class AiTrialControlRepository extends BaseRepository
{
    /**
     * @var list<string>
     */
    protected array $with = ['owner'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(AiTrialControl $model)
    {
        $this->model = $model;
    }
}
