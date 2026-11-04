// Captures a page of the local site at iPhone 17 size (402 x 874 points, 3x), as a
// visitor who has already answered the cookie banner (essential cookies only).
//
//   node tools/capture_site.mjs <url> <out.png> [height-in-points]
//
// Drives Playwright's cached headless shell over the DevTools protocol, so no
// package is needed. The cookie is the site's own plain JSON consent cookie.
import { spawn } from 'node:child_process';
import { readdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const [url, out, tall = '874'] = process.argv.slice(2);
const cache = join(homedir(), 'Library/Caches/ms-playwright');
const shellDir = readdirSync(cache).filter((d) => d.startsWith('chromium_headless_shell-')).sort().pop();
const shell = join(cache, shellDir, 'chrome-headless-shell-mac-arm64/chrome-headless-shell');
const port = 9333;

const browser = spawn(shell, ['--headless', `--remote-debugging-port=${port}`, '--ignore-certificate-errors', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

try {
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(100);
    target = await fetch(`http://127.0.0.1:${port}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === 'page')).catch(() => undefined);
  }
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }));
  let id = 0;
  const pending = new Map();
  ws.addEventListener('message', (event) => {
    const msg = JSON.parse(event.data);
    if (msg.id && pending.has(msg.id)) {
      pending.get(msg.id)(msg);
      pending.delete(msg.id);
    }
  });
  const send = (method, params = {}) => new Promise((resolve) => {
    id += 1;
    pending.set(id, resolve);
    ws.send(JSON.stringify({ id, method, params }));
  });

  const { hostname } = new URL(url);
  await send('Network.enable');
  await send('Network.setCookie', { name: 'cookie_consent', value: JSON.stringify({ version: 1, choices: {} }), domain: hostname, path: '/' });
  await send('Emulation.setDeviceMetricsOverride', { width: 402, height: Number(tall), deviceScaleFactor: 3, mobile: true });
  await send('Page.enable');
  await send('Page.navigate', { url });
  await sleep(5000);
  const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  writeFileSync(out, Buffer.from(shot.result.data, 'base64'));
  ws.close();
  console.log(`captured ${url} -> ${out}`);
} finally {
  browser.kill();
}
