<x-layouts::public title="Privacy Policy">

    {{-- Hero --}}
    <section style="padding:80px 0 48px;border-bottom:1px solid rgba(255,255,255,0.07);">
        <div class="sec-inner">
            <span class="sec-badge" style="border-color:var(--accent-border);color:var(--accent);background:var(--accent-dim);">Legal</span>
            <h1 class="sec-h2" style="font-size:42px;">Privacy Policy</h1>
            <p class="sec-sub">Last updated: 27 May 2025</p>
        </div>
    </section>

    {{-- Content --}}
    <section style="padding:64px 0 96px;">
        <div class="sec-inner-sm" style="max-width:56rem;">
            <div class="policy-body">

                <p>At <strong>{{ config('app.name') }}</strong> ("we", "us", or "our"), we are committed to protecting your personal information in accordance with the <strong>Protection of Personal Information Act 4 of 2013 (POPIA)</strong> and applicable South African privacy law. This Privacy Policy explains what information we collect, how we use it, and your rights regarding your data.</p>

                <h2>1. Information We Collect</h2>
                <p>When you use our platform, we may collect the following personal information:</p>
                <ul>
                    <li><strong>Contact information</strong> — your name, email address, and phone number when you place an order or create an account.</li>
                    <li><strong>Transaction data</strong> — details of products you purchase, payment amounts, and order references.</li>
                    <li><strong>Device &amp; usage data</strong> — IP address, browser type, pages visited, and referring URLs collected automatically via cookies and analytics tools.</li>
                    <li><strong>Payment information</strong> — we do <em>not</em> store card or banking details. All payment data is handled directly by our certified payment processors (see Section 4).</li>
                </ul>

                <h2>2. How We Use Your Information</h2>
                <p>We use your personal information to:</p>
                <ul>
                    <li>Process and fulfil your orders and deliver digital tokens/vouchers to you.</li>
                    <li>Send order confirmation and delivery emails containing your purchased tokens.</li>
                    <li>Respond to support enquiries and resolve disputes.</li>
                    <li>Detect and prevent fraud and unauthorised transactions.</li>
                    <li>Comply with legal obligations, including tax and consumer-protection legislation.</li>
                    <li>Improve our platform through aggregated, anonymised analytics.</li>
                </ul>
                <p>We will <strong>not sell, rent, or trade</strong> your personal information to third parties for marketing purposes.</p>

                <h2>3. Lawful Basis for Processing</h2>
                <p>We process your personal information on the following lawful bases:</p>
                <ul>
                    <li><strong>Contract performance</strong> — to fulfil your purchase order.</li>
                    <li><strong>Legitimate interest</strong> — to prevent fraud and improve our service.</li>
                    <li><strong>Legal obligation</strong> — to comply with tax, financial reporting, and consumer-protection laws.</li>
                    <li><strong>Consent</strong> — for optional marketing communications (you may withdraw consent at any time).</li>
                </ul>

                <h2>4. Payment Processing</h2>
                <p>Payments on our platform are processed by third-party payment service providers, which may include PayFast, Peach Payments, Flutterwave, Paystack, SnapScan, and DPO Pay. Each provider is bound by their own privacy policy and PCI-DSS standards. We only receive a transaction reference and confirmation status — we never store your full card or banking credentials.</p>

                <h2>5. Sharing of Information</h2>
                <p>We may share your personal information only in the following circumstances:</p>
                <ul>
                    <li><strong>Payment processors</strong> — to complete your transaction.</li>
                    <li><strong>Email service providers</strong> — to deliver your order confirmation and token codes.</li>
                    <li><strong>Legal authorities</strong> — where required by law, court order, or regulatory obligation.</li>
                    <li><strong>Business transfers</strong> — in the event of a merger or acquisition, your data may be transferred as a business asset; you will be notified beforehand.</li>
                </ul>

                <h2>6. Cookies &amp; Tracking</h2>
                <p>We use essential cookies to maintain your session and shopping cart. We may also use analytics cookies (e.g. Google Analytics) to understand how visitors use our site. You can disable non-essential cookies via your browser settings; however, this may affect site functionality.</p>

                <h2>7. Data Retention</h2>
                <p>We retain your personal information for as long as necessary to fulfil the purposes described in this policy or as required by law. Transaction records are retained for a minimum of 5 years for tax and audit purposes. You may request deletion of your account data subject to legal retention obligations.</p>

                <h2>8. Security</h2>
                <p>We implement industry-standard security measures including TLS/SSL encryption, access controls, and regular security audits to protect your data. While we strive to protect your information, no method of internet transmission is 100% secure, and we cannot guarantee absolute security.</p>

                <h2>9. Your Rights Under POPIA</h2>
                <p>As a South African data subject, you have the right to:</p>
                <ul>
                    <li>Access the personal information we hold about you.</li>
                    <li>Request correction of inaccurate or incomplete information.</li>
                    <li>Request deletion of your personal information (subject to legal obligations).</li>
                    <li>Object to the processing of your personal information.</li>
                    <li>Withdraw consent where processing is based on consent.</li>
                    <li>Lodge a complaint with the <strong>Information Regulator of South Africa</strong> at <em>inforeg.org.za</em>.</li>
                </ul>
                <p>To exercise any of these rights, contact us at <a href="mailto:support@voucherguy.co.za">support@voucherguy.co.za</a>.</p>

                <h2>10. Children's Privacy</h2>
                <p>Our services are not directed at persons under the age of 18. We do not knowingly collect personal information from minors. If you believe a minor has provided us with personal information, please contact us and we will promptly delete it.</p>

                <h2>11. Changes to This Policy</h2>
                <p>We may update this Privacy Policy from time to time. We will notify you of material changes by posting the updated policy on this page with a revised "Last updated" date. Continued use of our services after changes are posted constitutes your acceptance of the updated policy.</p>

                <h2>12. Contact Us</h2>
                <p>For privacy-related enquiries, requests, or complaints:</p>
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
