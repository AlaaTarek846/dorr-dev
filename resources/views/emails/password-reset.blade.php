<x-mail::message>
# {{ __('api.password_reset_email_heading') }}

{{ __('api.password_reset_email_intro', ['name' => $name]) }}

<x-mail::button :url="$resetUrl">
{{ __('api.password_reset_email_action') }}
</x-mail::button>

{{ __('api.password_reset_email_expiry', ['minutes' => $expiryMinutes]) }}

{{ __('api.password_reset_email_ignore') }}

{{ config('app.name') }}
</x-mail::message>
