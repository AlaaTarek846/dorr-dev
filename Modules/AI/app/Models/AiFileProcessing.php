<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFileProcessing extends Model
{
    // Same class of bug as AiProviderHealth (found 2026-09-24): migration
    // creates 'ai_file_processing' (singular); Eloquent's default guess
    // without this override would be 'ai_file_processings'.
    protected $table = 'ai_file_processing';

    public const TYPE_TEXT_EXTRACTION = 'text_extraction';

    public const TYPE_METADATA_EXTRACTION = 'metadata_extraction';

    public const TYPE_OCR = 'ocr';

    public const TYPE_PARSING = 'parsing';

    public const TYPE_INDEX_PREPARATION = 'index_preparation';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_id',
        'processing_type',
        'status',
        'extracted_content_ref',
        'blocks_ref',
        'error_message',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(AiFile::class, 'file_id');
    }
}
