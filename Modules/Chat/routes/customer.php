<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\General\BusinessController;
use Modules\Chat\Http\Controllers\General\CallController;
use Modules\Chat\Http\Controllers\General\ChannelController;
use Modules\Chat\Http\Controllers\General\ChatAiController;
use Modules\Chat\Http\Controllers\General\ContactController;
use Modules\Chat\Http\Controllers\General\ExpressionController;
use Modules\Chat\Http\Controllers\General\ConversationController;
use Modules\Chat\Http\Controllers\General\FolderController;
use Modules\Chat\Http\Controllers\General\GroupController;
use Modules\Chat\Http\Controllers\General\MessageController;
use Modules\Chat\Http\Controllers\General\MessageExtrasController;
use Modules\Chat\Http\Controllers\General\PrivacyController;
use Modules\Chat\Http\Controllers\General\RealtimeConfigController;
use Modules\Chat\Http\Controllers\General\ScheduledMessageController;
use Modules\Chat\Http\Controllers\General\StoryController;
use Modules\Chat\Http\Controllers\General\ThemeReportController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

/*
 * Chat endpoints shared by every kind of participant. Required from inside each audience's own
 * route group (mobile.php today; provider.php when providers can chat — docs/chat-plan.md §9.1),
 * which supplies the prefix, auth guard and `country` middleware.
 *
 * {conversation}, {message} and {call} are uuids; {contact}, {folder} and {participant} are ids.
 */
