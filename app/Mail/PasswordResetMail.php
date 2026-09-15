<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resetUrl,
        public Model $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('api.password_reset_email_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.password-reset',
            with: [
                'resetUrl' => $this->resetUrl,
                'name' => $this->recipient->name ?? config('app.name'),
                'expiryMinutes' => (int) config('auth_flow.flow_token_expiry_minutes', 60),
            ],
        );
    }
}
