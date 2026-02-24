<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestmentSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    /**
     * Create a new message instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Investment Confirmation: ' . ($this->data['application_number'] ?? 'New Investment'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.investment-sent',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        if (!empty($this->data['payment_proof'])) {
            $path = public_path('storage/' . $this->data['payment_proof']);
            if (file_exists($path)) {
                return [
                    \Illuminate\Mail\Mailables\Attachment::fromPath($path)
                        ->as('payment_proof.' . pathinfo($path, PATHINFO_EXTENSION))
                        ->withMime(mime_content_type($path)),
                ];
            }
        }
        return [];
    }
}
