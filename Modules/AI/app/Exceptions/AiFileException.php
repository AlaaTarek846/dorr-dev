<?php

namespace Modules\AI\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Acceptance criteria doc S13: normalized API errors for the File Engine
 * - internal reason strings (AiFileEngine::validate()'s return values,
 * "unsupported_file_type", etc.) are translated here into a stable
 * `apiErrorCode()` a client can branch on and a human-readable, localized
 * `apiMessage()`, instead of leaking the raw internal string or a stack
 * trace. Same one-class-many-reasons shape as AiSubscriptionException.
 */
class AiFileException extends RuntimeException implements ApiRenderable
{
    /**
     * @var array<string, array{code: string, key: string, status: int}>
     */
    protected const REASONS = [
        'file_size_limit_exceeded' => ['code' => 'FILE_TOO_LARGE', 'key' => 'file_too_large', 'status' => 422],
        'unsupported_file_type' => ['code' => 'UNSUPPORTED_FILE_TYPE', 'key' => 'file_unsupported_type', 'status' => 422],
        'executable_file_rejected' => ['code' => 'FILE_REJECTED_SECURITY', 'key' => 'file_rejected_security', 'status' => 422],
        'file_not_readable' => ['code' => 'FILE_VALIDATION_FAILED', 'key' => 'file_upload_failed', 'status' => 422],
        'file_not_ready' => ['code' => 'FILE_NOT_READY', 'key' => 'file_not_ready', 'status' => 409],
        'file_already_deleted' => ['code' => 'FILE_ALREADY_DELETED', 'key' => 'file_already_deleted', 'status' => 410],
        'upload_failed' => ['code' => 'FILE_UPLOAD_FAILED', 'key' => 'file_upload_failed', 'status' => 422],
        // Phase 10: detaching a file that was never part of this
        // conversation's scope in the first place (never attached, or
        // belongs to a different conversation and was never explicitly
        // referenced here).
        'file_not_attached' => ['code' => 'FILE_NOT_ATTACHED', 'key' => 'file_not_attached', 'status' => 404],
        // Phase 12 (doc S7/S28): a per-owner cap on total stored files or
        // total storage bytes - abuse protection against unlimited free
        // uploads, independent of the per-request size/type checks above.
        'file_quota_exceeded' => ['code' => 'FILE_QUOTA_EXCEEDED', 'key' => 'file_quota_exceeded', 'status' => 422],
    ];

    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function forReason(string $reason): self
    {
        return new self($reason);
    }

    public function apiStatus(): int
    {
        return self::REASONS[$this->reason]['status'] ?? 422;
    }

    public function apiMessage(): string
    {
        $key = self::REASONS[$this->reason]['key'] ?? 'file_upload_failed';

        return __('ai.'.$key);
    }

    public function apiErrorCode(): string
    {
        return self::REASONS[$this->reason]['code'] ?? 'FILE_VALIDATION_FAILED';
    }

    public function apiData(): array
    {
        return [];
    }
}
