<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sandbox payment</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(160deg, #fdecef, #fff 60%); font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; color: #111928; }
        .card { width: min(92vw, 340px); background: #fff; border-radius: 24px; padding: 28px 24px; text-align: center; box-shadow: 0 20px 50px rgba(229, 9, 20, .12); animation: rise .4s ease both; }
        @keyframes rise { from { opacity: 0; transform: translateY(16px); } }
        .badge { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .5px; color: #b45309; background: #fef3c7; border-radius: 999px; padding: 4px 12px; }
        .lock { width: 60px; height: 60px; margin: 18px auto 6px; border-radius: 50%; background: #fdecef; display: grid; place-items: center; }
        .lock svg { width: 28px; height: 28px; stroke: #e50914; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        h1 { font-size: 15px; font-weight: 600; margin: 8px 0 2px; color: #6b7280; }
        .amount { font-size: 34px; font-weight: 800; letter-spacing: -.5px; margin: 0 0 4px; }
        .amount small { font-size: 16px; font-weight: 700; color: #6b7280; }
        .ref { font-size: 11px; color: #9ca3af; margin-bottom: 22px; direction: ltr; }
        form { display: flex; flex-direction: column; gap: 10px; margin: 0; }
        button { border: 0; border-radius: 14px; height: 48px; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; transition: transform .12s; }
        button:active { transform: scale(.97); }
        .approve { background: #e50914; color: #fff; box-shadow: 0 8px 18px rgba(229, 9, 20, .25); }
        .decline { background: #f3f4f6; color: #374151; }
        .note { font-size: 12px; color: #9ca3af; margin-top: 16px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="card">
    <span class="badge">SANDBOX — لا يتم خصم أي مبلغ حقيقي</span>
    <div class="lock"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div>
    <h1>{{ app()->getLocale() === 'ar' ? 'بوابة الدفع التجريبية' : 'Test payment gateway' }}</h1>
    @unless ($missing)
        <p class="amount">{{ $amount }} <small>{{ $currency }}</small></p>
    @endunless
    <div class="ref">{{ $reference }}</div>

    @if ($missing)
        <p class="note">{{ app()->getLocale() === 'ar' ? 'انتهت صلاحية جلسة الدفع التجريبية هذه أو لم تعد موجودة. ارجع للتطبيق وابدأ عملية شحن جديدة.' : 'This test payment session has expired or no longer exists. Go back to the app and start a new top-up.' }}</p>
    @elseif ($settled)
        <p class="note">{{ app()->getLocale() === 'ar' ? 'تمت معالجة هذه العملية بالفعل.' : 'This payment was already settled.' }}</p>
    @else
        <form method="POST" action="{{ route('api.wallet.sandbox.decide', ['reference' => $reference]) }}">
            <button class="approve" name="result" value="approve">{{ app()->getLocale() === 'ar' ? 'تأكيد الدفع' : 'Approve payment' }}</button>
            <button class="decline" name="result" value="decline">{{ app()->getLocale() === 'ar' ? 'رفض العملية' : 'Decline' }}</button>
        </form>
    @endif

    <p class="note">{{ app()->getLocale() === 'ar' ? 'هذه صفحة للتجربة فقط وتظهر في بيئة التطوير.' : 'For development and demos only.' }}</p>
</div>
</body>
</html>
