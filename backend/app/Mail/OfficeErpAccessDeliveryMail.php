<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OfficeErpAccessDeliveryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $subjectLine, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.office-erp-access-delivery');
    }
}
