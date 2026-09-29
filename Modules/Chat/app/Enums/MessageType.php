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
        ]);
    }
}