Route::prefix('chat')->group(function () {
    Route::get('realtime-config', RealtimeConfigController::class);

    // ------------------------------------------------------------ conversations
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::post('conversations/direct', [ConversationController::class, 'direct']);
    Route::post('conversations/self', [ConversationController::class, 'self']);
    Route::post('conversations/delivered', [ConversationController::class, 'delivered']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::patch('conversations/{conversation}/settings', [ConversationController::class, 'settings']);
    Route::post('conversations/{conversation}/wallpaper', [ConversationController::class, 'wallpaper'])->middleware('throttle:20,1,chat-wallpaper');
    Route::post('conversations/{conversation}/clear', [ConversationController::class, 'clear']);
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read']);
    Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing'])->middleware('throttle:40,1,chat-typing');
    Route::put('conversations/{conversation}/disappearing', [ConversationController::class, 'disappearing']);
    Route::post('conversations/{conversation}/accept', [ConversationController::class, 'accept']);
    Route::post('conversations/{conversation}/reject', [ConversationController::class, 'reject']);

    // ------------------------------------------------------------ messages
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->middleware('throttle:120,1,chat-send');
    Route::post('conversations/{conversation}/messages/delete-for-me', [MessageController::class, 'deleteForMe']);
    Route::get('conversations/{conversation}/messages/search', [MessageController::class, 'search']);
    Route::get('conversations/{conversation}/gallery', [MessageController::class, 'gallery']);
    Route::get('conversations/{conversation}/pinned', [MessageController::class, 'pinned']);
    Route::get('conversations/{conversation}/starred', [MessageController::class, 'starred']);

    Route::post('messages/forward', [MessageController::class, 'forward'])->middleware('throttle:30,1,chat-forward');
    Route::get('messages/search', [MessageController::class, 'search']);
    Route::get('messages/starred', [MessageController::class, 'starred']);
    Route::get('messages/read-later', [MessageController::class, 'readLaterList']);
    Route::get('messages/follow-up', [MessageController::class, 'followUpList']);
    Route::get('reminders', [MessageController::class, 'reminders']);
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    Route::put('messages/{message}/reaction', [MessageController::class, 'react']);
    Route::get('messages/{message}/reactions', [MessageController::class, 'reactions']);
    Route::put('messages/{message}/star', [MessageController::class, 'star']);
    Route::put('messages/{message}/read-later', [MessageController::class, 'readLater']);
    Route::put('messages/{message}/follow-up', [MessageController::class, 'followUp']);
    Route::put('messages/{message}/reminder', [MessageController::class, 'setReminder'])->middleware('throttle:60,1,chat-reminders');
    Route::delete('messages/{message}/reminder', [MessageController::class, 'clearReminder']);
    Route::post('messages/{message}/pin', [MessageController::class, 'pin']);
    Route::delete('messages/{message}/pin', [MessageController::class, 'unpin']);
    Route::get('messages/{message}/info', [MessageController::class, 'info']);

    // ------------------------------------------------------------ polls, view once, live location, link cards
    Route::put('messages/{message}/vote', [MessageExtrasController::class, 'vote'])->middleware('throttle:60,1,chat-vote');
    Route::get('messages/{message}/votes', [MessageExtrasController::class, 'votes']);
    Route::post('messages/{message}/open', [MessageExtrasController::class, 'open']);
    Route::put('messages/{message}/live-location', [MessageExtrasController::class, 'moveLive'])->middleware('throttle:40,1,chat-live');
    Route::post('messages/{message}/live-location/stop', [MessageExtrasController::class, 'stopLive']);
    Route::get('live-locations', [MessageExtrasController::class, 'myLive']);
    Route::get('link-preview', [MessageExtrasController::class, 'linkPreview'])->middleware('throttle:30,1,chat-link-preview');

    // ------------------------------------------------------------ money requests & bill splits
    Route::post('messages/{message}/pay', [MessageExtrasController::class, 'pay'])->middleware([RequiresWalletPin::class, 'throttle:20,1,chat-pay']);
    Route::post('conversations/{conversation}/send-money', [MessageExtrasController::class, 'sendMoney'])->middleware([RequiresWalletPin::class, 'throttle:20,1,chat-send-money']);
    Route::post('messages/{message}/decline-request', [MessageExtrasController::class, 'declineRequest']);
    Route::post('messages/{message}/cancel-request', [MessageExtrasController::class, 'cancelRequest']);

    // ------------------------------------------------------------ groups
    Route::post('groups', [GroupController::class, 'store']);
    Route::post('groups/{conversation}', [GroupController::class, 'update']); // POST: multipart avatar
    Route::patch('groups/{conversation}/settings', [GroupController::class, 'settings']);
    Route::get('groups/{conversation}/members', [GroupController::class, 'members']);
    Route::post('groups/{conversation}/members', [GroupController::class, 'addMembers']);
    Route::delete('groups/{conversation}/members/{participant}', [GroupController::class, 'removeMember']);
    Route::patch('groups/{conversation}/members/{participant}/role', [GroupController::class, 'role']);
    Route::post('groups/{conversation}/leave', [GroupController::class, 'leave']);
    Route::post('groups/{conversation}/owner', [GroupController::class, 'transferOwnership']);
    Route::delete('groups/{conversation}', [GroupController::class, 'destroy']);
    Route::get('groups/{conversation}/invite', [GroupController::class, 'invite']);
    Route::post('groups/{conversation}/invite/reset', [GroupController::class, 'resetInvite']);
    Route::get('invites/{token}', [GroupController::class, 'previewInvite']);
    Route::post('invites/{token}/join', [GroupController::class, 'join']);
    Route::post('invites/{token}/cancel', [GroupController::class, 'cancelJoin']);
    Route::get('groups/{conversation}/join-requests', [GroupController::class, 'joinRequests']);
    Route::post('groups/{conversation}/join-requests/{joinRequest}/approve', [GroupController::class, 'approveJoin']);
    Route::post('groups/{conversation}/join-requests/{joinRequest}/reject', [GroupController::class, 'rejectJoin']);

    // ------------------------------------------------------------ stickers & GIFs
    Route::get('stickers', [ExpressionController::class, 'stickers']);
    Route::post('stickers/mine', [ExpressionController::class, 'storeMine'])->middleware('throttle:30,1,chat-my-stickers');
    Route::delete('stickers/mine/{sticker}', [ExpressionController::class, 'destroyMine'])->whereNumber('sticker');
    Route::get('gifs', [ExpressionController::class, 'library'])->middleware('throttle:60,1,chat-gifs');

    // ------------------------------------------------------------ channels
    Route::post('channels', [ChannelController::class, 'store'])->middleware('throttle:10,1,chat-channels');
    Route::get('channels/discover', [ChannelController::class, 'discover']);
    Route::get('channels/{channel}', [ChannelController::class, 'show']);
    Route::post('channels/{channel}/follow', [ChannelController::class, 'follow']);
    Route::post('channels/{conversation}/unfollow', [ChannelController::class, 'unfollow']);
    Route::patch('channels/{conversation}/handle', [ChannelController::class, 'handle']);

    // ------------------------------------------------------------ contacts
    Route::get('contacts', [ContactController::class, 'index']);
    Route::post('contacts/sync', [ContactController::class, 'sync'])->middleware('throttle:20,1,chat-contacts-sync');
    Route::post('contacts', [ContactController::class, 'store']);
    // Rate-limited: must not become a way to scan which numbers are registered.
    Route::post('contacts/lookup', [ContactController::class, 'lookup'])->middleware('throttle:20,1,chat-lookup');
    Route::get('contacts/qr', [ContactController::class, 'qr']);
    Route::post('contacts/qr/reset', [ContactController::class, 'resetQr']);
    Route::post('contacts/qr/resolve', [ContactController::class, 'resolveQr'])->middleware('throttle:30,1,chat-lookup');
    Route::patch('contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);

    // ------------------------------------------------------------ privacy, blocks, presence
    Route::get('privacy', [PrivacyController::class, 'show']);
    Route::patch('privacy', [PrivacyController::class, 'update']);
    Route::put('status', [PrivacyController::class, 'setStatus']);
    Route::put('privacy-mode', [PrivacyController::class, 'privacyModeOn']);
    Route::delete('privacy-mode', [PrivacyController::class, 'privacyModeOff']);
    Route::get('privacy-mode/summary', [PrivacyController::class, 'privacySummary']);
    Route::delete('status', [PrivacyController::class, 'clearStatus']);
    Route::get('blocks', [PrivacyController::class, 'blocked']);
    Route::post('blocks', [PrivacyController::class, 'block']);
    Route::post('blocks/remove', [PrivacyController::class, 'unblock']);
    Route::post('presence', [PrivacyController::class, 'presence'])->middleware('throttle:10,1,chat-presence');

    // ------------------------------------------------------------ folders
    Route::get('folders', [FolderController::class, 'index']);
    Route::post('folders', [FolderController::class, 'store']);
    Route::patch('folders/{folder}', [FolderController::class, 'update']);
    Route::delete('folders/{folder}', [FolderController::class, 'destroy']);
    Route::put('folders/{folder}/conversations', [FolderController::class, 'conversations']);

    // ------------------------------------------------------------ stories
    Route::get('stories', [StoryController::class, 'index']);
    Route::post('stories', [StoryController::class, 'store'])->middleware('throttle:30,1,chat-stories');
    Route::get('stories/privacy', [StoryController::class, 'privacy']);
    Route::put('stories/privacy', [StoryController::class, 'updatePrivacy']);
    Route::post('stories/mute', [StoryController::class, 'mute']);
    Route::delete('stories/{story}', [StoryController::class, 'destroy']);
    Route::post('stories/{story}/view', [StoryController::class, 'view']);
    Route::put('stories/{story}/reaction', [StoryController::class, 'react']);
    Route::post('stories/{story}/reply', [StoryController::class, 'reply']);
    Route::get('stories/{story}/viewers', [StoryController::class, 'viewers']);

    // ------------------------------------------------------------ themes & reports
    Route::get('themes', [ThemeReportController::class, 'themes']);
    Route::get('report-types', [ThemeReportController::class, 'reportTypes']);
    Route::post('conversations/{conversation}/report', [ThemeReportController::class, 'report'])->middleware('throttle:10,1,chat-report');

    // ------------------------------------------------------------ scheduled messages
    Route::get('conversations/{conversation}/scheduled', [ScheduledMessageController::class, 'index']);
    Route::post('conversations/{conversation}/scheduled', [ScheduledMessageController::class, 'store'])->middleware('throttle:30,1,chat-schedule');
    Route::patch('scheduled/{scheduled}', [ScheduledMessageController::class, 'update']);
    Route::delete('scheduled/{scheduled}', [ScheduledMessageController::class, 'destroy']);
    Route::post('scheduled/{scheduled}/send', [ScheduledMessageController::class, 'sendNow']);

    // ------------------------------------------------------------ business tools
    Route::get('business', [BusinessController::class, 'show']);
    Route::patch('business', [BusinessController::class, 'update']);
    Route::get('quick-replies', [BusinessController::class, 'quickReplies']);
    Route::post('quick-replies', [BusinessController::class, 'storeQuickReply']);
    Route::patch('quick-replies/{quickReply}', [BusinessController::class, 'updateQuickReply'])->whereNumber('quickReply');
    Route::delete('quick-replies/{quickReply}', [BusinessController::class, 'destroyQuickReply'])->whereNumber('quickReply');

    // ------------------------------------------------------------ AI (one tap each — nothing goes to the AI by itself)
    Route::get('ai', [ChatAiController::class, 'capabilities']);
    Route::post('messages/{message}/translate', [ChatAiController::class, 'translate'])->middleware('throttle:60,1,chat-ai-translate');
    Route::post('messages/{message}/transcribe', [ChatAiController::class, 'transcribe'])->middleware('throttle:20,1,chat-ai-transcribe');
    Route::post('conversations/{conversation}/summarize', [ChatAiController::class, 'summarize'])->middleware('throttle:10,1,chat-ai-summary');
    Route::post('conversations/{conversation}/smart-replies', [ChatAiController::class, 'smartReplies'])->middleware('throttle:20,1,chat-ai-replies');

    // ------------------------------------------------------------ calls (LiveKit)
    Route::get('calls', [CallController::class, 'index']);
    Route::post('conversations/{conversation}/calls', [CallController::class, 'store'])->middleware('throttle:20,1,chat-calls');
    Route::get('calls/{call}', [CallController::class, 'show']);
    Route::post('calls/{call}/accept', [CallController::class, 'accept']);
    Route::post('calls/{call}/decline', [CallController::class, 'decline']);
    Route::post('calls/{call}/leave', [CallController::class, 'leave']);
    Route::get('calls/{call}/token', [CallController::class, 'token']);
});
