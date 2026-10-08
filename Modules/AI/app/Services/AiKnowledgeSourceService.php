<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiKnowledgeSourceResource;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Repositories\AiKnowledgeSourceRepository;

class AiKnowledgeSourceService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiKnowledgeSourceResource::class;

    public function __construct(
        AiKnowledgeSourceRepository $repository,
        protected AiKnowledgeIngestionService $ingestion,
    ) {
        parent::__construct($repository);
    }

    /**
     * Runs the ingestion pipeline (clean -> chunk -> embed -> index) for a
     * new, admin-supplied piece of reference material and creates it as a
     * pending source - it will not be used as chat evidence until an
     * admin approves it (see approve()).
     *
     * @param  array{owner_type: string, owner_id: ?int, name: string, content: string, domain: ?string, country_code: ?string, publisher: ?string, authority: ?string, published_at: ?string, effective_at: ?string, data_classification: ?string, access_scope: ?array}  $data
     */
    public function ingest(array $data): AiKnowledgeSource
    {
        return $this->ingestion->ingestText([
            'file_id' => null,
            'owner_type' => $data['owner_type'] ?? 'system',
            'owner_id' => $data['owner_id'] ?? null,
            'name' => $data['name'],
            'domain' => $data['domain'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'authority' => $data['authority'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'effective_at' => $data['effective_at'] ?? null,
            'data_classification' => $data['data_classification'] ?? AiKnowledgeSource::CLASSIFICATION_INTERNAL,
            'access_scope' => $data['access_scope'] ?? null,
        ], $data['content']);
    }

    public function approve(int $id): AiKnowledgeSource
    {
        /** @var AiKnowledgeSource $source */
        $source = $this->repository->show($id);
        $source->update(['approval_status' => AiKnowledgeSource::APPROVAL_APPROVED]);

        return $source->fresh();
    }

    public function reject(int $id): AiKnowledgeSource
    {
        /** @var AiKnowledgeSource $source */
        $source = $this->repository->show($id);
        $source->update(['approval_status' => AiKnowledgeSource::APPROVAL_REJECTED]);

        return $source->fresh();
    }

    public function deprecate(int $id): AiKnowledgeSource
    {
        /** @var AiKnowledgeSource $source */
        $source = $this->repository->show($id);
        $source->update(['approval_status' => AiKnowledgeSource::APPROVAL_DEPRECATED, 'is_active' => false]);

        return $source->fresh();
    }
}
