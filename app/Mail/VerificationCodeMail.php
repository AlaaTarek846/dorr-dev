<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public Model $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('api.verification_email_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification-code',
            with: [
                'code' => $this->code,
                'name' => $this->recipient->name ?? config('app.name'),
                'expiryMinutes' => (int) config('auth_flow.otp_expiry_minutes', 10),
            ],
        );
    }
}
