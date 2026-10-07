<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('wallet.sandbox_page.title') }}</title>
    <style>
        @include('wallet::partials.theme-vars', ['theme' => $theme])
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(160deg, rgba(var(--primary-rgb), .14), var(--bg) 60%); font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; color: var(--ink); }
        .card { width: min(92vw, 340px); background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 28px 24px; text-align: center; box-shadow: 0 20px 50px rgba(var(--primary-rgb), .14); animation: rise .4s ease both; }
        @keyframes rise { from { opacity: 0; transform: translateY(16px); } }
        .badge { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .5px; color: #b45309; background: #fef3c7; border-radius: 999px; padding: 4px 12px; }
        .lock { width: 60px; height: 60px; margin: 18px auto 6px; border-radius: 50%; background: rgba(var(--primary-rgb), .14); display: grid; place-items: center; }
        .lock svg { width: 28px; height: 28px; stroke: var(--primary); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        h1 { font-size: 15px; font-weight: 600; margin: 8px 0 2px; color: var(--mut); }
        .amount { font-size: 34px; font-weight: 800; letter-spacing: -.5px; margin: 0 0 4px; }
        .amount small { font-size: 16px; font-weight: 700; color: var(--mut); }
        .ref { font-size: 11px; color: var(--soft); margin-bottom: 22px; direction: ltr; }
        form { display: flex; flex-direction: column; gap: 10px; margin: 0; }
        button { border: 0; border-radius: 14px; height: 48px; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; transition: transform .12s; }
        button:active { transform: scale(.97); }
        .approve { background: var(--primary); color: #fff; box-shadow: 0 8px 18px rgba(var(--primary-rgb), .28); }
        .decline { background: var(--field); color: var(--ink); }
        .note { font-size: 12px; color: var(--soft); margin-top: 16px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="card">
    <span class="badge">{{ __('wallet.sandbox_page.badge') }}</span>
    <div class="lock"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div>
    <h1>{{ __('wallet.sandbox_page.heading') }}</h1>
    @unless ($missing)
        <p class="amount">{{ $amount }} <small>{{ $currency }}</small></p>
    @endunless
    <div class="ref">{{ $reference }}</div>

    @if ($missing)
        <p class="note">{{ __('wallet.sandbox_page.missing') }}</p>
    @elseif ($settled)
        <p class="note">{{ __('wallet.sandbox_page.settled') }}</p>
    @else
        <form method="POST" action="{{ route('api.wallet.sandbox.decide', ['reference' => $reference] + ($themeQuery ?? [])) }}">
            <button class="approve" name="result" value="approve">{{ __('wallet.sandbox_page.approve') }}</button>
            <button class="decline" name="result" value="decline">{{ __('wallet.sandbox_page.decline') }}</button>
        </form>
    @endif

    <p class="note">{{ __('wallet.sandbox_page.dev_only') }}</p>
</div>
</body>
</html>
