<?php

namespace Modules\Chat\Services;

use App\Enums\UserStatus;
use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;
use Modules\Chat\Support\PhoneNumber;
use Modules\User\Models\User;

/**
 * My contacts, three ways in (decision 2026-09-27): synced from the phone's address book,
 * typed in by number, or scanned from someone's chat QR code.
 *
 * Synced numbers of people who aren't registered are kept too (so the app can offer "invite"),
 * but they are never shown to anyone else and never reveal anything about the person.
 */
class ContactService
{
    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly ParticipantDirectory $directory,
    ) {}

    /**
     * Upload (a batch of) the phone's address book. `$full` = this is the whole book: numbers
     * that disappeared from it are removed here too (manual / QR contacts are kept).
     *
     * @param  list<array{name: string, phone: string}>  $entries
     * @return Collection<int, ChatContact> the registered ones, ready to chat with
     */
    public function sync(Model $me, array $entries, ?Country $country, bool $full = false): Collection
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $phone = PhoneNumber::toE164((string) ($entry['phone'] ?? ''), $country);

            if ($phone !== null && $phone !== ($me->phone ?? null)) {
                $normalized[$phone] = mb_substr(trim((string) ($entry['name'] ?? '')) ?: $phone, 0, 150);
            }
        }

        DB::transaction(function () use ($me, $normalized, $full) {
            $ownerType = ParticipantType::aliasFor($me);
            $matches = $this->registeredByPhone(array_keys($normalized));
            $now = now();

            foreach (array_chunk($normalized, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $phone => $name) {
                    $account = $matches[$phone] ?? null;
                    $rows[] = [
                        'owner_type' => $ownerType,
                        'owner_id' => $me->getKey(),
                        'name' => $name,
                        'phone' => $phone,
                        'contact_type' => $account ? 'user' : null,
                        'contact_id' => $account?->id,
                        'source' => 'device',
                        'is_favorite' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // The address book is the source of truth for names of numbers it contains
                // (a later sync renames), and `source` of an existing manual/QR row is kept.
                ChatContact::query()->upsert($rows, ['owner_type', 'owner_id', 'phone'], ['name', 'contact_type', 'contact_id', 'updated_at']);
            }

            if ($full) {
                ChatContact::query()->ownedBy($me)->where('source', 'device')->whereNotIn('phone', array_keys($normalized))->delete();
            }
        });

        return $this->list($me, registeredOnly: true);
    }

    /**
     * @return Collection<int, ChatContact>
     */
    public function list(Model $me, bool $registeredOnly = false, bool $favoritesOnly = false): Collection
    {
        // Someone who registered after I synced shows up without a re-sync.
        $this->refreshMatches($me);

        $contacts = ChatContact::query()->ownedBy($me)
            ->when($registeredOnly, fn ($q) => $q->whereNotNull('contact_id'))
            ->when($favoritesOnly, fn ($q) => $q->where('is_favorite', true))
            ->orderBy('name')->get();

        $this->directory->prime($me, $contacts->whereNotNull('contact_id')->map(fn ($c) => [$c->contact_type, $c->contact_id]));

        return $contacts;
    }

    public function add(Model $me, string $name, string $phone, ?Country $country, string $source = 'manual'): ChatContact
    {
        $e164 = PhoneNumber::toE164($phone, $country) ?? throw new ChatException('invalid_phone', 422);
        $account = $this->registeredByPhone([$e164])[$e164] ?? null;

        return ChatContact::query()->updateOrCreate(
            ['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey(), 'phone' => $e164],
            ['name' => mb_substr(trim($name), 0, 150), 'contact_type' => $account ? 'user' : null, 'contact_id' => $account?->id, 'source' => $source],
        );
    }

    public function update(Model $me, ChatContact $contact, array $data): ChatContact
    {
        $this->assertOwned($me, $contact);
        $contact->update(array_intersect_key($data, array_flip(['name', 'is_favorite'])));

        return $contact;
    }

    public function delete(Model $me, ChatContact $contact): void
    {
        $this->assertOwned($me, $contact);
        $contact->delete();
    }

    /**
     * "Is this number on Dorr?" — rate-limited at the route so it can't be used to scan numbers.
     */
    public function lookup(Model $me, string $phone, ?Country $country): User
    {
        $e164 = PhoneNumber::toE164($phone, $country) ?? throw new ChatException('invalid_phone', 422);
        $account = $this->registeredByPhone([$e164])[$e164] ?? null;

        if ($account === null || ParticipantType::key($account) === ParticipantType::key($me)) {
            throw ChatException::userNotFound();
        }

        return $account;
    }

    /**
     * @return array{payload: string, token: string}
     */
    public function myQr(Model $me, bool $reset = false): array
    {
        $token = $this->privacy->qrToken($me, $reset);

        return ['token' => $token, 'payload' => config('chat.qr_prefix').$token];
    }

    /**
     * Who is behind a scanned chat QR code.
     */
    public function resolveQr(Model $me, string $payload): Model
    {
        $prefix = (string) config('chat.qr_prefix');
        $token = str_starts_with($payload, $prefix) ? substr($payload, strlen($prefix)) : $payload;

        $setting = $token !== '' ? ChatPrivacySetting::query()->where('qr_token', $token)->first() : null;

        if ($setting === null || ! ParticipantType::isEnabled($setting->owner_type)) {
            throw ChatException::invalidQr();
        }

        $account = ParticipantType::modelClassFor($setting->owner_type)::query()->find($setting->owner_id);

        if ($account === null || ParticipantType::key($account) === ParticipantType::key($me)) {
            throw ChatException::invalidQr();
        }

        return $account;
    }

    /**
     * The country to read *my* address book in: the one my own phone number belongs to (its dial
     * code) — far more reliable than the request country, which falls back to IP / the default
     * country when the profile has no country yet (that turned every "010…" into a Saudi number).
     */
    public function countryFor(Model $me): ?Country
    {
        $phone = (string) ($me->phone ?? '');

        if (str_starts_with($phone, '+')) {
            $digits = substr($phone, 1);
            $match = Country::query()->where('status', true)->whereNotNull('dial_code')->get(['id', 'code', 'dial_code', 'phone_length', 'phone_starts_with'])
                ->filter(fn (Country $c) => ($dial = ltrim((string) $c->dial_code, '+')) !== '' && str_starts_with($digits, $dial))
                ->sortByDesc(fn (Country $c) => strlen(ltrim((string) $c->dial_code, '+')))
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        return currentCountry();
    }

    /**
     * @param  list<string>  $phones  E.164
     * @return array<string, User>
     */
    private function registeredByPhone(array $phones): array
    {
        if ($phones === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($phones, 1000) as $chunk) {
            User::query()->whereIn('phone', $chunk)->where('status', UserStatus::Active)->whereNotNull('phone_verified_at')
                ->get(['id', 'name', 'phone'])
                ->each(function (User $u) use (&$found) {
                    $found[$u->phone] = $u;
                });
        }

        return $found;
    }

    private function refreshMatches(Model $me): void
    {
        $unmatched = ChatContact::query()->ownedBy($me)->whereNull('contact_id')->pluck('phone')->all();

        foreach ($this->registeredByPhone($unmatched) as $phone => $account) {
            ChatContact::query()->ownedBy($me)->where('phone', $phone)->update(['contact_type' => 'user', 'contact_id' => $account->id]);
        }
    }

    private function assertOwned(Model $me, ChatContact $contact): void
    {
        if ($contact->owner_type !== ParticipantType::aliasFor($me) || (int) $contact->owner_id !== (int) $me->getKey()) {
            throw new ChatException('contact_not_found', 404);
        }
    }
}
