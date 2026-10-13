import assert from 'node:assert/strict';
import { test } from 'node:test';
import { printDocument } from '../resources/js/lib/print.ts';

function printEnvironment(t, decode) {
    const events = [];
    const globals = {
        document: {
            fonts: {
                ready: Promise.resolve().then(() => events.push('fonts')),
            },
            querySelectorAll: () => [
                {
                    getAttribute: (name) =>
                        name === 'href' ? 'data:image/png;base64,agt' : null,
                },
                {
                    getAttribute: (name) =>
                        name === 'src' ? '/company.png' : null,
                },
            ],
        },
        Image: class {
            async decode() {
                events.push(this.src);
                await decode(this.src);
            }
        },
        requestAnimationFrame: (callback) => {
            events.push('frame');
            callback();
        },
        window: {
            print: () => events.push('print'),
            alert: (message) => events.push(message),
        },
    };

    for (const [key, value] of Object.entries(globals)) {
        const original = Object.getOwnPropertyDescriptor(globalThis, key);
        Object.defineProperty(globalThis, key, { configurable: true, value });
        t.after(() => {
            if (original) {
                Object.defineProperty(globalThis, key, original);
            } else {
                delete globalThis[key];
            }
        });
    }

    return events;
}

test('printing waits for fonts, the embedded AGT logo and a rendered frame', async (t) => {
    let finishLogo;
    const logoReady = new Promise((resolve) => {
        finishLogo = resolve;
    });
    const events = printEnvironment(t, (source) =>
        source.startsWith('data:') ? logoReady : Promise.resolve(),
    );
    const printing = printDocument();
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(events, [
        'fonts',
        'data:image/png;base64,agt',
        '/company.png',
    ]);
    finishLogo();
    await printing;
    assert.deepEqual(events.slice(-2), ['frame', 'print']);
});

test('a failed logo prevents an incomplete print and explains how to retry', async (t) => {
    const events = printEnvironment(t, () =>
        Promise.reject(new Error('decode failed')),
    );
    await printDocument();
    assert.equal(events.includes('print'), false);
    assert.match(events.at(-1), /Recarregue a página/);
});
