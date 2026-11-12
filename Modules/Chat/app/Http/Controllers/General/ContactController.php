<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\ContactSyncRequest;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Contacts — synced from the phone, added by number, or by QR (decision 2026-09-27).
 */
class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly ParticipantDirectory $directory,
    ) {}

    public function index(Request $request)
    {
        $me = $request->user();
        $f = $request->validate([
            'registered' => ['nullable', 'boolean'],
            'favorites' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $registered = $request->boolean('registered');
        $favorites = $request->boolean('favorites');
        if (isset($f['page'])) {
            $page = $this->contacts->page($me, $registered, $favorites, $f['q'] ?? null, (int) $f['page'], (int) ($f['per_page'] ?? 15));

            return ApiResponse::success($page->getCollection()->map(fn (ChatContact $c) => $this->present($me, $c))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
        }
        $list = $this->contacts->list($me, $registered, $favorites);

        return ApiResponse::success($list->map(fn (ChatContact $c) => $this->present($me, $c))->values(), __('api.retrieved'));
    }

    public function sync(ContactSyncRequest $request)
    {
        $me = $request->user();
        $registered = $this->contacts->sync($me, $request->validated('contacts'), $this->contacts->countryFor($me), $request->boolean('full'));

        return ApiResponse::success($registered->map(fn (ChatContact $c) => $this->present($me, $c))->values(), __('api.updated'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'string', 'max:40']]);
        $me = $request->user();

        return ApiResponse::created($this->present($me, $this->contacts->add($me, $data['name'], $data['phone'], $this->contacts->countryFor($me))), __('api.created'));
    }

    public function update(Request $request, ChatContact $contact)
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'is_favorite' => ['sometimes', 'boolean']]);
        $me = $request->user();

        return ApiResponse::success($this->present($me, $this->contacts->update($me, $contact, $data)), __('api.updated'));
    }

    public function destroy(Request $request, ChatContact $contact)
    {
        $this->contacts->delete($request->user(), $contact);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * "Is this number on Dorr?" (rate-limited at the route).
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            // The country picked next to the number; defaults to the country of my own phone.
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        $me = $request->user();
        $country = isset($data['country_code'])
            ? Country::query()->where('status', true)->where('code', strtoupper($data['country_code']))->first()
            : null;
        $account = $this->contacts->lookup($me, $data['phone'], $country ?? $this->contacts->countryFor($me));

        return ApiResponse::success($this->directory->profile($me, ParticipantType::aliasFor($account), $account->getKey()), __('api.retrieved'));
    }

    public function qr(Request $request)
    {
        return ApiResponse::success($this->contacts->myQr($request->user()), __('api.retrieved'));
    }

    /** `text?` — the message the chat opens with. */
    public function chatLink(Request $request)
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->contacts->chatLink($request->user(), $data['text'] ?? null), __('api.retrieved'));
    }

    public function resetQr(Request $request)
    {
        return ApiResponse::success($this->contacts->myQr($request->user(), reset: true), __('api.updated'));
    }

    public function resolveQr(Request $request)
    {
        $data = $request->validate(['payload' => ['required', 'string', 'max:200']]);
        $me = $request->user();
        $account = $this->contacts->resolveQr($me, $data['payload']);

        return ApiResponse::success($this->directory->profile($me, ParticipantType::aliasFor($account), $account->getKey()), __('api.retrieved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present($me, ChatContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'is_favorite' => $contact->is_favorite,
            'source' => $contact->source,
            'is_registered' => $contact->isRegistered(),
            'profile' => $contact->isRegistered() ? $this->directory->profile($me, $contact->contact_type, $contact->contact_id) : null,
        ];
    }
}
