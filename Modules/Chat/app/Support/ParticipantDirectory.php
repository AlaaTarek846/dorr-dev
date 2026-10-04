<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\PrivacyAudience;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatPrivacySetting;

/**
 * Who is behind "user:7", as one viewer should see them — loaded in batches so a chat list of
 * 50 rows costs a handful of queries, not hundreds (LeeTaxi's resources ran 5–8 queries a row).
 *
 * The name shown is the one the *viewer* saved in their contacts when there is one (like
 * WhatsApp), and the photo respects the owner's `profile_photo` privacy.
 *
 * Bound as a scoped singleton: prime() everything a response needs, then profile() reads from
 * memory while the resources are built.
 */
class ParticipantDirectory
{
    /** @var array<string, Model|null> */
    private array $accounts = [];

    /** @var array<string, array<string, ChatContact>> viewer key => (account key => contact) */
    private array $contacts = [];

    /** @var array<string, ChatPrivacySetting|null> */
    private array $privacy = [];

    /** @var array<string, array<string, bool>> owner key => (viewer key => true) */
    private array $ownerHasViewer = [];

    /**
     * @param  iterable<array{0: string, 1: int|string}>  $keys  [alias, id] pairs
     */
    public function prime(Model $viewer, iterable $keys): void
    {
        $byType = [];

        foreach ($keys as [$type, $id]) {
            if ($type === null || $id === null || array_key_exists("{$type}:{$id}", $this->accounts)) {
                continue;
            }
            $byType[$type][(int) $id] = true;
        }

        foreach ($byType as $type => $ids) {
            $class = ParticipantType::modelClassFor($type);
            $query = $class::query()->withoutGlobalScopes()->whereIn('id', array_keys($ids));

            if (method_exists($class, 'media')) {
                $query->with('media');
            }

            $found = $query->get()->keyBy('id');

            foreach (array_keys($ids) as $id) {
                $this->accounts["{$type}:{$id}"] = $found->get($id);
            }

            ChatPrivacySetting::query()->where('owner_type', $type)->whereIn('owner_id', array_keys($ids))->get()
                ->each(fn (ChatPrivacySetting $p) => $this->privacy["{$type}:{$p->owner_id}"] = $p);
        }

        $this->primeContacts($viewer);
        $this->primeReverseContacts($viewer, $byType);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profile(Model $viewer, string $type, int|string|null $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $key = "{$type}:{$id}";

        if (! array_key_exists($key, $this->accounts)) {
            $this->prime($viewer, [[$type, $id]]);
        }

        $account = $this->accounts[$key] ?? null;
        $viewerKey = ParticipantType::key($viewer);
        $contact = $this->contacts[$viewerKey][$key] ?? null;
        $isMe = $key === $viewerKey;

        if ($account === null) {
            // A deleted account keeps its messages; it just has no name any more.
            return ['type' => $type, 'id' => (int) $id, 'key' => $key, 'name' => $contact?->name, 'phone' => null, 'avatar' => null, 'is_contact' => $contact !== null, 'is_me' => false, 'is_deleted' => true];
        }

        return [
            'type' => $type,
            'id' => (int) $id,
            'key' => $key,
            'name' => $contact?->name ?: ($account->name ?? null),
            'account_name' => $account->name ?? null,
            'phone' => $account->phone ?? null,
            'avatar' => $isMe || $this->mayView($key, $viewerKey, 'profile_photo') ? $this->avatarOf($account) : null,
            'is_contact' => $contact !== null,
            'is_me' => $isMe,
            'is_deleted' => false,
            // "🏖️ On holiday until Sunday" — while it lasts, and only for its audience.
            'status' => $isMe || $this->mayView($key, $viewerKey, 'status_audience') ? ($this->privacy[$key] ?? null)?->activeStatus() : null,
        ];
    }

    /**
     * Whether `$owner`'s privacy lets `$viewer` see one of their audience-controlled facts
     * (`profile_photo`, `last_seen`). Everyone else: yes; "contacts": only people the owner saved.
     */
    public function mayView(string $ownerKey, string $viewerKey, string $field): bool
    {
        if ($ownerKey === $viewerKey) {
            return true;
        }

        /** @var PrivacyAudience $audience */
        $audience = $this->privacy[$ownerKey]?->{$field} ?? PrivacyAudience::Everyone;

        return match ($audience) {
            PrivacyAudience::Everyone => true,
            PrivacyAudience::Nobody => false,
            PrivacyAudience::Contacts => isset($this->ownerHasViewer[$ownerKey][$viewerKey]),
        };
    }

    public function privacyOf(string $key): ?ChatPrivacySetting
    {
        return $this->privacy[$key] ?? null;
    }

    public function account(string $key): ?Model
    {
        return $this->accounts[$key] ?? null;
    }

    private function avatarOf(Model $account): ?string
    {
        return method_exists($account, 'getSingleMediaUrl') ? ($account->getSingleMediaUrl('avatar') ?: null) : null;
    }

    private function primeContacts(Model $viewer): void
    {
        $viewerKey = ParticipantType::key($viewer);

        if (isset($this->contacts[$viewerKey])) {
            return;
        }

        $this->contacts[$viewerKey] = [];

        ChatContact::query()->ownedBy($viewer)->whereNotNull('contact_id')->get()
            ->each(function (ChatContact $c) use ($viewerKey) {
                $this->contacts[$viewerKey]["{$c->contact_type}:{$c->contact_id}"] = $c;
            });
    }

    /**
     * Which of these people saved the viewer in *their* contacts — needed for "my contacts" privacy.
     *
     * @param  array<string, array<int, true>>  $byType
     */
    private function primeReverseContacts(Model $viewer, array $byType): void
    {
        $viewerKey = ParticipantType::key($viewer);

        foreach ($byType as $type => $ids) {
            ChatContact::query()
                ->where('owner_type', $type)->whereIn('owner_id', array_keys($ids))
                ->where('contact_type', ParticipantType::aliasFor($viewer))->where('contact_id', $viewer->getKey())
                ->pluck('owner_id')
                ->each(fn ($ownerId) => $this->ownerHasViewer["{$type}:{$ownerId}"][$viewerKey] = true);
        }
    }
}
