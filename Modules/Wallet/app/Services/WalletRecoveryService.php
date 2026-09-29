<?php

namespace Modules\Wallet\Services;

use App\Enums\VerificationType;
use App\Services\Auth\VerificationCodeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Wallet\Enums\PinRecoveryReason;
use Modules\Wallet\Enums\PinRecoveryStatus;
use Modules\Wallet\Enums\RecoveryMethod;
use Modules\Wallet\Exceptions\PinFrozenException;
use Modules\Wallet\Exceptions\PinNotSetException;
use Modules\Wallet\Exceptions\RecoveryException;
use Modules\Wallet\Models\PinRecoveryRequest;
use Modules\Wallet\Models\WalletRecoveryMethod;
use Modules\Wallet\Support\OwnerType;
use Throwable;

/**
 * Getting back into the wallet after forgetting the PIN.
 *
 * One method is chosen when the PIN is first created (password, birth date, ID photo, passport photo
 * or e-mail) and it is the *only* way back:
 *
 *  - password / birth date: checked here against an Argon2id hash (same pepper as the PIN), with its
 *    own attempt counter + lockout — a birth date has few possibilities, so guessing must be slow;
 *  - e-mail: a 4-digit code mailed to the confirmed address;
 *  - ID / passport photo: nothing is checked automatically. The new photo becomes a request that a
 *    person compares with the original in the dashboard; approving resets the PIN to {@see self::RESET_PIN}.
 *
 * The PIN itself is never stored or readable: recovery always *replaces* it.
 */
class WalletRecoveryService
{
    /** What an approved document request sets the PIN to (the customer then changes it in the wallet). */
    public const RESET_PIN = '0000';

    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(
        private readonly PinService $pins,
        private readonly VerificationCodeService $codes,
        private readonly WalletNotifier $notifier,
    ) {}

