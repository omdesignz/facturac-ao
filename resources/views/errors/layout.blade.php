{{--
    Shared shell for every error status.

    Deliberately self-contained: no @vite, no @fonts, no compiled asset of any
    kind. A 500 can be caused by a missing Vite manifest or a broken build, and
    an error page that depends on the thing that just broke is no error page at
    all. The type falls back to the system stack when IBM Plex is not cached.
--}}
<!DOCTYPE html>
<html lang="pt-AO">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#faf9f6" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#171716" media="(prefers-color-scheme: dark)">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <title>@yield('title') — facturac.ao</title>
        <style>
            :root {
                --ground: #faf9f6;
                --panel: #fff;
                --ink: #1c1c1a;
                --muted: #6f6f69;
                --rule: rgba(28, 28, 26, 0.12);
                --gold: #f9b233;
                --gold-ink: #8a4b0c;
                --brand: #454543;
                --brand-ink: #fff;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --ground: #131312;
                    --panel: #1c1c1a;
                    --ink: #f2f1ec;
                    --muted: #918f86;
                    --rule: rgba(242, 241, 236, 0.14);
                    --gold-ink: #f5c468;
                    --brand: #f9b233;
                    --brand-ink: #171716;
                }
            }

            /* Set by the inline script below from the app's own preference. */
            :root[data-theme='dark'] {
                --ground: #131312;
                --panel: #1c1c1a;
                --ink: #f2f1ec;
                --muted: #918f86;
                --rule: rgba(242, 241, 236, 0.14);
                --gold-ink: #f5c468;
                --brand: #f9b233;
                --brand-ink: #171716;
            }

            :root[data-theme='light'] {
                --ground: #faf9f6;
                --panel: #fff;
                --ink: #1c1c1a;
                --muted: #6f6f69;
                --rule: rgba(28, 28, 26, 0.12);
                --gold-ink: #8a4b0c;
                --brand: #454543;
                --brand-ink: #fff;
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 1.5rem;
                background: var(--ground);
                color: var(--ink);
                font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, -apple-system, sans-serif;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
            }

            .card {
                width: 100%;
                max-width: 34rem;
                text-align: center;
            }

            .mark { width: 56px; height: 56px; margin: 0 auto 1.75rem; display: block; }

            .status {
                font-size: 0.6875rem;
                font-weight: 600;
                letter-spacing: 0.16em;
                text-transform: uppercase;
                color: var(--gold-ink);
                margin: 0 0 0.75rem;
            }

            h1 {
                margin: 0;
                font-size: clamp(1.6rem, 5vw, 2.25rem);
                font-weight: 600;
                letter-spacing: -0.028em;
                text-wrap: balance;
            }

            p.lede {
                margin: 0.9rem auto 0;
                max-width: 30rem;
                color: var(--muted);
                font-size: 1rem;
                text-wrap: pretty;
            }

            .actions {
                margin-top: 2rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                justify-content: center;
            }

            a.button {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.7rem 1.25rem;
                border-radius: 0.75rem;
                font-size: 0.9rem;
                font-weight: 600;
                text-decoration: none;
                background: var(--brand);
                color: var(--brand-ink);
            }

            a.button--quiet {
                background: transparent;
                color: var(--ink);
                border: 1px solid var(--rule);
            }

            a.button:focus-visible {
                outline: 2px solid var(--gold);
                outline-offset: 3px;
            }

            .ref {
                margin-top: 2.5rem;
                padding-top: 1.25rem;
                border-top: 1px solid var(--rule);
                font-size: 0.75rem;
                color: var(--muted);
            }
        </style>
        <script>
            // Match the theme the user picked inside the app. Wrapped because
            // localStorage throws in some privacy modes, and an error page must
            // never fail on its own script.
            try {
                var stored = localStorage.getItem('vap-appearance');
                if (stored === 'dark' || stored === 'light') {
                    document.documentElement.dataset.theme = stored;
                }
            } catch (e) {}
        </script>
    </head>
    <body>
        <main class="card">
            {{-- Carimbo, simplified for a single small rendering. --}}
            <svg class="mark" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <g transform="rotate(-8 32 32)" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="32" cy="32" r="29" stroke="#f9b233" stroke-width="2.4" stroke-dasharray="0.4 5.2"/>
                    <circle cx="32" cy="32" r="19.5" stroke="#f9b233" stroke-width="2.6"/>
                    <path d="M22 33L29.2 40.2L43.8 22.4" stroke="currentColor" stroke-width="5.4"/>
                </g>
            </svg>

            <p class="status">@yield('status')</p>
            <h1>@yield('title')</h1>
            <p class="lede">@yield('message')</p>

            <div class="actions">
                @yield('actions')
            </div>

            <p class="ref">facturac.ao — Feito para humanos, para negócios angolanos.</p>
        </main>
    </body>
</html>
