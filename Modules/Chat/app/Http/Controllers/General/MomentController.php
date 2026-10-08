<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatPersonalMoment;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\ChatAiService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Services\MomentCardService;
use Modules\Chat\Services\MomentService;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

/**
 * DORR Moments for the app (spec 160, 168): the Moments Center, the catalog to choose from, my
 * preferences, and my own dates.
 */
class MomentController extends Controller
{
    public function __construct(private readonly MomentService $moments) {}

    /** GET moments — on now, coming, and my own dates. */
    public function index(Request $request)
    {
        return ApiResponse::success($this->moments->center($request->user()), __('api.retrieved'));
    }

    /** GET moments/catalog?country_id= — every occasion of a country, with mine marked. */
    public function catalog(Request $request)
    {
        $data = $request->validate(['country_id' => ['nullable', 'integer', 'exists:countries,id']]);
        $country = isset($data['country_id']) ? Country::query()->find($data['country_id']) : null;

        return ApiResponse::success($this->moments->catalog($request->user(), $country), __('api.retrieved'));
    }

    /** PUT moments/preferences — `{enabled?, country_id?, effects?, on?[], off?[]}` */
    public function preferences(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'effects' => ['sometimes', Rule::in(['full', 'light', 'off'])],
            'on' => ['sometimes', 'array'],
            'on.*' => ['integer'],
            'off' => ['sometimes', 'array'],
            'off.*' => ['integer'],
        ]);
        $me = $request->user();
        $this->moments->savePreferences($me, $data);

        return ApiResponse::success($this->moments->center($me), __('api.updated'));
    }

    /**
     * POST conversations/{c}/moment-card (multipart) — a greeting card (spec 161–167): `moment_id`
     * or `personal_kind`, `title?`, `text?`, `voice?` (my own recording), `photos[]?`, `reveal_at?`
     * (a surprise), `send_at?` + `schedule_zone` (recipient | mine), `gift_amount_minor?` (+ the
     * wallet PIN in X-Wallet-Pin).
     */
    public function card(Request $request, ChatConversation $conversation)
    {
        $max = ChatSetting::current()->max_file_size_mb * 1024;
        $data = $request->validate([
            'moment_id' => ['nullable', 'integer'],
            'personal_kind' => ['nullable', Rule::in(ChatPersonalMoment::KINDS)],
            'title' => ['nullable', 'string', 'max:120'],
            'text' => ['nullable', 'string', 'max:2000'],
            'reveal_at' => ['nullable', 'date'],
            'send_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'schedule_zone' => ['nullable', Rule::in(['recipient', 'mine'])],
            'gift_amount_minor' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'silent' => ['nullable', 'boolean'],
            'uuid' => ['nullable', 'uuid'],
            'voice' => ['nullable', 'file', 'max:'.$max, 'mimes:m4a,aac,mp3,ogg,oga,opus,wav,amr,mp4,3gp,webm'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'max:'.$max],
        ]);
        $data['pin'] = $request->header('X-Wallet-Pin');
        $files = array_values(array_filter([$request->file('voice'), ...($request->file('photos') ?? [])]));
        $me = $request->user();

        $result = app(MomentCardService::class)->send($me, $conversation, $data, $files);

        return $result['message'] !== null
            ? ApiResponse::created(app(MessageService::class)->presentOne($me, $result['message']), __('api.created'))
            : ApiResponse::created(['scheduled' => $result['scheduled']->present()], __('api.created'));
    }

    /**
     * POST moments/greetings — `{moment_id? | kind?, relation, tone, name?}`: three greetings to
     * start from (spec 161). Suggestions only — I edit and send.
     */
    public function greetings(Request $request)
    {
        $data = $request->validate([
            'moment_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(ChatPersonalMoment::KINDS)],
            'relation' => ['required', Rule::in(['family', 'friend', 'partner', 'work', 'other'])],
            'tone' => ['required', Rule::in(['warm', 'formal', 'funny', 'short', 'poetic'])],
            'name' => ['nullable', 'string', 'max:60'],
            'dialect' => ['nullable', 'string', 'max:40'],
        ]);
        $occasion = ! empty($data['moment_id'])
            ? ChatMoment::query()->with('translations')->find($data['moment_id'])?->translatedName()
            : __('chat.card.titles.'.($data['kind'] ?? 'other'));

        return ApiResponse::success(app(ChatAiService::class)->greetings((string) $occasion, $data['relation'], $data['tone'], $data['name'] ?? null, $data['dialect'] ?? null), __('api.retrieved'));
    }

    public function personal(Request $request)
    {
        return ApiResponse::success($this->moments->personal($request->user()), __('api.retrieved'));
    }

    public function storePersonal(Request $request)
    {
        $me = $request->user();
        if (ChatPersonalMoment::query()->ownedBy($me)->count() >= 200) {
            throw new ChatException('moment_limit', 422, ['max' => 200]);
        }
        $data = $this->validated($request, true);
        ChatPersonalMoment::query()->create($data + [
            'uuid' => (string) Str::uuid(),
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
        ]);

        return ApiResponse::created($this->moments->personal($me), __('api.created'));
    }

    public function updatePersonal(Request $request, ChatPersonalMoment $moment)
    {
        $me = $request->user();
        abort_unless($moment->isOwnedBy($me), 404);
        $moment->update($this->validated($request, false));

        return ApiResponse::success($this->moments->personal($me), __('api.updated'));
    }

    public function destroyPersonal(Request $request, ChatPersonalMoment $moment)
    {
        $me = $request->user();
        abort_unless($moment->isOwnedBy($me), 404);
        $moment->delete();

        return ApiResponse::success($this->moments->personal($me), __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'kind' => [$req, Rule::in(ChatPersonalMoment::KINDS)],
            'title' => [$req, 'string', 'max:120'],
            'month' => [$req, 'integer', 'between:1,12'],
            'day' => [$req, 'integer', 'between:1,31'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1900,2100'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'remind_days_before' => ['sometimes', 'integer', 'between:0,30'],
        ]);

        if (isset($data['month'], $data['day']) && ! checkdate($data['month'], $data['day'], 2024)) {
            throw new ChatException('moment_date_invalid', 422);
        }
        if (array_key_exists('contact_id', $data)) {
            $data['contact_type'] = $data['contact_id'] ? ParticipantType::aliasFor(new User) : null;
        }

        return $data;
    }
}
