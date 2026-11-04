import assert from 'node:assert/strict';
import { test } from 'node:test';
import { connectionMessage } from '../resources/js/lib/connection.ts';
import {
    withEveryNotificationRead,
    withRecordInactive,
    withoutReadNotifications,
} from '../resources/js/lib/optimistic.ts';

const read = { url: '/documentos', writes: false };
const write = { url: '/documents/invoices', writes: true };

test('a connected screen says nothing', () => {
    assert.equal(connectionMessage(false, null), null);
});

test('being offline warns without offering anything to retry', () => {
    assert.equal(connectionMessage(true, null).canRetry, false);
});

test('a page that did not open can be tried again', () => {
    assert.equal(connectionMessage(false, read).canRetry, true);
    assert.equal(connectionMessage(true, read).canRetry, true);
});

test('a write is never offered a retry, online or offline', () => {
    for (const offline of [true, false]) {
        const message = connectionMessage(offline, write);

        assert.equal(message.canRetry, false);
        assert.match(message.detail, /confirme se ficou guardado/);
    }
});

test('deactivating changes only that record', () => {
    const list = {
        total: 2,
        data: [
            { public_id: 'a', is_active: true },
            { public_id: 'b', is_active: true },
        ],
    };
    const shown = withRecordInactive(list, 'b');

    assert.deepEqual(
        shown.data.map((record) => record.is_active),
        [true, false],
    );
    assert.equal(shown.total, 2);
    assert.equal(list.data[1].is_active, true);
});

test('notifications read and cleared without touching the rest of the list', () => {
    const list = {
        total: 2,
        data: [
            { id: 1, read: true },
            { id: 2, read: false },
        ],
    };

    assert.ok(withEveryNotificationRead(list).data.every((n) => n.read));
    assert.deepEqual(
        withoutReadNotifications(list).data.map((n) => n.id),
        [2],
    );
    assert.equal(withoutReadNotifications(list).total, 2);
});
