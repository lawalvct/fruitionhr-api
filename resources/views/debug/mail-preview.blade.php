<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Email Preview — FruitionHR (Dev)</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f9;
            color: #111827;
        }
        header {
            background: #064e3b;
            color: #fff;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        header h1 { font-size: 15px; margin: 0; font-weight: 600; }
        .badge {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .3px;
            text-transform: uppercase;
            background: #b45309;
            padding: 3px 9px;
            border-radius: 999px;
        }
        .bar {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            padding: 14px 24px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }
        select, .toggle a {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            background: #fff;
            color: #111827;
            text-decoration: none;
        }
        .toggle a.active {
            background: #047857;
            border-color: #047857;
            color: #fff;
            font-weight: 600;
        }
        .toggle { display: flex; gap: 6px; }
        .subject {
            padding: 12px 24px;
            font-size: 13px;
            color: #64748b;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }
        .subject strong { color: #111827; }
        .stage { padding: 24px; display: flex; justify-content: center; }
        iframe {
            width: 100%;
            max-width: 760px;
            height: calc(100vh - 210px);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
        }
        iframe.mobile { max-width: 390px; }
    </style>
</head>
<body>
    <header>
        <span class="badge">Dev / Test only</span>
        <h1>Email templates</h1>
    </header>

    <div class="bar">
        <select onchange="window.location = '?email=' + this.value">
            @foreach ($previews as $key => $label)
                <option value="{{ $key }}" @selected($key === $selected)>{{ $label }}</option>
            @endforeach
        </select>

        <div class="toggle">
            <a href="#" id="btn-desktop" class="active" onclick="setWidth(false); return false;">Desktop</a>
            <a href="#" id="btn-mobile" onclick="setWidth(true); return false;">Mobile</a>
            <a href="{{ route('debug.mail-preview.show', ['email' => $selected, 'format' => 'text']) }}" target="_blank">Plain text</a>
        </div>
    </div>

    <div class="subject">Subject: <strong>{{ $subject }}</strong></div>

    <div class="stage">
        <iframe id="frame" src="{{ route('debug.mail-preview.show', ['email' => $selected]) }}" title="Email preview"></iframe>
    </div>

    <script>
        function setWidth(mobile) {
            document.getElementById('frame').classList.toggle('mobile', mobile);
            document.getElementById('btn-mobile').classList.toggle('active', mobile);
            document.getElementById('btn-desktop').classList.toggle('active', !mobile);
        }
    </script>
</body>
</html>
