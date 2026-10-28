<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A certificate the freelancer lists (FL-104). It is what they say, not something the platform checked. Its proof (an
 * image or a PDF) is kept on the private disk — never behind a public link — and only its owner downloads it.
 */
class FreelancerCertificate extends Model implements HasMedia
{
    use HasMediaTrait;

    public const PROOF_DISK = 'local';

    protected $fillable = ['freelancer_id', 'title', 'issuer', 'issued_on', 'expires_on', 'credential_url'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    /** Kept as uploaded (no image conversion): it is a document. */
    protected function webpSkipCollections(): array
    {
        return ['proof'];
    }

    public function proof(): ?Media
    {
        return $this->getFirstMedia('proof');
    }

    public function setProof(UploadedFile $file): Media
    {
        $this->clearMediaCollection('proof');

        return $this->addUploadedMedia($file, 'proof')->toMediaCollection('proof', self::PROOF_DISK);
    }
}
