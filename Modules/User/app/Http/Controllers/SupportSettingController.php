<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Repositories\General\FaqRepository;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\User\Models\SupportSetting;

/**
 * Dashboard → Support settings: the automatic replies (acknowledgement, away note with the working hours,
 * the AI's answers from the FAQs).
 */
class SupportSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('support-settings', [
            ['view', ['show']],
            ['update', ['update']],
        ]);
    }

    public function show(AiProviderRepository $providers, FaqRepository $faqs): JsonResponse
    {
        return ApiResponse::success($this->present(SupportSetting::current(), $providers, $faqs), __('api.retrieved'));
    }

    public function update(Request $request, AiProviderRepository $providers, FaqRepository $faqs): JsonResponse
    {
        $data = $request->validate([
            'auto_reply_enabled' => ['required', 'boolean'],
            'ack_enabled' => ['required', 'boolean'],
            'ack_message' => ['nullable', 'array'],
            'ack_message.*' => ['nullable', 'string', 'max:1000'],
            'away_enabled' => ['required', 'boolean'],
            'away_message' => ['nullable', 'array'],
            'away_message.*' => ['nullable', 'string', 'max:1000'],
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.open' => ['required', 'boolean'],
            'hours.*.from' => ['required', 'date_format:H:i'],
            'hours.*.to' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'away_every_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'ai_enabled' => ['required', 'boolean'],
            'ai_max_replies' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $settings = SupportSetting::current();
        $settings->fill([
            ...$data,
            'ack_message' => $this->texts($data['ack_message'] ?? []),
            'away_message' => $this->texts($data['away_message'] ?? []),
            'hours' => SupportSetting::normalizeHours($data['hours']),
        ])->save();

        return ApiResponse::success($this->present($settings->refresh(), $providers, $faqs), __('api.updated'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function present(SupportSetting $settings, AiProviderRepository $providers, FaqRepository $faqs): array
    {
        return [
            'auto_reply_enabled' => (bool) $settings->auto_reply_enabled,
            'ack_enabled' => (bool) $settings->ack_enabled,
            'ack_message' => array_merge(['ar' => '', 'en' => ''], $settings->ack_message ?? []),
            'away_enabled' => (bool) $settings->away_enabled,
            'away_message' => array_merge(['ar' => '', 'en' => ''], $settings->away_message ?? []),
            'hours' => SupportSetting::normalizeHours($settings->hours),
            'timezone' => $settings->timezone,
            'away_every_hours' => (int) $settings->away_every_hours,
            'ai_enabled' => (bool) $settings->ai_enabled,
            'ai_max_replies' => (int) $settings->ai_max_replies,
            // What the screen shows so the admin knows whether the AI can actually answer.
            'ai_available' => $providers->resolveActiveForChat() !== null,
            'faqs_count' => $faqs->generalActive()->count(),
            'open_now' => $settings->isOpenAt(now()),
            // The default texts, shown as placeholders while the admin's own are empty.
            'default_texts' => [
                'ack' => ['ar' => __('support.auto_ack', [], 'ar'), 'en' => __('support.auto_ack', [], 'en')],
                'away' => ['ar' => __('support.auto_away', [], 'ar'), 'en' => __('support.auto_away', [], 'en')],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array{ar: string, en: string}
     */
    private function texts(array $texts): array
    {
        $out = ['ar' => '', 'en' => ''];
        foreach ($texts as $code => $text) {
            if (preg_match('/^[a-z]{2,8}(-[A-Za-z]{2,4})?$/', (string) $code)) {
                $out[$code] = trim((string) $text);
            }
        }

        return $out;
    }
}
