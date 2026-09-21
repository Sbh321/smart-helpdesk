// `node --test tools/webhook-echo`: the documented Node snippet against known signatures.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { verify } from './verify.mjs';

const body = '{"id":"019a1f2f-5b7c-7e1d-8c3a-1a2b3c4d5e6f","type":"ping","data":{"message":"hi"}}';
const sign = (secret, ts, raw = body) => createHmac('sha256', secret).update(`${ts}.${raw}`).digest('hex');
const now = () => Math.floor(Date.now() / 1000);

test('matches the test vector shared with the PHP signer tests', () => {
  assert.equal(sign('whsec', 1700000000, '{}'), '7d44587dddbaf4c7f70fef20f48cd594834ffea1641e3ac227b84408298738af');
});

test('accepts a fresh delivery, also with two signatures during a rotation', () => {
  const ts = now();
  assert.equal(verify(body, { 'x-helpdesk-timestamp': String(ts), 'x-helpdesk-signature': `v1=${sign('s3cret', ts)}` }, 's3cret'), true);
  const both = `v1=${sign('new', ts)},v1=${sign('old', ts)}`;
  assert.equal(verify(body, { 'x-helpdesk-timestamp': String(ts), 'x-helpdesk-signature': both }, 'old'), true);
});

test('rejects a wrong secret and a tampered body', () => {
  const ts = now();
  const headers = { 'x-helpdesk-timestamp': String(ts), 'x-helpdesk-signature': `v1=${sign('s3cret', ts)}` };
  assert.equal(verify(body, headers, 'wrong'), false);
  assert.equal(verify(`${body} `, headers, 's3cret'), false);
});

test('rejects a replay outside the five-minute window', () => {
  const ts = now() - 301;
  assert.equal(verify(body, { 'x-helpdesk-timestamp': String(ts), 'x-helpdesk-signature': `v1=${sign('s3cret', ts)}` }, 's3cret'), false);
});
