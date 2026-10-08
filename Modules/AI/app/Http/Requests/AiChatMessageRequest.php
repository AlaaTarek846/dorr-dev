<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Rules\SafeUploadedFile;

class AiChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user('user_api') ?? $this->user('provider_api'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Voice-only sends have nothing typed - the transcript (built
            // server-side in AiChatService) becomes the real content, so
            // requiring text here would force the client to paste in a
            // throwaway placeholder caption just to pass validation (that
            // placeholder used to leak into the stored message content and
            // the conversation's auto-generated title - see the "voice
            // message" caption bug this fixes).
            'message' => ['required_without:attachment', 'nullable', 'string', 'max:8000'],
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                // Root-cause fix - real, observed gap: legacy .xls was
                // missing from this list even though ExcelFileProcessor
                // (the File Engine processor buildDocumentText() now
                // actually calls - see AiChatService's own docblock)
                // already fully supports 'application/vnd.ms-excel' via
                // PhpSpreadsheet, and SafeUploadedFile below already
                // allow-lists that real MIME type too - only this
                // extension check was never updated to match. pptx/ppt
                // are deliberately NOT added here yet: their processors
                // exist but PhpPresentation is not installed in this
                // project (see PptxFileProcessor's own docblock) and
                // would always honestly fail, so accepting the upload
                // only to immediately decline it would be worse than
                // rejecting it at this earlier, clearer validation step.
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,txt,csv,xlsx,xls,m4a,mp4,m4v',
                // Voice notes: MediaRecorder writes an audio-only AAC/.m4a file,
                // but its MPEG_4 muxer produces a generic MP4 container box that
                // some libmagic/fileinfo builds sniff as "video/mp4" rather than
                // "audio/mp4" purely from the box structure - nothing to do with
                // the actual content. mp4/m4v are allowed here for that reason
                // (SafeUploadedFile below still restricts the *real* allowed
                // audio MIME types, so a genuine video file is still rejected).
                // v2.0 requirements doc S15.5: mimes: above only trusts
                // the extension/client-declared type - this checks the
                // file's actual bytes so a renamed executable/script
                // can't ride through as a "photo.jpg".
                new SafeUploadedFile,
            ],

            // Phase 10 (doc S13/S28): explicit multi-file selection for
            // this one message. Never trusted as-is - every id is
            // re-validated against the owner and the conversation's own
            // scope in AiConversationFileScope (never here, and never
            // just from this array's shape).
            'file_ids' => ['nullable', 'array', 'max:'.max(1, (int) config('ai.retrieval.multi_file.max_files', 10))],
            'file_ids.*' => ['integer', 'min:1'],
        ];
    }
}
