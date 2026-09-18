<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrainerInvitation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $organization,
        public string $organizationCode,
        public string $language = 'ru',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('team.invitation_title', [], $this->language),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.trainer-invitation',
        );
    }
}
