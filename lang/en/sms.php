<?php

return [

    'providers' => [
        'created' => 'SMS provider created successfully.',
        'fetched' => 'SMS provider retrieved successfully.',
        'updated' => 'SMS provider updated successfully.',
        'deleted' => 'SMS provider deleted successfully.',
        'activated' => 'SMS provider activated successfully.',
        'deactivated' => 'SMS provider deactivated successfully.',
        'ready' => 'Provider is ready.',
        'not_ready' => 'Provider is not ready. Check that the provider is active and its adapter is registered.',
        'in_use' => 'This SMS provider still has accounts bound to it and cannot be deleted.',
        'unsupported' => 'Unsupported SMS provider: :key',

        'connection_successful' => 'Connection successful.',
        'connection_failed' => 'Connection failed.',
        'missing_configuration' => 'Missing required configuration: :fields',

        'name_required' => 'Name is required.',
        'key_required' => 'Provider is required.',
        'unknown_provider' => 'Unknown SMS provider.',
        'already_registered' => 'This SMS provider is already registered.',

        'test_errors' => [
            'required_credentials' => ':provider credentials are required',
            'sms_misr' => [
                'sent' => 'SMS Misr: Message sent successfully',
                'insufficient_balance' => 'Insufficient balance',
                'invalid_credentials' => 'Invalid username or password',
                'sender_not_approved' => 'Sender not approved',
                'invalid_sender' => 'Invalid sender',
                'invalid_mobile' => 'Invalid mobile number',
                'message_too_long' => 'Message too long or empty',
                'invalid_language' => 'Invalid language parameter',
                'invalid_environment' => 'Environment value must be 1 or 2',
                'invalid_request' => 'Invalid request parameters',
                'server_updating' => 'Provider is temporarily updating, try again',
                'invalid_delay' => 'Invalid delay date format',
                'invalid_message' => 'Invalid message content',
                'generic' => 'Provider returned code :code',
            ],
        ],
    ],

    'accounts' => [
        'created' => 'SMS account created successfully.',
        'fetched' => 'SMS account retrieved successfully.',
        'updated' => 'SMS account updated successfully.',
        'deleted' => 'SMS account deleted successfully.',
        'default_set' => 'Default SMS account set successfully.',
        'activated' => 'SMS account activated successfully.',
        'deactivated' => 'SMS account deactivated successfully.',

        'name_required' => 'Name is required.',
        'provider_required' => 'Provider is required.',
        'provider_not_found' => 'The selected provider does not exist.',
        'invalid_sender_type' => 'Invalid sender type.',
        'missing_configuration' => 'Missing required configuration: :fields',

        'provider_inactive' => 'The provider of this account is inactive. Activate the provider first.',
        'not_configured' => 'This account has no configuration. Configure its credentials first.',
        'none_active' => 'No active SMS account exists. Create and activate one first.',
        'account_unavailable' => 'This SMS account is not ready. It must be active, its test must have passed, and its provider must be active.',
        'unsupported_provider' => 'Unsupported SMS provider: :key',

        'connection_successful' => 'Connection successful.',
        'connection_failed' => 'Connection failed.',
        'balance_not_supported' => 'This provider does not expose a balance API.',
        'balance_retrieved' => 'Balance retrieved.',
        'balance_failed' => 'Balance lookup failed.',

        'test_number_required' => 'Test phone number is required.',
        'test_sent' => 'Test SMS sent.',
        'test_failed' => 'Test SMS could not be sent.',
        'default_test_message' => 'Test SMS',
        'live_test_sent' => 'No sandbox environment is configured: a LIVE test SMS was sent.',

        'body_required' => 'The message body cannot be empty.',

        'country_required' => 'A country must be selected to format the phone number.',
        'country_not_found' => 'The selected country does not exist.',
        'country_mismatch' => 'The number :phone does not belong to the selected country. Either pick the matching country or enter a national number.',
        'country_missing_dial_code' => 'The selected country has no international dialing code configured.',
        'invalid_recipient' => 'The phone number ":phone" is not valid.',
        'invalid_recipient_length' => 'The phone number ":phone" is not valid for the selected country. It should be :length digits and start with :starts_with.',

        'test_status' => [
            'never_tested' => 'Never Tested',
            'passed' => 'Passed',
            'failed' => 'Failed',
        ],

        'sender_type' => [
            'number' => 'Phone Number',
            'alphanumeric' => 'Alphanumeric Sender ID',
        ],
    ],
];
