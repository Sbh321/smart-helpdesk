// The webhook-echo request handler (server.mjs starts it). Verifies each delivery with the documented
// Node snippet, prints it as one JSON line and answers 204 when it verifies, 401 when it does not.
//
// Control endpoints for automated tests (development only; the service is never deployed):
//   PUT /_echo/receivers/<name>   {"secret": "...", "status": 0}  deliveries to /hook/<name> are verified
//                                 with this secret and, when status is not 0, answered with that status
//   GET /_echo/deliveries?path=/hook/<name>   the last deliveries (newest last) as JSON, optionally
//                                 filtered by path; the same records that are printed
import { verify } from './verify.mjs';

const MAX_BODY = 512 * 1024;
const MAX_RECORDS = 500;
const RECEIVER_PATH = /^\/_echo\/receivers\/([A-Za-z0-9._-]{1,80})$/;

export function createEcho({ secret = '', forced = 0, log = console.log } = {}) {
  /** @type {Map<string, {secret: string, status: number}>} keyed by delivery path, e.g. /hook/e2e-1 */
  const receivers = new Map();
  const records = [];

  function settingsFor(path) {
    const receiver = receivers.get(path);
    return { secret: receiver?.secret || secret, status: receiver?.status || forced };
  }

  function check(rawBody, headers, key) {
    if (key === '') return { ok: false, reason: 'WEBHOOK_ECHO_SECRET is not set' };
    if (!headers['x-helpdesk-signature'] || !headers['x-helpdesk-timestamp']) return { ok: false, reason: 'missing signature headers' };
    try {
      return verify(rawBody, headers, key)
        ? { ok: true, reason: 'signature verified' }
        : { ok: false, reason: 'bad signature or timestamp outside 5 minutes' };
    } catch (error) {
      return { ok: false, reason: `verification error: ${error.message}` };
    }
  }

  function readBody(req, done) {
    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY) req.destroy();
      else chunks.push(chunk);
    });
    req.on('end', () => done(Buffer.concat(chunks).toString('utf8')));
  }

  function json(res, status, value) {
    res.writeHead(status, { 'content-type': 'application/json' }).end(`${JSON.stringify(value)}\n`);
  }

  return function handle(req, res) {
    const url = new URL(req.url ?? '/', 'http://webhook-echo');

    if (req.method === 'GET' && url.pathname === '/_echo/deliveries') {
      const path = url.searchParams.get('path');
      json(res, 200, { data: path ? records.filter((record) => record.path === path) : records });
      return;
    }

    const receiver = url.pathname.match(RECEIVER_PATH);
    if (receiver && req.method === 'PUT') {
      readBody(req, (raw) => {
        let body;
        try {
          body = JSON.parse(raw || '{}');
        } catch {
          json(res, 400, { error: 'body must be JSON' });
          return;
        }
        receivers.set(`/hook/${receiver[1]}`, { secret: String(body.secret ?? ''), status: Number(body.status ?? 0) || 0 });
        json(res, 200, { path: `/hook/${receiver[1]}` });
      });
      return;
    }

    if (req.method === 'GET') {
      res.writeHead(200, { 'content-type': 'text/plain' }).end('webhook-echo: POST signed deliveries here\n');
      return;
    }

    readBody(req, (rawBody) => {
      const settings = settingsFor(url.pathname);
      const result = check(rawBody, req.headers, settings.secret);
      const status = settings.status || (result.ok ? 204 : 401);
      const age = Math.round(Date.now() / 1000 - Number(req.headers['x-helpdesk-timestamp'] ?? 0));
      const record = {
        received_at: new Date().toISOString(),
        verified: result.ok,
        reason: result.reason,
        answered: status,
        path: url.pathname,
        event_type: req.headers['x-helpdesk-event-type'],
        event_id: req.headers['x-helpdesk-event-id'],
        delivery_id: req.headers['x-helpdesk-delivery-id'],
        timestamp: req.headers['x-helpdesk-timestamp'],
        timestamp_age_seconds: age,
        signature: req.headers['x-helpdesk-signature'],
        user_agent: req.headers['user-agent'],
        body: rawBody,
      };
      records.push(record);
      if (records.length > MAX_RECORDS) records.shift();
      log(JSON.stringify(record));

      res.writeHead(status, { 'content-type': 'text/plain' }).end(status === 401 ? `${result.reason}\n` : '');
    });
  };
}
