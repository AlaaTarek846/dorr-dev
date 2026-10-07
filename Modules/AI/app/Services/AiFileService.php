<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiFileResource;
use Modules\AI\Repositories\AiFileRepository;

class AiFileService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiFileResource::class;

    public function __construct(AiFileRepository $repository)
    {
        parent::__construct($repository);
    }

    /**
     * Phase 8 (doc S49 - extend the existing File Engine admin
     * dashboard rather than building a new one): eager-loads chunk rows
     * so AiFileResource can report chunk count/chunking status/
     * indexing status/failed chunks/last indexed time without an extra
     * round trip. Only overridden here (the single-file admin view),
     * never on the paginated list() path, so the list endpoint's
     * performance is unaffected.
     */
    public function show(int|string $id): \Illuminate\Database\Eloquent\Model
    {
        return parent::show($id)->loadMissing(['chunks', 'conversationFiles']);
    }
}
