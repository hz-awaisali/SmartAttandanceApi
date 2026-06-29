<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public string $code) {}

    public function build(): self
    {
        return $this->subject('Your verification code')
            ->view('emails.verification-code');
    }
}
