<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    /**
     * Business gap fix: nothing enforced "only one default variant per
     * language" - the admin UI's default toggle only ever updated the row
     * being saved, so marking a second variant default for the same
     * language silently left both rows is_default=true, with no defined
     * winner wherever "the language's default variant" gets looked up.
     * Enforced the same way AiProviderModelService already does for a
     * provider's default model: making a variant the default unsets
     * is_default on every other variant of the same language, inside a
     * transaction so a request never leaves two defaults set even if it
     * fails partway through.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Model
    {
        if (($data['is_default'] ?? false) && ! empty($data['language_id'])) {
            return DB::transaction(function () use ($data) {
                $this->repository->query()
                    ->where('language_id', $data['language_id'])
                    ->update(['is_default' => false]);

                return parent::store($data);
            });
        }

        return parent::store($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): Model
    {
        if (($data['is_default'] ?? false) === true) {
            return DB::transaction(function () use ($id, $data) {
                $variant = $this->repository->show($id);

                $this->repository->query()
                    ->where('language_id', $data['language_id'] ?? $variant->language_id)
                    ->where('id', '!=', $variant->id)
                    ->update(['is_default' => false]);

                return parent::update($id, $data);
            });
        }

        return parent::update($id, $data);
    }
}
