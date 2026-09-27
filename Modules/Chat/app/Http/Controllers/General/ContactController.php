<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
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
        $list = $this->contacts->list($me, $request->boolean('registered'), $request->boolean('favorites'));

        return ApiResponse::success($list->map(fn (ChatContact $c) => $this->present($me, $c))->values(), __('api.retrieved'));
    }

    public function sync(ContactSyncRequest $request)
    {
        $me = $request->user();
        $registered = $this->contacts->sync($me, $request->validated('contacts'), currentCountry(), $request->boolean('full'));

        return ApiResponse::success($registered->map(fn (ChatContact $c) => $this->present($me, $c))->values(), __('api.updated'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'string', 'max:40']]);
        $me = $request->user();

        return ApiResponse::created($this->present($me, $this->contacts->add($me, $data['name'], $data['phone'], currentCountry())), __('api.created'));
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
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $me = $request->user();
        $account = $this->contacts->lookup($me, $data['phone'], currentCountry());

        return ApiResponse::success($this->directory->profile($me, ParticipantType::aliasFor($account), $account->getKey()), __('api.retrieved'));
    }

    public function qr(Request $request)
    {
        return ApiResponse::success($this->contacts->myQr($request->user()), __('api.retrieved'));
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
