<?php

return [
    'test_success' => 'Connection test succeeded.',
    'test_failed' => 'Connection test failed.',
    'api_key_missing' => 'No API key is set for this provider.',
    'api_key_required_to_enable' => 'An API key is required before you can enable this provider.',
    'connection_failed' => 'Could not reach the provider: :message',
    'unexpected_error' => 'Something went wrong while testing the connection: :message',
    'http_error' => 'The provider returned an HTTP :status error.',
    'connected_with_models' => 'Connected successfully. :count model(s) available.',
    'connected_but_model_missing' => 'Connected successfully, but the model ":model" was not found among the :count available model(s).',
    'default_requires_enabled_and_key' => 'The provider must be enabled and have an API key before it can be set as the active chat model.',
    'empty_reply' => 'The provider returned an empty reply.',
    'no_active_provider' => 'No AI model is active yet. Ask an administrator to enable and configure one from the AI settings screen.',
    'conversation_not_found' => 'This conversation was not found.',
    'transcription_unsupported' => "This AI provider can't turn speech into text.",
    // DORR AI safety (spec 350–362): approved, fixed texts — added by the system, never reworded by a model.
    'safety' => [
        'disclaimer' => 'DORR AI is not a fatwa authority, an accredited legal body or an accredited medical body. Every religious, legal or medical answer is general, introductory information only — not a fatwa, legal advice, a diagnosis or a final medical recommendation.',
        'notice' => [
            'religion' => 'This is a general view; consult a trusted religious scholar about your own situation.',
            'law' => 'This is general information; consult a licensed lawyer in your country.',
            'medicine' => 'This is general medical information; consult a licensed doctor or health professional about your own situation.',
            'engineering' => 'This is a general design concept; it must be reviewed and approved by a licensed engineer before it is carried out, because it affects structural safety.',
            'code' => 'This code has not been run or tested. It must go through a code review and testing before it is merged into production.',
        ],
    ],
];
