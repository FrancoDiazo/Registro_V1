import { beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import 'fake-indexeddb/auto';
import { OfflineQueue } from '../public/js/offline-queue.js';

let queue;
let testRun = 0;

beforeEach(() => {
    queue = new OfflineQueue(indexedDB, `asistencia-local-test-${++testRun}`);
});

function event(eventUuid) {
    return {
        event_uuid: eventUuid,
        event_type: 'ENTRY',
        occurred_at_device: '2026-06-01T09:00:00.000-04:00',
        client_timezone: 'America/Santiago',
        device_id: '8fc770c8-53bf-4ca0-a092-baa748b1b905',
        device_event_seq: 1,
        offline: true,
        local_status: 'pending',
        account_binding: 'a'.repeat(64),
    };
}

test('la marca permanece pendiente si falla la conectividad y se confirma al recuperar la red', async () => {
    await queue.save(event('b00d115f-6579-405a-a3dd-c1efb81c2575'));

    const failedDelivery = await queue.deliver(async () => {
        throw new TypeError('Failed to fetch');
    });
    assert.match(failedDelivery.error.message, /Failed to fetch/);
    assert.equal((await queue.all()).length, 1);

    const delivery = await queue.deliver(async (batch) => ({
        results: batch.map((item) => ({ event_uuid: item.event_uuid, status: 'accepted' })),
    }));
    assert.equal(delivery.confirmed.length, 1);
    assert.equal(delivery.remaining.length, 0);
});

test('no elimina eventos que el servidor no confirma', async () => {
    const pending = event('ee5ffcb6-5782-4cd0-9ae2-9e8871119c06');
    await queue.save(pending);

    const delivery = await queue.deliver(async () => ({
        results: [{ event_uuid: pending.event_uuid, status: 'error', errors: { event_type: ['Inválido'] } }],
    }));

    assert.equal(delivery.confirmed.length, 0);
    assert.equal(delivery.remaining.length, 1);
    assert.equal(delivery.remaining[0].event_uuid, pending.event_uuid);
});

test('ya recibido es una confirmación idempotente que permite retirar la copia local', async () => {
    const pending = event('9cced564-f751-4fae-aa69-ecf295f4da6f');
    await queue.save(pending);

    const delivery = await queue.deliver(async () => ({
        results: [{ event_uuid: pending.event_uuid, status: 'already_received' }],
    }));

    assert.equal(delivery.remaining.length, 0);
});

test('divide las marcas en lotes que no superan cincuenta eventos', async () => {
    const events = Array.from({ length: 51 }, (_, index) =>
        event(`00000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`));
    for (const pending of events) await queue.save(pending);
    const batchSizes = [];

    const delivery = await queue.deliver(async (batch) => {
        batchSizes.push(batch.length);
        return { results: batch.map((item) => ({ event_uuid: item.event_uuid, status: 'accepted' })) };
    });

    assert.deepEqual(batchSizes, [50, 1]);
    assert.equal(delivery.remaining.length, 0);
});
