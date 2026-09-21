// webhook-echo: a development receiver for Smart Helpdesk webhooks (docs/07-api/webhooks.md).
// Verifies each delivery with the documented Node snippet (signature and the 5-minute window),
// prints it, and answers 204 when it verifies, 401 when it does not. No dependencies.
//
//   WEBHOOK_ECHO_SECRET  the subscription's secret as shown once by the API (not base64-decoded);
//                        without it deliveries are printed but answered 401
//   WEBHOOK_ECHO_PORT    listening port (default 9100)
//   WEBHOOK_ECHO_STATUS  force this response status for every delivery (e.g. 503 to watch retries)
import { createServer } from 'node:http';
import { verify } from './verify.mjs';

const secret = process.env.WEBHOOK_ECHO_SECRET ?? '';
const port = Number(process.env.WEBHOOK_ECHO_PORT ?? 9100);
const forced = Number(process.env.WEBHOOK_ECHO_STATUS ?? 0);
const MAX_BODY = 512 * 1024;

function check(rawBody, headers) {
  if (secret === '') return { ok: false, reason: 'WEBHOOK_ECHO_SECRET is not set' };
  if (!headers['x-helpdesk-signature'] || !headers['x-helpdesk-timestamp']) return { ok: false, reason: 'missing signature headers' };
  try {
    return verify(rawBody, headers, secret)
      ? { ok: true, reason: 'signature verified' }
      : { ok: false, reason: 'bad signature or timestamp outside 5 minutes' };
  } catch (error) {
    return { ok: false, reason: `verification error: ${error.message}` };
  }
}

const server = createServer((req, res) => {
  if (req.method === 'GET') {
    res.writeHead(200, { 'content-type': 'text/plain' }).end('webhook-echo: POST signed deliveries here\n');
    return;
  }

  const chunks = [];
  let size = 0;
  req.on('data', (chunk) => {
    size += chunk.length;
    if (size > MAX_BODY) req.destroy();
    else chunks.push(chunk);
  });
  req.on('end', () => {
    const rawBody = Buffer.concat(chunks).toString('utf8');
    const result = check(rawBody, req.headers);
    const status = forced || (result.ok ? 204 : 401);
    const age = Math.round(Date.now() / 1000 - Number(req.headers['x-helpdesk-timestamp'] ?? 0));

    console.log(JSON.stringify({
      received_at: new Date().toISOString(),
      verified: result.ok,
      reason: result.reason,
      answered: status,
      path: req.url,
      event_type: req.headers['x-helpdesk-event-type'],
      event_id: req.headers['x-helpdesk-event-id'],
      delivery_id: req.headers['x-helpdesk-delivery-id'],
      timestamp: req.headers['x-helpdesk-timestamp'],
      timestamp_age_seconds: age,
      signature: req.headers['x-helpdesk-signature'],
      user_agent: req.headers['user-agent'],
      body: rawBody,
    }));

    res.writeHead(status, { 'content-type': 'text/plain' }).end(status === 401 ? `${result.reason}\n` : '');
  });
});

server.listen(port, () => {
  console.log(`webhook-echo listening on :${port}${secret === '' ? ' (WEBHOOK_ECHO_SECRET not set: every delivery gets 401)' : ''}`);
});
