<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiUserLanguagePreferenceResource;
use Modules\AI\Repositories\AiUserLanguagePreferenceRepository;

class AiUserLanguagePreferenceService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiUserLanguagePreferenceResource::class;

    public function __construct(AiUserLanguagePreferenceRepository $repository)
    {
        parent::__construct($repository);
    }
}
