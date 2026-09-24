<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiConversationAttachmentService;

class AiConversationAttachmentController extends Controller
{
    public function __construct(protected AiConversationAttachmentService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $attachment)
    {
        return $this->service->find($attachment);
    }
}
