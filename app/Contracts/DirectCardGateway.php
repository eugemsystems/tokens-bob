<?php

namespace App\Contracts;

use App\Models\Transaction;

interface DirectCardGateway
{
    /**
     * Charge a card directly.
     *
     * @param  array{number: string, cvv: string, expiry_month: string, expiry_year: string, name: string}  $cardData
     * @return array{status: 'success'|'send_pin'|'send_otp'|'redirect'|'error', card_ref?: string, gateway_txn_id?: int|string, redirect_url?: string, message?: string}
     */
    public function chargeCard(array $cardData, Transaction $transaction): array;

    /**
     * Submit PIN for step-up authentication.
     * $cardData is required by Flutterwave (re-sends full charge); ignored by others.
     *
     * @param  array{number: string, cvv: string, expiry_month: string, expiry_year: string, name: string}  $cardData
     * @return array{status: 'success'|'send_otp'|'redirect'|'error', card_ref?: string, gateway_txn_id?: int|string, message?: string}
     */
    public function submitPin(string $reference, string $pin, Transaction $transaction, array $cardData = []): array;

    /**
     * Submit OTP for final authorization.
     *
     * @return array{status: 'success'|'error', gateway_txn_id?: int|string, message?: string}
     */
    public function submitOtp(string $reference, string $otp): array;

    /**
     * Verify a completed transaction. Returns normalized data or null on failure.
     *
     * @return array{status: string, tx_ref: string, amount: float, currency: string, id: int|string}|null
     */
    public function verifyTransaction(int|string $transactionId): ?array;
}
