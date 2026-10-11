{{--
    Shared shell for every error status.

    Deliberately self-contained: no @vite, no web fonts, no compiled asset of
    any kind, no network. A 500 can be caused by a missing Vite manifest or a
    broken build, and an error page that depends on the thing that just broke
    is no error page at all. It also never queries: the database may be what
    failed. The type uses the same stacks as the app and falls back to the
    system faces when the brand fonts are not installed.

    A status view fills these sections:
      status      the eyebrow, "Erro 404 · Não encontrado"
      stamp       the brand device, "direcc.ao": the full stop becomes the gold dot
      word        the real spelling of the stamp, "Direcção", read once by screen readers
      stampwidth  the stamp's width in em in the widest face (Century Gothic), so
                  the size can be fitted to the column
      title       the h1, also the document title
      message     the sentence under it
      actions     the pills, built with the .pill classes below
    and may switch on, by defining them: retry (429/503: say when to try again),
    reference (500: time and request id), refresh (503: reload by itself when a
    retry interval is known).
--}}
@use('Illuminate\Support\Facades\Context')
@php
    /*
     * Everything below is read defensively. This view renders while something
     * is already wrong, so it must never be the second thing to throw.
     */
    $retrySeconds = null;

    try {
        if (isset($exception) && method_exists($exception, 'getHeaders')) {
            $headers = array_change_key_case((array) $exception->getHeaders(), CASE_LOWER);
            $retryAfter = $headers['retry-after'] ?? null;

            if (is_string($retryAfter) || is_int($retryAfter)) {
                $retryAfter = trim((string) $retryAfter);

                if (ctype_digit($retryAfter)) {
                    $retrySeconds = (int) $retryAfter;
                } elseif (preg_match('/^[A-Za-z]{3}, \d{1,2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $retryAfter) === 1 && ($retryAt = strtotime($retryAfter)) !== false) {
                    $retrySeconds = $retryAt - time();
                }
            }
        }
    } catch (\Throwable $e) {
        $retrySeconds = null;
    }

    $retrySeconds = $retrySeconds !== null && $retrySeconds > 0 ? min($retrySeconds, 86400) : null;

    $retryText = null;

    if ($retrySeconds !== null) {
        if ($retrySeconds < 60) {
            $retryText = $retrySeconds === 1 ? '1 segundo' : "{$retrySeconds} segundos";
        } elseif ($retrySeconds < 3600) {
            $minutes = (int) ceil($retrySeconds / 60);
            $retryText = $minutes === 1 ? '1 minuto' : "{$minutes} minutos";
        } else {
            $hours = (int) ceil($retrySeconds / 3600);
            $retryText = $hours === 1 ? '1 hora' : "{$hours} horas";
        }
    }

    $reference = null;

    if (View::hasSection('reference')) {
        $requestId = null;

        try {
            $candidate = Context::get('request_id');
            $requestId = is_string($candidate) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $candidate) === 1 ? $candidate : null;
        } catch (\Throwable $e) {
            $requestId = null;
        }

        try {
            $referenceTime = now('Africa/Luanda')->format('Y-m-d H:i:s').' (Luanda)';
        } catch (\Throwable $e) {
            $referenceTime = gmdate('Y-m-d H:i:s').' (UTC)';
        }

        $reference = ['time' => $referenceTime, 'id' => $requestId];
    }

    // The address printed here is a configured default; it needs no query.
    $supportEmail = null;

    if (View::hasSection('support')) {
        try {
            $configured = config('platform.settings.support_email');
            $supportEmail = is_string($configured) && filter_var($configured, FILTER_VALIDATE_EMAIL) ? $configured : null;
        } catch (\Throwable $e) {
            $supportEmail = null;
        }
    }

    $supportHref = null;

    if ($supportEmail !== null) {
        $subject = 'Ajuda com um erro — '.trim(View::yieldContent('status'));
        $body = $reference === null
            ? ''
            : 'Referência: '.$reference['time'].($reference['id'] !== null ? ' · '.$reference['id'] : '');
        $supportHref = 'mailto:'.$supportEmail.'?subject='.rawurlencode($subject).($body !== '' ? '&body='.rawurlencode($body) : '');
    }

    [$stampLead, $stampTail] = array_pad(explode('.', trim(View::yieldContent('stamp')), 2), 2, '');
