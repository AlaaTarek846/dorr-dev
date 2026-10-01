<?php

namespace App\Http\Requests\General;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranslationFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'validateFile', 'import' => [
                'file' => [
                    'required',
                    'file',
                    'max:'.(int) config('translations.max_upload_kb', 2048),
                    'extensions:json,csv',
                    'mimetypes:application/json,text/plain,text/csv,application/csv,text/x-csv,application/vnd.ms-excel',
                ],
            ],
            'export' => [
                'format' => ['nullable', Rule::in(['json', 'csv'])],
                'mode' => ['nullable', Rule::in(['all', 'missing'])],
            ],
            'exportAndroid' => [
                'source' => ['nullable', Rule::in(['published', 'draft'])],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => __('validation.attributes.file'),
        ];
    }
}
