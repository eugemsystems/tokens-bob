<x-layouts::public title="Cancellation Policy">

    {{-- Hero --}}
    <section style="padding:80px 0 48px;border-bottom:1px solid rgba(255,255,255,0.07);">
        <div class="sec-inner">
            <span class="sec-badge" style="border-color:var(--accent-border);color:var(--accent);background:var(--accent-dim);">Legal</span>
            <h1 class="sec-h2" style="font-size:42px;">Cancellation Policy</h1>
            <p class="sec-sub">Last updated: 27 May 2025</p>
        </div>
    </section>

    {{-- Content --}}
    <section style="padding:64px 0 96px;">
        <div class="sec-inner-sm" style="max-width:56rem;">
            <div class="policy-body">

                <p>This Cancellation Policy explains the circumstances under which orders placed on <strong>{{ config('app.name') }}</strong> can be cancelled, and what happens when a cancellation is requested or occurs. Because we deal exclusively in <strong>instantly delivered digital goods</strong>, the window for cancellation is very limited by the nature of the products we sell.</p>

                <div style="background:rgba(221,242,71,0.07);border:1px solid rgba(221,242,71,0.2);border-radius:12px;padding:20px 24px;margin-bottom:32px;">
                    <p style="margin:0;color:rgba(255,255,255,0.85);"><strong style="color:#DDF247;">Key point:</strong> Once your payment is confirmed and your digital token or voucher has been delivered to your email, the order is considered complete and cannot be cancelled. Please verify your order carefully before checkout.</p>
                </div>

                <h2>1. Before Payment is Processed</h2>
                <p>You may abandon your cart or navigate away from the checkout page at any time before completing payment — no charge will be made and no order will be created. There is no need to formally cancel at this stage.</p>
                <p>If you have initiated a payment but it has <strong>not yet been confirmed</strong> by the payment gateway, please contact us at <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a> immediately with your order details. We will attempt to halt the order if it has not yet been processed, though this cannot be guaranteed.</p>

                <h2>2. After Payment is Confirmed — Digital Delivery</h2>
                <p>Once payment is confirmed by our payment processor, our system automatically fulfils the order by dispatching the digital token or voucher code to your registered email address. This process typically completes within <strong>seconds to a few minutes</strong>.</p>
                <p>At this point, <strong>the order cannot be cancelled</strong> because the digital product has already been committed and dispatched. This is consistent with Section 44 of the <strong>Electronic Communications and Transactions Act 25 of 2002 (ECTA)</strong>, under which the right to cancel does not apply where digital content supply has commenced with the consumer's express agreement.</p>

                <h2>3. Failed or Pending Payments</h2>
                <p>If your payment fails or is marked as pending by the payment gateway:</p>
                <ul>
                    <li>No digital code will be delivered until payment is fully confirmed.</li>
                    <li>If funds were deducted from your account but the order shows as failed, contact us with proof of payment within <strong>24 hours</strong>. We will investigate with the payment provider and either fulfil the order or arrange a refund.</li>
                    <li>Pending transactions that are not resolved within 48 hours may be automatically voided by the payment gateway.</li>
                </ul>

                <h2>4. Cancellation Due to Order Errors</h2>
                <p>We reserve the right to cancel an order and issue a full refund in the following situations:</p>
                <ul>
                    <li>A pricing error was displayed on the product listing.</li>
                    <li>The ordered product is temporarily out of stock and cannot be fulfilled.</li>
                    <li>The order is flagged as potentially fraudulent by our payment processors or fraud-prevention systems.</li>
                    <li>A technical error resulted in an incorrect product being associated with your order before delivery.</li>
                </ul>
                <p>In any of these cases, you will be notified by email and a full refund will be issued to your original payment method within <strong>3–7 business days</strong>.</p>

                <h2>5. Subscription or Recurring Orders</h2>
                <p>{{ config('app.name') }} currently sells one-time digital purchases only and does not offer recurring subscription billing. There are no automatic renewals or scheduled charges to cancel.</p>

                <h2>6. Issues with Delivered Tokens</h2>
                <p>If you received a token or voucher that is invalid, already redeemed, or otherwise non-functional, this is covered under our <a href="{{ route('refund-policy') }}">Refund Policy</a> rather than a cancellation. Please refer to that policy for the replacement and refund process.</p>

                <h2>7. Delivery Timeline Confirmation</h2>
                <p>To summarise our delivery commitment for payment gateway and compliance purposes:</p>
                <ul>
                    <li><strong>Product type:</strong> Digital vouchers, tokens, and activation codes.</li>
                    <li><strong>Delivery method:</strong> Email delivery to the address provided at checkout.</li>
                    <li><strong>Delivery timeline:</strong> Instantly upon payment confirmation — typically within <strong>seconds to 5 minutes</strong>. In exceptional cases (gateway delays), up to 30 minutes.</li>
                    <li><strong>Delivery guarantee:</strong> If a code is not received within 30 minutes of confirmed payment, we will investigate and re-deliver or refund within 24 hours.</li>
                </ul>

                <h2>8. How to Contact Us for Cancellation Requests</h2>
                <p>If you believe your order qualifies for cancellation under this policy, contact our support team as soon as possible:</p>
                <div class="policy-contact-box">
                    <p><strong>{{ config('app.name') }}</strong></p>
                    <p>Email: <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a></p>
                    <p>Subject line: <strong>Cancellation Request – [Your Order ID]</strong></p>
                    <p>Support hours: 24 / 7</p>
                    <p style="margin-top:12px;color:rgba(255,255,255,0.45);font-size:13px;font-family:'Azeret Mono',monospace;">Faster resolution is guaranteed when your Order ID is included.</p>
                </div>

            </div>
        </div>
    </section>

    <style>
        .policy-body { color: rgba(255,255,255,0.75); font-family: 'Manrope', sans-serif; font-size: 15px; line-height: 1.85; }
        .policy-body h2 { font-size: 20px; font-weight: 800; color: #fff; margin: 48px 0 16px; font-family: 'Manrope', sans-serif; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        .policy-body p { margin: 0 0 16px; }
        .policy-body ul { margin: 0 0 16px 20px; padding: 0; }
        .policy-body ul li { margin-bottom: 10px; }
        .policy-body a { color: #DDF247; text-decoration: none; }
        .policy-body a:hover { text-decoration: underline; }
        .policy-body strong { color: rgba(255,255,255,0.95); }
        .policy-contact-box { background: #1a1a1a; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 28px 32px; margin-top: 8px; }
        .policy-contact-box p { margin: 0 0 8px; }
        .policy-contact-box p:last-child { margin: 0; }
    </style>

</x-layouts::public>
