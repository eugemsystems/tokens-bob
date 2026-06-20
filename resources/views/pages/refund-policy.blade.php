<x-layouts::public title="Refund Policy">

    {{-- Hero --}}
    <section style="padding:80px 0 48px;border-bottom:1px solid rgba(255,255,255,0.07);">
        <div class="sec-inner">
            <span class="sec-badge" style="border-color:var(--accent-border);color:var(--accent);background:var(--accent-dim);">Legal</span>
            <h1 class="sec-h2" style="font-size:42px;">Refund Policy</h1>
            <p class="sec-sub">Last updated: 27 May 2025</p>
        </div>
    </section>

    {{-- Content --}}
    <section style="padding:64px 0 96px;">
        <div class="sec-inner-sm" style="max-width:56rem;">
            <div class="policy-body">

                <p>At <strong>{{ config('app.name') }}</strong>, we strive to provide a reliable and seamless experience for all digital purchases. Because we sell <strong>digital goods that are delivered instantly</strong> (voucher codes, token codes, and activation keys), our refund policy is necessarily different from that of physical goods retailers. Please read this policy carefully before completing your purchase.</p>

                <div style="background:rgba(221,242,71,0.07);border:1px solid rgba(221,242,71,0.2);border-radius:12px;padding:20px 24px;margin-bottom:32px;">
                    <p style="margin:0;color:rgba(255,255,255,0.85);"><strong style="color:#DDF247;">Summary:</strong> We generally do not issue refunds on delivered digital products. However, we will replace or refund any token/voucher that is proven to be invalid, non-functional, or incorrectly delivered through no fault of yours.</p>
                </div>

                <h2>1. General Policy — Digital Goods</h2>
                <p>Under Section 44 of the <strong>Electronic Communications and Transactions Act 25 of 2002 (ECTA)</strong> and the <strong>Consumer Protection Act 68 of 2008</strong>, consumers have a right to cancel electronic transactions within 7 days under certain conditions. However, this right does not apply to digital content where the supply has commenced with the consumer's prior agreement and acknowledgement that the right to cancel will be lost.</p>
                <p>By completing a purchase on {{ config('app.name') }}, you expressly consent to the immediate supply of digital content and acknowledge that your right of withdrawal is forfeited once the digital code has been delivered to your email address.</p>

                <h2>2. Eligibility for a Refund or Replacement</h2>
                <p>You may be eligible for a refund or replacement in the following circumstances:</p>
                <ul>
                    <li><strong>Invalid code</strong> — the token or voucher code we delivered does not work and returns an error when entered on the intended platform.</li>
                    <li><strong>Already-redeemed code</strong> — the code was already used before it was delivered to you (i.e., it was not a fresh, unredeemed code).</li>
                    <li><strong>Wrong product delivered</strong> — you received a code for a different product, denomination, or region than what you ordered.</li>
                    <li><strong>Duplicate charge</strong> — your payment was processed more than once for the same order (provide bank/payment proof).</li>
                    <li><strong>Non-delivery</strong> — payment was confirmed by the payment gateway but no code was delivered within 24 hours.</li>
                </ul>
                <p><strong>Note:</strong> We are unable to issue refunds where the code has been successfully delivered and redeemed by you, or where the issue arises from third-party platform outages or regional restrictions that are outside our control.</p>

                <h2>3. Non-Refundable Circumstances</h2>
                <p>Refunds will <strong>not</strong> be issued in the following cases:</p>
                <ul>
                    <li>You purchased the wrong product, denomination, or region and the code has already been delivered.</li>
                    <li>The code was delivered and redeemed successfully but you changed your mind.</li>
                    <li>The third-party service (game, streaming platform, etc.) is experiencing downtime, has changed its terms, or has geo-restricted the product after purchase.</li>
                    <li>You failed to check platform compatibility or regional availability before purchasing.</li>
                    <li>More than 14 calendar days have passed since the order was delivered.</li>
                </ul>

                <h2>4. How to Request a Refund or Replacement</h2>
                <p>To initiate a refund or replacement request, please contact our support team within <strong>14 days</strong> of your order date:</p>
                <ol style="margin: 0 0 16px 20px; padding: 0;">
                    <li style="margin-bottom:10px;">Email <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a> with the subject line: <strong>"Refund Request – [Your Order ID]"</strong>.</li>
                    <li style="margin-bottom:10px;">Include your <strong>order reference number</strong>, the <strong>email address</strong> used at checkout, and a clear description of the issue.</li>
                    <li style="margin-bottom:10px;">Attach any relevant <strong>screenshots or error messages</strong> showing the issue with the code.</li>
                    <li style="margin-bottom:10px;">For duplicate-charge claims, attach your <strong>bank statement or payment confirmation</strong> showing the double charge.</li>
                </ol>
                <p>We will respond to all refund requests within <strong>1–2 business days</strong>.</p>

                <h2>5. Refund Processing Timeline</h2>
                <p>Once a refund is approved:</p>
                <ul>
                    <li>Replacement codes are issued <strong>within 24 hours</strong> of approval.</li>
                    <li>Monetary refunds are processed back to your original payment method within <strong>3–7 business days</strong>, depending on your bank or payment provider.</li>
                </ul>

                <h2>6. Chargebacks</h2>
                <p>We strongly encourage customers to contact us directly before initiating a chargeback with their bank. Unresolved chargebacks result in additional fees and may cause your account to be suspended. We cooperate fully with all payment processor dispute resolutions and will provide transaction evidence when required.</p>

                <h2>7. Contact Us</h2>
                <p>For refund requests or questions about this policy:</p>
                <div class="policy-contact-box">
                    <p><strong>{{ config('app.name') }}</strong></p>
                    <p>Email: <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a></p>
                    <p>Support hours: 24 / 7</p>
                    <p style="margin-top:12px;color:rgba(255,255,255,0.45);font-size:13px;font-family:'Azeret Mono',monospace;">Please include your Order ID in all refund correspondence.</p>
                </div>

            </div>
        </div>
    </section>

    <style>
        .policy-body { color: rgba(255,255,255,0.75); font-family: 'Manrope', sans-serif; font-size: 15px; line-height: 1.85; }
        .policy-body h2 { font-size: 20px; font-weight: 800; color: #fff; margin: 48px 0 16px; font-family: 'Manrope', sans-serif; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        .policy-body p { margin: 0 0 16px; }
        .policy-body ul, .policy-body ol { margin: 0 0 16px 20px; padding: 0; }
        .policy-body ul li, .policy-body ol li { margin-bottom: 10px; }
        .policy-body a { color: #DDF247; text-decoration: none; }
        .policy-body a:hover { text-decoration: underline; }
        .policy-body strong { color: rgba(255,255,255,0.95); }
        .policy-contact-box { background: #1a1a1a; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 28px 32px; margin-top: 8px; }
        .policy-contact-box p { margin: 0 0 8px; }
        .policy-contact-box p:last-child { margin: 0; }
    </style>

</x-layouts::public>
