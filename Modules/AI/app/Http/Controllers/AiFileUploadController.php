<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Http\Requests\AiFileUploadRequest;
use Modules\AI\Services\AiFileUploadService;

/**
 * Acceptance criteria doc S14: customer-facing File Engine API
 * (POST/GET/DELETE /user/v1/ai-files, status). Deliberately thin - every
 * method is a one-line delegation to AiFileUploadService, same shape as
 * AiChatController (doc S4: no processing logic in the controller).
 */
class AiFileUploadController extends Controller
{
    public function __construct(protected AiFileUploadService $service) {}

    public function store(AiFileUploadRequest $request)
    {
        return $this->service->store(
            $this->owner($request),
            $request->file('file'),
            $request->input('conversation_id'),
        );
    }

    public function show(Request $request, int|string $file)
    {
        return $this->service->show($this->owner($request), $file);
    }

    public function status(Request $request, int|string $file)
    {
        return $this->service->status($this->owner($request), $file);
    }

    public function destroy(Request $request, int|string $file)
    {
        return $this->service->destroy($this->owner($request), $file);
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api');
    }
}
