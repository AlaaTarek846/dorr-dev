<?php

namespace Modules\Chat\Services;

use App\Support\Media\WebpUploadConverter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Resources\MessageResource;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReaction;
use Modules\Chat\Models\ChatMessageReceipt;
use Modules\Chat\Models\ChatMessageUserState;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPinnedMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatStarFolder;
use Modules\Chat\Support\BannedWords;
use Modules\Chat\Support\MessageViewContext;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Messages: send (text, media, voice, location, contact, wallet receipt / QR), edit, delete for
 * me / for everyone, forward, react, star, pin, "message info", search and the media gallery.
 *
 * Every write goes through the sender's own participant row (so only members write), and every
 * real-time event and push is sent only after the transaction commits.
 */
class MessageService
{
    /** Card designs for a money gift (spec 65). */
    public const GIFT_CARDS = ['general', 'birthday', 'eid', 'ramadan', 'wedding', 'newborn', 'graduation', 'congrats', 'thanks'];

    /** How many urgent messages one person may send another in 24 hours (spec 117). */
    public const URGENT_PER_DAY = 3;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatPrivacy $privacy,
        private readonly ChatBroadcaster $broadcaster,
        private readonly ChatPushNotifier $push,
        private readonly WalletShareService $walletShare,
    ) {}

    // ---------------------------------------------------------------- reading

    /**
     * A page of messages, newest last. `before` / `after` (message uuids) page through history;
     * `around` opens the page centred on one message (search results, pinned messages, replies).
     *
     * @return array{messages: Collection<int, ChatMessage>, has_more_before: bool, has_more_after: bool, context: MessageViewContext}
     */
    public function page(Model $me, ChatConversation $conversation, array $cursor, int $limit = 50): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $root = ! empty($cursor['thread']) ? $this->findInConversation($conversation, $cursor['thread']) : null;
        $base = fn () => $this->visibleTo($participant, $conversation->messages()->getQuery())
            ->when($root, fn (Builder $q, $r) => $q->where('chat_messages.thread_id', $r->id), fn (Builder $q) => $q->whereNull('chat_messages.thread_id'));

        if (! empty($cursor['around'])) {
            $pivot = $this->findInConversation($conversation, $cursor['around']);
            $half = intdiv($limit, 2);
            $older = $base()->where('id', '<', $pivot->id)->orderByDesc('id')->limit($half)->get();
            $newer = $base()->where('id', '>=', $pivot->id)->orderBy('id')->limit($limit - $half)->get();
            $messages = $older->reverse()->values()->merge($newer);
        } elseif (! empty($cursor['after'])) {
            $pivot = $this->findInConversation($conversation, $cursor['after']);
            $messages = $base()->where('id', '>', $pivot->id)->orderBy('id')->limit($limit)->get();
        } else {
            $query = $base()->orderByDesc('id')->limit($limit);
            if (! empty($cursor['before'])) {
                $query->where('id', '<', $this->findInConversation($conversation, $cursor['before'])->id);
            }
            $messages = $query->get()->reverse()->values();
        }

        $messages->load(['media', 'reactions', 'replyTo.media', 'threadRoot']);

        $first = $messages->first();
        $last = $messages->last();

        return [
            'messages' => $messages,
            'has_more_before' => $first !== null && $base()->where('id', '<', $first->id)->exists(),
            'has_more_after' => $last !== null && $base()->where('id', '>', $last->id)->exists(),
            'context' => MessageViewContext::build($me, $participant, $conversation->loadViewParticipants($participant), $messages),
        ];
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<MessageResource>
     */
    public function present(Collection $messages, MessageViewContext $context): array
    {
        return $messages->map(fn (ChatMessage $m) => new MessageResource($m, $context))->values()->all();
    }

    public function presentOne(Model $me, ChatMessage $message): MessageResource
    {
        $conversation = $message->conversation;
        $participant = $this->conversations->participantOf($me, $conversation);
        $message->load(['media', 'reactions', 'replyTo.media']);

        return new MessageResource($message, MessageViewContext::build($me, $participant, $conversation->loadViewParticipants($participant), collect([$message])));
    }

    /**
     * What this participant may see: not before they joined / cleared the chat, not what they
     * deleted for themselves, and no disappeared messages.
     */
    public function visibleTo(ChatParticipant $participant, Builder $query): Builder
    {
        return $query
            ->when($participant->cleared_before_message_id, fn (Builder $q, $id) => $q->where('chat_messages.id', '>', $id))
            // Someone who left or was removed sees nothing sent after that.
            ->when($participant->left_at, fn (Builder $q, $at) => $q->where('chat_messages.created_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('chat_messages.expires_at')->orWhere('chat_messages.expires_at', '>', now()))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('chat_message_user_states')
                ->whereColumn('chat_message_user_states.message_id', 'chat_messages.id')
                ->where('chat_message_user_states.participant_id', $participant->id)
                ->whereNotNull('chat_message_user_states.deleted_at'));
    }

    // ---------------------------------------------------------------- sending

    /**
     * Send a message. `uuid` (optional, generated by the app) makes sending idempotent: the same
     * uuid sent twice — a retry after a dropped connection — returns the first message.
     *
     * @param  array<string, mixed>  $data  type, body, uuid, reply_to, mentions, meta fields
     * @param  list<UploadedFile>  $files
     *
     * @throws ChatException
     */
    public function send(Model $me, ChatConversation $conversation, array $data, array $files = []): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $conversation, true);

        if (! empty($data['uuid']) && ($existing = ChatMessage::query()->where('uuid', $data['uuid'])->first())) {
            if ($existing->conversation_id === $conversation->id && $existing->isFrom($participant->participant_type, (int) $participant->participant_id)) {
                return $existing;
            }
            throw new ChatException('uuid_taken', 409);
        }

        $this->assertCanSend($me, $participant, $conversation);

        $type = MessageType::from($data['type'] ?? MessageType::Text->value);
        $meta = match ($type) {
            // Built from the chat itself (who can pay, in which currency) — never from client data.
            MessageType::MoneyRequest => app(MoneyRequestService::class)->requestMeta($me, $conversation, $data),
            MessageType::BillSplit => app(MoneyRequestService::class)->splitMeta($me, $conversation, $participant, $data),
            default => $this->buildMeta($me, $type, $data),
        };
        $body = isset($data['body']) ? trim((string) $data['body']) : null;
        $body = $body === '' ? null : $body;

        // Files copied over: a forwarded message's, a scheduled card's, or several (a group card's
        // contributions, in order).
        $copyFrom = array_values(array_filter(is_array($data['copy_media_from'] ?? null) ? $data['copy_media_from'] : [$data['copy_media_from'] ?? null]));
        $hasFiles = $files !== [] || collect($copyFrom)->contains(fn ($source) => $source->getMedia(ChatMessage::ATTACHMENTS)->isNotEmpty());

        if ($body === null && ! $hasFiles && $meta === null && $type !== MessageType::MomentCard) {
            throw ChatException::emptyMessage();
        }

        // The text, and a poll's options too.
        $this->assertNoBannedWords($participant, $conversation, trim($body.' '.implode("\n", array_column((array) data_get($meta, 'options', []), 'text'))));

        if ($type->hasAttachments() && ! $hasFiles) {
            throw new ChatException('attachment_required', 422);
        }

        $replyTo = ! empty($data['reply_to']) ? $this->findInConversation($conversation, $data['reply_to']) : null;
        if ($replyTo?->isGone()) {
            $replyTo = null;
        }

        // A thread hangs from a real message of this chat, one level deep (a reply in a thread
        // continues the same thread).
        $threadRoot = null;
        if (! empty($data['thread'])) {
            $threadRoot = $this->findInConversation($conversation, $data['thread']);
            if ($threadRoot->thread_id !== null) {
                $threadRoot = ChatMessage::query()->findOrFail($threadRoot->thread_id);
            }
            if ($threadRoot->isGone() || $threadRoot->type === MessageType::System) {
                throw new ChatException('thread_unavailable', 422);
            }
        }

        $mentions = $conversation->isGroup() ? $this->validMentions($conversation, (array) ($data['mentions'] ?? [])) : [];

        if ($type === MessageType::Poll && $body === null) {
            throw new ChatException('poll_invalid', 422);
        }

        $viewOnce = ! empty($data['view_once']);
        if ($viewOnce && ! $type->canViewOnce()) {
            throw new ChatException('view_once_not_allowed', 422);
        }

        $urgent = ! empty($data['urgent']);
        if ($urgent) {
            $this->assertMayBeUrgent($me, $participant, $conversation);
        }

        $message = DB::transaction(function () use ($me, $participant, $conversation, $type, $body, $meta, $files, $replyTo, $mentions, $data, $copyFrom, $viewOnce, $urgent, $threadRoot) {
            // Replying to a request accepts it (you clearly want to talk).
            if ($conversation->status === ConversationStatus::Pending && ! $this->startedBy($conversation, $participant)) {
                $conversation->status = ConversationStatus::Accepted;
            }

            $message = ChatMessage::query()->create([
                'uuid' => $data['uuid'] ?? null,
                'conversation_id' => $conversation->id,
                'sender_type' => $participant->participant_type,
                'sender_id' => $participant->participant_id,
                'type' => $type,
                'body' => $body,
                'meta' => $meta,
                'reply_to_id' => $replyTo?->id,
                'thread_id' => $threadRoot?->id,
                'is_forwarded' => (bool) ($data['is_forwarded'] ?? false),
                'forward_score' => (int) ($data['forward_score'] ?? 0),
                'mentions' => $mentions ?: null,
                'has_link' => $body !== null && (bool) preg_match('~(https?://|www\.)\S+~i', $body),
                'view_once' => $viewOnce,
                'is_silent' => ! empty($data['silent']),
                'is_urgent' => $urgent,
                // Sensitive: never in a notification; the recipient's app hides it until unlocked.
                'is_sensitive' => ! empty($data['sensitive']),
                'expires_at' => $conversation->disappearing_seconds ? now()->addSeconds($conversation->disappearing_seconds) : null,
            ]);

            if ($threadRoot !== null) {
                ChatMessage::query()->whereKey($threadRoot->id)->update([
                    'thread_replies_count' => DB::raw('thread_replies_count + 1'),
                    'thread_last_at' => now(),
                ]);
            }

            $this->attach($message, $files);

            $order = count($files);
            foreach ($copyFrom as $source) {
                foreach ($source->getMedia(ChatMessage::ATTACHMENTS) as $media) {
                    $media->copy($message, ChatMessage::ATTACHMENTS)->forceFill(['order_column' => ++$order])->save();
                }
            }
            foreach (($copyFrom[0] ?? null)?->getMedia(ChatMessage::THUMBNAIL) ?? [] as $media) {
                $media->copy($message, ChatMessage::THUMBNAIL);
            }

            // The video's poster frame (sent by the app, only meaningful for a video).
            if (($thumbnail = $data['thumbnail_file'] ?? null) instanceof UploadedFile && $type === MessageType::Video) {
                $thumbnail = WebpUploadConverter::convert($thumbnail);
                $extension = strtolower($thumbnail->getClientOriginalExtension() ?: $thumbnail->extension() ?: 'jpg');
                $message->addMedia($thumbnail)->usingFileName(Str::uuid().'.'.$extension)->toMediaCollection(ChatMessage::THUMBNAIL);
            }

            $this->afterNewMessage($conversation, $message, $participant, $mentions);
            $this->answeredFollowUps($conversation, $participant, $message, $replyTo);

            $senderName = app(ParticipantDirectory::class)->profile($me, $participant->participant_type, $participant->participant_id)['account_name'] ?? '';
            $others = $conversation->activeParticipants()->where('id', '!=', $participant->id)->get();
            $this->push->message($conversation, $message, (string) $senderName, $others);

            return $message;
        });

        $this->previewLinkLater($message);

        // A business on the other side may answer by itself (welcome / away). Never fails the send.
        if (! $conversation->isGroup() && ! $conversation->isSelf()) {
            try {
                app(BusinessService::class)->answer($conversation, $message, $participant);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $message;
    }

    /**
     * The link card is fetched after the response is sent — sending never waits for a website.
     */
    private function previewLinkLater(ChatMessage $message): void
    {
        if (! $message->has_link || $message->view_once || $message->is_forwarded && isset($message->meta['link_preview'])) {
            return;
        }

        $id = $message->id;
        dispatch(fn () => app(LinkPreviewService::class)->attachTo($id))->afterResponse();
    }

    /**
     * Tell everyone a message changed after the fact (link card arrived, live location stopped…).
     */
    public function rebroadcast(ChatMessage $message): void
    {
        $this->broadcast($message->conversation, $message, 'chat.message.updated');
    }

    /**
     * A business's automatic reply (welcome / away), sent as the business. Unlike a reply typed by
     * hand it doesn't accept a message request, doesn't mark the customer's message as read, and
     * is labelled `meta.auto_reply` so the apps can show "Automatic reply".
     */
    public function autoReply(ChatConversation $conversation, ChatParticipant $business, string $body, string $kind): ChatMessage
    {
        return DB::transaction(function () use ($conversation, $business, $body, $kind) {
            $message = ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => $business->participant_type,
                'sender_id' => $business->participant_id,
                'type' => MessageType::Text,
                'body' => $body,
                'meta' => ['auto_reply' => $kind],
                'has_link' => (bool) preg_match('~(https?://|www\.)\S+~i', $body),
                'expires_at' => $conversation->disappearing_seconds ? now()->addSeconds($conversation->disappearing_seconds) : null,
            ]);

            $this->afterNewMessage($conversation, $message, $business, [], senderReads: false);

            $name = app(ParticipantDirectory::class)->profile($business->participant(), $business->participant_type, $business->participant_id)['account_name'] ?? '';
            $this->push->message($conversation, $message, (string) $name, $conversation->activeParticipants()->where('id', '!=', $business->id)->get());

            return $message;
        });
    }

    /**
     * A system line in the conversation ("Ahmed added Sara", "Messages now disappear after 24 hours").
     *
     * @param  list<string>  $targets  account keys ("user:7")
     * @param  array<string, mixed>  $extra
     */
    public function system(ChatConversation $conversation, ?Model $actor, string $event, array $targets = [], array $extra = []): ChatMessage
    {
        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'type' => MessageType::System,
            'meta' => ['event' => $event, 'actor' => $actor ? ParticipantType::key($actor) : null, 'targets' => $targets] + $extra,
        ]);

        $this->afterNewMessage($conversation, $message, null, []);

        return $message;
    }

    /**
     * Bookkeeping every new message needs: the conversation's "last message", everyone's unread
     * badges, deleted chats coming back, and the real-time event.
     *
     * @param  list<int>  $mentions  participant ids
     */
    public function afterNewMessage(ChatConversation $conversation, ChatMessage $message, ?ChatParticipant $sender, array $mentions, bool $senderReads = true): void
    {
        $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

        $others = $conversation->activeParticipants()->when($sender, fn ($q) => $q->where('id', '!=', $sender->id));

        if ($sender !== null) {
            (clone $others)->update(['unread_count' => DB::raw('unread_count + 1'), 'is_deleted' => false]);

            if ($mentions !== []) {
                ChatParticipant::query()->whereIn('id', $mentions)->where('conversation_id', $conversation->id)->update(['has_unread_mention' => true]);
            }

            // My own message counts as read (and delivered) by me — except an automatic reply:
            // the business hasn't read the customer's message just because the reply went out.
            if ($senderReads) {
                $sender->update(['last_read_message_id' => $message->id, 'last_delivered_message_id' => $message->id, 'last_read_at' => now(), 'unread_count' => 0, 'marked_unread' => false, 'has_unread_mention' => false, 'is_deleted' => false]);
            }
        } else {
            (clone $others)->update(['is_deleted' => false]);
        }

        $this->broadcast($conversation, $message, 'chat.message.sent');
    }

    // ---------------------------------------------------------------- changing

    /**
     * Edit my own text (or caption) within the edit window set by the admin.
     */
    public function edit(Model $me, ChatMessage $message, string $body): ChatMessage
    {
        $participant = $this->conversations->participantOf($me, $message->conversation, true);
        $this->assertMine($message, $participant);

        if ($message->isGone()) {
            throw ChatException::messageDeleted();
        }

        if (! in_array($message->type, [MessageType::Text, MessageType::Image, MessageType::Video, MessageType::Document], true)) {
            throw ChatException::notEditable();
        }

        $window = ChatSetting::current()->edit_window_minutes;
        if ($message->created_at->addMinutes($window)->isPast()) {
            throw ChatException::editWindowPassed($window);
        }

        $body = trim($body);
        if ($body === '' && $message->type === MessageType::Text) {
            throw ChatException::emptyMessage();
        }

        // Editing can't sneak a banned word past the filter.
        $this->assertNoBannedWords($participant, $message->conversation, $body);

        $message->update([
            'body' => $body === '' ? null : $body,
            'edited_at' => now(),
            'has_link' => (bool) preg_match('~(https?://|www\.)\S+~i', $body),
        ]);

        $this->broadcast($message->conversation, $message, 'chat.message.updated');

        // The link may have changed or gone: refresh (or drop) the card.
        if ($message->has_link || isset($message->meta['link_preview'])) {
            $id = $message->id;
            dispatch(fn () => app(LinkPreviewService::class)->attachTo($id))->afterResponse();
        }

        return $message;
    }

    /**
     * Delete for everyone: my own message inside the window, or any message if I'm a group admin.
     * The content is kept (hidden) for `deleted_message_retention_days` so reports can still be
     * reviewed, then purged by `chat:purge`.
     */
    public function deleteForEveryone(Model $me, ChatMessage $message): ChatMessage
    {
        $conversation = $message->conversation;
        $participant = $this->conversations->participantOf($me, $conversation, true);

        if ($message->isDeletedForEveryone()) {
            return $message;
        }

        if ($message->sender_type === null) {
            throw ChatException::notYourMessage();
        }

        $isMine = $message->isFrom($participant->participant_type, (int) $participant->participant_id);

        if (! $isMine && ! ($conversation->isGroup() && $participant->isAdmin())) {
            throw ChatException::notYourMessage();
        }

        if ($isMine && $message->created_at->addMinutes(ChatSetting::current()->delete_for_everyone_window_minutes)->isPast()) {
            throw ChatException::deleteWindowPassed();
        }

        DB::transaction(function () use ($message, $conversation) {
            $message->update(['deleted_for_everyone_at' => now()]);
            ChatPinnedMessage::query()->where('message_id', $message->id)->delete();
            $this->broadcast($conversation, $message, 'chat.message.deleted');
        });

        return $message;
    }

    /**
     * Delete for me — any messages in the conversation, mine or not. Nobody else is affected.
     *
     * @param  list<string>  $uuids
     */
    public function deleteForMe(Model $me, ChatConversation $conversation, array $uuids): int
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $ids = $conversation->messages()->whereIn('uuid', $uuids)->pluck('id');

        foreach ($ids as $id) {
            ChatMessageUserState::query()->updateOrCreate(['message_id' => $id, 'participant_id' => $participant->id], ['deleted_at' => now()]);
        }

        return $ids->count();
    }

    /**
     * Forward messages to up to `max_forward_targets` chats. Media is copied, so deleting the
     * original never breaks a forward. Wallet receipts can't be forwarded (they prove *my*
     * transfer, not the forwarder's).
     *
     * @param  list<string>  $messageUuids
     * @param  list<string>  $conversationUuids
     * @return list<ChatMessage>
     */
    public function forward(Model $me, array $messageUuids, array $conversationUuids): array
    {
        $max = ChatSetting::current()->max_forward_targets;
        if (count($conversationUuids) > $max) {
            throw ChatException::tooManyForwardTargets($max);
        }

        $sources = ChatMessage::query()->whereIn('uuid', $messageUuids)->with(['media', 'conversation'])->orderBy('id')->get();

        foreach ($sources as $source) {
            $sourceParticipant = $this->conversations->participantOf($me, $source->conversation);

            if ($source->isGone() || ! $this->visibleTo($sourceParticipant, ChatMessage::query()->whereKey($source->id))->exists()) {
                throw ChatException::messageDeleted();
            }

            if ($source->view_once || in_array($source->type, [MessageType::WalletTransfer, MessageType::Call, MessageType::System, MessageType::StoryReply, MessageType::MoneyRequest, MessageType::BillSplit], true)) {
                throw new ChatException('not_forwardable', 422);
            }
        }

        $targets = ChatConversation::query()->whereIn('uuid', $conversationUuids)->get();
        $sent = [];

        foreach ($targets as $target) {
            foreach ($sources as $source) {
                $sent[] = $this->send($me, $target, [
                    'type' => $source->type->value,
                    'body' => $source->body,
                    // A forwarded live location is the last position, not a live one.
                    'meta_raw' => $source->type === MessageType::Location && $source->meta !== null
                        ? array_merge($source->meta, ['live_until' => null, 'stopped' => null])
                        : $source->meta,
                    'is_forwarded' => true,
                    'forward_score' => $source->forward_score + 1,
                    'copy_media_from' => $source,
                ]);
            }
        }

        return $sent;
    }

    /**
     * React (one reaction per person, like WhatsApp); an empty emoji removes mine.
     */
    public function react(Model $me, ChatMessage $message, ?string $emoji): ChatMessage
    {
        $conversation = $message->conversation;
        $participant = $this->conversations->participantOf($me, $conversation, true);

        if ($message->isGone() || $message->sender_type === null) {
            throw ChatException::messageDeleted();
        }

        if ($emoji === null || $emoji === '') {
            ChatMessageReaction::query()->where(['message_id' => $message->id, 'participant_id' => $participant->id])->delete();
        } else {
            ChatMessageReaction::query()->updateOrCreate(['message_id' => $message->id, 'participant_id' => $participant->id], ['emoji' => $emoji]);
        }

        $message->load('reactions');

        $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.reaction', [
            'conversation_id' => $conversation->uuid,
            'message_id' => $message->uuid,
            'participant' => $participant->key(),
            'emoji' => $emoji ?: null,
            'summary' => $message->reactions->groupBy('emoji')->map(fn ($rows, $e) => ['emoji' => (string) $e, 'count' => $rows->count()])->values()->all(),
        ]);

        return $message;
    }

    /**
     * Who reacted with what (the sheet under a message).
     *
     * @return list<array<string, mixed>>
     */
    public function reactions(Model $me, ChatMessage $message): array
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);
        $context = MessageViewContext::build($me, $participant, $message->conversation->loadViewParticipants($participant), collect());

        return $message->reactions()->latest('updated_at')->get()->map(fn (ChatMessageReaction $r) => [
            'emoji' => $r->emoji,
            'participant' => $context->profileOfParticipant($r->participant_id),
            'reacted_at' => $r->updated_at?->toIso8601String(),
        ])->all();
    }

    public function star(Model $me, ChatMessage $message, bool $starred, ?int $folderId = null): void
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);
        // A favourites folder of mine (spec 24), or none ("Favourites").
        if ($folderId !== null && ! ChatStarFolder::query()->ownedBy($me)->whereKey($folderId)->exists()) {
            throw new ChatException('star_folder_not_found', 404);
        }

        ChatMessageUserState::query()->updateOrCreate(
            ['message_id' => $message->id, 'participant_id' => $participant->id],
            ['starred_at' => $starred ? now() : null, 'star_folder_id' => $starred ? $folderId : null],
        );
    }

    /**
     * "Read later": set a message aside for me (or take it off the list once done).
     */
    public function readLater(Model $me, ChatMessage $message, bool $on): void
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);

        ChatMessageUserState::query()->updateOrCreate(
            ['message_id' => $message->id, 'participant_id' => $participant->id],
            ['read_later_at' => $on ? now() : null],
        );
    }

    /**
     * My "read later" list across every chat, oldest first (the order I set them aside).
     *
     * @return list<MessageResource>
     */
    public function readLaterList(Model $me): array
    {
        $states = ChatMessageUserState::query()
            ->whereIn('participant_id', ChatParticipant::query()->of($me)->pluck('id'))
            ->whereNotNull('read_later_at')->whereNull('deleted_at')
            ->oldest('read_later_at')->limit(200)->with('message.conversation')->get();

        return $states->map(function (ChatMessageUserState $state) use ($me) {
            $message = $state->message;

            return $message === null || $message->isGone() ? null : $this->presentOne($me, $message);
        })->filter()->values()->all();
    }

    /**
     * "Needs a reply" (spec 118): a message I keep on a follow-up list until I answer it — or
     * take it off by hand.
     */
    public function followUp(Model $me, ChatMessage $message, bool $on): void
    {
        $participant = $this->conversations->participantOf($me, $message->conversation);

        ChatMessageUserState::query()->updateOrCreate(
            ['message_id' => $message->id, 'participant_id' => $participant->id],
            ['follow_up_at' => $on ? now() : null],
        );
    }

    /**
     * What came in while I was private (spec 113): messages from others in my chats since
     * `$from`, and how many chats they were in.
     *
     * @return array{messages: int, conversations: int, from: string}
     */
    public function receivedSince(Model $me, CarbonInterface $from): array
    {
        $mine = ChatParticipant::query()->of($me)->whereNull('left_at')->get(['id', 'conversation_id', 'participant_type', 'participant_id']);
        $rows = ChatMessage::query()
            ->whereIn('conversation_id', $mine->pluck('conversation_id'))
            ->where('created_at', '>=', $from)->whereNotNull('sender_type')
            ->where(fn ($q) => $q->where('sender_type', '!=', ParticipantType::aliasFor($me))->orWhere('sender_id', '!=', $me->getKey()))
            ->get(['id', 'conversation_id']);

        return ['messages' => $rows->count(), 'conversations' => $rows->pluck('conversation_id')->unique()->count(), 'from' => $from->toIso8601String()];
    }

    /**
     * Messages still waiting for my answer, oldest first.
     *
     * @return list<MessageResource>
     */
    public function followUpList(Model $me): array
    {
        $states = ChatMessageUserState::query()
            ->whereIn('participant_id', ChatParticipant::query()->of($me)->pluck('id'))
            ->whereNotNull('follow_up_at')->whereNull('deleted_at')
            ->oldest('follow_up_at')->limit(200)->with('message.conversation')->get();

        return $states->map(function (ChatMessageUserState $state) use ($me) {
            $message = $state->message;

            return $message === null || $message->isGone() ? null : $this->presentOne($me, $message);
        })->filter()->values()->all();
    }

    /**
     * I answered: in a one-to-one chat anything I send answers what came before it; in a group
     * only a reply to that message does.
     */
    private function answeredFollowUps(ChatConversation $conversation, ChatParticipant $participant, ChatMessage $message, ?ChatMessage $replyTo): void
    {
        $answered = $conversation->isGroup()
            ? ($replyTo ? [$replyTo->id] : [])
            : ChatMessage::query()->where('conversation_id', $conversation->id)->where('id', '<', $message->id)->select('id');

        if ($answered === []) {
            return;
        }

        ChatMessageUserState::query()->where('participant_id', $participant->id)->whereNotNull('follow_up_at')
            ->whereIn('message_id', $answered)->update(['follow_up_at' => null]);
    }

    /**
     * Urgent (spec 116–117): one-to-one only, the recipient must allow it (`who_can_urgent`), and
     * at most URGENT_PER_DAY in 24 hours to the same person.
     */
    private function assertMayBeUrgent(Model $me, ChatParticipant $participant, ChatConversation $conversation): void
    {
        if ($conversation->isGroup() || $conversation->isSelf()) {
            throw new ChatException('urgent_direct_only', 422);
        }

        $peer = $conversation->activeParticipants()->where('id', '!=', $participant->id)->first()?->participant();
        if ($peer === null || ! $this->privacy->canSendUrgent($me, $peer)) {
            throw new ChatException('urgent_not_allowed', 403);
        }

        $sent = ChatMessage::query()->where('conversation_id', $conversation->id)
            ->where('sender_type', $participant->participant_type)->where('sender_id', $participant->participant_id)
            ->where('is_urgent', true)->where('created_at', '>=', now()->subDay())->count();

        if ($sent >= self::URGENT_PER_DAY) {
            throw new ChatException('urgent_quota', 429, ['max' => self::URGENT_PER_DAY]);
        }
    }

    /**
     * My starred messages — across every chat, or in one.
     *
     * @return array<string, mixed>
     */
    public function starred(Model $me, ?ChatConversation $conversation = null, int|string|null $folder = null): array
    {
        $states = ChatMessageUserState::query()
            ->whereIn('participant_id', ChatParticipant::query()->of($me)->when($conversation, fn ($q) => $q->where('conversation_id', $conversation->id))->pluck('id'))
            ->whereNotNull('starred_at')->whereNull('deleted_at')
            // One favourites folder, or "none" = the ones in no folder.
            ->when($folder === 'none', fn ($q) => $q->whereNull('star_folder_id'))
            ->when(is_int($folder), fn ($q) => $q->where('star_folder_id', $folder))
            ->latest('starred_at')->limit(200)->with('message.conversation')->get();

        return $states->map(function (ChatMessageUserState $state) use ($me) {
            $message = $state->message;

            return $message === null || $message->isGone() ? null : $this->presentOne($me, $message);
        })->filter()->values()->all();
    }

    /**
     * Pin for everyone (24h / 7 days / 30 days). Over the limit, the oldest pin makes room.
     */
    public function pin(Model $me, ChatMessage $message, int $durationSeconds): ChatPinnedMessage
    {
        $conversation = $message->conversation;
        $participant = $this->conversations->participantOf($me, $conversation, true);

        if ($conversation->isGroup() && $conversation->group->only_admins_edit_info && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        if ($message->isGone() || $message->sender_type === null) {
            throw ChatException::messageDeleted();
        }

        return DB::transaction(function () use ($me, $conversation, $message, $participant, $durationSeconds) {
            $max = ChatSetting::current()->max_pinned_messages;
            $active = ChatPinnedMessage::query()->where('conversation_id', $conversation->id)->where('message_id', '!=', $message->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('created_at')->get();

            if ($active->count() >= $max) {
                $active->take($active->count() - $max + 1)->each->delete();
            }

            $pin = ChatPinnedMessage::query()->updateOrCreate(
                ['conversation_id' => $conversation->id, 'message_id' => $message->id],
                ['pinned_by_participant_id' => $participant->id, 'expires_at' => now()->addSeconds($durationSeconds)],
            );

            $this->system($conversation, $me, 'message_pinned', [], ['message_id' => $message->uuid]);

            return $pin;
        });
    }

    public function unpin(Model $me, ChatMessage $message): void
    {
        $conversation = $message->conversation;
        $participant = $this->conversations->participantOf($me, $conversation, true);

        if ($conversation->isGroup() && $conversation->group->only_admins_edit_info && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }

        ChatPinnedMessage::query()->where('conversation_id', $conversation->id)->where('message_id', $message->id)->delete();

        $this->broadcaster->toParticipants($conversation->activeParticipants()->get(), 'chat.pins.updated', ['conversation_id' => $conversation->uuid]);
    }

    /**
     * @return list<MessageResource>
     */
    public function pinned(Model $me, ChatConversation $conversation): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);

        $messages = ChatMessage::query()
            ->whereIn('id', ChatPinnedMessage::query()->where('conversation_id', $conversation->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('message_id'))
            ->whereNull('deleted_for_everyone_at')
            ->orderByDesc('id')->get();

        $messages = $messages->filter(fn (ChatMessage $m) => $this->visibleTo($participant, ChatMessage::query()->whereKey($m->id))->exists())->values();
        $messages->load(['media', 'reactions']);

        return $this->present($messages, MessageViewContext::build($me, $participant, $conversation->loadViewParticipants($participant), $messages));
    }

    /**
     * "Message info" for my own message: who received it and who read it, and when.
     *
     * @return array<string, mixed>
     */
    public function info(Model $me, ChatMessage $message): array
    {
        $conversation = $message->conversation;
        $participant = $this->conversations->participantOf($me, $conversation);
        $conversation->loadViewParticipants($participant);
        $this->assertMine($message, $participant);

        $context = MessageViewContext::build($me, $participant, $conversation, collect([$message]));
        $others = $conversation->participants->where('id', '!=', $participant->id)->whereNull('left_at');

        if ($conversation->isGroup()) {
            $receipts = ChatMessageReceipt::query()->where('message_id', $message->id)->get()->keyBy('participant_id');
            $rows = $others->map(fn (ChatParticipant $p) => [
                'participant' => $context->profileOfParticipant($p->id),
                'delivered_at' => $receipts->get($p->id)?->delivered_at?->toIso8601String(),
                'read_at' => $receipts->get($p->id)?->read_at?->toIso8601String(),
            ])->values();
        } else {
            $rows = $others->map(fn (ChatParticipant $p) => [
                'participant' => $context->profileOfParticipant($p->id),
                'delivered_at' => (int) $p->last_delivered_message_id >= $message->id ? true : null,
                'read_at' => $context->showReads && (int) $p->last_read_message_id >= $message->id ? $p->last_read_at?->toIso8601String() : null,
            ])->values();
        }

        return [
            'message' => new MessageResource($message->load(['media', 'reactions']), $context),
            'read_by' => $rows->whereNotNull('read_at')->values()->all(),
            'delivered_to' => $rows->whereNull('read_at')->whereNotNull('delivered_at')->values()->all(),
            'pending' => $rows->whereNull('delivered_at')->values()->all(),
        ];
    }

    /**
     * Search text messages — in one chat, or in all of mine.
     *
     * @return list<MessageResource>
     */
    public function search(Model $me, ?string $term, ?ChatConversation $conversation = null, array $types = []): array
    {
        $term = trim((string) $term);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $participants = ChatParticipant::query()->of($me)->when($conversation, fn ($q) => $q->where('conversation_id', $conversation->id))->get();
        $results = collect();

        foreach ($participants as $participant) {
            $found = $this->visibleTo($participant, ChatMessage::query()->where('conversation_id', $participant->conversation_id))
                ->whereNull('deleted_for_everyone_at')
                // The words — or, in a voice message I turned into text, what was said (spec 20).
                ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('body', 'like', $like)->orWhereExists(fn ($t) => $t->select(DB::raw(1))->from('chat_message_transcripts')
                    ->whereColumn('chat_message_transcripts.message_id', 'chat_messages.id')->where('chat_message_transcripts.participant_id', $participant->id)->where('chat_message_transcripts.text', 'like', $like))))
                // Only some kinds (spec 21): photos, videos, voice, files, links, places, polls…
                ->when($types !== [], fn ($q) => $q->where(fn ($q) => $this->whereTypes($q, $types)))
                ->where('view_once', false)
                ->orderByDesc('id')->limit(50)->get();

            if ($found->isNotEmpty()) {
                $conv = $participant->conversation()->with('participants')->first();
                $found->load(['media', 'reactions']);
                $ctx = MessageViewContext::build($me, $participant, $conv, $found);
                $results = $results->merge($found->map(fn ($m) => new MessageResource($m, $ctx)));
            }
        }

        return $results->sortByDesc(fn (MessageResource $r) => $r->resource->id)->take(100)->values()->all();
    }

    /**
     * The chat's gallery tabs: media (photos + videos), documents, links, voice, locations.
     *
     * @return array{messages: Collection<int, ChatMessage>, context: MessageViewContext, has_more: bool}
     */
    /**
     * The kinds of message a search can be narrowed to (spec 21).
     *
     * @param  list<string>  $types
     */
    private function whereTypes(Builder|\Illuminate\Database\Query\Builder $q, array $types): void
    {
        $map = [
            'text' => [MessageType::Text->value], 'image' => [MessageType::Image->value], 'video' => [MessageType::Video->value],
            'voice' => [MessageType::Voice->value, MessageType::Audio->value], 'document' => [MessageType::Document->value],
            'location' => [MessageType::Location->value], 'poll' => [MessageType::Poll->value], 'contact' => [MessageType::Contact->value],
            'sticker' => [MessageType::Sticker->value, MessageType::Gif->value],
            'money' => [MessageType::WalletTransfer->value, MessageType::MoneyRequest->value, MessageType::BillSplit->value],
        ];
        $values = collect($types)->flatMap(fn ($t) => $map[$t] ?? [])->unique()->values()->all();
        if ($values !== []) {
            $q->whereIn('type', $values);
        }
        if (in_array('link', $types, true)) {
            $q->orWhere('has_link', true);
        }
    }

    /**
     * The chat's gallery tabs. `filters` (spec 17, 18): `date` (Y-m-d — from that day back),
     * `file_kind` (pdf · word · excel · slides · archive · other), `min_size` / `max_size` (bytes),
     * `sort` = size (biggest first, one page).
     *
     * @param  array<string, mixed>  $filters
     */
    public function gallery(Model $me, ChatConversation $conversation, string $kind, ?string $before, int $limit = 60, array $filters = []): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);

        $query = $this->visibleTo($participant, $conversation->messages()->getQuery())
            ->whereNull('deleted_for_everyone_at')
            ->where('view_once', false)
            ->when($before, fn ($q) => $q->where('id', '<', $this->findInConversation($conversation, $before)->id));

        if (! empty($filters['date'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['date'])->addDay()->startOfDay());
        }
        $media = function (\Closure $where) use ($query) {
            $query->whereHas('media', fn ($m) => $m->where('collection_name', ChatMessage::ATTACHMENTS)->where($where));
        };
        if (! empty($filters['min_size'])) {
            $media(fn ($m) => $m->where('size', '>=', (int) $filters['min_size']));
        }
        if (! empty($filters['max_size'])) {
            $media(fn ($m) => $m->where('size', '<=', (int) $filters['max_size']));
        }
        if (! empty($filters['file_kind'])) {
            // By type and by extension (a phone may not know a file's type).
            $kinds = [
                'pdf' => [['application/pdf'], ['pdf']],
                'word' => [['%word%', '%msword%', '%opendocument.text%', '%rtf%'], ['doc', 'docx', 'odt', 'rtf']],
                'excel' => [['%sheet%', '%excel%', '%csv%'], ['xls', 'xlsx', 'ods', 'csv']],
                'slides' => [['%presentation%', '%powerpoint%'], ['ppt', 'pptx', 'odp', 'key']],
                'archive' => [['%zip%', '%rar%', '%7z%', '%x-tar%', '%gzip%'], ['zip', 'rar', '7z', 'tar', 'gz']],
            ];
            $matches = fn ($m, array $kind) => $m->where(function ($m) use ($kind) {
                foreach ($kind[0] as $mime) {
                    $m->orWhere('mime_type', 'like', $mime);
                }
                foreach ($kind[1] as $ext) {
                    $m->orWhere('file_name', 'like', '%.'.$ext);
                }
            });
            $media(fn ($m) => $filters['file_kind'] === 'other'
                ? collect($kinds)->each(fn ($kind) => $m->whereNot(fn ($n) => $matches($n, $kind)))
                : $matches($m, $kinds[$filters['file_kind']]));
        }

        match ($kind) {
            'media' => $query->whereIn('type', [MessageType::Image->value, MessageType::Video->value]),
            'documents' => $query->where('type', MessageType::Document->value),
            'audio' => $query->whereIn('type', [MessageType::Audio->value, MessageType::Voice->value]),
            'links' => $query->where('has_link', true),
            'locations' => $query->where('type', MessageType::Location->value),
            default => throw new ChatException('invalid_gallery', 422),
        };

        if (($filters['sort'] ?? null) === 'size') {
            // Biggest first — one page, no paging.
            $query->orderByDesc(Media::query()->select('size')->whereColumn('model_id', 'chat_messages.id')
                ->where('model_type', (new ChatMessage)->getMorphClass())->where('collection_name', ChatMessage::ATTACHMENTS)->orderByDesc('size')->limit(1));
            $limit = 200;
        }
        $messages = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = ($filters['sort'] ?? null) !== 'size' && $messages->count() > $limit;
        $messages = $messages->take($limit)->values();
        $messages->load(['media', 'reactions']);

        return ['messages' => $messages, 'has_more' => $hasMore, 'context' => MessageViewContext::build($me, $participant, $conversation->loadViewParticipants($participant), $messages)];
    }

    // ---------------------------------------------------------------- helpers

    public function findInConversation(ChatConversation $conversation, string $uuid): ChatMessage
    {
        return $conversation->messages()->where('uuid', $uuid)->first()
            ?? throw new ChatException('message_not_found', 404);
    }

    private function assertCanSend(Model $me, ChatParticipant $participant, ChatConversation $conversation): void
    {
        if ($conversation->status === ConversationStatus::Rejected) {
            throw ChatException::requestRejected();
        }

        if ($conversation->isGroup()) {
            // A channel: its admins post, followers read (and react).
            if (($conversation->isChannel() || $conversation->group->only_admins_send) && ! $participant->isAdmin()) {
                throw ChatException::adminsOnly();
            }

            $this->assertNotTooSoon($participant, $conversation);

            return;
        }

        // Note to self: nobody else to be blocked by.
        if ($conversation->isSelf()) {
            return;
        }

        $peer = $conversation->participants()->where('id', '!=', $participant->id)->first()?->participant();

        if ($peer === null) {
            throw ChatException::userNotFound();
        }

        // Blocked either way = the chat is closed until someone unblocks.
        $this->privacy->assertReachable($me, $peer);
    }

    /**
     * Slow mode: a member waits `slow_mode_seconds` after their last message (admins never wait).
     * The wait left comes back in `retry_after` so the app can count it down.
     */
    private function assertNotTooSoon(ChatParticipant $participant, ChatConversation $conversation): void
    {
        $seconds = (int) $conversation->group?->slow_mode_seconds;

        if ($seconds <= 0 || $participant->isAdmin()) {
            return;
        }

        $last = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', $participant->participant_type)->where('sender_id', $participant->participant_id)
            ->latest('id')->value('created_at');

        if ($last === null) {
            return;
        }

        $wait = $seconds - (int) floor(Carbon::parse($last)->diffInSeconds(now(), true));

        if ($wait > 0) {
            throw new ChatException('slow_mode', 429, ['seconds' => $wait], ['retry_after' => $wait]);
        }
    }

    /**
     * A group's banned words: a member's text (or caption) containing one isn't sent. Admins are
     * exempt — they moderate, and may need to quote what they're removing.
     */
    private function assertNoBannedWords(ChatParticipant $participant, ChatConversation $conversation, ?string $body): void
    {
        if (! $conversation->isGroup() || $participant->isAdmin()) {
            return;
        }

        if (BannedWords::firstIn($body, $conversation->group?->banned_words) !== null) {
            throw new ChatException('banned_word', 422);
        }
    }

    private function startedBy(ChatConversation $conversation, ChatParticipant $participant): bool
    {
        return $conversation->created_by_type === $participant->participant_type
            && (int) $conversation->created_by_id === (int) $participant->participant_id;
    }

    private function assertMine(ChatMessage $message, ChatParticipant $participant): void
    {
        if (! $message->isFrom($participant->participant_type, (int) $participant->participant_id)) {
            throw ChatException::notYourMessage();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildMeta(Model $me, MessageType $type, array $data): ?array
    {
        // A forwarded copy keeps the original's structured data as it was.
        if (array_key_exists('meta_raw', $data)) {
            return $data['meta_raw'];
        }

        return match ($type) {
            MessageType::Location => [
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'name' => $data['location_name'] ?? null,
                'address' => $data['address'] ?? null,
                // Live location: moves (PUT messages/{m}/live-location) until this time.
                'live_until' => in_array((int) ($data['live_seconds'] ?? 0), MessageExtrasService::LIVE_DURATIONS, true)
                    ? now()->addSeconds((int) $data['live_seconds'])->toIso8601String() : null,
                'updated_at' => now()->toIso8601String(),
            ],
            // Looked up by id on the server: a message can only show real library media.
            MessageType::Gif => app(GiphyService::class)->find((string) ($data['giphy_id'] ?? ''), 'gif'),
            MessageType::Sticker => match (true) {
                ! empty($data['my_sticker_id']) => app(StickerService::class)->myMeta($me, (int) $data['my_sticker_id']),
                ! empty($data['sticker_id']) => app(StickerService::class)->meta((int) $data['sticker_id']),
                default => app(GiphyService::class)->find((string) ($data['giphy_id'] ?? ''), 'sticker'),
            },
            MessageType::Poll => MessageExtrasService::pollMeta((array) ($data['poll_options'] ?? []), (bool) ($data['poll_multiple'] ?? false)),
            MessageType::Contact => [
                'name' => (string) $data['contact_name'],
                'phones' => array_values((array) $data['contact_phones']),
            ],
            MessageType::Voice, MessageType::Audio => array_filter([
                'duration_ms' => isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
                'waveform' => isset($data['waveform']) ? array_map('intval', (array) $data['waveform']) : null,
            ], fn ($v) => $v !== null) ?: null,
            MessageType::Video => isset($data['duration_ms']) ? ['duration_ms' => (int) $data['duration_ms']] : null,
            MessageType::WalletTransfer => $this->walletShare->transferReceipt($me, (string) $data['wallet_transaction_id'])
                // A money gift: the same receipt, drawn as a card for the occasion.
                + (in_array($data['gift'] ?? null, self::GIFT_CARDS, true) ? ['gift' => $data['gift']] : []),
            MessageType::WalletQr => $this->walletShare->walletQr($me, $data['country_code'] ?? null),
            // Built by StoryService::reply() from the real story — never from the client.
            MessageType::StoryReply => $data['story_meta'] ?? throw new ChatException('story_not_found', 404),
            default => null,
        };
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function attach(ChatMessage $message, array $files): void
    {
        if ($files === []) {
            return;
        }

        // The admin's chat limit, not the app-wide media-library default (10MB): a 100MB video must fit.
        config(['media-library.max_file_size' => ChatSetting::current()->max_file_size_mb * 1024 * 1024]);

        foreach (array_values($files) as $i => $file) {
            $file = WebpUploadConverter::convert($file);
            $message->addMedia($file)
                ->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'))
                ->usingName($file->getClientOriginalName())
                ->setOrder($i + 1)
                ->toMediaCollection(ChatMessage::ATTACHMENTS);
        }
    }

    /**
     * Only people actually in this group can be mentioned.
     *
     * @param  list<int|string>  $ids  participant ids
     * @return list<int>
     */
    private function validMentions(ChatConversation $conversation, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $conversation->activeParticipants()->whereIn('id', array_map('intval', $ids))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Push the message to everyone in the conversation, rendered for a neutral viewer; each app
     * re-derives the viewer-specific bits (is_mine, ticks) from `sender.key`.
     */
    private function broadcast(ChatConversation $conversation, ChatMessage $message, string $event): void
    {
        $participants = $conversation->activeParticipants()->get();
        $senderRow = $message->sender_type !== null
            ? $participants->first(fn (ChatParticipant $p) => $p->participant_type === $message->sender_type && (int) $p->participant_id === (int) $message->sender_id)
            : null;
        $viewerRow = $senderRow ?? $participants->first();

        if ($viewerRow === null) {
            return;
        }

        $viewer = $viewerRow->participant();
        if ($viewer === null) {
            return;
        }

        $message->load(['media', 'reactions', 'replyTo.media']);
        $context = MessageViewContext::build($viewer, $viewerRow, $conversation->setRelation('participants', $participants), collect([$message]));
        $payload = (new MessageResource($message, $context))->resolve();
        // Neutral: the recipients' apps decide these for themselves.
        $payload['is_mine'] = null;
        $payload['status'] = null;
        $payload['is_starred'] = false;

        $this->broadcaster->toParticipants($participants, $event, [
            'conversation_id' => $conversation->uuid,
            'conversation_type' => $conversation->type->value,
            'message' => $payload,
        ]);
    }
}
