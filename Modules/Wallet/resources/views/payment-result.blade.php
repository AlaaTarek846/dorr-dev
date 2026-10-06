<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        @include('wallet::partials.theme-vars', ['theme' => $theme])
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(160deg, rgba(var(--primary-rgb), .14), var(--bg) 60%); font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; color: var(--ink); }
        .card { width: min(92vw, 340px); margin: 1rem; padding: 28px 24px; text-align: center; background: var(--surface); border: 1px solid var(--line); border-radius: 24px; box-shadow: 0 20px 50px rgba(var(--primary-rgb), .14); animation: rise .4s ease both; }
        @keyframes rise { from { opacity: 0; transform: translateY(16px); } }
        .icon { width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 50%; display: grid; place-items: center; }
        .icon svg { width: 30px; height: 30px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; stroke: currentColor; }
        .icon.success, .icon.processed { color: var(--green); background: rgba(22, 163, 74, .14); }
        .icon.pending { color: var(--primary); background: rgba(var(--primary-rgb), .14); }
        .icon.failed { color: var(--danger); background: rgba(220, 38, 38, .12); }
        p { margin: 0; font-size: 15px; font-weight: 600; line-height: 1.7; }
    </style>
</head>
<body>
    @php($tone = in_array($state, ['success', 'processed', 'pending'], true) ? $state : 'failed')
    <div class="card">
        <div class="icon {{ $tone }}">
            @if ($tone === 'success' || $tone === 'processed')
                <svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            @elseif ($tone === 'pending')
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            @else
                <svg viewBox="0 0 24 24"><path d="M12 8v5"/><path d="M12 16.5v.01"/><path d="M10.3 3.9L2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
            @endif
        </div>
        <p>{{ __('wallet.payment_page.'.$state) }}</p>
    </div>
</body>
</html>
