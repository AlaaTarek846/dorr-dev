<?php

namespace App\Http\Requests\General;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MobileAppFontRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('mobile_app_font');

        return match ($this->route()->getActionMethod()) {
            'store' => array_merge($this->baseRules($id), $this->fileRules()),
            'update' => array_merge($this->baseRules($id), $this->fileRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('mobile_app_fonts'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $id): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', Rule::unique('mobile_app_fonts', 'name')->ignore($id)],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('mobile_app_fonts', 'slug')->ignore($id)],
            'status' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'remove_font_file_ids' => ['nullable', 'array'],
            'remove_font_file_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fileRules(): array
    {
        return [
            'font_files' => ['nullable', 'array'],
            'font_files.*' => ['file', 'mimes:ttf,otf', 'max:5120'],
        ];
    }
}
