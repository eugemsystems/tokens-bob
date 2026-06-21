<?php

namespace App\Mail;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountActivationPending extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly Category $category,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->category->name.' is being activated — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-activation-pending',
        );
    }
}
