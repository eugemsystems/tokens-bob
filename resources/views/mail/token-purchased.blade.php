<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Token Purchase</title>
</head>
<body style="margin:0;padding:0;background:#f0f0f0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f0;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;width:100%;">

                    {{-- Header --}}
                    <tr>
                        <td style="background:#111111;padding:32px 40px;text-align:center;">
                            <h1 style="margin:0;color:#DDF247;font-size:22px;font-weight:900;letter-spacing:-0.5px;">{{ config('app.name') }}</h1>
                            <p style="margin:6px 0 0;color:rgba(255,255,255,0.45);font-size:13px;">Digital Token Store</p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:40px;">

                            <h2 style="margin:0 0 8px;color:#111111;font-size:22px;font-weight:800;">Payment Confirmed!</h2>
                            <p style="margin:0 0 24px;color:#555555;font-size:15px;line-height:24px;">
                                Your payment of <strong style="color:#111111;">R{{ number_format((float) $transaction->amount, 2) }}</strong> was successful.
                                Here {{ $tokens->count() === 1 ? 'is' : 'are' }} your token {{ $tokens->count() === 1 ? 'code' : 'codes' }}:
                            </p>

                            @foreach ($tokens as $token)
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">
                                <tr>
                                    <td style="background:#f8f8f8;border:1px solid #e8e8e8;border-radius:10px;padding:20px 24px;">
                                        <p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:3px;color:#888888;">{{ $token['name'] }}</p>
                                        @if (!empty($token['description']))
                                        <p style="margin:0 0 10px;font-size:12px;color:#999999;line-height:18px;">{{ $token['description'] }}</p>
                                        @endif
                                        <p style="margin:0;font-family:'Courier New',Courier,monospace;font-size:20px;font-weight:700;color:#111111;letter-spacing:3px;word-break:break-all;">{{ $token['code'] }}</p>
                                    </td>
                                </tr>
                            </table>
                            @endforeach

                            <p style="margin:24px 0 0;color:#777777;font-size:13px;line-height:21px;">
                                Keep this email as your purchase record.<br>
                                Transaction reference: <strong>#{{ $transaction->id }}</strong>
                            </p>

                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background:#f8f8f8;padding:20px 40px;text-align:center;border-top:1px solid #eeeeee;">
                            <p style="margin:0;font-size:12px;color:#aaaaaa;">
                                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
