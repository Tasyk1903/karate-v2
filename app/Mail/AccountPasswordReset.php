<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountPasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $resetUrl, public string $language) {}

    public function build(): static
    {
        return $this->subject(__('account.reset_title', [], $this->language))->view('mail.account-password-reset');
    }
}
