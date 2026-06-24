<?php

namespace App\Contracts;

use App\Models\Transaction;

interface SeamlessGateway
{
    /**
     * @return array{success: bool, reference_number: string, poll_url: string, message: string}
     */
    public function makePayment(Transaction $transaction, string $paymentMethodCode, string $phoneNumber): array;

    /**
     * @return array{transaction_status: string, reference_number: string}|null
     */
    public function checkStatus(string $referenceNumber): ?array;

    /**
     * @return array<int, array{code: string, name: string, requires_phone: bool, is_card: bool}>
     */
    public function paymentMethods(): array;

    /**
     * @return array{success: bool, reference_number: string, transaction_status: string, redirect_url: string, message: string}
     */
    public function makeCardPayment(Transaction $transaction, string $paymentMethodCode, string $cardNumber, string $cardExpiry, string $cvv): array;

    /**
     * @return array{success: bool, reference_number: string, redirect_url: string, message: string}
     */
    public function initiateTransaction(Transaction $transaction): array;

    public function decryptPayload(string $payload): ?array;
}
