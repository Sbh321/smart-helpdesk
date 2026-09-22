// `node --test` in tools/webhook-echo: the echo handler and its test-control endpoints on a real port.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { createServer } from 'node:http';
import { createEcho } from './echo.mjs';

const sign = (secret, ts, raw) => createHmac('sha256', secret).update(`${ts}.${raw}`).digest('hex');

async function withEcho(options, run) {
  const server = createServer(createEcho({ log: () => {}, ...options }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  try {
    await run(base);
  } finally {
    server.close();
  }
}

function deliver(base, path, secret, body = '{"type":"ping"}') {
  const ts = Math.floor(Date.now() / 1000);
  return fetch(`${base}${path}`, {
    method: 'POST',
    headers: { 'x-helpdesk-timestamp': String(ts), 'x-helpdesk-signature': `v1=${sign(secret, ts, body)}`, 'x-helpdesk-event-type': 'ping' },
    body,
  });
}

test('verifies with the default secret and records the delivery', async () => {
  await withEcho({ secret: 'default' }, async (base) => {
    assert.equal((await deliver(base, '/hook', 'default')).status, 204);
    assert.equal((await deliver(base, '/hook', 'wrong')).status, 401);
    const { data } = await (await fetch(`${base}/_echo/deliveries?path=/hook`)).json();
    assert.deepEqual(data.map((record) => [record.verified, record.answered, record.event_type]), [[true, 204, 'ping'], [false, 401, 'ping']]);
  });
});

test('a registered receiver uses its own secret and forced status', async () => {
  await withEcho({ secret: 'default' }, async (base) => {
    const put = (status) => fetch(`${base}/_echo/receivers/e2e-1`, { method: 'PUT', body: JSON.stringify({ secret: 'own', status }) });
    assert.equal((await put(0)).status, 200);
    assert.equal((await deliver(base, '/hook/e2e-1', 'own')).status, 204);
    assert.equal((await deliver(base, '/hook/e2e-1', 'default')).status, 401);
    await put(500);
    assert.equal((await deliver(base, '/hook/e2e-1', 'own')).status, 500);
    const { data } = await (await fetch(`${base}/_echo/deliveries?path=/hook/e2e-1`)).json();
    assert.deepEqual(data.map((record) => [record.verified, record.answered]), [[true, 204], [false, 401], [true, 500]]);
    assert.equal((await deliver(base, '/hook', 'default')).status, 204, 'other paths keep the default secret');
  });
});

test('rejects a receiver body that is not JSON', async () => {
  await withEcho({}, async (base) => {
    assert.equal((await fetch(`${base}/_echo/receivers/x`, { method: 'PUT', body: 'nope' })).status, 400);
  });
});
