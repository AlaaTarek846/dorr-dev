<x-mail::message>
# {{ __('api.verification_email_heading') }}

{{ __('api.verification_email_intro', ['name' => $name]) }}

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

{{ __('api.verification_email_expiry', ['minutes' => $expiryMinutes]) }}

{{ __('api.verification_email_ignore') }}

{{ config('app.name') }}
</x-mail::message>
