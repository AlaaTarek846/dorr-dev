<?php

namespace Modules\Chat\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    // A voice note recorded in the app: meta holds the waveform + duration.
    case Voice = 'voice';
    case Document = 'document';
    case Location = 'location';
    case Contact = 'contact';
    // body = the question; meta = {options: [{id, text}], multiple}; votes in chat_poll_votes.
    case Poll = 'poll';
    // "Send me 50" (direct chats) and a bill shared between members — paid with the wallet PIN.
    // meta = the media (Giphy, or one of Dorr's sticker packs) — built on the server from an id.
    case Gif = 'gif';
    case Sticker = 'sticker';
    case MoneyRequest = 'money_request';
    case BillSplit = 'bill_split';
    // Built by the server from the sender's own wallet (docs/chat-plan.md §10.0.1) — never from client data.
    case WalletTransfer = 'wallet_transfer';
    case WalletQr = 'wallet_qr';
    case StoryReply = 'story_reply';
    case Call = 'call';
    case System = 'system';

    /**
     * Types that carry uploaded files.
     */
    public function hasAttachments(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Audio, self::Voice, self::Document], true);
    }

    /**
     * Types a person may send through the send-message endpoint (the rest are created by the server).
     *
     * @return list<string>
     */
    public static function sendable(): array
    {
        return array_map(fn (self $t) => $t->value, [
            self::Text, self::Image, self::Video, self::Audio, self::Voice, self::Document,
            self::Location, self::Contact, self::Poll, self::WalletTransfer, self::WalletQr,
            self::MoneyRequest, self::BillSplit, self::Gif, self::Sticker,
        ]);
    }

    /**
     * Types that can be sent "view once".
     */
    public function canViewOnce(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Voice], true);
    }
}
