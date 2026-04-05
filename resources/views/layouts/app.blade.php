<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'App') }}</title>
    <style>
        :root {
            --bg: #f8fafc;
            --panel: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --success-bg: #ecfdf5;
            --success-text: #065f46;
            --danger: #b91c1c;
            --danger-hover: #991b1b;
            --primary: #0f766e;
            --primary-hover: #115e59;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.4 "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        h1, h2 { margin: 0 0 12px; }

        .muted { color: var(--muted); }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            border-bottom: 1px solid var(--border);
            padding: 10px 8px;
            vertical-align: middle;
        }

        form.inline { display: inline-block; }

        label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }

        input, select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            border: 0;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            color: #fff;
            background: var(--primary);
        }

        .btn:hover { background: var(--primary-hover); }

        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: var(--danger-hover); }

        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .errors {
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fef2f2;
            color: #7f1d1d;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .errors ul { margin: 0; padding-left: 18px; }
    </style>
</head>
<body>
<div class="container">
    @if(session('status'))
        <div class="alert-success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="errors">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