@endphp
<!DOCTYPE html>
<html lang="pt-AO">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#faf9f6" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#09090b" media="(prefers-color-scheme: dark)">
        @if (View::hasSection('refresh') && $retrySeconds !== null)
            <meta http-equiv="refresh" content="{{ max(5, $retrySeconds) }}">
        @endif
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <title>@yield('title') — facturac.ao</title>
        <style>
            /* The app's own tokens: page ground, zinc ink, brand grey, gold. */
            :root {
                color-scheme: light;
                --ground: #faf9f6;
                --ink: #18181b;
                --muted: #52525b;
                --rule: rgba(24, 24, 27, 0.12);
                --well: rgba(24, 24, 27, 0.04);
                --mark: #575756;
                --gold: #f9b233;
                --gold-ink: #91410e;
                --pill: #171716;
                --pill-hover: #333332;
                --pill-ink: #fff;
                --focus: #575756;
                --sans: 'Hanken Grotesk', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
                --display: 'Century Gothic', 'CenturyGothic', 'Didact Gothic', ui-sans-serif, system-ui, sans-serif;
                --mono: 'IBM Plex Mono', ui-monospace, 'SF Mono', SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
            }

            @media (prefers-color-scheme: dark) {
                :root:not([data-theme='light']) {
                    color-scheme: dark;
                    --ground: #09090b;
                    --ink: #f4f4f5;
                    --muted: #a1a1aa;
                    --rule: rgba(255, 255, 255, 0.14);
                    --well: rgba(255, 255, 255, 0.04);
                    --mark: #e4e4e7;
                    --gold-ink: #fbc94d;
                    --pill: #f4f4f5;
                    --pill-hover: #fff;
                    --pill-ink: #171716;
                    --focus: #d9d9d5;
                }
            }

            /* Set by the inline script below from the app's own preference. */
            :root[data-theme='dark'] {
                color-scheme: dark;
                --ground: #09090b;
                --ink: #f4f4f5;
                --muted: #a1a1aa;
                --rule: rgba(255, 255, 255, 0.14);
                --well: rgba(255, 255, 255, 0.04);
                --mark: #e4e4e7;
                --gold-ink: #fbc94d;
                --pill: #f4f4f5;
                --pill-hover: #fff;
                --pill-ink: #171716;
                --focus: #d9d9d5;
            }

            * { box-sizing: border-box; }

            html {
                background: var(--ground);
                -webkit-text-size-adjust: 100%;
                text-size-adjust: 100%;
                overflow-wrap: break-word;
            }

            body {
                margin: 0;
                min-block-size: 100vh;
                min-block-size: 100dvh;
                display: grid;
                justify-items: center;
                align-items: center;
                padding-block: max(1.5rem, env(safe-area-inset-top)) max(1.5rem, env(safe-area-inset-bottom));
                padding-inline: max(1.25rem, env(safe-area-inset-left)) max(1.25rem, env(safe-area-inset-right));
                background: var(--ground);
                color: var(--ink);
                font-family: var(--sans);
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
            }

            main {
                inline-size: 100%;
                max-inline-size: 36rem;
                min-inline-size: 0;
                container-type: inline-size;
            }

            .home {
                display: inline-flex;
                align-items: center;
                min-block-size: 2.75rem;
                color: var(--mark);
                border-radius: 0.5rem;
            }

            .mark { display: block; inline-size: auto; block-size: 1.5rem; }

            .status {
                margin: 2.5rem 0 0;
                font-family: var(--mono);
                font-size: 0.75rem;
                line-height: 1.4;
                font-weight: 500;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--gold-ink);
            }

            /*
             * The brand device: "-ção" written "c.ao" with a gold full stop,
             * set large like the landing page's stamps. Sized from the column
             * so the widest word still fits at 320px; nowrap, so it never
             * breaks, and the fit is what keeps it from overflowing.
             */
            .stamp {
                --w: {{ (float) trim(View::yieldContent('stampwidth')) ?: 7 }};
                margin: 0.5rem 0 0;
                font-family: var(--display);
                font-weight: 400;
                font-size: min(6.5rem, calc((100vw - 2.5rem) / var(--w)));
                font-size: min(6.5rem, calc(100cqi / var(--w)));
                line-height: 0.9;
                letter-spacing: -0.04em;
                white-space: nowrap;
                color: var(--ink);
            }

            .dot {
                display: inline-block;
                inline-size: 0.19em;
                block-size: 0.19em;
                margin-inline: 0.065em 0.05em;
                border-radius: 9999px;
                background: var(--gold);
                vertical-align: baseline;
            }

            .sr-only {
                position: absolute;
                inline-size: 1px;
                block-size: 1px;
                margin: -1px;
                padding: 0;
                overflow: hidden;
                clip-path: inset(50%);
                white-space: nowrap;
                border: 0;
            }

            h1 {
                margin: 1.5rem 0 0;
                font-family: var(--display);
                font-weight: 400;
                font-size: clamp(1.5rem, 1.25rem + 1.2vw, 2rem);
                line-height: 1.2;
                letter-spacing: -0.02em;
                text-wrap: balance;
            }

            .lede {
                margin: 1rem 0 0;
                max-inline-size: 32rem;
                color: var(--muted);
                font-size: 1rem;
                text-wrap: pretty;
            }

            .note {
                margin: 0.75rem 0 0;
                max-inline-size: 32rem;
                color: var(--ink);
                font-size: 0.9375rem;
                font-weight: 500;
                text-wrap: pretty;
            }

            .ref {
                margin: 1.5rem 0 0;
                padding: 0.875rem 1rem;
                border-radius: 1rem;
                background: var(--well);
                font-size: 0.75rem;
            }

            .ref dt {
                margin: 0;
                color: var(--muted);
                font-weight: 500;
                letter-spacing: 0.07em;
                text-transform: uppercase;
            }

            .ref dd {
                margin: 0.125rem 0 0.625rem;
                color: var(--ink);
                font-family: var(--mono);
                font-size: 0.8125rem;
                overflow-wrap: anywhere;
                user-select: all;
            }

            .ref dd:last-child { margin-block-end: 0; }

            .actions {
                margin-top: 2rem;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.75rem;
            }

            .pill {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-block-size: 2.75rem;
                padding-inline: 1.25rem;
                border-radius: 9999px;
                font-family: var(--sans);
                font-size: 0.9375rem;
                font-weight: 600;
                line-height: 1.2;
                text-align: center;
                text-decoration: none;
                white-space: nowrap;
                touch-action: manipulation;
            }

            .pill--ink { background: var(--pill); color: var(--pill-ink); }

            .pill--outline {
                background: transparent;
                color: var(--ink);
                box-shadow: inset 0 0 0 1px var(--rule);
            }

            .link {
                display: inline-flex;
                align-items: center;
                min-block-size: 2.75rem;
                padding-inline: 0.5rem;
                border-radius: 0.5rem;
                color: var(--ink);
                font-size: 0.9375rem;
                font-weight: 500;
                text-decoration: underline;
                text-decoration-color: var(--rule);
                text-underline-offset: 0.25em;
            }

            a:focus-visible {
                outline: 2px solid var(--focus);
                outline-offset: 2px;
            }

            .pill:active { transform: scale(0.97); }

            @media (hover: hover) and (pointer: fine) {
                .pill--ink:hover { background: var(--pill-hover); }
                .pill--outline:hover { background: var(--well); }
                .link:hover { text-decoration-color: currentColor; }
            }

            @media (prefers-reduced-motion: no-preference) {
                .pill { transition: background-color 100ms ease-out, transform 100ms ease-out; }
            }

            @media (max-width: 30rem) {
                .pill { flex: 1 1 100%; }
                .link { flex: 1 1 100%; justify-content: center; }
            }

            .foot {
                margin: 3rem 0 0;
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
        <main>
            {{--
                The wordmark, inline and as outlines: this page may not load the
                build, so the logo cannot be an asset or depend on a font. Same
                paths as BrandWordmark.vue. The home link needs no session.
            --}}
            <a class="home" href="/">
                <svg class="mark" viewBox="-1.6 3 524 80.4" role="img" aria-label="facturac.ao">
                <path fill="currentColor" d="M0.4 26.9H5.1Q5.2 16.4 5.5 14.5Q6 10.1 9.2 7.6Q12.3 5 18.1 5Q22.2 5 27.4 6.9V17.1Q24.6 16.2 22.7 16.2Q20.4 16.2 19.3 17.2Q18.5 17.9 18.5 20.2L18.5 26.9H26.9V38.2H18.5V80H5.1V38.2H0.4ZM69 26.9H82.3V80H69V74.4Q65.1 78.1 61.1 79.7Q57.2 81.4 52.6 81.4Q42.3 81.4 34.8 73.4Q27.3 65.4 27.3 53.5Q27.3 41.2 34.6 33.4Q41.8 25.5 52.2 25.5Q57 25.5 61.2 27.3Q65.4 29.1 69 32.7ZM55 37.8Q48.8 37.8 44.7 42.2Q40.6 46.6 40.6 53.4Q40.6 60.3 44.7 64.7Q48.9 69.2 55 69.2Q61.3 69.2 65.5 64.8Q69.6 60.4 69.6 53.3Q69.6 46.4 65.5 42.1Q61.3 37.8 55 37.8ZM142.7 37.6 131.7 43.7Q128.5 40.4 125.5 39.2Q122.4 37.9 118.3 37.9Q110.8 37.9 106.2 42.4Q101.6 46.8 101.6 53.8Q101.6 60.6 106.1 64.9Q110.5 69.2 117.7 69.2Q126.7 69.2 131.7 63.1L142.1 70.3Q133.6 81.4 118 81.4Q104 81.4 96.1 73.1Q88.1 64.8 88.1 53.6Q88.1 45.9 92 39.4Q95.9 32.9 102.8 29.2Q109.7 25.5 118.2 25.5Q126.1 25.5 132.4 28.7Q138.7 31.8 142.7 37.6ZM151.4 7.3H164.6V26.9H172.5V38.3H164.6V80H151.4V38.3H144.5V26.9H151.4ZM174.7 26.9H188.2V52.5Q188.2 59.9 189.2 62.8Q190.2 65.7 192.5 67.4Q194.7 69 198.1 69Q201.4 69 203.7 67.4Q206 65.8 207.1 62.7Q208 60.4 208 52.9V26.9H221.4V49.4Q221.4 63.3 219.2 68.4Q216.5 74.7 211.2 78Q206 81.4 198 81.4Q189.2 81.4 183.8 77.5Q178.4 73.6 176.2 66.6Q174.7 61.7 174.7 49ZM226.6 26.9H238V33.6Q239.9 29.6 243 27.6Q246 25.5 249.7 25.5Q252.3 25.5 255.1 26.9L251 38.3Q248.6 37.2 247.1 37.2Q244 37.2 241.9 41Q239.8 44.8 239.8 55.9L239.8 58.5V80H226.6ZM296 26.9H309.3V80H296V74.4Q292.1 78.1 288.1 79.7Q284.2 81.4 279.6 81.4Q269.3 81.4 261.8 73.4Q254.3 65.4 254.3 53.5Q254.3 41.2 261.6 33.4Q268.8 25.5 279.2 25.5Q284 25.5 288.2 27.3Q292.4 29.1 296 32.7ZM282 37.8Q275.8 37.8 271.7 42.2Q267.6 46.6 267.6 53.4Q267.6 60.3 271.7 64.7Q275.9 69.2 282 69.2Q288.3 69.2 292.5 64.8Q296.6 60.4 296.6 53.3Q296.6 46.4 292.5 42.1Q288.3 37.8 282 37.8ZM369.7 37.6 358.7 43.7Q355.5 40.4 352.5 39.2Q349.4 37.9 345.3 37.9Q337.9 37.9 333.2 42.4Q328.6 46.8 328.6 53.8Q328.6 60.6 333.1 64.9Q337.5 69.2 344.7 69.2Q353.7 69.2 358.7 63.1L369.2 70.3Q360.6 81.4 345 81.4Q331 81.4 323.1 73.1Q315.1 64.8 315.1 53.6Q315.1 45.9 319 39.4Q322.9 32.9 329.8 29.2Q336.7 25.5 345.2 25.5Q353.1 25.5 359.4 28.7Q365.7 31.8 369.7 37.6ZM445.5 26.9H458.8V80H445.5V74.4Q441.6 78.1 437.7 79.7Q433.7 81.4 429.1 81.4Q418.8 81.4 411.3 73.4Q403.8 65.4 403.8 53.5Q403.8 41.2 411.1 33.4Q418.4 25.5 428.8 25.5Q433.5 25.5 437.7 27.3Q441.9 29.1 445.5 32.7ZM431.5 37.8Q425.3 37.8 421.2 42.2Q417.1 46.6 417.1 53.4Q417.1 60.3 421.3 64.7Q425.4 69.2 431.5 69.2Q437.8 69.2 442 64.8Q446.1 60.4 446.1 53.3Q446.1 46.4 442 42.1Q437.8 37.8 431.5 37.8ZM492.2 25.5Q499.7 25.5 506.4 29.3Q513 33 516.7 39.5Q520.4 45.9 520.4 53.4Q520.4 60.9 516.7 67.5Q512.9 74 506.5 77.7Q500 81.4 492.3 81.4Q480.8 81.4 472.8 73.2Q464.7 65.1 464.7 53.5Q464.7 41 473.8 32.7Q481.8 25.5 492.2 25.5ZM492.4 38.1Q486.2 38.1 482.1 42.4Q478 46.7 478 53.4Q478 60.4 482 64.7Q486.1 69 492.4 69Q498.6 69 502.8 64.6Q506.9 60.3 506.9 53.4Q506.9 46.6 502.8 42.3Q498.8 38.1 492.4 38.1Z"/>
                <circle fill="#f9b233" cx="385.01" cy="70" r="10"/>
            </svg>
            </a>

            <p class="status">@yield('status')</p>
            <p class="stamp"><span aria-hidden="true">{{ $stampLead }}<span class="dot"></span>{{ $stampTail }}</span><span class="sr-only">@yield('word')</span></p>
            <h1>@yield('title')</h1>
            <p class="lede">@yield('message')</p>

            @if (View::hasSection('retry') && $retryText !== null)
                <p class="note">Volte a tentar dentro de {{ $retryText }}.</p>
            @endif

            @if ($reference !== null)
                <dl class="ref">
                    <dt>Referência para o apoio</dt>
                    <dd>{{ $reference['time'] }}</dd>
                    @if ($reference['id'] !== null)
                        <dd>{{ $reference['id'] }}</dd>
                    @endif
                </dl>
            @endif

            <div class="actions">
                @yield('actions')

                @if ($supportHref !== null)
                    <a class="link" href="{{ $supportHref }}">Escrever ao apoio</a>
                @endif
            </div>

            <p class="foot">facturac.ao — Emitida. Validada. Paga.</p>
        </main>
        <script>
            // «Voltar atrás» is a plain link to the start page. With history
            // behind it, it goes back instead; without script it still works.
            (function () {
                var back = document.querySelector('[data-back]');

                if (back && window.history.length > 1) {
                    back.addEventListener('click', function (event) {
                        event.preventDefault();
                        window.history.back();
                    });
                }
            })();
        </script>
    </body>
</html>
