<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatSetting;

/**
 * One message. Files go in `files[]` (multipart); the size limit is the admin's chat setting.
 */
class SendMessageRequest extends FormRequest
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
        $maxKb = ChatSetting::current()->max_file_size_mb * 1024;

        return [
            'type' => ['required', Rule::in(MessageType::sendable())],
            'uuid' => ['nullable', 'uuid'],
            'body' => ['nullable', 'string', 'max:65000'],
            'reply_to' => ['nullable', 'uuid'],
            'mentions' => ['nullable', 'array', 'max:100'],
            'mentions.*' => ['integer'],

            'files' => ['nullable', 'array', 'max:30'],
            'files.*' => ['file', 'max:'.$maxKb, 'mimes:jpeg,jpg,png,webp,gif,heic,heif,mp4,mov,3gp,mkv,webm,mp3,m4a,aac,ogg,oga,opus,wav,amr,flac,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z'],

            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:36000000'],
            'waveform' => ['nullable', 'array', 'max:200'],
            'waveform.*' => ['integer', 'min:0', 'max:100'],

            'latitude' => ['required_if:type,location', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:type,location', 'nullable', 'numeric', 'between:-180,180'],
            'location_name' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:500'],

            'contact_name' => ['required_if:type,contact', 'nullable', 'string', 'max:150'],
            'contact_phones' => ['required_if:type,contact', 'nullable', 'array', 'max:10'],
            'contact_phones.*' => ['string', 'max:32'],

            'wallet_transaction_id' => ['required_if:type,wallet_transfer', 'nullable', 'uuid'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ];
    }
}
