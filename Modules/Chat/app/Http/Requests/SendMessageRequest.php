<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\MessageExtrasService;

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

            // A frame of the video, made on the phone (the server has no video decoder).
            'thumbnail' => ['nullable', 'image', 'max:2048'],
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

            // A poll: at least two options, which the service trims and numbers. Loose values are
            // cast by pollMeta(), so only real non-strings are rejected.
            'poll_options' => ['required_if:type,poll', 'nullable', 'array', 'max:12'],
            'poll_options.*' => ['string', 'max:100', 'nullable'],
            'poll_multiple' => ['nullable', 'boolean'],

            // Asking for money, or splitting a bill.
            'amount_minor' => ['required_if:type,money_request', 'nullable', 'integer', 'min:1'],
            'split_mode' => ['nullable', Rule::in(['equal', 'custom'])],
            'split_participants' => ['nullable', 'array', 'max:100'],
            'split_participants.*' => ['integer'],
            'split_shares' => ['nullable', 'array', 'max:100'],
            'split_shares.*.participant_id' => ['required', 'integer'],
            'split_shares.*.amount_minor' => ['required', 'integer', 'min:1'],

            // A GIF / sticker: only its id, so the server builds the file's address itself. A URL
            // from the client is never trusted.
            'giphy_id' => ['required_if:type,gif', 'nullable', 'string', 'max:64'],
            'sticker_id' => ['required_if:type,sticker', 'nullable', 'integer'],

            // A photo or video the recipient may see once, then it is gone.
            'view_once' => ['nullable', 'boolean'],

            // A location that keeps moving: the sender's own message stays live for this long. The
            // app offers the same few choices, so only those are accepted.
            'live_seconds' => ['nullable', 'integer', Rule::in(MessageExtrasService::LIVE_DURATIONS)],
        ];
    }

    /**
     * View-once and live location are media/location messages with an extra rule, not types of
     * their own, so they are checked here rather than in rules().
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('view_once') && ! in_array($this->input('type'), [MessageType::Image->value, MessageType::Video->value, MessageType::Voice->value], true)) {
                $validator->errors()->add('view_once', __('validation.view_once_media_only'));
            }

            if ($this->filled('live_seconds') && $this->input('type') !== MessageType::Location->value) {
                $validator->errors()->add('live_seconds', __('validation.live_location_only'));
            }
        });
    }
}
