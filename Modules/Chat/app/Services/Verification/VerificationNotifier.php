<?php

namespace Modules\Chat\Services\Verification;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Chat\Models\Verification\ChatVerificationNotification;
use Throwable;

/**
 * Every account-verification notification, to both sides (AV-006 and the "مصفوفة الإشعارات" of
 * docs/verification_tasks.md): the account gets it in the app (in-app list + real-time + push), the
 * staff who may act on it get it in the dashboard (in-app list + real-time) — only those holding the
 * event's permission (NotificationCenter::adminsWith). One call per action:
 *
 *     $notifier->notify('approved', $request->user, $request, ['reason' => …]);
 *
 * Texts are keys in lang/{locale}/notifications.php — `chat_verification_{event}_title|body` for the
 * account, `chat_verification_admin_{event}_title|body` for the staff — so each person reads their own
 * language. Each notification sent is written to chat_verification_notifications (REQ-09-07), after the
 * action's transaction commits: an action rolled back never notifies, and a failing notification never
 * fails the action.
 */
class VerificationNotifier
{
    /**
     * event => who hears of it: `user` (the account) and the staff permission(s), null = nobody there.
     *
     * @var array<string, array{user: bool, admin: list<string>|null}>
     */
    public const EVENTS = [
        // Requests
        'submitted' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'documents_uploaded' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'completion_requested' => ['user' => true, 'admin' => null],
        'identity_checked' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
        'preliminary_approved' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
        'approved' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
        'rejected' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
        'cancelled' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'expired' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'deadline_changed' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'deadline_paused' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'deadline_near' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'deadline_passed' => ['user' => true, 'admin' => ['chat-verification-requests.review', 'chat-verification-requests.approve']],
        // The badge
        'badge_activated' => ['user' => true, 'admin' => ['chat-verification-badges.update']],
        'badge_early_activated' => ['user' => true, 'admin' => ['chat-verification-requests.early-activate']],
        'badge_changed' => ['user' => true, 'admin' => ['chat-verification-badges.update']],
        'badge_suspended' => ['user' => true, 'admin' => ['chat-verification-badges.update']],
        'badge_reactivated' => ['user' => true, 'admin' => ['chat-verification-badges.update']],
        'badge_expired' => ['user' => true, 'admin' => ['chat-verification-badges.view']],
        'badge_withdrawn' => ['user' => true, 'admin' => ['chat-verification-badges.update']],
        // Celebrities: the offer and the agreement
        'offer_accepted' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'offer_rejected' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'offer_changes_requested' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'agreement_pending' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'agreement_accepted' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'ad_due_soon' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        'ad_overdue' => ['user' => true, 'admin' => ['chat-verification-requests.review']],
        // Money
        'payment_succeeded' => ['user' => true, 'admin' => null],
        'payment_pending' => ['user' => true, 'admin' => null],
        'payment_failed' => ['user' => true, 'admin' => null],
        'payment_failed_repeatedly' => ['user' => false, 'admin' => ['chat-verification-requests.approve']],
        'subscription_expiring' => ['user' => true, 'admin' => null],
        'subscription_expired' => ['user' => true, 'admin' => null],
        'package_changed' => ['user' => true, 'admin' => ['chat-verification-packages.view']],
        'subscription_cancelled' => ['user' => true, 'admin' => ['chat-verification-packages.view']],
        'refunded' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
        // Exemptions
        'exemption_activated' => ['user' => true, 'admin' => ['chat-verification-exemptions.view']],
        'exemption_expiring' => ['user' => true, 'admin' => ['chat-verification-exemptions.view']],
        'exemption_expired' => ['user' => true, 'admin' => ['chat-verification-exemptions.view']],
        'exemption_withdrawn' => ['user' => true, 'admin' => ['chat-verification-exemptions.update']],
        // Impersonation and appeals
        'impersonation_reported' => ['user' => true, 'admin' => ['chat-verification-impersonation.view']],
        'impersonation_decided' => ['user' => true, 'admin' => ['chat-verification-impersonation.view']],
        'impersonation_suspended' => ['user' => true, 'admin' => ['chat-verification-impersonation.update']],
        'appeal_submitted' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
        'appeal_decided' => ['user' => true, 'admin' => ['chat-verification-requests.approve']],
    ];

    /** One real-time name for every verification event (the event itself is `data.verification_event`): the apps bind once. */
    public const REALTIME_EVENT = 'chat.verification.updated';

    public function __construct(private readonly NotificationCenter $center) {}

    /**
     * @param  Model|null  $account  the account the event is about (it hears of it when the event says so)
     * @param  Model|null  $subject  the request / subscription / exemption… — kept in the log and sent as its id
     * @param  array<string, scalar|null>  $variables  placeholders for the texts (`name` is added: the account's)
     * @param  array<string, mixed>  $data  extra ids for the deep link — no secrets
     */
    public function notify(string $event, ?Model $account, ?Model $subject = null, array $variables = [], array $data = []): void
    {
        $rule = self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown verification event [{$event}].");

        // Every placeholder the texts use has a value, so none is ever shown as ":reason".
        $variables += ['name' => $this->nameOf($account), 'reason' => '', 'date' => '', 'amount' => ''];
        $data = [
            'type' => 'chat_verification',
            'verification_event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ] + $data;

        if ($rule['user'] && $account !== null) {
            $this->send('user', collect([$account]), $event, "chat_verification_{$event}", $variables, $data, $subject, true);
        }

        if ($rule['admin'] !== null) {
            $admins = collect($rule['admin'])
                ->flatMap(fn (string $permission) => $this->center->adminsWith($permission))
                ->unique(fn (Model $admin) => $admin->getKey())
                ->values();

            // The dashboard shows it live and in its list; staff get no phone push.
            $this->send('admin', $admins, $event, "chat_verification_admin_{$event}", $variables, $data + ['account_id' => $account?->getKey()], $subject, false);
        }
    }

    /**
     * @param  Collection<int, Model>  $receivers
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $data
     */
    private function send(string $audience, Collection $receivers, string $event, string $key, array $variables, array $data, ?Model $subject, bool $push): void
    {
        if ($receivers->isEmpty()) {
            return;
        }

        $status = 'sent';
        $error = null;

        try {
            $this->center->send($receivers, self::REALTIME_EVENT, "{$key}_title", "{$key}_body", $variables, $data, $push);
        } catch (Throwable $e) {
            [$status, $error] = ['failed', mb_substr($e->getMessage(), 0, 255)];
            Log::error('[VerificationNotifier] '.$e->getMessage(), ['event' => $event]);
        }

        DB::afterCommit(function () use ($audience, $receivers, $event, $subject, $push, $status, $error) {
            try {
                $now = now();
                ChatVerificationNotification::query()->insert($receivers->map(fn (Model $receiver) => [
                    'event' => $event,
                    'audience' => $audience,
                    'recipient_type' => $receiver->getMorphClass(),
                    'recipient_id' => $receiver->getKey(),
                    'subject_type' => $subject?->getMorphClass(),
                    'subject_id' => $subject?->getKey(),
                    'push' => $push,
                    'status' => $status,
                    'error' => $error,
                    'created_at' => $now,
                ])->all());
            } catch (Throwable $e) {
                Log::error('[VerificationNotifier] log failed: '.$e->getMessage(), ['event' => $event]);
            }
        });
    }

    private function nameOf(?Model $account): string
    {
        if ($account === null) {
            return '';
        }

        return (string) ($account->getAttribute('name') ?: ($account->getAttribute('phone') ?? '#'.$account->getKey()));
    }
}
