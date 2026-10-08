<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiFileService;

class AiFileController extends Controller
{
    public function __construct(protected AiFileService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $file)
    {
        return $this->service->find($file);
    }
}
