<?php

namespace Modules\AI\Enums;

/**
 * Fixed taxonomy of what a given ai_provider_models row can actually do,
 * chosen deliberately over free-text tags so AiModelSelector can match
 * against it reliably and the admin screen can render a stable checkbox
 * list instead of an open text field.
 */
enum AiModelCapability: string
{
    case Chat = 'chat';
    case Vision = 'vision';
    case ImageGeneration = 'image_generation';
    case DocumentAnalysis = 'document_analysis';
    case Coding = 'coding';
    case Research = 'research';
    case Study = 'study';
    case Reasoning = 'reasoning';
    case SpeechToText = 'speech_to_text';
    case TextToSpeech = 'text_to_speech';

    // Added for the broader OpenAI-catalog capability vocabulary
    // requested directly by the admin. Deliberately does NOT duplicate
    // four of the requested names that already have an exact functional
    // equivalent above under a different, already-wired label: a chat-
    // capable model already means "text_input + text_output", Vision
    // already means "image_input", ImageGeneration already means
    // "image_output", and SpeechToText already means "transcription" -
    // adding a second tag for the identical fact would be noise, not
    // precision, and this codebase's own routing/tests key off the
    // existing names.
    case AudioInput = 'audio_input';
    case AudioOutput = 'audio_output';
    case VideoInput = 'video_input';
    case VideoOutput = 'video_output';
    case Translation = 'translation';
    case Realtime = 'realtime';
    case Embeddings = 'embeddings';
    case Moderation = 'moderation';
    case FunctionCalling = 'function_calling';
    case StructuredOutput = 'structured_output';
    case WebSearch = 'web_search';
    case FileSearch = 'file_search';
    case CodeExecution = 'code_execution';
    case ComputerUse = 'computer_use';

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
                self::Chat => 'General text chat',
                self::Vision => 'Understands attached images',
                self::ImageGeneration => 'Generates or edits images',
                self::DocumentAnalysis => 'Analyzes documents and files',
                self::Coding => 'Coding and code analysis',
                self::Research => 'Research and deep analysis',
                self::Study => 'Study, teaching and explanation',
                self::Reasoning => 'Complex multi-step reasoning',
                self::SpeechToText => 'Transcribes spoken audio into text',
                self::TextToSpeech => 'Generates a spoken audio reply',
                self::AudioInput => 'Accepts spoken/audio input',
                self::AudioOutput => 'Produces spoken/audio output',
                self::VideoInput => 'Accepts video input',
                self::VideoOutput => 'Generates video output',
                self::Translation => 'Translates between languages',
                self::Realtime => 'Real-time, low-latency bidirectional interaction',
                self::Embeddings => 'Produces text embeddings (vector representations)',
                self::Moderation => 'Flags unsafe or policy-violating content',
                self::FunctionCalling => 'Calls developer-defined functions/tools',
                self::StructuredOutput => 'Returns responses in a strict schema (JSON mode)',
                self::WebSearch => 'Searches the live web as part of its response',
                self::FileSearch => 'Searches over uploaded files/knowledge',
                self::CodeExecution => 'Executes code in a sandbox',
                self::ComputerUse => 'Operates a computer via screenshots/clicks',
            };
        }

        return match ($this) {
            self::Chat => 'محادثة نصية عامة',
            self::Vision => 'فهم/تحليل الصور المرفقة',
            self::ImageGeneration => 'توليد أو تعديل صور',
            self::DocumentAnalysis => 'تحليل مستندات وملفات',
            self::Coding => 'برمجة وتحليل كود',
            self::Research => 'بحث وتحليل عميق',
            self::Study => 'دراسة وتعليم وشرح',
            self::Reasoning => 'تفكير منطقي معقد متعدد الخطوات',
            self::SpeechToText => 'تفريغ الرسائل الصوتية إلى نص',
            self::TextToSpeech => 'توليد رد صوتي منطوق',
            self::AudioInput => 'استقبال مدخلات صوتية',
            self::AudioOutput => 'إنتاج مخرجات صوتية منطوقة',
            self::VideoInput => 'استقبال مدخلات فيديو',
            self::VideoOutput => 'توليد فيديو',
            self::Translation => 'الترجمة بين اللغات',
            self::Realtime => 'تفاعل فوري ثنائي الاتجاه بتأخير منخفض',
            self::Embeddings => 'توليد متجهات نصية (Embeddings)',
            self::Moderation => 'رصد المحتوى غير الآمن أو المخالف',
            self::FunctionCalling => 'استدعاء دوال/أدوات معرّفة من المطوّر',
            self::StructuredOutput => 'إرجاع الرد بصيغة JSON ثابتة (Structured Output)',
            self::WebSearch => 'البحث فى الويب كجزء من الرد',
            self::FileSearch => 'البحث فى الملفات/المعرفة المرفوعة',
            self::CodeExecution => 'تنفيذ كود داخل بيئة معزولة',
            self::ComputerUse => 'التحكم فى جهاز كمبيوتر عبر لقطات شاشة ونقرات',
        };
    }
}
