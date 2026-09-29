<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; min-height: 100vh; margin: 0; align-items: center; justify-content: center; background: #f5f6f8; }
        .card { max-width: 24rem; margin: 1rem; padding: 2rem; text-align: center; background: #fff; border-radius: 1rem; box-shadow: 0 2px 12px rgba(0, 0, 0, .08); }
        .icon { font-size: 3rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{{ $state === 'success' ? '✅' : ($state === 'pending' ? '⏳' : '⚠️') }}</div>
        <p>{{ __('wallet.payment_page.'.$state) }}</p>
    </div>
</body>
</html>
