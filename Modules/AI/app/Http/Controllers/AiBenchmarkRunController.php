<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\AI\Services\AiBenchmarkRunService;

class AiBenchmarkRunController extends Controller
{
    public function __construct(protected AiBenchmarkRunService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $run)
    {
        return $this->service->find($run);
    }

    public function store(Request $request)
    {
        $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'domain_key' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->service->trigger(
            $request->user('admin_api'),
            $request->integer('provider_id') ?: null,
            $request->string('domain_key')->value() ?: null,
        );
    }
}
