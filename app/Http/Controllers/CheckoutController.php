<?php

namespace App\Http\Controllers;

use App\Actions\ProcessCheckout;
use App\Http\Requests\CheckoutRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(private readonly ProcessCheckout $checkout) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $category = Category::findOrFail($request->integer('category_id'));
        $cardData = $request->cardData();

        try {
            $result = $this->checkout->execute(
                category: $category,
                customerData: [
                    'email' => $request->input('customer_email'),
                    'phone' => $request->input('customer_phone'),
                ],
                cardData: $cardData,
            );
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
