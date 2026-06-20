<?php

namespace App\Mail;

use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class TokenPurchased extends Mailable
{
    use Queueable, SerializesModels;

    /** @var Collection<int, array{name: string, description: string|null, code: string}> */
    public Collection $tokens;

    public function __construct(public readonly Transaction $transaction)
    {
        $this->tokens = Token::with('category')
            ->where('transaction_id', $transaction->id)
            ->get()
            ->map(fn (Token $t) => [
                'name'        => $t->category?->name ?? 'Token',
                'description' => $t->category?->description,
                'code'        => $t->token_code,
            ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Token Purchase — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.token-purchased',
        );
    }
}
