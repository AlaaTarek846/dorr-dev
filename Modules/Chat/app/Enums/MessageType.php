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
    // A question with numbered options; meta holds them and multiple (MessageExtrasService::pollMeta).
    case Poll = 'poll';
    // Asking someone for money; meta holds the amount in the asker's currency.
    case MoneyRequest = 'money_request';
    // A bill being split; meta holds the shares.
    case BillSplit = 'bill_split';
    // A GIF from the Giphy library, sent by its id; the URL is resolved server-side.
    case Gif = 'gif';
    // A sticker from one of Dorr's own packs, sent by its id.
    case Sticker = 'sticker';
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
            self::Location, self::Contact, self::WalletTransfer, self::WalletQr,
            self::Poll, self::MoneyRequest, self::BillSplit, self::Gif, self::Sticker,
        ]);
    }
}