    public function methodFor(Model $owner): ?WalletRecoveryMethod
    {
        return WalletRecoveryMethod::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    /** Has a usable recovery method — the precondition for creating a PIN. */
    public function isReady(Model $owner): bool
    {
        return $this->methodFor($owner)?->isReady() === true;
    }

    public function pendingRequestFor(Model $owner): ?PinRecoveryRequest
    {
        return PinRecoveryRequest::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->where('status', PinRecoveryStatus::Pending)
            ->latest('id')
            ->first();
    }

    /** The owner's latest request, whatever its state — the app shows "under review" / "rejected: …". */
    public function latestRequestFor(Model $owner): ?PinRecoveryRequest
    {
        return PinRecoveryRequest::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * What the app needs to know to decide which screen to show: is there a PIN, which recovery method
     * is on file (and is it usable), and where the latest document request stands.
     *
     * @return array<string, mixed>
     */
    public function summary(Model $owner): array
    {
        $record = $this->methodFor($owner);
        $request = $this->latestRequestFor($owner);

        return [
            'has_pin' => $this->pins->has($owner),
            // true after an approved recovery request: the PIN is 0000 and has to be replaced
            'must_change' => $this->pins->mustChange($owner),
            // permanently locked (a wrong attempt right after a temporary lock) — only a selfie + ID,
            // reviewed by a person, lifts it; every other action, self-service recovery included, is refused
            'is_frozen' => $this->pins->isFrozen($owner),
            'recovery' => $record === null ? null : [
                'method' => $record->method->value,
                'ready' => $record->isReady(),
                'email' => $record->email === null ? null : $this->codes->maskEmail($record->email),
                // a new e-mail waiting for its code; the method above keeps working meanwhile
                'pending_email' => $record->pending_email === null ? null : $this->codes->maskEmail($record->pending_email),
            ],
            'request' => $request === null ? null : [
                'id' => $request->id,
                'method' => $request->method->value,
                'reason' => $request->reason->value,
                'status' => $request->status->value,
                'rejection_reason' => $request->rejection_reason,
                'created_at' => $request->created_at?->toISOString(),
                'reviewed_at' => $request->reviewed_at?->toISOString(),
            ],
        ];
    }

    // ------------------------------------------------------------------ setting it up

    /**
     * Chooses (or replaces) the recovery method. For e-mail the address is stored *unconfirmed* and a
     * code is sent; the method only counts as ready after {@see self::confirmEmail()}.
     *
     * @param  array<string, mixed>  $data  password | birth_date | email, depending on the method
     */
    public function configure(Model $owner, RecoveryMethod $method, array $data, ?UploadedFile $document = null): WalletRecoveryMethod
    {
        $record = DB::transaction(function () use ($owner, $method, $data, $document) {
            $record = WalletRecoveryMethod::query()->firstOrNew([
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
            ]);

            // Switching *to* e-mail while a working method exists: keep the old one until the code is entered,
            // so abandoning the change halfway never leaves the wallet without a way back.
            if ($method === RecoveryMethod::Email && $record->exists && $record->isReady()) {
                $record->update(['pending_email' => strtolower(trim((string) $data['email']))]);

                return $record;
            }

            $record->fill([
                'method' => $method,
                'secret_hash' => match ($method) {
                    RecoveryMethod::Password => $this->hashSecret((string) $data['password']),
                    RecoveryMethod::BirthDate => $this->hashSecret($this->normalizeDate((string) $data['birth_date'])),
                    default => null,
                },
                'email' => $method === RecoveryMethod::Email ? strtolower(trim((string) $data['email'])) : null,
                'email_verified_at' => null,
                'pending_email' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
            ])->save();

            if ($method->isDocument()) {
                $record->setSingleMedia('document', $document);
            } else {
                $record->clearMediaCollection('document');
            }

            return $record;
        });

        if ($method === RecoveryMethod::Email) {
            $this->sendEmailCode($owner, (string) ($record->pending_email ?? $record->email));
        } else {
            $this->notifier->recoveryConfigured($owner, $method);
        }

        return $record->refresh();
    }

    /**
     * Confirms the address with the 4-digit code that was mailed to it.
     *
     * @throws \Illuminate\Validation\ValidationException  wrong / expired / too many tries
     */
    public function confirmEmail(Model $owner, string $code): WalletRecoveryMethod
    {
        $record = $this->methodFor($owner);

        if ($record === null || ($record->pending_email === null && ($record->method !== RecoveryMethod::Email || $record->email === null))) {
            throw RecoveryException::wrongMethod(RecoveryMethod::Email->value);
        }

        $this->codes->verify($owner, VerificationType::WalletRecovery, $code);

        if ($record->pending_email !== null) {
            // The new address is confirmed: it replaces whatever method was there.
            $record->fill([
                'method' => RecoveryMethod::Email,
                'email' => $record->pending_email,
                'email_verified_at' => now(),
                'pending_email' => null,
                'secret_hash' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
            ])->save();
            $record->clearMediaCollection('document');
            $this->notifier->recoveryConfigured($owner, RecoveryMethod::Email);

            return $record->refresh();
        }

        $record->update(['email_verified_at' => now()]);
        $this->notifier->recoveryConfigured($owner, RecoveryMethod::Email);

        return $record->refresh();
    }

    /**
     * (Re)sends the code to the e-mail on file — for confirming it at setup, and for recovering.
     */
    public function resendEmailCode(Model $owner, bool $forPending = false): void
    {
        $record = $this->methodFor($owner);
        // [$forPending]: the code for a new address that is being switched to, not for the confirmed one on file.
        $address = $forPending ? $record?->pending_email : ($record?->method === RecoveryMethod::Email ? $record->email : null);

        if ($record === null || $address === null) {
            throw RecoveryException::wrongMethod(RecoveryMethod::Email->value);
        }

        $this->sendEmailCode($owner, $address);
    }

    // ------------------------------------------------------------------ forgetting the PIN

    /**
     * Recovery with something the server can check itself. On success the PIN becomes [newPin].
     *
     * @param  array<string, mixed>  $input  password | birth_date | code
     *
     * @throws RecoveryException|PinNotSetException|\Illuminate\Validation\ValidationException
     */
    public function recover(Model $owner, array $input, string $newPin): void
    {
        // Frozen means "prove it's you in person" — self-service (even with the right password/date/code)
        // is refused on purpose; see requestSecurityUnfreeze().
        if ($this->pins->isFrozen($owner)) {
            throw new PinFrozenException;
        }

        $record = $this->methodFor($owner);

        if ($record === null || ! $record->isReady()) {
            throw RecoveryException::notConfigured();
        }

        if (! $this->pins->has($owner)) {
            throw new PinNotSetException; // nothing to recover: create it
        }

        if ($record->method->isDocument()) {
            throw RecoveryException::wrongMethod($record->method->value);
        }

        $this->assertNotLocked($record);

        if ($record->method === RecoveryMethod::Email) {
            // ValidationException on a wrong code; VerificationCodeService counts and caps the attempts.
            $this->codes->verify($owner, VerificationType::WalletRecovery, (string) ($input['code'] ?? ''));
        } else {
            $secret = $record->method === RecoveryMethod::Password
                ? (string) ($input['password'] ?? '')
                : $this->normalizeDate((string) ($input['birth_date'] ?? ''));

            if (! password_verify($secret.$this->pepper(), (string) $record->secret_hash)) {
                $this->registerFailure($record);

                throw RecoveryException::wrongSecret();
            }
        }

        $record->update(['failed_attempts' => 0, 'locked_until' => null]);
        $this->pins->set($owner, $newPin);
        $this->notifier->pinRecovered($owner);
    }

    /**
     * Recovery by document: files a request for a person to review. Nothing changes until they approve.
     */
    public function requestWithDocument(Model $owner, UploadedFile $document): PinRecoveryRequest
    {
        if ($this->pins->isFrozen($owner)) {
            throw new PinFrozenException;
        }

        $record = $this->methodFor($owner);

        if ($record === null || ! $record->isReady()) {
            throw RecoveryException::notConfigured();
        }

        if (! $record->method->isDocument()) {
            throw RecoveryException::wrongMethod($record->method->value);
        }

        if (! $this->pins->has($owner)) {
            throw new PinNotSetException;
        }

        if ($this->pendingRequestFor($owner) !== null) {
            throw RecoveryException::pendingExists();
        }

        $request = DB::transaction(function () use ($owner, $record, $document) {
            $request = PinRecoveryRequest::query()->create([
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
                'method' => $record->method,
                'reason' => PinRecoveryReason::RecoveryDocument,
                'status' => PinRecoveryStatus::Pending,
            ]);

            // Copy — not link — the photo from setup, so what the reviewer compares against can't
            // change if the owner later picks a different recovery method.
            $record->getFirstMedia('document')?->copy($request, 'original_document');
            $request->setSingleMedia('new_document', $document);

            return $request;
        });

        $this->notifier->pinRecoveryRequested($request);

        return $request;
    }

    /**
     * The only way out of a permanent freeze ({@see PinFrozenException}): a selfie *and* an ID/passport
     * photo, whatever the owner's configured recovery method actually is — the freeze exists because a
     * wrong PIN followed a temporary lock, which is reason enough to ask for a person's judgment instead
     * of trusting the method already on file.
     */
    public function requestSecurityUnfreeze(Model $owner, UploadedFile $idDocument, UploadedFile $selfie): PinRecoveryRequest
    {
        if (! $this->pins->isFrozen($owner)) {
            throw RecoveryException::notFrozen();
        }

        if ($this->pendingRequestFor($owner) !== null) {
            throw RecoveryException::pendingExists();
        }

        // Always exists: a PIN — which is what got frozen — can't be created before a recovery method is.
        $method = $this->methodFor($owner)?->method ?? throw RecoveryException::notConfigured();

        $request = DB::transaction(function () use ($owner, $method, $idDocument, $selfie) {
            $request = PinRecoveryRequest::query()->create([
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
                'method' => $method,
                'reason' => PinRecoveryReason::SecurityFreeze,
                'status' => PinRecoveryStatus::Pending,
            ]);

            $request->setSingleMedia('original_document', $idDocument);
            $request->setSingleMedia('new_document', $selfie);

            return $request;
        });

        $this->notifier->pinRecoveryRequested($request);

        return $request;
    }

    // ------------------------------------------------------------------ the reviewer

    /**
     * Approves: the PIN becomes {@see self::RESET_PIN} and the owner is told so. For a security freeze
     * this also lifts it — {@see PinService::set()} always clears `frozen_at`.
     */
    public function approve(PinRecoveryRequest $request, int $adminId): PinRecoveryRequest
    {
        $approved = DB::transaction(function () use ($request, $adminId) {
            $locked = $this->lockPending($request);
            $owner = $locked->owner();

            abort_if($owner === null, 404);

            $this->pins->set($owner, self::RESET_PIN, mustChange: true);
            $locked->update(['status' => PinRecoveryStatus::Approved, 'reviewed_by' => $adminId, 'reviewed_at' => now()]);

            $this->notifier->pinRecoveryApproved($locked);

            return $locked;
        });

        return $approved;
    }

    public function reject(PinRecoveryRequest $request, string $reason, int $adminId): PinRecoveryRequest
    {
        return DB::transaction(function () use ($request, $reason, $adminId) {
            $locked = $this->lockPending($request);
            $locked->update(['status' => PinRecoveryStatus::Rejected, 'rejection_reason' => $reason, 'reviewed_by' => $adminId, 'reviewed_at' => now()]);

            $this->notifier->pinRecoveryRejected($locked, $reason);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ internals

    private function lockPending(PinRecoveryRequest $request): PinRecoveryRequest
    {
        $locked = PinRecoveryRequest::query()->lockForUpdate()->findOrFail($request->id);

        if ($locked->status !== PinRecoveryStatus::Pending) {
            throw RecoveryException::notPending();
        }

        return $locked;
    }

    private function sendEmailCode(Model $owner, string $email): void
    {
        try {
            $this->codes->send($owner, VerificationType::WalletRecovery, $email);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // resend cooldown
        } catch (Throwable $e) {
            Log::error('[WalletRecovery] could not send the e-mail code: '.$e->getMessage());

            // With a fixed development code the flow can go on without a mail server.
            if (! config('auth_flow.email_otp_fixed')) {
                throw RecoveryException::emailFailed();
            }
        }
    }

    private function assertNotLocked(WalletRecoveryMethod $record): void
    {
        if ($record->locked_until !== null && $record->locked_until->isFuture()) {
            throw RecoveryException::locked(max(1, (int) ceil(now()->diffInSeconds($record->locked_until) / 60)));
        }
    }

    private function registerFailure(WalletRecoveryMethod $record): void
    {
        $attempts = $record->failed_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $record->update(['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]);

            return;
        }

        $record->update(['failed_attempts' => $attempts]);
    }

    private function normalizeDate(string $date): string
    {
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (Throwable) {
            return trim($date);
        }
    }

    private function hashSecret(string $secret): string
    {
        return password_hash($secret.$this->pepper(), PASSWORD_ARGON2ID);
    }

    private function pepper(): string
    {
        return (string) config('wallet.pin_pepper');
    }
}
