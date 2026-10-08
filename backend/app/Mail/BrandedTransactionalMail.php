<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BrandedTransactionalMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $templateData */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $viewName,
        public readonly array $templateData,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: $this->viewName, with: $this->templateData);
    }
}
