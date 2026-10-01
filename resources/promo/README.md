# Promotional film

`coming-soon.html` is the source of the launch video. It is deliberately **not**
in `public/`: it is a marketing asset, not part of the application, and it does
not need a URL of its own.

Nothing in it animates by itself. `window.frame(t)` positions every element for
the millisecond it is handed, and `render.mjs` steps through the timeline
calling it, saving one PNG per frame. That makes the render deterministic — a
busy machine cannot drop or duplicate a frame — which matters for a file that
goes out to customers.

## Regenerating

The page loads the brand typefaces from `/build/assets/...`, so it has to be
served from the application's own origin while rendering.

```bash
cp resources/promo/coming-soon.html public/__promo.html
```

Start Chrome with the debugging port open:

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --ignore-certificate-errors --remote-debugging-port=9222 --user-data-dir=/tmp/promo-profile about:blank &
```

Render the frames — the last argument is a body class, and `square` switches the
type scale and padding for the 1:1 cut:

```bash
node resources/promo/render.mjs https://vap-invoice.test/__promo.html /tmp/frames-v 1080 1920 30
```

Encode. H.264 High with `yuv420p` is the combination WhatsApp, Instagram and
Facebook all accept without re-encoding, and `+faststart` lets it preview before
the whole file has arrived:

```bash
ffmpeg -y -framerate 30 -i /tmp/frames-v/f%05d.png -c:v libx264 -profile:v high -level 4.0 -pix_fmt yuv420p -crf 20 -preset slow -movflags +faststart storage/app/promo/facturacao-em-breve-vertical.mp4
```

Then delete `public/__promo.html`.

## Editing the script

The timeline lives in the `T` object near the foot of the file, in milliseconds,
with `END` setting the running time. The cuts are quick on purpose: this is
watched on a phone, with the sound off, by someone whose thumb is already moving
towards the next status.

When there is a launch date or a price to announce, the outro is the place for
it — `#soon` currently reads "Em breve" and has room for a date beneath it.
