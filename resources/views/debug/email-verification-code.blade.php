<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Email Verification Code Lookup — FruitionHR (Dev)</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 28px 28px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .badge {
            display: inline-block;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .3px;
            text-transform: uppercase;
            color: #fff;
            background: #b45309;
            padding: 3px 9px;
            border-radius: 999px;
            margin-bottom: 12px;
        }
        h1 {
            font-size: 18px;
            margin: 0 0 4px;
            color: #064e3b;
        }
        p.sub {
            margin: 0 0 20px;
            color: #64748b;
            font-size: 13px;
        }
        form { display: flex; gap: 8px; margin-bottom: 18px; }
        input[type=email] {
            flex: 1;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
        }
        input[type=email]:focus {
            outline: none;
            border-color: #047857;
            box-shadow: 0 0 0 3px rgba(4,120,87,.12);
        }
        button {
            border: none;
            background: #047857;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 16px;
            border-radius: 8px;
            cursor: pointer;
        }
        button:hover { background: #065f46; }
        .result-code {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 18px;
            text-align: center;
        }
        .result-code .label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #047857;
            margin-bottom: 6px;
        }
        .result-code .code {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 6px;
            color: #064e3b;
        }
        .message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
        }
        .email-echo {
            margin-top: 10px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">Dev / Test only</span>
        <h1>Email Verification Code Lookup</h1>
        <p class="sub">Enter the test account's email to view its current verification code.</p>

        <form method="GET" action="{{ url()->current() }}">
            <input
                type="email"
                name="email"
                placeholder="tester@example.com"
                value="{{ $email }}"
                required
                autofocus
            >
            <button type="submit">Look up</button>
        </form>

        @if ($code !== null)
            <div class="result-code">
                <span class="label">Verification code</span>
                <span class="code">{{ $code }}</span>
            </div>
            <p class="email-echo">{{ $email }}</p>
        @elseif ($message !== null)
            <div class="message">{{ $message }}</div>
        @endif
    </div>
</body>
</html>
