<?php

namespace App\Mail;

use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TimeEntryReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TimeEntry $entry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Comprovante de registro de ponto — NSR '.$this->entry->nsr);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.receipt',
            with: ['r' => app(TimeEntryService::class)->receipt($this->entry)],
        );
    }
}
