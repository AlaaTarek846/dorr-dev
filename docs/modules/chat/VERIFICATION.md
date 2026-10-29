# Account Verification (inside the Chat module)

Source: `03 - DORR - Community - Module - Account Verification - Requirements - v1.0.docx` (repo root).
Tasks and decisions: [docs/verification_tasks.md](../../verification_tasks.md). API: the "Account verification" section of [API.md](API.md).

## Decisions that shape the code

- The code lives in `Modules/Chat`, not in a new module. Tables are named `chat_verification_*`.
- Only `User` (the Dorr account) can be verified. A channel's verification (`ChatGroup.verified_until`) is a different thing and stays separate.
- The staff review documents by hand. There is no outside KYC provider.
- Every action notifies both sides (see the notifications table in the tasks file).
- AI add-ons and AI controls (REQ-06 and REQ-07) are handled by Eng. Ali and are not part of this work.
- Mobile comes first. The user website comes later.

## Where things are (batch 0)

| Piece | File |
|---|---|
| Settings (one row, cached, column defaults = the owner's starting values) | `app/Models/Verification/ChatVerificationSetting.php`, migration `2026_10_27_200000_create_chat_verification_foundation.php` |
| Documents per track | `ChatVerificationDocumentType` (+ translations). Seeded by `ChatVerificationSeeder`: ID front and back, a selfie holding the ID, and proof of account ownership (celebrities) |
| Tracks · activation timings · exemption types | `app/Enums/VerificationTrack.php`, `VerificationActivationTiming.php`, `VerificationExemptionType.php` |
| Track switches by country and city | `ChatFeatures` features `verification_paid` and `verification_celebrity`. The admin's switch comes first (off by default), then the `chat_feature_rules` rules |
| Permissions | `AdminPermissionSeeder` groups `chat-verification-*`. Reviewing (`requests.review`) is separate from approving (`requests.approve`). `early-activate`, badges, exemptions, packages, impersonation, settings and audit each have their own group |
| Permission check + refused-attempt log | `app/Http/Middleware/EnsureVerificationPermission.php` (`::map()` for controllers) → `audit_logs` action `chat_verification.denied` |
| Audit trail | The shared `audit_logs` table (`App\Services\General\AuditLogger`). It gained a `reason` column. Actions are prefixed `chat_verification.` |
| Notifications | `app/Services/Verification/VerificationNotifier.php`. `EVENTS` lists each event, whether the account hears of it, and the staff permissions that hear of it. Texts are `chat_verification_{event}_*` and `chat_verification_admin_{event}_*` in `lang/{ar,en}/notifications.php`. Sent notifications are logged in `chat_verification_notifications` |
| Routes | `routes/verification_customer.php` (inside `chat/`), `routes/verification_admin.php` (`/api/admin/v1/chat-verification/*`) |
| Dashboard | `views/chat/verification/{settings,documents,audit}` · sidebar under Chat |

## Requests (batch 1)

| Piece | File |
|---|---|
| Statuses and allowed moves | `app/Enums/VerificationRequestStatus.php` (`transitions()`, `isClosed()`) |
| Request · documents (private disk, front/back) · trail of moves | `ChatVerificationRequest`, `ChatVerificationRequestDocument`, `ChatVerificationRequestTransition`, migration `2026_10_27_210000_create_chat_verification_requests.php` |
| One open request per account | `open_key` is the user id while the request is open and null once it closes. A unique index on it blocks two requests sent at the same time |
| The rules | `app/Services/Verification/VerificationRequestService.php`: submit, complete, cancel; review, document review, identity check, request completion, approve (→ `preliminary_approved`), reject; extend, pause and resume the deadline; resume from hold, expire, staff cancel; `sweepDeadlines()` |
| What the account and the staff see | `VerificationRequestPresenter` (`next_step` for the account, `actions[]` for the staff) |
| Deadlines | Set on submit (`review_deadline_days`, owner admin) and on a completion request (`completion_deadline_days`, owner user). `chat:verification-deadlines` runs every 5 minutes on the server: it warns once inside `deadline_warning_hours`, and once the deadline passes it puts the request on hold (`suspended`, `suspended_from`). Reviewers and approvers are both notified |
| App | `androidApp/.../ui/screens/profile/VerificationScreen.kt` (Account → Verification), `network/VerificationApi.kt`. The screen reloads on `chat.verification.updated`, and a tapped push (`type: chat_verification`) opens it. The countdown runs on the phone's clock |
| Dashboard | `views/chat/verification/requests/{index,show}.vue` |

Not in batch 1: approval stops at `preliminary_approved`. Payment (batch 4) and the celebrity agreement (batch 3) take the request on to `completed`, and the badge comes in batch 2.

## The badge (batch 2) and the ✔ next to the name (batch 2-b)

| Piece | File |
|---|---|
| Statuses: temporary · active · suspended · expired · withdrawn | `app/Enums/VerificationBadgeStatus.php` |
| One badge per account, plus its trail | `ChatVerificationBadge`, `ChatVerificationBadgeEvent`, migration `2026_10_27_220000_create_chat_verification_badges.php` |
| The rules | `VerificationBadgeService`. `stepReached()` turns the badge on at the request's timing: the track's, or one set for the request with `setTiming()`. Also `earlyActivate()` (only after the identity check, AT-11), `change`, `suspend`, `reactivate`, `withdraw`, `expireDue()` (run by `chat:verification-deadlines`), and `requestClosed()`, which takes back a temporary badge when its request closes without completing (AT-04) |
| Who is verified | `Support/VerifiedAccounts` keeps one cached map of user id → end time. Saving a badge clears it, and clears it again after the transaction commits. Other modules ask through `App\Support\AccountBadge::verified($model)` |
| `is_verified` in the API | `ParticipantDirectory::profile()` (all chat screens), `UserResource`, `DriverRideResource.rider`, wallet transaction/recipient/admin resources, chat wallet cards, ratings, referrals, support tickets, chat reports, freelance admin (client), rides admin (rider), security events |
| Real time | `account.verification.changed {participant, user_id, is_verified}` goes to the account itself and to its direct-chat peers |
| Dashboard | `components/ui/UserName.vue` is used in every customer-name spot. There is a "Verification" filter on Users (`?verified=1\|0`), a `badges` page, and in the request page a badge card plus early activation and the activation moment |
| Apps | `androidApp/.../ui/components/VerifiedName.kt` (`VerifiedName`, `VerifiedTick`) is used in the chat list, chat header, chat info, group sender, members, communities, wallet confirm and the Account screen. The verification screen has a badge card. `driverApp` uses `ui/RiderTick.kt` beside the rider's name in the trip card, the rating, the chat header, the next-trip card, the change request, the history, With Me, hire jobs, bus passengers (and their chat), and the call screen (`RideCallSession.peerVerified`, outgoing and incoming) |

The paid track's badge has no end date until payment arrives (batch 4); the admin can set one with `change`.

## Rules every later batch follows

- Values come from `ChatVerificationSetting::current()`. Never hard-code a number.
- A sensitive action calls `AuditLogger::record('chat_verification.<action>', $subject, $before, $after, reason: $reason)` and `VerificationNotifier::notify('<event>', $account, $subject, [...])`.
- Admin controllers declare `EnsureVerificationPermission::map('<group>', [...])`.
