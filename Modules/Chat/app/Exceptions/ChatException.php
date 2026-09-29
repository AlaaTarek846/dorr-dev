<?php

namespace Modules\Chat\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A chat rule the person can act on (or at least understand). Renders itself — see ApiRenderable.
 * `errorCode` is what the apps key their behaviour on; the message follows the request locale.
 */
class ChatException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly int $status = 422,
        private readonly array $replace = [],
        private readonly array $data = [],
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('chat.errors.'.$this->errorCode, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'chat_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }

    public static function notParticipant(): self
    {
        return new self('not_participant', 403);
    }

    public static function participantDisabled(): self
    {
        return new self('participant_disabled', 403);
    }

    public static function blocked(): self
    {
        return new self('blocked', 403);
    }

    public static function notAllowedToMessage(): self
    {
        return new self('not_allowed_to_message', 403);
    }

    public static function notAllowedToAdd(): self
    {
        return new self('not_allowed_to_add', 403);
    }

    public static function notAllowedToCall(): self
    {
        return new self('not_allowed_to_call', 403);
    }

    public static function toSelf(): self
    {
        return new self('to_self', 422);
    }

    public static function requestPending(): self
    {
        return new self('request_pending', 403);
    }

    public static function requestRejected(): self
    {
        return new self('request_rejected', 403);
    }

    public static function adminsOnly(): self
    {
        return new self('admins_only', 403);
    }

    public static function ownerOnly(): self
    {
        return new self('owner_only', 403);
    }

    public static function groupOnly(): self
    {
        return new self('group_only', 422);
    }

    public static function groupFull(int $max): self
    {
        return new self('group_full', 422, ['max' => $max]);
    }

    public static function emptyMessage(): self
    {
        return new self('empty_message', 422);
    }

    public static function notYourMessage(): self
    {
        return new self('not_your_message', 403);
    }

    public static function editWindowPassed(int $minutes): self
    {
        return new self('edit_window_passed', 422, ['minutes' => $minutes]);
    }

    public static function deleteWindowPassed(): self
    {
        return new self('delete_window_passed', 422);
    }

    public static function notEditable(): self
    {
        return new self('not_editable', 422);
    }

    public static function messageDeleted(): self
    {
        return new self('message_deleted', 422);
    }

    public static function tooManyPins(int $max): self
    {
        return new self('too_many_pins', 422, ['max' => $max]);
    }

    public static function tooManyForwardTargets(int $max): self
    {
        return new self('too_many_forward_targets', 422, ['max' => $max]);
    }

    public static function tooManyFolders(int $max): self
    {
        return new self('too_many_folders', 422, ['max' => $max]);
    }

    public static function walletTransferNotFound(): self
    {
        return new self('wallet_transfer_not_found', 422);
    }

    public static function walletNotFound(): self
    {
        return new self('wallet_not_found', 422);
    }

    public static function userNotFound(): self
    {
        return new self('user_not_found', 404);
    }

    public static function invalidQr(): self
    {
        return new self('invalid_qr', 422);
    }

    public static function inviteInvalid(): self
    {
        return new self('invite_invalid', 404);
    }

    public static function callsDisabled(): self
    {
        return new self('calls_disabled', 403);
    }

    public static function callsNotConfigured(): self
    {
        return new self('calls_not_configured', 503);
    }

    public static function callBusy(): self
    {
        return new self('call_busy', 409);
    }

    public static function callNotActive(): self
    {
        return new self('call_not_active', 422);
    }

    public static function callTooManyParticipants(int $max): self
    {
        return new self('call_too_many_participants', 422, ['max' => $max]);
    }

    public static function storiesDisabled(): self
    {
        return new self('stories_disabled', 403);
    }

    public static function storyNotFound(): self
    {
        return new self('story_not_found', 404);
    }

    public static function storyRepliesOff(): self
    {
        return new self('story_replies_off', 403);
    }

    public static function storyVideoTooLong(int $seconds): self
    {
        return new self('story_video_too_long', 422, ['seconds' => $seconds]);
    }
}
