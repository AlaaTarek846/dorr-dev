<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiConversationAttachment;

class AiConversationAttachmentRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['conversation', 'message'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiConversationAttachment $model)
    {
        $this->model = $model;
    }
}
