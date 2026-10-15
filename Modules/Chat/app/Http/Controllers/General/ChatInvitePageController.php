<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Support\AppLinkPage;

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
        $group = ChatGroup::query()->where('invite_token', $token)->first();
        $valid = $group !== null && ! $group->invite_expires_at?->isPast();
        $group = $valid ? $group : null;

        return AppLinkPage::render($request, 'join', $token, $valid, $group?->name, [
            'ar' => ['دعوة على دُر', 'افتح الدعوة في دُر', 'لو التطبيق مش متسطّب عندك، نزّله الأول وبعدين افتح اللينك تاني، أو اكتب الكود في «انضم بكود أو لينك».', 'اللينك ده مبقاش شغال. اطلب لينك جديد من مشرف الجروب.', 'الكود'],
            'en' => ['An invite on DORR', 'Open the invite in DORR', 'No DORR app on this phone? Install it, then open this link again — or type the code in "Join with a code or link".', 'This link no longer works. Ask a group admin for a new one.', 'Code'],
        ]);
    }
}
