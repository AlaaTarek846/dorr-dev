<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Chat\Models\ChatGroup;

/**
 * A group / channel invite (https://…/j/{token}) opens the join sheet in the DORR app
 * (dorr://chat/join/{token}); without the app, it says how to get it. Wrong or expired
 * tokens are 404 — the page shows the group's name only when the link is still valid,
 * never its members.
 */
class ChatInvitePageController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $group = ChatGroup::query()->where('invite_token', $token)->with('conversation')->first();
        $valid = $group !== null && $group->conversation !== null && ! $group->invite_expires_at?->isPast();
        $deep = 'dorr://chat/join/'.$token;
        $intent = 'intent://chat/join/'.$token.'#Intent;scheme=dorr;package=com.dorr.app;end';
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $ar = str_starts_with((string) $request->getPreferredLanguage(['ar', 'en']), 'ar');
        [$title, $button, $hint, $gone, $codeLabel] = $ar
            ? ['دعوة لمجموعة على دُر', 'افتح الدعوة في دُر', 'لو التطبيق مش متسطّب عندك، نزّله الأول وبعدين افتح اللينك تاني، أو اكتب الكود في «انضم بكود أو لينك».', 'اللينك ده مبقاش شغال. اطلب لينك جديد من مشرف الجروب.', 'الكود']
            : ['A DORR group invite', 'Open the invite in DORR', 'No DORR app on this phone? Install it, then open this link again — or type the code in "Join with a code or link".', 'This link no longer works. Ask a group admin for a new one.', 'Code'];
        $dir = $ar ? 'rtl' : 'ltr';
        $name = $valid ? $group->name : null;

        $body = $valid
            ? ($name ? '<div class="name">'.$e($name).'</div>' : '')
                .'<a class="btn" href="'.$e($intent).'" data-deep="'.$e($deep).'">'.$e($button).'</a>'
                .'<p>'.$e($hint).'</p><div class="code">'.$e($codeLabel).': <b dir="ltr">'.$e($token).'</b></div>'
            : '<p>'.$e($gone).'</p>';
        $script = $valid
            ? '<script>(function(){var a=document.querySelector("a.btn");if(!/android/i.test(navigator.userAgent)){a.href=a.getAttribute("data-deep");}setTimeout(function(){window.location.href=a.href;},300);})();</script>'
            : '';

        $html = <<<HTML
<!doctype html>
<html lang="{$e($ar ? 'ar' : 'en')}" dir="{$dir}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$e($title)}</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7fb;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;color:#001b53}
.card{background:#fff;border-radius:24px;padding:32px 24px;max-width:360px;margin:16px;text-align:center;box-shadow:0 10px 30px rgba(0,27,83,.08)}
h1{font-size:20px;margin:0 0 12px}
.name{font-size:18px;font-weight:700;background:#f0f3fa;border-radius:14px;padding:12px;margin-bottom:6px}
a.btn{display:block;background:#fa7552;color:#fff;text-decoration:none;font-weight:700;padding:14px;border-radius:14px;margin:18px 0 12px}
p{color:#5b6b8c;font-size:14px;line-height:1.6;margin:0}
.code{margin-top:14px;color:#5b6b8c;font-size:13px}
</style>
</head>
<body>
<div class="card">
<h1>{$e($title)}</h1>
{$body}
</div>
{$script}
</body>
</html>
HTML;

        return response($html, $valid ? 200 : 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
