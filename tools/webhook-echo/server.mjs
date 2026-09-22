// webhook-echo: a development receiver for Smart Helpdesk webhooks (docs/07-api/webhooks.md).
// Verifies each delivery with the documented Node snippet (signature and the 5-minute window),
// prints it, and answers 204 when it verifies, 401 when it does not. No dependencies. The handler and
// its test-control endpoints (per-path secrets, the delivery record) are in echo.mjs.
//
//   WEBHOOK_ECHO_SECRET  the subscription's secret as shown once by the API (not base64-decoded);
//                        without it deliveries are printed but answered 401
//   WEBHOOK_ECHO_PORT    listening port (default 9100)
//   WEBHOOK_ECHO_STATUS  force this response status for every delivery (e.g. 503 to watch retries)
import { createServer } from 'node:http';
import { createEcho } from './echo.mjs';

const secret = process.env.WEBHOOK_ECHO_SECRET ?? '';
const port = Number(process.env.WEBHOOK_ECHO_PORT ?? 9100);
const forced = Number(process.env.WEBHOOK_ECHO_STATUS ?? 0);

createServer(createEcho({ secret, forced })).listen(port, () => {
  console.log(`webhook-echo listening on :${port}${secret === '' ? ' (WEBHOOK_ECHO_SECRET not set: every delivery gets 401)' : ''}`);
});
