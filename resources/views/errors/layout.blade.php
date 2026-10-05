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
                --brand: #171716;
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
                --brand: #171716;
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

            .mark { width: auto; height: 1.75rem; margin: 0 auto 2.25rem; display: block; }

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
            {{--
                The wordmark, inline and as outlines: this page may not load the
                build, so the logo cannot be an asset or depend on a font. Same
                paths as BrandWordmark.vue.
            --}}
            <svg class="mark" viewBox="-1.6 3 524 80.4" role="img" aria-label="facturac.ao">
                <path fill="currentColor" d="M0.4 26.9H5.1Q5.2 16.4 5.5 14.5Q6 10.1 9.2 7.6Q12.3 5 18.1 5Q22.2 5 27.4 6.9V17.1Q24.6 16.2 22.7 16.2Q20.4 16.2 19.3 17.2Q18.5 17.9 18.5 20.2L18.5 26.9H26.9V38.2H18.5V80H5.1V38.2H0.4ZM69 26.9H82.3V80H69V74.4Q65.1 78.1 61.1 79.7Q57.2 81.4 52.6 81.4Q42.3 81.4 34.8 73.4Q27.3 65.4 27.3 53.5Q27.3 41.2 34.6 33.4Q41.8 25.5 52.2 25.5Q57 25.5 61.2 27.3Q65.4 29.1 69 32.7ZM55 37.8Q48.8 37.8 44.7 42.2Q40.6 46.6 40.6 53.4Q40.6 60.3 44.7 64.7Q48.9 69.2 55 69.2Q61.3 69.2 65.5 64.8Q69.6 60.4 69.6 53.3Q69.6 46.4 65.5 42.1Q61.3 37.8 55 37.8ZM142.7 37.6 131.7 43.7Q128.5 40.4 125.5 39.2Q122.4 37.9 118.3 37.9Q110.8 37.9 106.2 42.4Q101.6 46.8 101.6 53.8Q101.6 60.6 106.1 64.9Q110.5 69.2 117.7 69.2Q126.7 69.2 131.7 63.1L142.1 70.3Q133.6 81.4 118 81.4Q104 81.4 96.1 73.1Q88.1 64.8 88.1 53.6Q88.1 45.9 92 39.4Q95.9 32.9 102.8 29.2Q109.7 25.5 118.2 25.5Q126.1 25.5 132.4 28.7Q138.7 31.8 142.7 37.6ZM151.4 7.3H164.6V26.9H172.5V38.3H164.6V80H151.4V38.3H144.5V26.9H151.4ZM174.7 26.9H188.2V52.5Q188.2 59.9 189.2 62.8Q190.2 65.7 192.5 67.4Q194.7 69 198.1 69Q201.4 69 203.7 67.4Q206 65.8 207.1 62.7Q208 60.4 208 52.9V26.9H221.4V49.4Q221.4 63.3 219.2 68.4Q216.5 74.7 211.2 78Q206 81.4 198 81.4Q189.2 81.4 183.8 77.5Q178.4 73.6 176.2 66.6Q174.7 61.7 174.7 49ZM226.6 26.9H238V33.6Q239.9 29.6 243 27.6Q246 25.5 249.7 25.5Q252.3 25.5 255.1 26.9L251 38.3Q248.6 37.2 247.1 37.2Q244 37.2 241.9 41Q239.8 44.8 239.8 55.9L239.8 58.5V80H226.6ZM296 26.9H309.3V80H296V74.4Q292.1 78.1 288.1 79.7Q284.2 81.4 279.6 81.4Q269.3 81.4 261.8 73.4Q254.3 65.4 254.3 53.5Q254.3 41.2 261.6 33.4Q268.8 25.5 279.2 25.5Q284 25.5 288.2 27.3Q292.4 29.1 296 32.7ZM282 37.8Q275.8 37.8 271.7 42.2Q267.6 46.6 267.6 53.4Q267.6 60.3 271.7 64.7Q275.9 69.2 282 69.2Q288.3 69.2 292.5 64.8Q296.6 60.4 296.6 53.3Q296.6 46.4 292.5 42.1Q288.3 37.8 282 37.8ZM369.7 37.6 358.7 43.7Q355.5 40.4 352.5 39.2Q349.4 37.9 345.3 37.9Q337.9 37.9 333.2 42.4Q328.6 46.8 328.6 53.8Q328.6 60.6 333.1 64.9Q337.5 69.2 344.7 69.2Q353.7 69.2 358.7 63.1L369.2 70.3Q360.6 81.4 345 81.4Q331 81.4 323.1 73.1Q315.1 64.8 315.1 53.6Q315.1 45.9 319 39.4Q322.9 32.9 329.8 29.2Q336.7 25.5 345.2 25.5Q353.1 25.5 359.4 28.7Q365.7 31.8 369.7 37.6ZM445.5 26.9H458.8V80H445.5V74.4Q441.6 78.1 437.7 79.7Q433.7 81.4 429.1 81.4Q418.8 81.4 411.3 73.4Q403.8 65.4 403.8 53.5Q403.8 41.2 411.1 33.4Q418.4 25.5 428.8 25.5Q433.5 25.5 437.7 27.3Q441.9 29.1 445.5 32.7ZM431.5 37.8Q425.3 37.8 421.2 42.2Q417.1 46.6 417.1 53.4Q417.1 60.3 421.3 64.7Q425.4 69.2 431.5 69.2Q437.8 69.2 442 64.8Q446.1 60.4 446.1 53.3Q446.1 46.4 442 42.1Q437.8 37.8 431.5 37.8ZM492.2 25.5Q499.7 25.5 506.4 29.3Q513 33 516.7 39.5Q520.4 45.9 520.4 53.4Q520.4 60.9 516.7 67.5Q512.9 74 506.5 77.7Q500 81.4 492.3 81.4Q480.8 81.4 472.8 73.2Q464.7 65.1 464.7 53.5Q464.7 41 473.8 32.7Q481.8 25.5 492.2 25.5ZM492.4 38.1Q486.2 38.1 482.1 42.4Q478 46.7 478 53.4Q478 60.4 482 64.7Q486.1 69 492.4 69Q498.6 69 502.8 64.6Q506.9 60.3 506.9 53.4Q506.9 46.6 502.8 42.3Q498.8 38.1 492.4 38.1Z"/>
                <circle fill="#f9b233" cx="385.01" cy="70" r="10"/>
            </svg>

            <p class="status">@yield('status')</p>
            <h1>@yield('title')</h1>
            <p class="lede">@yield('message')</p>

            <div class="actions">
                @yield('actions')
            </div>

            <p class="ref">facturac.ao — Emitida. Validada. Paga.</p>
        </main>
    </body>
</html>
