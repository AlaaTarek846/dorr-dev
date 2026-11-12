<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Models\MarketingCampaign;
use Modules\Marketing\Models\MarketingWebhookEvent;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Platforms\IntegrationService;
use Modules\Marketing\Services\Platforms\PlatformRegistry;

/** The platform's side: the OAuth redirect back and signed webhooks (no user session on either). */
class PlatformCallbackController extends Controller
{
    public function __construct(private readonly IntegrationService $integrations, private readonly PlatformRegistry $platforms, private readonly MarketingAudit $audit) {}

    /** GET marketing/v1/oauth/{provider}/callback — a small page telling the person to go back to the app */
    public function callback(Request $request, string $provider)
    {
        abort_unless(in_array($provider, PlatformRegistry::PROVIDERS, true), 404);
        $integration = $this->integrations->callback($provider, $request->query('state'), $request->query('code'), $request->query('error_description') ?? $request->query('error'));
        $ok = $integration !== null && $integration->status !== 'ERROR';
        $title = __($ok ? 'marketing.oauth.done_title' : 'marketing.oauth.failed_title');
        $text = __($ok ? 'marketing.oauth.done_text' : 'marketing.oauth.failed_text');
        $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        return response(
            '<!doctype html><html dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;display:flex;min-height:90vh;align-items:center;justify-content:center;background:#f9fafb;margin:0;padding:24px}'
            .'.c{background:#fff;border-radius:22px;padding:28px;max-width:420px;text-align:center;box-shadow:0 10px 30px #0001}h1{font-size:20px;color:#111928}p{color:#6b7280;line-height:1.6}</style></head>'
            .'<body><div class="c"><h1>'.($ok ? '✅ ' : '⚠️ ').e($title).'</h1><p>'.e($text).'</p></div></body></html>',
            $ok ? 200 : 400,
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET marketing/v1/webhooks/{provider} — Meta's subscription check */
    public function verify(Request $request, string $provider)
    {
        abort_unless($provider === 'meta', 404);
        $token = (string) config('marketing.platforms.meta.webhook_verify_token');
        if ($token === '' || $request->query('hub_mode') !== 'subscribe' || ! hash_equals($token, (string) $request->query('hub_verify_token'))) {
            abort(403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    /** POST marketing/v1/webhooks/{provider} — signed (POL-15); the same delivery twice is handled once */
    public function receive(Request $request, string $provider)
    {
        abort_unless(in_array($provider, PlatformRegistry::PROVIDERS, true), 404);
        $raw = $request->getContent();
        if (! $this->platforms->get($provider)->verifySignature($raw, $request->header('X-Hub-Signature-256'))) {
            Log::warning('[Marketing] webhook with a bad signature', ['provider' => $provider]);
            abort(403);
        }
        $event = MarketingWebhookEvent::query()->firstOrCreate(['event_hash' => hash('sha256', $raw)], ['provider' => $provider, 'payload' => json_decode($raw, true) ?: []]);
        if (! $event->wasRecentlyCreated) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        $updated = 0;
        foreach ((array) ($event->payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $ref = (string) ($value['id'] ?? $value['campaign_id'] ?? '');
                $status = (string) ($value['status'] ?? $value['effective_status'] ?? '');
                if ($ref === '' || $status === '') {
                    continue;
                }
                MarketingCampaign::query()->where('provider', $provider)->where('platform_ref', $ref)->get()->each(function (MarketingCampaign $c) use ($status, &$updated) {
                    $c->update(['platform_status' => mb_substr($status, 0, 30)]);
                    $this->audit->record($c->project, null, 'campaign.platform_status', $c, meta: ['status' => $status, 'via' => 'webhook']);
                    $updated++;
                });
            }
        }
        $event->update(['processed_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }
}
