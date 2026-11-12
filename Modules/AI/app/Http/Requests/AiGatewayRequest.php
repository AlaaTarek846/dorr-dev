<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiGateway;

/** Admin AI gateway form - same shape as the catalog requests (CountryRequest, AiPlanRequest). */
class AiGatewayRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        // Older callers send is_active; the catalog column is status.
        if (! $this->has('status') && $this->has('is_active')) {
            $this->merge(['status' => $this->input('is_active')]);
        }

        if ($this->has('status') && $this->input('status') !== null) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('default_policy_id') && $this->input('default_policy_id') === '') {
            $this->merge(['default_policy_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->gatewayTranslationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_gateways'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        // A policy in the trash can't be picked - unless it is already this gateway's (saving it again keeps it).
        $current = $this->route()->getActionMethod() === 'update'
            ? AiGateway::withTrashed()->whereKey($this->route('gateway'))->value('default_policy_id')
            : null;

        return [
            'environment' => ['nullable', 'string', 'in:production,staging,development'],
            'default_policy_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_routing_policies', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->when($current, fn ($q) => $q->orWhere('id', $current))),
            ],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * One name per stored language.
     *
     * @return array<string, mixed>
     */
    protected function gatewayTranslationRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.name'),
        ];
    }
}
