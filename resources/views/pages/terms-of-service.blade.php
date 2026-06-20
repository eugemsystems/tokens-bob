<x-layouts::public title="Terms of Service">

    {{-- Hero --}}
    <section style="padding:80px 0 48px;border-bottom:1px solid rgba(255,255,255,0.07);">
        <div class="sec-inner">
            <span class="sec-badge" style="border-color:var(--accent-border);color:var(--accent);background:var(--accent-dim);">Legal</span>
            <h1 class="sec-h2" style="font-size:42px;">Terms of Service</h1>
            <p class="sec-sub">Last updated: 27 May 2025</p>
        </div>
    </section>

    {{-- Content --}}
    <section style="padding:64px 0 96px;">
        <div class="sec-inner-sm" style="max-width:56rem;">
            <div class="policy-body">

                <p>Welcome to <strong>{{ config('app.name') }}</strong>. By accessing or using our website and services, you agree to be bound by these Terms of Service ("Terms"). Please read them carefully before making a purchase. If you do not agree to these Terms, do not use our platform.</p>

                <div style="background:rgba(221,242,71,0.07);border:1px solid rgba(221,242,71,0.2);border-radius:12px;padding:20px 24px;margin-bottom:32px;">
                    <p style="margin:0;color:rgba(255,255,255,0.85);"><strong style="color:#DDF247;">Important:</strong> {{ config('app.name') }} sells digital products (vouchers, tokens, and activation codes). Due to the instant digital delivery nature of our products, please review our <a href="{{ route('refund-policy') }}">Refund Policy</a> and <a href="{{ route('cancellation-policy') }}">Cancellation Policy</a> before purchasing.</p>
                </div>

                <h2>1. About Our Service</h2>
                <p>{{ config('app.name') }} is a South African digital marketplace that sells prepaid digital vouchers, tokens, and activation codes for a variety of services, including gaming platforms, streaming services, online shopping, software licenses, and more. All products are delivered digitally to the customer's email address immediately after successful payment.</p>

                <h2>2. Eligibility</h2>
                <p>You must be at least <strong>18 years of age</strong> to use our services. By using our platform, you represent and warrant that you are 18 or older and are legally capable of entering into binding agreements under South African law. If you are under 18, you may only use our services with the direct involvement and consent of a parent or legal guardian.</p>

                <h2>3. Account Registration</h2>
                <p>Some features of our platform may require account registration. You agree to:</p>
                <ul>
                    <li>Provide accurate, current, and complete information during registration.</li>
                    <li>Maintain the security of your account credentials and not share them with any third party.</li>
                    <li>Notify us immediately at <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a> of any unauthorised use of your account.</li>
                    <li>Accept responsibility for all activities that occur under your account.</li>
                </ul>
                <p>We reserve the right to suspend or terminate accounts that violate these Terms.</p>

                <h2>4. Products &amp; Digital Goods</h2>
                <p>All products sold on {{ config('app.name') }} are <strong>digital goods</strong> (voucher codes, token codes, activation keys). By placing an order, you acknowledge that:</p>
                <ul>
                    <li>Digital products are delivered electronically to your registered email address.</li>
                    <li>Once a digital code has been delivered and/or redeemed, it cannot be returned or exchanged (see our <a href="{{ route('refund-policy') }}">Refund Policy</a> for exceptions).</li>
                    <li>The validity period, terms, and conditions of each voucher or token are set by the issuing brand or service provider. We are not responsible for expiry, regional restrictions, or third-party platform changes.</li>
                    <li>You are solely responsible for ensuring the product is compatible with the intended platform before purchase.</li>
                </ul>

                <h2>5. Pricing &amp; Payment</h2>
                <p>All prices are displayed in South African Rand (ZAR) and are inclusive of applicable taxes unless stated otherwise. We reserve the right to change prices at any time without prior notice; however, the price at the time of your order confirmation will be honoured.</p>
                <p>Payments are processed securely by our third-party payment partners. By submitting payment, you authorise the charge for the order amount. We do not store your payment card details.</p>
                <p>In the event of a pricing error, we reserve the right to cancel the affected order and issue a full refund.</p>

                <h2>6. Digital Delivery</h2>
                <p>Upon successful payment verification, your digital token or voucher code will be:</p>
                <ul>
                    <li>Delivered to your email address <strong>within seconds to minutes</strong> of payment confirmation.</li>
                    <li>Also accessible on your order confirmation page at <strong>https://voucherguy.co.za/order/[your-order-id]</strong>.</li>
                </ul>
                <p>Delivery is subject to payment processing by our gateway partners. If you do not receive your order within 30 minutes of payment, please contact us at <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a> with your order reference number.</p>

                <h2>7. Prohibited Uses</h2>
                <p>You agree not to use our platform to:</p>
                <ul>
                    <li>Purchase products for resale or commercial redistribution without prior written authorisation.</li>
                    <li>Commit fraud, including the use of stolen payment instruments.</li>
                    <li>Attempt to reverse-engineer, scrape, or systematically extract data from our platform.</li>
                    <li>Circumvent security measures or attempt to gain unauthorised access to our systems.</li>
                    <li>Use our services for any unlawful purpose under South African law.</li>
                </ul>

                <h2>8. Intellectual Property</h2>
                <p>All content on this website — including text, graphics, logos, and code — is the property of {{ config('app.name') }} or its licensors and is protected by South African copyright law. You may not reproduce, distribute, or create derivative works without our express written consent.</p>
                <p>Third-party brand names, logos, and trademarks displayed on our platform belong to their respective owners and are used solely for product identification purposes.</p>

                <h2>9. Disclaimer of Warranties</h2>
                <p>Our platform is provided on an "as is" and "as available" basis. To the maximum extent permitted by law, we disclaim all warranties, express or implied, including warranties of merchantability, fitness for a particular purpose, and non-infringement. We do not warrant that our services will be uninterrupted, error-free, or free of viruses.</p>

                <h2>10. Limitation of Liability</h2>
                <p>To the fullest extent permitted by the Consumer Protection Act 68 of 2008 and other applicable South African law, {{ config('app.name') }} shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of our services. Our total liability for any claim shall not exceed the amount you paid for the order giving rise to the claim.</p>

                <h2>11. Governing Law &amp; Jurisdiction</h2>
                <p>These Terms are governed by and construed in accordance with the laws of the Republic of South Africa. Any disputes arising under these Terms shall be subject to the exclusive jurisdiction of the South African courts. This includes compliance with the <strong>Electronic Communications and Transactions Act 25 of 2002 (ECTA)</strong> and the <strong>Consumer Protection Act 68 of 2008</strong>.</p>

                <h2>12. Changes to These Terms</h2>
                <p>We reserve the right to update these Terms at any time. Updated Terms will be posted on this page with a revised "Last updated" date. Your continued use of our services after changes are published constitutes acceptance of the new Terms.</p>

                <h2>13. Contact Us</h2>
                <p>For questions about these Terms:</p>
                <div class="policy-contact-box">
                    <p><strong>{{ config('app.name') }}</strong></p>
                    <p>Email: <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a></p>
                    <p>Support hours: 24 / 7</p>
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
