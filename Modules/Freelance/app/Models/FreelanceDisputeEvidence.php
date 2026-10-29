<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** What one side shows in a dispute (FL-704): a text and an optional file, on the private disk. Never changed or deleted. */
class FreelanceDisputeEvidence extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const UPDATED_AT = null;

    protected $table = 'freelance_dispute_evidence';

    protected $fillable = ['dispute_id', 'side', 'author_id', 'body', 'created_at'];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(FreelanceDispute::class, 'dispute_id');
    }

    public function addFile(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'files', '1')
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection('files', self::DISK);
    }
}
