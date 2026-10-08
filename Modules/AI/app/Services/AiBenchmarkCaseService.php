<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiBenchmarkCaseResource;
use Modules\AI\Repositories\AiBenchmarkCaseRepository;

class AiBenchmarkCaseService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiBenchmarkCaseResource::class;

    public function __construct(AiBenchmarkCaseRepository $repository)
    {
        parent::__construct($repository);
    }
}
