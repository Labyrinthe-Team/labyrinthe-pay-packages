package cd.labyrinthe.labpay.internal;

/**
 * Quand une requête peut être renvoyée automatiquement. Mêmes règles que les SDK PHP et Node.
 *
 * <ul>
 *   <li>{@link #SAFE} : le rejeu ne produit pas deux fois un effet (GET, predict-provider, sync, et
 *       dépôts / envois / remboursements avec {@code reference}). Relancée sur erreur réseau, 5xx
 *       et 429 du limiteur.
 *   <li>{@link #THROTTLED_ONLY} : rotations, renvoi de notification, annulation, paiement sans
 *       référence. Relancée seulement sur 429 du limiteur.
 * </ul>
 *
 * <p>Dans les deux modes, un 503 qui refuse la requête avant tout traitement ({@code MAINTENANCE},
 * {@code DEPOSITS_DISABLED}…) n'est relancé qu'après un {@code Retry-After} au plus égal à {@code
 * maxRetryWait} ; {@code DAILY_LIMIT_REACHED} jamais.
 */
public enum RetryMode {
  /** Rejeu sans risque. */
  SAFE,
  /** Rejeu seulement sur 429 du limiteur. */
  THROTTLED_ONLY
}
