<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Firebase Browser Token QA</title>
    <style>
        :root {
            color-scheme: light;
            font-family: "Segoe UI", sans-serif;
            --bg: #f4efe6;
            --panel: #fffaf2;
            --border: #d8cab0;
            --text: #1f2933;
            --muted: #5b6470;
            --accent: #b85c38;
            --accent-dark: #8f4325;
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(184, 92, 56, 0.18), transparent 28%),
                linear-gradient(135deg, #f7f0e1, #efe7da 45%, #f8f3ea);
            color: var(--text);
        }

        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 18px 48px rgba(74, 56, 34, 0.12);
            padding: 24px;
        }

        h1 {
            margin-top: 0;
            font-size: 2rem;
        }

        p {
            color: var(--muted);
            line-height: 1.55;
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 20px 0;
        }

        button {
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            font: inherit;
            font-weight: 600;
            padding: 12px 18px;
        }

        button:hover {
            background: var(--accent-dark);
        }

        label {
            display: grid;
            gap: 8px;
            font-weight: 600;
        }

        input,
        textarea,
        pre {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            font: inherit;
            background: #fff;
            color: var(--text);
        }

        textarea,
        pre {
            min-height: 140px;
        }

        pre {
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .status {
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(184, 92, 56, 0.08);
            color: var(--text);
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Firebase Browser Token QA</h1>
        <p>
            Use this page to request a browser FCM token and optionally register it against the backend notifications endpoint.
            For automated Playwright coverage, add <code>?mock=1&amp;auto=1</code> to the URL.
        </p>

        <div class="grid">
            <div class="status" data-testid="config-status">Loading Firebase token tools...</div>
            <div class="status" data-testid="token-status">No browser token acquired yet.</div>
            <div class="status" data-testid="register-status">No backend registration has been attempted yet.</div>
        </div>

        <div class="actions">
            <button type="button" data-testid="acquire-token">Acquire Browser Token</button>
            <button type="button" data-testid="register-token">Register Token With Backend</button>
        </div>

        <div class="grid">
            <label>
                Browser FCM token
                <textarea data-testid="fcm-token" spellcheck="false" placeholder="The browser token will appear here."></textarea>
            </label>

            <label>
                API endpoint
                <input data-testid="api-endpoint" spellcheck="false" placeholder="/api/v1/user/notifications/token">
            </label>

            <label>
                Bearer token
                <input data-testid="bearer-token" spellcheck="false" placeholder="Paste a Sanctum bearer token for a real backend registration">
            </label>

            <label>
                Registration response
                <pre data-testid="response-body"></pre>
            </label>
        </div>
    </section>
</main>

<script>
    window.firebaseTokenDebugConfig = @json($firebaseDebugConfig, JSON_UNESCAPED_SLASHES);
</script>
<script src="{{ asset('js/firebase-token-debug.js') }}"></script>
</body>
</html>
