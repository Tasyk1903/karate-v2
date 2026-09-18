<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class StudentInvitation extends Mailable
{
    use Queueable;

    public function __construct(public string $coachName, public string $coachCode, public string $language) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mobile_students.invitation_subject', [], $this->language));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.student-invitation');
    }
}
