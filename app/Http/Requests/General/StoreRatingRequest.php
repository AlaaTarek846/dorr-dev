<?php

namespace App\Http\Requests\General;

use App\Models\Rating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('user_api');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $alias = $this->input('rateable_type');
        $class = is_string($alias) ? (Rating::RATEABLES[$alias] ?? null) : null;

        return [
            'stars' => ['required', 'numeric', 'between:1,5', 'multiple_of:0.25'],
            'comment' => ['nullable', 'string', 'min:5', 'max:300'],
            // Empty = the app itself.
            'rateable_type' => ['nullable', 'string', Rule::in(array_keys(Rating::RATEABLES))],
            'rateable_id' => [
                Rule::requiredIf(fn () => $this->filled('rateable_type')),
                'nullable',
                'integer',
                $class !== null ? Rule::exists((new $class)->getTable(), 'id') : Rule::prohibitedIf(fn () => ! $this->filled('rateable_type')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.min' => __('api.rating_comment_length'),
            'comment.max' => __('api.rating_comment_length'),
        ];
    }
}
