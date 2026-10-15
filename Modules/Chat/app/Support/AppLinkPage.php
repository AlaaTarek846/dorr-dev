<?php

namespace Modules\Chat\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The small web page behind a DORR link (a group invite /j/{code}, a call link /c/{code}): it
 * opens the app at `dorr://chat/{path}/{code}` (an Android intent: the app if it's installed),
 * and without the app says how to get it. Arabic or English from the browser.
 */
final class AppLinkPage
{
    /**
     * @param  array{ar: array{0: string, 1: string, 2: string, 3: string, 4: string}, en: array{0: string, 1: string, 2: string, 3: string, 4: string}}  $texts  title, button, hint, gone, code label
     */
    public static function render(Request $request, string $path, string $token, bool $valid, ?string $name, array $texts): Response
    {
        $deep = 'dorr://chat/'.$path.'/'.$token;
        $intent = 'intent://chat/'.$path.'/'.$token.'#Intent;scheme=dorr;package=com.dorr.app;end';
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $ar = str_starts_with((string) $request->getPreferredLanguage(['ar', 'en']), 'ar');
        [$title, $button, $hint, $gone, $codeLabel] = $ar ? $texts['ar'] : $texts['en'];
        $dir = $ar ? 'rtl' : 'ltr';

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
