// Point de réception des notifications (callback_url) avec node:http, sans framework.
// Avec Express : express.raw({ type: 'application/json' }) puis req.body (Buffer) — jamais express.json().
//   LABPAY_WEBHOOK_SECRET=whsec_… npx tsx examples/webhook-node-http.ts
import { createServer } from 'node:http';
import { InvalidWebhookPayloadError, SignatureVerificationError, WEBHOOK_HEADERS, Webhooks } from '@labyrinthe/labpay';

const secret = process.env['LABPAY_WEBHOOK_SECRET'] ?? '';

createServer((req, res) => {
  const chunks: Buffer[] = [];
  req.on('data', (chunk: Buffer) => chunks.push(chunk));
  req.on('end', () => {
    const header = req.headers[WEBHOOK_HEADERS.signature];
    Webhooks.constructEvent(Buffer.concat(chunks), typeof header === 'string' ? header : null, secret)
      .then((event) => {
        const delivery = req.headers[WEBHOOK_HEADERS.delivery]; // dédoublonner sur cet identifiant ou event.payment.id
        if (event.event === 'payment.completed') {
          // Livrer la commande event.payment.reference, une seule fois.
        }
        console.log(delivery, event.event, event.payment.id);
        res.writeHead(200).end(); // répondre en moins de 10 s
      })
      .catch((error: unknown) => {
        const invalid = error instanceof SignatureVerificationError || error instanceof InvalidWebhookPayloadError;
        res.writeHead(invalid ? 400 : 500).end();
      });
  });
}).listen(8080);
