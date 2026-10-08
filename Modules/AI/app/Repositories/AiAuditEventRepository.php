<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiAuditEvent;

class AiAuditEventRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner', 'actor'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiAuditEvent $model)
    {
        $this->model = $model;
    }
}
