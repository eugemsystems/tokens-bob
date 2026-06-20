<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'customer_email' => ['required', 'email', 'max:254'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'card_name' => ['required', 'string', 'max:100'],
            'card_number' => ['required', 'string', 'digits_between:13,19'],
            'card_expiry_month' => ['required', 'string', 'digits:2'],
            'card_expiry_year' => ['required', 'string', 'digits:2'],
            'card_cvv' => ['required', 'string', 'digits_between:3,4'],
        ];
    }

    /**
     * Card data extracted for forwarding — caller must unset after use.
     *
     * @return array{name: string, number: string, expiry_month: string, expiry_year: string, cvv: string}
     */
    public function cardData(): array
    {
        return [
            'name' => $this->input('card_name'),
            'number' => $this->input('card_number'),
            'expiry_month' => $this->input('card_expiry_month'),
            'expiry_year' => $this->input('card_expiry_year'),
            'cvv' => $this->input('card_cvv'),
        ];
    }
}
