<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Your {{ $category->name }} is being activated — {{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#0f0f0f;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0f0f0f;padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

                    {{-- HEADER --}}
                    <tr>
                        <td style="background:#161616;border-radius:16px 16px 0 0;padding:32px 40px;border-bottom:1px solid #2a2a2a;text-align:center;">
                            <p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:4px;color:#666;">{{ config('app.name') }}</p>
                            <h1 style="margin:0;font-size:26px;font-weight:900;color:#fff;">We're activating your account</h1>
                            <p style="margin:12px 0 0;font-size:14px;color:#888;">Order #{{ $transaction->id }} &middot; {{ $transaction->created_at->format('d M Y') }}</p>
                        </td>
                    </tr>

                    {{-- SUCCESS BANNER --}}
                    <tr>
                        <td style="background:#1a1a1a;padding:20px 40px;border-bottom:1px solid #2a2a2a;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#0d1f0d;border:1px solid #1a4a1a;border-radius:10px;padding:14px 18px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="font-size:13px;color:#4ade80;font-weight:700;">
                                                    &#10003; &nbsp;Payment confirmed &mdash; R{{ number_format($transaction->amount, 2) }}
                                                </td>
                                                <td align="right" style="font-size:12px;color:#22c55e;">
                                                    Secure Payment
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- PRODUCT DETAILS --}}
                    <tr>
                        <td style="background:#1a1a1a;padding:28px 40px;">
                            <p style="margin:0 0 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:3px;color:#555;">What you purchased</p>
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#111;border:1px solid #2a2a2a;border-radius:12px;padding:20px;">
                                        <p style="margin:0 0 6px;font-size:17px;font-weight:900;color:#fff;">{{ $category->name }}</p>
                                        @if ($category->description)
                                        <p style="margin:0;font-size:13px;color:#666;line-height:1.6;">{{ $category->description }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- STATUS NOTICE --}}
                    <tr>
                        <td style="background:#161616;padding:24px 40px;border-top:1px solid #2a2a2a;border-bottom:1px solid #2a2a2a;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#13100a;border:1px solid #3a2c00;border-radius:12px;padding:20px 22px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="36" valign="top">
                                                    <div style="width:28px;height:28px;background:#DDF247;border-radius:50%;display:flex;align-items:center;justify-content:center;text-align:center;line-height:28px;font-size:14px;">&#x23F1;</div>
                                                </td>
                                                <td style="padding-left:12px;">
                                                    <p style="margin:0 0 4px;font-size:14px;font-weight:800;color:#DDF247;">Your account is being activated</p>
                                                    <p style="margin:0;font-size:13px;color:#999;line-height:1.6;">
                                                        This usually takes about <strong style="color:#fff;">1 minute</strong>. Please check back shortly &mdash; your access will be ready soon.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- WHAT TO DO NEXT --}}
                    <tr>
                        <td style="background:#1a1a1a;padding:24px 40px;border-bottom:1px solid #2a2a2a;">
                            <p style="margin:0 0 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:3px;color:#555;">What happens next</p>
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="24" valign="top" style="color:#DDF247;font-size:13px;font-weight:900;padding-top:1px;">1.</td>
                                    <td style="font-size:13px;color:#888;line-height:1.6;padding-bottom:8px;">We are processing your activation right now.</td>
                                </tr>
                                <tr>
                                    <td width="24" valign="top" style="color:#DDF247;font-size:13px;font-weight:900;padding-top:1px;">2.</td>
                                    <td style="font-size:13px;color:#888;line-height:1.6;padding-bottom:8px;">Wait approximately <strong style="color:#fff;">1 minute</strong> for the process to complete.</td>
                                </tr>
                                <tr>
                                    <td width="24" valign="top" style="color:#DDF247;font-size:13px;font-weight:900;padding-top:1px;">3.</td>
                                    <td style="font-size:13px;color:#888;line-height:1.6;">Your account will be active and ready to use.</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- FOOTER --}}
                    <tr>
                        <td style="background:#111;border-radius:0 0 16px 16px;padding:24px 40px;text-align:center;">
                            <p style="margin:0 0 6px;font-size:12px;color:#555;">Questions? Contact us at</p>
                            <a href="mailto:{{ config('mail.from.address') }}" style="font-size:12px;color:#DDF247;text-decoration:none;">{{ config('mail.from.address') }}</a>
                            <p style="margin:16px 0 0;font-size:11px;color:#333;">
                                &copy; {{ date('Y') }} {{ config('app.name') }} &middot; Sent to {{ $transaction->customer_email }}
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
