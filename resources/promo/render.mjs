/**
 * Renders the promo one frame at a time and writes them as PNGs.
 *
 * Steps the page's own `frame(t)` rather than recording a running animation, so
 * a slow capture cannot drop or duplicate anything — frame 137 is always the
 * same pixels. Slower than a screen recording and worth it: this is the file
 * that goes out to customers.
 *
 * Usage: node render.mjs <url> <outdir> <width> <height> <fps> [bodyClass]
 */

import { writeFileSync, mkdirSync } from 'node:fs';

const [, , url, outdir, wArg, hArg, fpsArg, bodyClass] = process.argv;
const width = Number(wArg);
const height = Number(hArg);
const fps = Number(fpsArg);
const PORT = Number(process.env.CDP_PORT ?? 9222);

mkdirSync(outdir, { recursive: true });

const targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
const socket = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);

let nextId = 1;
const pending = new Map();
const events = new Map();

socket.addEventListener('message', (message) => {
    const frame = JSON.parse(message.data);
    if (frame.id !== undefined) {
        pending.get(frame.id)?.(frame.result ?? {});
        pending.delete(frame.id);
        return;
    }
    events.get(frame.method)?.forEach((s) => s(frame.params));
    events.delete(frame.method);
});

await new Promise((s) => socket.addEventListener('open', s));

const send = (method, params = {}) =>
    new Promise((s) => {
        const id = nextId++;
        pending.set(id, s);
        socket.send(JSON.stringify({ id, method, params }));
    });

const once = (m) => new Promise((s) => events.set(m, [...(events.get(m) ?? []), s]));
const pause = (ms) => new Promise((s) => setTimeout(s, ms));

await send('Page.enable');
await send('Runtime.enable');
await send('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile: false,
});

const loaded = once('Page.loadEventFired');
await send('Page.navigate', { url });
await loaded;

/* Fonts must be in before the first frame or the opening titles render in a
   fallback face and the whole thing looks like a different brand. */
await send('Runtime.evaluate', { awaitPromise: true, expression: 'document.fonts.ready' });
await pause(400);

if (bodyClass) {
    await send('Runtime.evaluate', { expression: `document.body.className = ${JSON.stringify(bodyClass)}` });
    await pause(120);
}

const { result } = await send('Runtime.evaluate', { returnByValue: true, expression: 'window.VIDEO_END' });
const end = result.value;
const total = Math.round((end / 1000) * fps);

process.stdout.write(`${total} frames at ${fps}fps → ${outdir}\n`);

for (let i = 0; i < total; i++) {
    const t = Math.round((i / fps) * 1000);
    await send('Runtime.evaluate', { expression: `window.frame(${t})` });

    const shot = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
    writeFileSync(`${outdir}/f${String(i).padStart(5, '0')}.png`, Buffer.from(shot.data, 'base64'));

    if (i % 50 === 0) process.stdout.write(`  ${i}/${total}\n`);
}

process.stdout.write('done\n');
socket.close();
