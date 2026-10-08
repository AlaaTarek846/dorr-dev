<?php

namespace Modules\AI\Support;

/**
 * The closed vocabulary of "what the user wants" that the smart intent
 * router (AiIntentRouterService) is allowed to return and that the chat
 * layer knows how to act on. Anything outside this list coming back from
 * a model is discarded - the model suggests, this list decides.
 */
final class AiChatIntent
{
    public const CHAT = 'chat';

    public const IMAGE_GENERATION = 'image_generation';

    public const IMAGE_EDIT = 'image_edit';

    public const VIDEO_GENERATION = 'video_generation';

    public const VOICE_REPLY = 'voice_reply';

    public const FILE_OUTPUT = 'file_output';

    public const WEB_SEARCH = 'web_search';

    public const RESEARCH = 'research';

    public const STUDY = 'study';

    public const CODING = 'coding';

    public const STRUCTURED_OUTPUT = 'structured_output';

    /** Everything except plain chat. */
    public const ACTIONS = [
        self::IMAGE_GENERATION,
        self::IMAGE_EDIT,
        self::VIDEO_GENERATION,
        self::VOICE_REPLY,
        self::FILE_OUTPUT,
        self::WEB_SEARCH,
        self::RESEARCH,
        self::STUDY,
        self::CODING,
        self::STRUCTURED_OUTPUT,
    ];

    public const FILE_FORMATS = ['pdf', 'docx', 'xlsx'];

    /** Intents that translate into a required ai_provider_models capability. */
    public const CAPABILITY_BY_INTENT = [
        self::IMAGE_GENERATION => 'image_generation',
        self::IMAGE_EDIT => 'image_generation',
        self::VIDEO_GENERATION => 'video_output',
        self::WEB_SEARCH => 'web_search',
        self::RESEARCH => 'research',
        self::STUDY => 'study',
        self::CODING => 'coding',
        self::STRUCTURED_OUTPUT => 'structured_output',
    ];
}
