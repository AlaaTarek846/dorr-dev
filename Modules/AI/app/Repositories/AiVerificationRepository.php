<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiVerification;

class AiVerificationRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['request', 'verifierProvider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiVerification $model)
    {
        $this->model = $model;
    }
}
