<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AttendanceConfirmationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $eventType,
        public string $occurredAt,
        public bool $wasOffline,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmación de marcación de asistencia');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.attendance-confirmation',
            with: [
                'eventType' => $this->eventType,
                'occurredAt' => $this->occurredAt,
                'wasOffline' => $this->wasOffline,
            ],
        );
    }
}
