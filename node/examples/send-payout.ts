// Envoyer 5 000 CDF vers un wallet après avoir vérifié le solde et l'opérateur.
//   LABPAY_API_KEY=test_… npx tsx examples/send-payout.ts
import { InsufficientBalanceError, isQueued, LabPay } from '@labyrinthe/labpay';

const labpay = new LabPay();

const prediction = await labpay.providers.predict({ phone: '0813456789' });
console.log(`${prediction.phone} → ${prediction.provider} (${prediction.country})`);
console.log('CDF balance:', (await labpay.account.balance('CDF'))?.balance ?? 'none');

try {
  const payout = await labpay.payouts.create({ amount: '5000', currency: 'CDF', phone: '0813456789', provider: prediction.provider, reference: `PAYOUT-${Date.now()}` });
  console.log(`${payout.id}: ${payout.status}, ${payout.totalAmount ?? '?'} ${payout.currency} debited`);
  if (isQueued(payout)) {
    // L'opérateur est en difficulté : l'envoi peut être annulé (solde recrédité).
    await labpay.payouts.cancel(payout.id);
  }
} catch (error) {
  if (!(error instanceof InsufficientBalanceError)) throw error;
  console.error('Insufficient balance:', error.message);
}
