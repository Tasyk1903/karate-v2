<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TrainerEmailVerification extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public string $language) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('team.verification_title', [], $this->language));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.trainer-email-verification');
    }
}
