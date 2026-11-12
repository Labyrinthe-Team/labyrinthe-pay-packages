// Encaisser 1 000 CDF avec la clé de test, puis suivre le paiement.
//   LABPAY_API_KEY=test_… npx tsx examples/collect-payment.ts
import { ApiError, ConnectionError, isFinal, LabPay } from '@labyrinthe/labpay';

const labpay = new LabPay(); // LABPAY_API_KEY

try {
  let payment = await labpay.deposits.create({
    amount: '1000',
    currency: 'CDF',
    phone: '0813456789', // numéro de la sandbox : réussite
    reference: `ORDER-${Date.now()}`, // unique par token : un renvoi rend le même paiement
    customerMessage: 'Commande 42',
    metadata: { orderId: '42' },
  });
  console.log(`${payment.id}: ${payment.status}, the customer pays ${payment.totalAmount ?? '?'} ${payment.currency}`);

  // Filet de sécurité seulement : en production, attendez la notification sur callbackUrl.
  for (let i = 0; i < 10 && !isFinal(payment); i++) {
    await new Promise((resolve) => setTimeout(resolve, 5000));
    payment = await labpay.payments.sync(payment.id);
  }
  console.log(`Final status: ${payment.status}`, payment.failure ?? '');
} catch (error) {
  if (error instanceof ConnectionError) {
    console.error('Network error: the payment may exist, resend with the same reference.');
  } else if (error instanceof ApiError) {
    console.error(`Refused: ${error.code} (${error.message})`);
  } else {
    throw error;
  }
}
