<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A single transactional email for the IDP approval workflow — a "need approval"
 * alert or an approved / rejected outcome. Carries only scalar props so it is
 * safe to queue. Queued onto the default connection; when the mailer is `log`
 * (dev) it simply writes to the log.
 */
class ApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $recipientName,
        public string $bodyLine,
        public string $actionUrl,
        public string $actionText,
    ) {
        // Every approval email is raised inside the transaction that records the
        // decision. Queue it only once that commits, so a rolled-back action
        // never mails anyone, and a worker never sends it before the row exists.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.approval');
    }
}
