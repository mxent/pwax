{{--
    The last resort: a navigation with no network and nothing stored for that URL.

    A whole document, so it carries its own styles — the shell's stylesheet belongs to a
    page that never loaded. It is written to match the application's other screens rather
    than to stand out: a visitor who has already seen the in-app error should recognise
    this as the same thing said by something further down the stack, not as a second,
    worse failure.

    No script, and no reload button that reloads on its own. Reloading is exactly what
    will fail again; the browser's own control is the honest place for that.

    A Blade view since 4.1, rather than forty lines of HTML inside a JavaScript string
    inside another Blade template. It picks up the application's language and direction,
    and can be published on its own:

        php artisan vendor:publish --tag=pwax-service-worker
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }}</title>
    <style>
        :root {
            --fg: #18181b;
            --muted: #71717a;
            --line: #e4e4e7;
            --bg: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --fg: #fafafa;
                --muted: #a1a1aa;
                --line: #3f3f46;
                --bg: #09090b;
            }
        }

        html,
        body {
            margin: 0;
            height: 100%;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            background: var(--bg);
            color: var(--fg);
            line-height: 1.5;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .panel {
            width: 100%;
            max-width: 32rem;
            text-align: center;
        }

        .code {
            margin: 0 0 1rem;
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .code::after {
            content: "";
            display: block;
            width: 2.5rem;
            height: 1px;
            margin: .75rem auto 0;
            background: var(--line);
        }

        h1 {
            margin: 0 0 .5rem;
            font-size: 1.375rem;
            font-weight: 600;
            letter-spacing: -.01em;
        }

        .message {
            margin: 0;
            color: var(--muted);
        }
    </style>
</head>

<body>
    <div class="panel" role="alert">
        <p class="code">{{ $title }}</p>
        <h1>{{ $heading }}</h1>
        <p class="message">{{ $message }}</p>
    </div>
</body>

</html>
