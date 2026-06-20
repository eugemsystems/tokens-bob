<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Cancelled</title>
    <style>
        body { margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #111; font-family: sans-serif; color: #fff; text-align: center; }
        p { color: rgba(255,255,255,0.5); font-size: 14px; margin-top: 8px; }
    </style>
</head>
<body>
    <div>
        <div style="font-size:48px;">✕</div>
        <p>Payment cancelled. This window will close.</p>
    </div>
    <script>
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage({ dpoCancelled: true }, '*');
            setTimeout(function () { window.close(); }, 800);
        } else {
            window.location.replace('{{ route('shop') }}');
        }
    </script>
</body>
</html>
