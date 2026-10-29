<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\General\AttentionController;
use Modules\Chat\Http\Controllers\General\BroadcastController;
use Modules\Chat\Http\Controllers\General\BusinessController;
use Modules\Chat\Http\Controllers\General\CalendarController;
use Modules\Chat\Http\Controllers\General\CallController;
use Modules\Chat\Http\Controllers\General\CallLiveController;
use Modules\Chat\Http\Controllers\General\CallRecordingController;
use Modules\Chat\Http\Controllers\General\ChannelController;
use Modules\Chat\Http\Controllers\General\ChatAccessController;
use Modules\Chat\Http\Controllers\General\ChatAiController;
use Modules\Chat\Http\Controllers\General\ChatAiToolsController;
use Modules\Chat\Http\Controllers\General\ChatFeatureController;
use Modules\Chat\Http\Controllers\General\ChatOverviewController;
use Modules\Chat\Http\Controllers\General\ChatStorageController;
use Modules\Chat\Http\Controllers\General\CircleController;
use Modules\Chat\Http\Controllers\General\ContactController;
use Modules\Chat\Http\Controllers\General\ConversationController;
use Modules\Chat\Http\Controllers\General\DecisionController;
use Modules\Chat\Http\Controllers\General\ExpressionController;
use Modules\Chat\Http\Controllers\General\FolderController;
use Modules\Chat\Http\Controllers\General\GroupController;
use Modules\Chat\Http\Controllers\General\GroupEducationController;
use Modules\Chat\Http\Controllers\General\GroupExtrasController;
use Modules\Chat\Http\Controllers\General\GroupParentController;
use Modules\Chat\Http\Controllers\General\GroupWorkController;
use Modules\Chat\Http\Controllers\General\GroupLifecycleController;
use Modules\Chat\Http\Controllers\General\GroupSpaceController;
use Modules\Chat\Http\Controllers\General\GroupTaskController;
use Modules\Chat\Http\Controllers\General\GroupToolsController;
use Modules\Chat\Http\Controllers\General\GroupRoleController;
use Modules\Chat\Http\Controllers\General\LifeController;
use Modules\Chat\Http\Controllers\General\MessageController;
use Modules\Chat\Http\Controllers\General\MessageExtrasController;
use Modules\Chat\Http\Controllers\General\MomentController;
use Modules\Chat\Http\Controllers\General\MomentExtrasController;
use Modules\Chat\Http\Controllers\General\PortalController;
use Modules\Chat\Http\Controllers\General\PrivacyController;
use Modules\Chat\Http\Controllers\General\RealtimeConfigController;
use Modules\Chat\Http\Controllers\General\ScheduledMessageController;
use Modules\Chat\Http\Controllers\General\StarFolderController;
use Modules\Chat\Http\Controllers\General\StoryController;
use Modules\Chat\Http\Controllers\General\ThemeReportController;
use Modules\Chat\Http\Controllers\General\VoiceRoomController;
use Modules\Chat\Http\Middleware\EnsureChatFeature;
use Modules\Chat\Http\Middleware\EnsureGroupTool;
use Modules\Chat\Http\Middleware\RememberTimezone;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

/*
 * Chat endpoints shared by every kind of participant. Required from inside each audience's own
 * route group (mobile.php today; provider.php when providers can chat — docs/chat-plan.md §9.1),
 * which supplies the prefix, auth guard and `country` middleware.
 *
 * {conversation}, {message} and {call} are uuids; {contact}, {folder} and {participant} are ids.
 */
