// app/api/webhooks/labpay/route.ts (Next.js App Router)
import { SignatureVerificationError, WEBHOOK_HEADERS, Webhooks } from '@labyrinthe/labpay';

export async function POST(request: Request): Promise<Response> {
  const rawBody = await request.text(); // corps BRUT
  try {
    const event = await Webhooks.constructEvent(rawBody, request.headers.get(WEBHOOK_HEADERS.signature), process.env['LABPAY_WEBHOOK_SECRET'] ?? '');
    if (event.event === 'payment.completed') {
      // Livrer la commande event.payment.reference, une seule fois.
    }
    return new Response(null, { status: 200 });
  } catch (error) {
    if (error instanceof SignatureVerificationError) return new Response(null, { status: 400 });
    throw error;
  }
}
