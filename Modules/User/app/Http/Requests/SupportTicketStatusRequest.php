<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Enums\SupportTicketStatus;

/** Moves a support ticket to another status (the app: close / reopen; the dashboard: any). */
class SupportTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user('user_api') ?? $this->user('admin_api'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(SupportTicketStatus::values())],
        ];
    }
}
