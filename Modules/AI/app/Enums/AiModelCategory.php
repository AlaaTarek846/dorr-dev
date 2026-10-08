<?php

namespace Modules\AI\Enums;

/**
 * WHAT KIND of thing a registered model is - deliberately separate from
 * AiModelCapability (WHAT it can actually do). A model has exactly one
 * category but can have several capabilities: gpt-image-2 is category
 * ImageGeneration with capabilities [image_generation], while a general
 * chat model is category General with capabilities [chat, vision, ...].
 * See AiProviderModelSyncService::inferCategory() for the explicit,
 * non-guessing rules that assign this from a model id - never a bare
 * "starts with gpt- = General" check, which would misclassify
 * gpt-image-2, gpt-transcribe, gpt-5.3-codex, sora-2, etc.
 */
enum AiModelCategory: string
{
    case General = 'general';
    case Reasoning = 'reasoning';
    case Coding = 'coding';
    case ImageGeneration = 'image_generation';
    case VideoGeneration = 'video_generation';
    case RealtimeVoice = 'realtime_voice';
    case SpeechToText = 'speech_to_text';
    case TextToSpeech = 'text_to_speech';
    case DeepResearch = 'deep_research';
    case Cybersecurity = 'cybersecurity';
    case LifeSciences = 'life_sciences';
    case Embeddings = 'embeddings';
    case Moderation = 'moderation';

    /**
     * A real, older base/completions-era model OpenAI still lists but no
     * longer recommends for new integrations (davinci-002, babbage-002,
     * the gpt-3.5-turbo family, dated pre-gpt-4o gpt-4 snapshots like
     * gpt-4-0613). Not "deprecated" (that is the row's own `status`
     * lifecycle field - OpenAI may still serve these) and not General
     * (an admin scanning "General" for a flagship chat model should not
     * have to wade through a decade of superseded snapshots to find one).
     */
    case Legacy = 'legacy';

    /**
     * Not a guess - AiProviderModelSyncService::inferCategory() falls
     * back to this, plus needs_review=true, whenever a model id matches
     * none of the explicit category rules, so it shows up for manual
     * review in the admin screen instead of being silently mis-tagged.
     */
    case Unknown = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        if (app()->getLocale() === 'en') {
            return match ($this) {
                self::General => 'General / Flagship',
                self::Reasoning => 'Reasoning',
                self::Coding => 'Coding / Codex',
                self::ImageGeneration => 'Image Generation & Editing',
                self::VideoGeneration => 'Video Generation',
                self::RealtimeVoice => 'Realtime Voice',
                self::SpeechToText => 'Speech-to-Text / Transcription',
                self::TextToSpeech => 'Text-to-Speech',
                self::DeepResearch => 'Deep Research',
                self::Cybersecurity => 'Cybersecurity',
                self::LifeSciences => 'Life Sciences',
                self::Embeddings => 'Embeddings',
                self::Moderation => 'Moderation',
                self::Legacy => 'Legacy',
                self::Unknown => 'Unknown - needs review',
            };
        }

        return match ($this) {
            self::General => 'عام / رئيسي',
            self::Reasoning => 'تفكير منطقي معقد',
            self::Coding => 'برمجة / Codex',
            self::ImageGeneration => 'توليد وتعديل الصور',
            self::VideoGeneration => 'توليد الفيديو',
            self::RealtimeVoice => 'صوت لحظي (Realtime)',
            self::SpeechToText => 'تفريغ الصوت إلى نص',
            self::TextToSpeech => 'تحويل النص إلى صوت',
            self::DeepResearch => 'بحث عميق',
            self::Cybersecurity => 'أمن سيبراني',
            self::LifeSciences => 'علوم حياتية',
            self::Embeddings => 'متجهات نصية (Embeddings)',
            self::Moderation => 'مراقبة المحتوى',
            self::Legacy => 'قديم / متوقف التطوير',
            self::Unknown => 'غير معروف - يحتاج مراجعة',
        };
    }
}
