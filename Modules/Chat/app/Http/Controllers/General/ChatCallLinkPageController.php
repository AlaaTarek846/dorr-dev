<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Chat\Models\ChatCallLink;
use Modules\Chat\Support\AppLinkPage;

/**
 * A call link as a web page (https://…/call/{code}, WhatsApp list 72): it opens the call in the
 * DORR app (dorr://chat/call/{code}). It shows whether it's a voice or video call — never who made it.
 */
class ChatCallLinkPageController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $link = ChatCallLink::query()->usable()->where('token', $token)->first();
        $video = $link?->type?->value === 'video';

        return AppLinkPage::render($request, 'call', $token, $link !== null, null, [
            'ar' => [$video ? 'مكالمة فيديو على دُر' : 'مكالمة صوتية على دُر', 'ادخل المكالمة في دُر', 'لو التطبيق مش متسطّب عندك، نزّله الأول وبعدين افتح اللينك تاني.', 'لينك المكالمة ده مبقاش شغال. اطلب لينك جديد من اللي بعته.', 'الكود'],
            'en' => [$video ? 'A video call on DORR' : 'A voice call on DORR', 'Join the call in DORR', 'No DORR app on this phone? Install it, then open this link again.', 'This call link no longer works. Ask whoever sent it for a new one.', 'Code'],
        ]);
    }
}