Route::prefix('chat')->middleware(RememberTimezone::class)->group(function () {
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
    Route::post('messages/{message}/choose', [MessageExtrasController::class, 'choose'])->middleware('throttle:60,1,chat-vote');
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
    // Before {conversation}: what hiding a group's members costs here (paid, chat settings).
    Route::get('groups/hidden-members', [GroupController::class, 'hiddenMembersPrice']);
    // Educational / work groups I deleted that can still come back (GR-006).
    Route::get('groups/deleted', [GroupLifecycleController::class, 'deleted']);
    Route::post('groups/{conversation}/restore', [GroupLifecycleController::class, 'restore'])->withTrashed();
    Route::post('groups/{conversation}', [GroupController::class, 'update']); // POST: multipart avatar
    Route::patch('groups/{conversation}/settings', [GroupController::class, 'settings']);
    Route::get('groups/{conversation}/members', [GroupController::class, 'members']);
    Route::post('groups/{conversation}/members', [GroupController::class, 'addMembers']);
    Route::delete('groups/{conversation}/members/{participant}', [GroupController::class, 'removeMember']);
    Route::patch('groups/{conversation}/members/{participant}/role', [GroupController::class, 'role']);
    // Educational and work groups: roles and their permissions (docs/groups_tasks.md GR-004).
    Route::get('groups/{conversation}/roles', [GroupRoleController::class, 'index']);
    Route::post('groups/{conversation}/roles', [GroupRoleController::class, 'store']);
    Route::patch('groups/{conversation}/roles/{role}', [GroupRoleController::class, 'update'])->whereNumber('role');
    Route::delete('groups/{conversation}/roles/{role}', [GroupRoleController::class, 'destroy'])->whereNumber('role');
    Route::put('groups/{conversation}/members/{participant}/roles', [GroupRoleController::class, 'member'])->whereNumber('participant');
    Route::post('groups/{conversation}/leave', [GroupController::class, 'leave']);
    Route::post('groups/{conversation}/owner', [GroupController::class, 'transferOwnership']);
    // Educational / work groups: the hand-over waits for the member's answer (GR-005).
    Route::post('groups/{conversation}/owner/accept', [GroupLifecycleController::class, 'accept']);
    Route::post('groups/{conversation}/owner/decline', [GroupLifecycleController::class, 'decline']);
    Route::delete('groups/{conversation}/owner', [GroupLifecycleController::class, 'cancel']);
    Route::post('groups/{conversation}/archive', [GroupLifecycleController::class, 'archive']);
    // Announcements, my notice settings, the activity log (GR-008, GR-009, GR-010).
    Route::get('groups/{conversation}/announcements', [GroupToolsController::class, 'announcements'])->middleware(EnsureGroupTool::class.':announcements');
    Route::post('groups/{conversation}/announcements', [GroupToolsController::class, 'announce'])->middleware('throttle:30,1,chat-announce')->middleware(EnsureGroupTool::class.':announcements');
    Route::get('groups/{conversation}/notification-prefs', [GroupToolsController::class, 'prefs']);
    Route::put('groups/{conversation}/notification-prefs', [GroupToolsController::class, 'setPrefs']);
    Route::get('groups/{conversation}/audit-log', [GroupToolsController::class, 'auditLog']);
    // Educational groups: lessons, homework, tests and the bank, sessions and attendance (GR-013..017).
    Route::get('groups/{conversation}/lessons', [GroupEducationController::class, 'lessons'])->middleware(EnsureGroupTool::class.':lessons');
    Route::post('groups/{conversation}/lessons', [GroupEducationController::class, 'storeLesson'])->middleware('throttle:30,1,chat-edu-write')->middleware(EnsureGroupTool::class.':lessons');
    Route::get('groups/{conversation}/lessons/{lesson}', [GroupEducationController::class, 'lesson'])->whereUuid('lesson')->middleware(EnsureGroupTool::class.':lessons');
    Route::post('groups/{conversation}/lessons/{lesson}', [GroupEducationController::class, 'updateLesson'])->whereUuid('lesson')->middleware(EnsureGroupTool::class.':lessons');
    Route::delete('groups/{conversation}/lessons/{lesson}', [GroupEducationController::class, 'destroyLesson'])->whereUuid('lesson')->middleware(EnsureGroupTool::class.':lessons');
    Route::post('groups/{conversation}/lessons/{lesson}/messages', [GroupEducationController::class, 'lessonMessage'])->whereUuid('lesson')->middleware(EnsureGroupTool::class.':lessons');
    Route::get('groups/{conversation}/assignments', [GroupEducationController::class, 'assignments'])->middleware(EnsureGroupTool::class.':assignments');
    Route::post('groups/{conversation}/assignments', [GroupEducationController::class, 'storeAssignment'])->middleware('throttle:30,1,chat-edu-write')->middleware(EnsureGroupTool::class.':assignments');
    Route::get('groups/{conversation}/assignments/{assignment}', [GroupEducationController::class, 'assignment'])->whereUuid('assignment')->middleware(EnsureGroupTool::class.':assignments');
    Route::post('groups/{conversation}/assignments/{assignment}', [GroupEducationController::class, 'updateAssignment'])->whereUuid('assignment')->middleware(EnsureGroupTool::class.':assignments');
    Route::delete('groups/{conversation}/assignments/{assignment}', [GroupEducationController::class, 'destroyAssignment'])->whereUuid('assignment')->middleware(EnsureGroupTool::class.':assignments');
    Route::post('groups/{conversation}/assignments/{assignment}/submit', [GroupEducationController::class, 'submit'])->whereUuid('assignment')->middleware('throttle:20,1,chat-edu-submit')->middleware(EnsureGroupTool::class.':assignments');
    Route::get('groups/{conversation}/assignments/{assignment}/submissions', [GroupEducationController::class, 'submissions'])->whereUuid('assignment')->middleware(EnsureGroupTool::class.':assignments');
    Route::post('groups/{conversation}/assignments/{assignment}/grades', [GroupEducationController::class, 'grade'])->whereUuid('assignment')->middleware(EnsureGroupTool::class.':assignments');
    Route::get('groups/{conversation}/quizzes', [GroupEducationController::class, 'quizzes'])->middleware(EnsureGroupTool::class.':quizzes');
    // Before {quiz}: the coming tests (GR-022).
    Route::get('groups/{conversation}/quizzes/schedule', [GroupExtrasController::class, 'schedule'])->middleware(EnsureGroupTool::class.':quizzes');
    Route::post('groups/{conversation}/quizzes', [GroupEducationController::class, 'storeQuiz'])->middleware('throttle:30,1,chat-edu-write')->middleware(EnsureGroupTool::class.':quizzes');
    Route::get('groups/{conversation}/quizzes/{quiz}', [GroupEducationController::class, 'quiz'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::patch('groups/{conversation}/quizzes/{quiz}', [GroupEducationController::class, 'updateQuiz'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::delete('groups/{conversation}/quizzes/{quiz}', [GroupEducationController::class, 'destroyQuiz'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::post('groups/{conversation}/quizzes/{quiz}/start', [GroupEducationController::class, 'startQuiz'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::post('groups/{conversation}/quizzes/{quiz}/submit', [GroupEducationController::class, 'submitQuiz'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::get('groups/{conversation}/quizzes/{quiz}/attempts', [GroupEducationController::class, 'attempts'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::post('groups/{conversation}/quizzes/{quiz}/attempts/{attempt}/mark', [GroupEducationController::class, 'markAttempt'])->whereUuid('quiz')->whereNumber('attempt')->middleware(EnsureGroupTool::class.':quizzes');
    Route::get('groups/{conversation}/question-bank', [GroupEducationController::class, 'bank'])->middleware(EnsureGroupTool::class.':quizzes');
    Route::post('groups/{conversation}/question-bank', [GroupEducationController::class, 'storeBank'])->middleware(EnsureGroupTool::class.':quizzes');
    Route::delete('groups/{conversation}/question-bank/{question}', [GroupEducationController::class, 'destroyBank'])->whereNumber('question')->middleware(EnsureGroupTool::class.':quizzes');
    Route::get('groups/{conversation}/sessions', [GroupEducationController::class, 'sessions'])->middleware(EnsureGroupTool::class.':sessions');
    Route::post('groups/{conversation}/sessions', [GroupEducationController::class, 'storeSession'])->middleware(EnsureGroupTool::class.':sessions');
    Route::get('groups/{conversation}/sessions/{session}', [GroupEducationController::class, 'session'])->whereUuid('session')->middleware(EnsureGroupTool::class.':sessions');
    Route::delete('groups/{conversation}/sessions/{session}', [GroupEducationController::class, 'destroySession'])->whereUuid('session')->middleware(EnsureGroupTool::class.':sessions');
    Route::post('groups/{conversation}/sessions/{session}/attendance', [GroupEducationController::class, 'recordAttendance'])->whereUuid('session')->middleware(EnsureGroupTool::class.':sessions');
    Route::get('groups/{conversation}/attendance', [GroupEducationController::class, 'attendanceReport'])->middleware(EnsureGroupTool::class.':sessions');
    // Educational extras (GR-021, 024, 025, 026, 028, 030) and help requests (GR-031, GR-041).
    Route::get('groups/{conversation}/bag', [GroupExtrasController::class, 'bag'])->middleware(EnsureGroupTool::class.':bag');
    Route::get('groups/{conversation}/quizzes/{quiz}/leaderboard', [GroupExtrasController::class, 'leaderboard'])->whereUuid('quiz')->middleware(EnsureGroupTool::class.':quizzes');
    Route::get('groups/{conversation}/stars', [GroupExtrasController::class, 'stars'])->middleware(EnsureGroupTool::class.':stars');
    Route::post('groups/{conversation}/stars', [GroupExtrasController::class, 'awardStar'])->middleware(EnsureGroupTool::class.':stars');
    Route::get('groups/{conversation}/reading', [GroupExtrasController::class, 'reading'])->middleware(EnsureGroupTool::class.':reading');
    Route::post('groups/{conversation}/reading', [GroupExtrasController::class, 'addReading'])->middleware(EnsureGroupTool::class.':reading');
    Route::post('groups/{conversation}/reading/{item}/progress', [GroupExtrasController::class, 'readingProgress'])->whereUuid('item')->middleware(EnsureGroupTool::class.':reading');
    Route::get('groups/{conversation}/certificates', [GroupExtrasController::class, 'certificates'])->middleware(EnsureGroupTool::class.':certificates');
    Route::post('groups/{conversation}/certificates', [GroupExtrasController::class, 'issueCertificate'])->middleware('throttle:30,1,chat-certificates')->middleware(EnsureGroupTool::class.':certificates');
    Route::post('groups/{conversation}/surveys', [GroupExtrasController::class, 'survey'])->middleware(EnsureGroupTool::class.':surveys');
    Route::get('groups/{conversation}/help', [GroupExtrasController::class, 'helpRequests'])->middleware(EnsureGroupTool::class.':help');
    Route::post('groups/{conversation}/help', [GroupExtrasController::class, 'ask'])->middleware('throttle:30,1,chat-help')->middleware(EnsureGroupTool::class.':help');
    Route::post('groups/{conversation}/help/{help}/answer', [GroupExtrasController::class, 'answer'])->whereUuid('help')->middleware(EnsureGroupTool::class.':help');
    Route::post('groups/{conversation}/help/{help}/close', [GroupExtrasController::class, 'closeHelp'])->whereUuid('help')->middleware(EnsureGroupTool::class.':help');
    // Work extras (GR-040, 042, 043, 044, 046) and polls in both kinds (GR-045).
    Route::get('groups/{conversation}/shifts', [GroupWorkController::class, 'shifts'])->middleware(EnsureGroupTool::class.':shifts');
    Route::post('groups/{conversation}/shifts', [GroupWorkController::class, 'storeShift'])->middleware(EnsureGroupTool::class.':shifts');
    Route::delete('groups/{conversation}/shifts/{shift}', [GroupWorkController::class, 'destroyShift'])->whereUuid('shift')->middleware(EnsureGroupTool::class.':shifts');
    Route::post('groups/{conversation}/shifts/{shift}/handover', [GroupWorkController::class, 'handover'])->whereUuid('shift')->middleware(EnsureGroupTool::class.':shifts');
    Route::post('groups/{conversation}/shifts/{shift}/swap', [GroupWorkController::class, 'askSwap'])->whereUuid('shift')->middleware(EnsureGroupTool::class.':shifts');
    Route::post('groups/{conversation}/shifts/{shift}/swap/answer', [GroupWorkController::class, 'answerSwap'])->whereUuid('shift')->middleware(EnsureGroupTool::class.':shifts');
    Route::get('groups/{conversation}/goals', [GroupWorkController::class, 'goals'])->middleware(EnsureGroupTool::class.':competitions');
    Route::post('groups/{conversation}/goals', [GroupWorkController::class, 'storeGoal'])->middleware(EnsureGroupTool::class.':competitions');
    Route::post('groups/{conversation}/goals/{goal}/points', [GroupWorkController::class, 'points'])->whereUuid('goal')->middleware(EnsureGroupTool::class.':competitions');
    Route::get('groups/{conversation}/badges', [GroupWorkController::class, 'badges'])->middleware(EnsureGroupTool::class.':badges');
    Route::post('groups/{conversation}/badges', [GroupWorkController::class, 'giveBadge'])->middleware(EnsureGroupTool::class.':badges');
    Route::get('groups/{conversation}/ideas', [GroupWorkController::class, 'ideas'])->middleware(EnsureGroupTool::class.':ideas');
    Route::post('groups/{conversation}/ideas', [GroupWorkController::class, 'propose'])->middleware('throttle:30,1,chat-ideas')->middleware(EnsureGroupTool::class.':ideas');
    Route::post('groups/{conversation}/ideas/{idea}/vote', [GroupWorkController::class, 'vote'])->whereUuid('idea')->middleware(EnsureGroupTool::class.':ideas');
    Route::post('groups/{conversation}/ideas/{idea}/status', [GroupWorkController::class, 'ideaStatus'])->whereUuid('idea')->middleware(EnsureGroupTool::class.':ideas');
    Route::get('groups/{conversation}/meetings', [GroupWorkController::class, 'meetings'])->middleware(EnsureGroupTool::class.':meetings');
    Route::post('groups/{conversation}/meetings', [GroupWorkController::class, 'storeMeeting'])->middleware(EnsureGroupTool::class.':meetings');
    Route::post('groups/{conversation}/meetings/{meeting}/minutes', [GroupWorkController::class, 'minutes'])->whereUuid('meeting')->middleware(EnsureGroupTool::class.':meetings');
    Route::post('groups/{conversation}/meetings/{meeting}/tasks', [GroupWorkController::class, 'meetingTasks'])->whereUuid('meeting')->middleware(EnsureGroupTool::class.':meetings');
    Route::post('groups/{conversation}/polls', [GroupWorkController::class, 'poll'])->middleware('throttle:30,1,chat-polls')->middleware(EnsureGroupTool::class.':polls');

    // Parents in educational groups (GR-018, 019, 027 — D8): the student, the teacher…
    Route::post('groups/{conversation}/parents/code', [GroupParentController::class, 'code'])->middleware('throttle:10,1,chat-parent-code')->middleware(EnsureGroupTool::class.':parents');
    Route::get('groups/{conversation}/parents/mine', [GroupParentController::class, 'mine'])->middleware(EnsureGroupTool::class.':parents');
    Route::get('groups/{conversation}/parents', [GroupParentController::class, 'index'])->middleware(EnsureGroupTool::class.':parents');
    Route::patch('groups/{conversation}/parents/settings', [GroupParentController::class, 'settings'])->middleware(EnsureGroupTool::class.':parents');
    Route::post('groups/{conversation}/parents/{link}/decide', [GroupParentController::class, 'decide'])->whereUuid('link')->middleware(EnsureGroupTool::class.':parents');
    Route::delete('groups/{conversation}/parents/{link}', [GroupParentController::class, 'destroy'])->whereUuid('link')->middleware(EnsureGroupTool::class.':parents');
    Route::get('groups/{conversation}/parent-meetings', [GroupParentController::class, 'meetings'])->middleware(EnsureGroupTool::class.':parents');
    Route::post('groups/{conversation}/parent-meetings', [GroupParentController::class, 'storeMeeting'])->middleware(EnsureGroupTool::class.':parents');
    Route::delete('groups/{conversation}/parent-meetings/{meeting}', [GroupParentController::class, 'destroyMeeting'])->whereUuid('meeting')->middleware(EnsureGroupTool::class.':parents');
    // …and the parent (no member of the class: checked on every request in the service).
    Route::post('parent/link', [GroupParentController::class, 'redeem'])->middleware('throttle:10,1,chat-parent-link');
    Route::get('parent/children', [GroupParentController::class, 'children']);
    Route::get('parent/children/{link}', [GroupParentController::class, 'child'])->whereUuid('link');
    Route::delete('parent/children/{link}', [GroupParentController::class, 'unlink'])->whereUuid('link');
    Route::post('parent/children/{link}/message', [GroupParentController::class, 'message'])->whereUuid('link');
    Route::get('parent/children/{link}/meetings', [GroupParentController::class, 'childMeetings'])->whereUuid('link');
    Route::post('parent/children/{link}/meetings/{meeting}/book', [GroupParentController::class, 'book'])->whereUuid(['link', 'meeting']);
    Route::delete('parent/children/{link}/meetings/{meeting}/book', [GroupParentController::class, 'cancelBooking'])->whereUuid(['link', 'meeting']);
    // Terms: the next one, and who's in one (GR-012).
    Route::post('groups/{conversation}/spaces/{space}/next-term', [GroupSpaceController::class, 'nextTerm'])->whereNumber('space')->middleware(EnsureGroupTool::class.':spaces');
    Route::get('groups/{conversation}/spaces/{space}/members', [GroupSpaceController::class, 'members'])->whereNumber('space')->middleware(EnsureGroupTool::class.':spaces');
    // Work groups: tasks, repeating, checklist and proof, approval (GR-033..036).
    Route::get('groups/{conversation}/tasks', [GroupTaskController::class, 'index'])->middleware(EnsureGroupTool::class.':tasks');
    Route::get('groups/{conversation}/tasks/report', [GroupTaskController::class, 'report'])->middleware(EnsureGroupTool::class.':tasks');
    Route::get('groups/{conversation}/tasks/export', [GroupTaskController::class, 'export'])->middleware('throttle:10,1,chat-tasks-export')->middleware(EnsureGroupTool::class.':tasks');
    Route::post('groups/{conversation}/tasks', [GroupTaskController::class, 'store'])->middleware('throttle:60,1,chat-group-tasks')->middleware(EnsureGroupTool::class.':tasks');
    Route::get('groups/{conversation}/tasks/{task}', [GroupTaskController::class, 'show'])->whereUuid('task')->middleware(EnsureGroupTool::class.':tasks');
    Route::patch('groups/{conversation}/tasks/{task}', [GroupTaskController::class, 'update'])->whereUuid('task')->middleware(EnsureGroupTool::class.':tasks');
    Route::delete('groups/{conversation}/tasks/{task}', [GroupTaskController::class, 'destroy'])->whereUuid('task')->middleware(EnsureGroupTool::class.':tasks');
    Route::post('groups/{conversation}/tasks/{task}/status', [GroupTaskController::class, 'status'])->whereUuid('task')->middleware(EnsureGroupTool::class.':tasks');
    Route::post('groups/{conversation}/tasks/{task}/review', [GroupTaskController::class, 'review'])->whereUuid('task')->middleware(EnsureGroupTool::class.':tasks');
    Route::post('groups/{conversation}/tasks/{task}/proof', [GroupTaskController::class, 'proof'])->whereUuid('task')->middleware('throttle:30,1,chat-task-proof')->middleware(EnsureGroupTool::class.':tasks');
    Route::post('groups/{conversation}/tasks/{task}/items', [GroupTaskController::class, 'addItem'])->whereUuid('task')->middleware(EnsureGroupTool::class.':tasks');
    Route::patch('groups/{conversation}/tasks/{task}/items/{item}', [GroupTaskController::class, 'tickItem'])->whereUuid('task')->whereNumber('item')->middleware(EnsureGroupTool::class.':tasks');
    Route::delete('groups/{conversation}/tasks/{task}/items/{item}', [GroupTaskController::class, 'removeItem'])->whereUuid('task')->whereNumber('item')->middleware(EnsureGroupTool::class.':tasks');
    // Terms, subgroups and projects: chats of their own inside the group (GR-007).
    Route::get('groups/{conversation}/spaces', [GroupSpaceController::class, 'index'])->middleware(EnsureGroupTool::class.':spaces');
    Route::post('groups/{conversation}/spaces', [GroupSpaceController::class, 'store'])->middleware(EnsureGroupTool::class.':spaces');
    Route::post('groups/{conversation}/spaces/{space}/members', [GroupSpaceController::class, 'addMembers'])->whereNumber('space')->middleware(EnsureGroupTool::class.':spaces');
    Route::delete('groups/{conversation}/spaces/{space}/members/{participant}', [GroupSpaceController::class, 'removeMember'])->whereNumber(['space', 'participant'])->middleware(EnsureGroupTool::class.':spaces');
    Route::post('groups/{conversation}/spaces/{space}/close', [GroupSpaceController::class, 'close'])->whereNumber('space')->middleware(EnsureGroupTool::class.':spaces');
    Route::delete('groups/{conversation}', [GroupController::class, 'destroy']);
    Route::get('groups/{conversation}/invite', [GroupController::class, 'invite']);
    Route::post('groups/{conversation}/invite/reset', [GroupController::class, 'resetInvite']);
    Route::get('invites/{token}', [GroupController::class, 'previewInvite']);
    Route::post('invites/{token}/join', [GroupController::class, 'join']);
    Route::post('invites/{token}/cancel', [GroupController::class, 'cancelJoin']);
    Route::get('groups/{conversation}/join-requests', [GroupController::class, 'joinRequests']);
    // Communities (WhatsApp list 93–98).
    Route::get('communities', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'index']);
    Route::post('communities', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'store'])->middleware('throttle:10,1,chat-communities');
    Route::get('communities/{community}', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'show']);
    Route::post('communities/{community}', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'update']); // POST: multipart photo
    Route::post('communities/{community}/groups', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'addGroups']);
    Route::delete('communities/{community}/groups/{conversation}', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'removeGroup']);
    Route::post('communities/{community}/groups/{conversation}/join', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'joinGroup']);
    Route::get('communities/{community}/members', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'members']);
    Route::delete('communities/{community}/members/{participant}', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'removeMember'])->whereNumber('participant');
    Route::patch('communities/{community}/members/{participant}/role', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'role'])->whereNumber('participant');
    Route::post('communities/{community}/leave', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'leave']);
    Route::delete('communities/{community}', [\Modules\Chat\Http\Controllers\General\CommunityController::class, 'destroy']);
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
    Route::get('channels/directory', [ChannelController::class, 'directory']);
    Route::get('channels/{channel}', [ChannelController::class, 'show']);
    Route::post('channels/{channel}/follow', [ChannelController::class, 'follow']);
    Route::post('channels/{conversation}/unfollow', [ChannelController::class, 'unfollow']);
    Route::patch('channels/{conversation}/handle', [ChannelController::class, 'handle']);
    Route::patch('channels/{conversation}/category', [ChannelController::class, 'category']);
    Route::get('channels/{conversation}/verification', [ChannelController::class, 'verification']);
    // Backlog 107 and 110: a channel's numbers, and the reactions it allows.
    Route::get('channels/{conversation}/stats', [ChannelController::class, 'stats']);
    Route::patch('channels/{conversation}/reactions', [ChannelController::class, 'reactions']);

    // ------------------------------------------------------------ DORR Moments (spec 157–168)
    Route::get('moments', [MomentController::class, 'index']);
    Route::get('moments/catalog', [MomentController::class, 'catalog']);
    Route::put('moments/preferences', [MomentController::class, 'preferences']);
    Route::post('conversations/{conversation}/moment-card', [MomentController::class, 'card'])->middleware('throttle:30,1,chat-moment-card');
    Route::post('moments/greetings', [MomentController::class, 'greetings'])->middleware('throttle:20,1,chat-ai-greetings');
    // Group cards (spec 163) and capsules (166).
    Route::get('collab-cards', [MomentExtrasController::class, 'cards']);
    Route::post('collab-cards', [MomentExtrasController::class, 'createCard'])->middleware('throttle:20,1,chat-collab');
    Route::get('collab-cards/{card}', [MomentExtrasController::class, 'card']);
    Route::post('collab-cards/{card}/members', [MomentExtrasController::class, 'invite']);
    Route::delete('collab-cards/{card}/members/{user}', [MomentExtrasController::class, 'removeMember']);
    Route::post('collab-cards/{card}/contribution', [MomentExtrasController::class, 'contribute']);
    Route::post('collab-cards/{card}/send', [MomentExtrasController::class, 'sendCard']);
    Route::delete('collab-cards/{card}', [MomentExtrasController::class, 'cancelCard']);
    Route::get('capsules', [MomentExtrasController::class, 'capsules']);
    Route::post('capsules', [MomentExtrasController::class, 'createCapsule']);
    Route::get('capsules/{capsule}', [MomentExtrasController::class, 'capsule']);
    Route::patch('capsules/{capsule}', [MomentExtrasController::class, 'updateCapsule']);
    Route::delete('capsules/{capsule}', [MomentExtrasController::class, 'deleteCapsule']);
    Route::post('capsules/{capsule}/items', [MomentExtrasController::class, 'addItem']);
    Route::delete('capsules/{capsule}/items/{item}', [MomentExtrasController::class, 'removeItem']);
    Route::get('moments/personal', [MomentController::class, 'personal']);
    Route::post('moments/personal', [MomentController::class, 'storePersonal']);
    Route::patch('moments/personal/{moment}', [MomentController::class, 'updatePersonal']);
    Route::delete('moments/personal/{moment}', [MomentController::class, 'destroyPersonal']);

    // ------------------------------------------------------------ group decisions (spec 119–120)
    Route::post('messages/{message}/decision', [DecisionController::class, 'store']);
    Route::post('decisions/{decision}/decide', [DecisionController::class, 'decide']);
    Route::get('groups/{conversation}/decisions', [DecisionController::class, 'index']);
    // The decision room (spec 153).
    Route::get('decisions/{decision}', [DecisionController::class, 'show']);
    Route::post('decisions/{decision}/arguments', [DecisionController::class, 'argue'])->middleware('throttle:30,1,chat-decision-args');
    Route::delete('decisions/{decision}/arguments/{argument}', [DecisionController::class, 'removeArgument'])->whereNumber('argument');

    // ------------------------------------------------------------ daily intelligence (spec 148, 149, 152, 154)
    Route::post('messages/{message}/commitment', [LifeController::class, 'commitFromMessage'])->middleware('throttle:30,1,chat-life');
    Route::post('conversations/{conversation}/commitment', [LifeController::class, 'commit'])->middleware('throttle:30,1,chat-life');
    Route::get('commitments', [LifeController::class, 'commitments']);
    Route::get('commitments/{commitment}', [LifeController::class, 'commitment']);
    Route::post('commitments/{commitment}/respond', [LifeController::class, 'respond']);
    Route::post('commitments/{commitment}/done', [LifeController::class, 'done']);
    Route::post('commitments/{commitment}/cancel', [LifeController::class, 'cancel']);
    Route::post('messages/{message}/handoff', [LifeController::class, 'handOff'])->middleware('throttle:30,1,chat-life');
    Route::get('handoffs', [LifeController::class, 'handoffs']);
    Route::post('handoffs/{handoff}/{action}', [LifeController::class, 'moveHandoff'])->whereIn('action', ['accept', 'decline', 'done', 'cancel']);
    Route::post('conversations/{conversation}/agreements', [LifeController::class, 'propose'])->middleware('throttle:30,1,chat-life');
    Route::get('agreements/{agreement}', [LifeController::class, 'agreement']);
    Route::post('agreements/{agreement}/answer', [LifeController::class, 'answer']);
    Route::put('agreements/{agreement}', [LifeController::class, 'amend']);
    Route::post('agreements/{agreement}/cancel', [LifeController::class, 'cancelAgreement']);
    // The life inbox (spec 147).
    Route::get('life-inbox', [LifeController::class, 'inbox']);

    // ------------------------------------------------------------ privacy circles (spec 98–103)
    Route::get('circles', [CircleController::class, 'index']);
    Route::post('circles', [CircleController::class, 'store']);
    Route::patch('circles/{circle}', [CircleController::class, 'update']);
    Route::delete('circles/{circle}', [CircleController::class, 'destroy']);
    Route::put('conversations/{conversation}/circle', [CircleController::class, 'assign']);

    // ------------------------------------------------------------ broadcast lists (like WhatsApp)
    Route::get('broadcasts', [BroadcastController::class, 'index']);
    Route::post('broadcasts', [BroadcastController::class, 'store']);
    // A list from one of my folders (labels) — backlog 129.
    Route::post('broadcasts/from-folder', [BroadcastController::class, 'fromFolder']);
    Route::get('broadcasts/{broadcast}', [BroadcastController::class, 'show']);
    Route::patch('broadcasts/{broadcast}', [BroadcastController::class, 'update']);
    Route::delete('broadcasts/{broadcast}', [BroadcastController::class, 'destroy']);
    Route::post('broadcasts/{broadcast}/messages', [BroadcastController::class, 'send'])->middleware('throttle:20,1,chat-broadcasts');

    // ------------------------------------------------------------ merchant portals (ج.3)
    Route::get('categories', [PortalController::class, 'categories']);
    Route::get('portals', [PortalController::class, 'index']);
    Route::get('portals/mine', [PortalController::class, 'mine']);
    Route::get('portals/packages', [PortalController::class, 'packages']);
    Route::get('portals/languages', [PortalController::class, 'languages']);
    Route::post('portals/translate', [PortalController::class, 'translate'])->middleware('throttle:20,1,chat-portal-translate');
    Route::post('portals', [PortalController::class, 'store'])->middleware('throttle:10,1,chat-portals');
    Route::get('portals/{portal}', [PortalController::class, 'show']);
    Route::post('portals/{portal}', [PortalController::class, 'update']);
    Route::delete('portals/{portal}', [PortalController::class, 'destroy']);
    Route::post('portals/{portal}/open', [PortalController::class, 'open']);

    // ------------------------------------------------------------ contacts
    Route::get('contacts', [ContactController::class, 'index']);
    Route::post('contacts/sync', [ContactController::class, 'sync'])->middleware('throttle:20,1,chat-contacts-sync');
    Route::post('contacts', [ContactController::class, 'store']);
    // Rate-limited: must not become a way to scan which numbers are registered.
    Route::post('contacts/lookup', [ContactController::class, 'lookup'])->middleware('throttle:20,1,chat-lookup');
    Route::get('contacts/qr', [ContactController::class, 'qr']);
    Route::get('contacts/chat-link', [ContactController::class, 'chatLink']);
    Route::post('contacts/qr/reset', [ContactController::class, 'resetQr']);
    Route::post('contacts/qr/resolve', [ContactController::class, 'resolveQr'])->middleware('throttle:30,1,chat-lookup');
    Route::patch('contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);

    // ------------------------------------------------------------ privacy, blocks, presence
    // My communication rules (spec 146, 151, 155).
    Route::get('attention', [AttentionController::class, 'show']);
    Route::put('attention', [AttentionController::class, 'update']);
    Route::put('attention/context', [AttentionController::class, 'context']);
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
    Route::middleware(EnsureChatFeature::class.':stories')->group(function () {
        Route::get('stories', [StoryController::class, 'index']);
        Route::post('stories', [StoryController::class, 'store'])->middleware('throttle:30,1,chat-stories');
        Route::get('stories/public', [StoryController::class, 'publicFeed']);
        Route::post('stories/dorr/{story}/view', [StoryController::class, 'viewDorr']);
        Route::get('stories/privacy', [StoryController::class, 'privacy']);
        Route::put('stories/privacy', [StoryController::class, 'updatePrivacy']);
        Route::post('stories/mute', [StoryController::class, 'mute']);
        Route::delete('stories/{story}', [StoryController::class, 'destroy']);
        Route::post('stories/{story}/view', [StoryController::class, 'view']);
        Route::put('stories/{story}/reaction', [StoryController::class, 'react']);
        Route::post('stories/{story}/reply', [StoryController::class, 'reply']);
        Route::post('stories/{story}/reshare', [StoryController::class, 'reshare']);
        Route::get('stories/{story}/viewers', [StoryController::class, 'viewers']);
    });

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
    Route::get('business/stats', [BusinessController::class, 'stats']);
    Route::get('quick-replies', [BusinessController::class, 'quickReplies']);
    Route::post('quick-replies', [BusinessController::class, 'storeQuickReply']);
    Route::patch('quick-replies/{quickReply}', [BusinessController::class, 'updateQuickReply'])->whereNumber('quickReply');
    Route::delete('quick-replies/{quickReply}', [BusinessController::class, 'destroyQuickReply'])->whereNumber('quickReply');

    // ------------------------------------------------------------ AI (one tap each — nothing goes to the AI by itself)
    Route::get('ai', [ChatAiController::class, 'capabilities']);
    Route::middleware(EnsureChatFeature::class.':ai')->group(function () {
        Route::post('messages/{message}/translate', [ChatAiController::class, 'translate'])->middleware('throttle:60,1,chat-ai-translate');
        Route::post('messages/{message}/transcribe', [ChatAiController::class, 'transcribe'])->middleware('throttle:20,1,chat-ai-transcribe');
        Route::post('conversations/{conversation}/summarize', [ChatAiController::class, 'summarize'])->middleware('throttle:10,1,chat-ai-summary');
        Route::post('conversations/{conversation}/smart-replies', [ChatAiController::class, 'smartReplies'])->middleware('throttle:20,1,chat-ai-replies');
        Route::post('conversations/{conversation}/ask', [ChatAiController::class, 'ask'])->middleware('throttle:20,1,chat-ai-ask');
        Route::post('conversations/{conversation}/commitments', [ChatAiController::class, 'commitments'])->middleware('throttle:10,1,chat-ai-commitments');
        // DORR AI tools (spec 31, 36–42, 46, 48, 49) — under the DORR AI safety rules (350–362).
        Route::post('conversations/{conversation}/assistant', [ChatAiToolsController::class, 'assistant'])->middleware('throttle:30,1,chat-ai-assistant');
        Route::post('ai/proofread', [ChatAiToolsController::class, 'proofread'])->middleware('throttle:30,1,chat-ai-proofread');
        Route::post('messages/{message}/understand', [ChatAiToolsController::class, 'understand'])->middleware('throttle:30,1,chat-ai-understand');
        Route::post('messages/{message}/simplify', [ChatAiToolsController::class, 'simplify'])->middleware('throttle:30,1,chat-ai-simplify');
        Route::post('messages/{message}/tasks', [ChatAiToolsController::class, 'tasksFrom'])->middleware('throttle:20,1,chat-ai-tasks');
        Route::post('conversations/{conversation}/note', [ChatAiToolsController::class, 'note'])->middleware('throttle:20,1,chat-ai-note');
        Route::post('conversations/{conversation}/dates', [ChatAiToolsController::class, 'dates'])->middleware('throttle:10,1,chat-ai-dates');
        Route::post('ai/important', [ChatAiToolsController::class, 'important'])->middleware('throttle:10,1,chat-ai-important');
        Route::post('conversations/{conversation}/related-files', [ChatAiToolsController::class, 'relatedFiles'])->middleware('throttle:10,1,chat-ai-files');
        Route::post('ai/today', [ChatAiToolsController::class, 'today'])->middleware('throttle:6,1,chat-ai-today');
        // DORR AI's search by meaning, continue-in-another-chat and AI-images gallery, in the chat.
        Route::get('conversations/{conversation}/ai/search', [ChatAiController::class, 'searchByMeaning'])->middleware('throttle:20,1,chat-ai-meaning');
        Route::post('conversations/{conversation}/ai/capsule', [ChatAiController::class, 'capsule'])->middleware('throttle:10,1,chat-ai-capsule');
        Route::get('ai/images', [ChatAiController::class, 'aiImages']);
        // Images with DORR AI (spec 50, 51): drafts counted against the AI plan's daily images.
        Route::get('ai/image/quota', [ChatAiController::class, 'imageQuota']);
        Route::post('conversations/{conversation}/ai/image', [ChatAiController::class, 'generateImage'])->middleware('throttle:10,1,chat-ai-image');
        Route::post('messages/{message}/ai/edit-image', [ChatAiController::class, 'editImage'])->middleware('throttle:10,1,chat-ai-image');
        Route::post('conversations/{conversation}/ai/image/send', [ChatAiController::class, 'sendImage'])->middleware('throttle:30,1,chat-ai-image-send');
    });
    // Favourites folders (spec 24).
    Route::get('star-folders', [StarFolderController::class, 'index']);
    Route::post('star-folders', [StarFolderController::class, 'store']);
    Route::patch('star-folders/{starFolder}', [StarFolderController::class, 'update'])->whereNumber('starFolder');
    Route::delete('star-folders/{starFolder}', [StarFolderController::class, 'destroy'])->whereNumber('starFolder');
    // A PIN for one chat (spec 25) and my @username (81).
    Route::put('conversations/{conversation}/lock-pin', [ChatAccessController::class, 'setPin'])->middleware('throttle:10,1,chat-lock-pin');
    Route::delete('conversations/{conversation}/lock-pin', [ChatAccessController::class, 'removePin'])->middleware('throttle:10,1,chat-lock-pin');
    Route::post('conversations/{conversation}/unlock', [ChatAccessController::class, 'unlock'])->middleware('throttle:20,1,chat-unlock');
    Route::get('username', [ChatAccessController::class, 'username']);
    Route::put('username', [ChatAccessController::class, 'setUsername'])->middleware('throttle:10,1,chat-username');
    Route::get('users/by-username', [ChatAccessController::class, 'findByUsername'])->middleware('throttle:30,1,chat-username-find');
    // "What I missed" (123), money in a chat (66), the privacy center (127).
    Route::get('catch-up', [ChatOverviewController::class, 'catchUp']);
    Route::get('conversations/{conversation}/money', [ChatOverviewController::class, 'money']);
    Route::get('privacy/center', [ChatOverviewController::class, 'privacyCenter']);
    // DORR Calendar & DORR Today (spec 201–207).
    Route::get('calendar', [CalendarController::class, 'index']);
    Route::get('calendar/today', [CalendarController::class, 'today']);
    Route::get('calendar/search', [CalendarController::class, 'search'])->middleware('throttle:60,1,chat-calendar-search');
    Route::get('calendar/preferences', [CalendarController::class, 'preferences']);
    Route::put('calendar/preferences', [CalendarController::class, 'savePreferences']);
    Route::post('calendar/items', [CalendarController::class, 'store'])->middleware('throttle:60,1,chat-calendar-add');
    Route::get('calendar/items/{item}', [CalendarController::class, 'show']);
    Route::patch('calendar/items/{item}', [CalendarController::class, 'update']);
    Route::delete('calendar/items/{item}', [CalendarController::class, 'destroy']);
    // My tasks (spec 38).
    Route::get('tasks', [ChatAiToolsController::class, 'tasks']);
    Route::post('tasks', [ChatAiToolsController::class, 'storeTasks']);
    Route::patch('tasks/{task}', [ChatAiToolsController::class, 'updateTask']);
    Route::delete('tasks/{task}', [ChatAiToolsController::class, 'destroyTask']);

    // Manage storage (backlog 46–48) and export a chat (25).
    Route::get('storage', [ChatStorageController::class, 'overview']);
    Route::get('storage/files', [ChatStorageController::class, 'files']);
    Route::post('storage/delete', [ChatStorageController::class, 'delete'])->middleware('throttle:20,1,chat-storage');
    Route::get('conversations/{conversation}/export', [ChatStorageController::class, 'export'])->middleware('throttle:6,1,chat-export');
    // What the app may offer where I am (country / city rules) — and where the phone is (kept as a city only).
    Route::get('features', [ChatFeatureController::class, 'show']);
    Route::put('location', [ChatFeatureController::class, 'locate'])->middleware('throttle:10,1,chat-location');
    // ------------------------------------------------------------ calls (LiveKit)
    Route::get('calls', [CallController::class, 'index']);
    Route::post('conversations/{conversation}/calls', [CallController::class, 'store'])->middleware('throttle:20,1,chat-calls');
    Route::get('calls/{call}', [CallController::class, 'show']);
    // Call links (WhatsApp list 72): a link anyone can open to join one call room.
    Route::get('call-links', [\Modules\Chat\Http\Controllers\General\CallLinkController::class, 'index']);
    Route::post('call-links', [\Modules\Chat\Http\Controllers\General\CallLinkController::class, 'store'])->middleware('throttle:20,1,chat-call-links');
    Route::get('call-links/{token}', [\Modules\Chat\Http\Controllers\General\CallLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{8,64}');
    Route::post('call-links/{token}/join', [\Modules\Chat\Http\Controllers\General\CallLinkController::class, 'join'])->where('token', '[A-Za-z0-9]{8,64}')->middleware('throttle:30,1,chat-call-links-join');
    Route::delete('call-links/{token}', [\Modules\Chat\Http\Controllers\General\CallLinkController::class, 'destroy'])->where('token', '[A-Za-z0-9]{8,64}');
    // Standing voice rooms (spec 91).
    Route::get('conversations/{conversation}/voice-room', [VoiceRoomController::class, 'show']);
    Route::post('conversations/{conversation}/voice-room/join', [VoiceRoomController::class, 'join'])->middleware('throttle:30,1,chat-voice');
    Route::post('voice-rooms/{room}/leave', [VoiceRoomController::class, 'leave']);
    Route::post('voice-rooms/{room}/ping', [VoiceRoomController::class, 'ping']);
    Route::post('voice-rooms/{room}/hand', [VoiceRoomController::class, 'hand']);
    Route::post('voice-rooms/{room}/members/{participant}/role', [VoiceRoomController::class, 'role'])->whereNumber('participant');
    Route::post('voice-rooms/{room}/members/{participant}/remove', [VoiceRoomController::class, 'remove'])->whereNumber('participant');
    Route::patch('voice-rooms/{room}', [VoiceRoomController::class, 'update']);
    // A call broadcast live (spec 93).
    Route::post('calls/{call}/live', [CallLiveController::class, 'start']);
    Route::post('lives/{live}/end', [CallLiveController::class, 'end']);
    Route::get('lives/watch/{token}', [CallLiveController::class, 'show'])->where('token', '[A-Za-z0-9]{20,40}');
    Route::post('lives/watch/{token}', [CallLiveController::class, 'watch'])->where('token', '[A-Za-z0-9]{20,40}');
    Route::post('lives/watch/{token}/ping', [CallLiveController::class, 'ping'])->where('token', '[A-Za-z0-9]{20,40}');
    Route::post('lives/watch/{token}/share', [CallLiveController::class, 'share'])->where('token', '[A-Za-z0-9]{20,40}');
    // Call recording, only with the yes of everyone in the call (spec 92).
    Route::post('calls/{call}/recording', [CallRecordingController::class, 'store'])->middleware('throttle:10,1,chat-recording');
    Route::post('calls/{call}/recording/consent', [CallRecordingController::class, 'consent']);
    Route::post('calls/{call}/recording/stop', [CallRecordingController::class, 'stop']);
    Route::get('calls/{call}/recordings', [CallRecordingController::class, 'index']);
    Route::post('calls/{call}/accept', [CallController::class, 'accept']);
    Route::post('calls/{call}/decline', [CallController::class, 'decline']);
    Route::post('calls/{call}/leave', [CallController::class, 'leave']);
    Route::get('calls/{call}/token', [CallController::class, 'token']);

    // ------------------------------------------------------------ account verification (docs/verification_tasks.md)
    require __DIR__.'/verification_customer.php';
});
