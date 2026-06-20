<?php

namespace App\Contracts;

use App\Models\Transaction;

interface PaymentGateway
{
    public function getKey(): string;

    public function getName(): string;

    /** One of: 'onsite', 'qr', 'redirect', 'inline' */
    public function getCheckoutType(): string;

    /**
     * Initiate a payment session and return checkout data for the UI.
     *
     * @param  array{email: string, phone: string}  $customerData
     * @return array{success: bool, checkout_type: string, data: array<string, mixed>, message: string}
     */
    public function initiate(Transaction $transaction, array $customerData, string $description): array;
}
