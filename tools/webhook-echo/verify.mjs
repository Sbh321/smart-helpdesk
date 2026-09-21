// Receiver verification, copied verbatim from docs/07-api/webhooks.md §Signing ("Receiver
// verification (Node)"). Keep the two in sync: the echo service and its tests run this exact code.
import { createHmac, timingSafeEqual } from 'node:crypto';
export function verify(rawBody, headers, secret, toleranceSec = 300) {
  const ts = Number(headers['x-helpdesk-timestamp']);
  if (!ts || Math.abs(Date.now() / 1000 - ts) > toleranceSec) return false;
  const expected = createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');
  return headers['x-helpdesk-signature'].split(',').some(part => {
    const sig = part.trim().replace(/^v1=/, '');
    return sig.length === expected.length && timingSafeEqual(Buffer.from(sig, 'hex'), Buffer.from(expected, 'hex'));
  });
}
