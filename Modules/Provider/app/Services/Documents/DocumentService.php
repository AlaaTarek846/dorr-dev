<?php

namespace Modules\Provider\Services\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Provider\Enums\DocumentKind;
use Modules\Provider\Enums\DocumentStatus;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Events\ProviderDocumentExpiring;
use Modules\Provider\Events\ProviderDocumentReviewed;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\DocumentType;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\ProviderDocument;

/**
 * Uploading papers and vehicle photos, the admin's review (A-06, A-07, A-11) and the expiry sweep (A-09).
 * Like the captains: a new approval replaces the previous one of the same type; an expired paper stops work until
 * a new one is approved.
 */
class DocumentService
{
    /**
     * @param  array{number?: string|null, expires_on?: string|null}  $data
     */
    public function upload(Establishment $establishment, DocumentSubject $subject, int $subjectId, DocumentType $type, UploadedFile $front, ?UploadedFile $back, array $data): ProviderDocument
    {
        if ($type->subject !== $subject || ! $type->status) {
            throw new ProviderException('document_type_mismatch');
        }
        if ($type->needs_back_side && $back === null) {
            throw new ProviderException('document_back_required');
        }
        if ($type->has_number && empty($data['number'])) {
            throw new ProviderException('document_number_required');
        }
        if ($type->has_expiry && empty($data['expires_on'])) {
            throw new ProviderException('document_expiry_required');
        }
        if ($type->kind === DocumentKind::Photo && ! str_starts_with((string) $front->getMimeType(), 'image/')) {
            throw new ProviderException('photo_must_be_image');
        }

        return DB::transaction(function () use ($establishment, $subject, $subjectId, $type, $front, $back, $data): ProviderDocument {
            // An upload still waiting for review is simply swapped for the new one.
            ProviderDocument::query()
                ->where('subject_type', $subject->storedAs())->where('subject_id', $subjectId)
                ->where('document_type_id', $type->id)->where('status', DocumentStatus::Pending->value)
                ->update(['status' => DocumentStatus::Replaced->value]);

            $document = ProviderDocument::query()->create([
                'establishment_id' => $establishment->id,
                'subject_type' => $subject->storedAs(),
                'subject_id' => $subjectId,
                'document_type_id' => $type->id,
                'number' => $type->has_number ? ($data['number'] ?? null) : null,
                'expires_on' => $type->has_expiry ? ($data['expires_on'] ?? null) : null,
                'status' => DocumentStatus::Pending,
            ]);
            $document->setSingleMedia('front', $front);
            if ($back !== null && $type->needs_back_side) {
                $document->setSingleMedia('back', $back);
            }

            return $document;
        });
    }

    public function approve(ProviderDocument $document, int $adminId): ProviderDocument
    {
        DB::transaction(function () use ($document, $adminId): void {
            ProviderDocument::query()
                ->where('subject_type', $document->subject_type)->where('subject_id', $document->subject_id)
                ->where('document_type_id', $document->document_type_id)
                ->where('status', DocumentStatus::Approved->value)->whereKeyNot($document->id)
                ->update(['status' => DocumentStatus::Replaced->value]);

            $document->update([
                'status' => DocumentStatus::Approved,
                'rejection_reason' => null,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
            ]);
        });
        event(new ProviderDocumentReviewed($document));

        return $document;
    }

    public function reject(ProviderDocument $document, string $reason, int $adminId): ProviderDocument
    {
        $document->update([
            'status' => DocumentStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
        ]);
        event(new ProviderDocumentReviewed($document));

        return $document;
    }

    /**
     * Marks ended papers expired and warns the ones about to end (the days the admin set on each type).
     *
     * @return array{expired: int, warned: int}
     */
    public function sweep(): array
    {
        $expired = ProviderDocument::query()
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('expires_on')->whereDate('expires_on', '<', now()->toDateString())
            ->update(['status' => DocumentStatus::Expired->value]);

        $warned = 0;
        ProviderDocument::query()
            ->with('type')
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('expires_on')->whereNull('expiry_alerted_at')
            ->whereHas('type', fn ($q) => $q->whereNotNull('expiry_alert_days'))
            ->chunkById(200, function ($documents) use (&$warned): void {
                foreach ($documents as $document) {
                    $days = (int) $document->type->expiry_alert_days;
                    if ($document->expires_on->copy()->subDays($days)->isFuture()) {
                        continue;
                    }
                    $document->update(['expiry_alerted_at' => now()]);
                    event(new ProviderDocumentExpiring($document));
                    $warned++;
                }
            });

        return ['expired' => $expired, 'warned' => $warned];
    }
}
