<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\TranslationFileRequest;
use App\Services\General\TranslationService;
use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Translation files of a language (Languages page → Translations). Uses the `languages`
 * permission group: view = overview/export, update = validate/import/publish/discard.
 */
class TranslationController extends Controller implements HasMiddleware
{
    public function __construct(protected TranslationService $service) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('languages', [
            ['view', ['index', 'export', 'exportAndroid']],
            ['update', ['validateFile', 'import', 'publish', 'discardDraft']],
        ]);
    }

    public function index(int|string $language)
    {
        return $this->service->overview($language);
    }

    public function export(TranslationFileRequest $request, int|string $language, string $platform, string $group)
    {
        return $this->service->export(
            $language,
            $platform,
            $group,
            $request->validated('format') ?? 'csv',
            $request->validated('mode') ?? 'all',
        );
    }

    public function exportAndroid(TranslationFileRequest $request, int|string $language)
    {
        return $this->service->exportAndroid($language, $request->validated('source') ?? 'published');
    }

    public function validateFile(TranslationFileRequest $request, int|string $language, string $platform, string $group)
    {
        return $this->service->validateUpload($language, $platform, $group, $request->file('file'));
    }

    public function import(TranslationFileRequest $request, int|string $language, string $platform, string $group)
    {
        return $this->service->import($language, $platform, $group, $request->file('file'), $this->adminId());
    }

    public function publish(int|string $language, string $platform, string $group)
    {
        return $this->service->publish($language, $platform, $group, $this->adminId());
    }

    public function discardDraft(int|string $language, string $platform, string $group)
    {
        return $this->service->discardDraft($language, $platform, $group, $this->adminId());
    }

    protected function adminId(): ?int
    {
        $id = auth('admin_api')->id();

        return $id === null ? null : (int) $id;
    }
}
