<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Marketing\Models\MarketingProject;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->isMethod('PATCH') || $this->isMethod('PUT');
        $req = $update ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'industry' => [$req, 'string', 'min:2', 'max:120'],
            'stage' => ['sometimes', 'string', Rule::in(MarketingProject::STAGES)],
            'goals' => ['sometimes', 'nullable', 'array', 'max:10'],
            'goals.*' => ['string', 'max:200'],
            'cities' => ['sometimes', 'nullable', 'array', 'max:10'],
            'cities.*' => ['string', 'max:100'],
            'lock_version' => [$update ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }
}
