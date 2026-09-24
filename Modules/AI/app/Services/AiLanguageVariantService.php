<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiLanguageVariantResource;
use Modules\AI\Repositories\AiLanguageVariantRepository;

class AiLanguageVariantService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiLanguageVariantResource::class;

    public function __construct(AiLanguageVariantRepository $repository)
    {
        parent::__construct($repository);
    }
}
