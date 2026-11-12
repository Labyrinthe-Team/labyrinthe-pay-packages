<?php
/**
 * Copie les dépendances d'exécution (SDK PHP, Guzzle, PSR) dans vendor-prefixed/
 * sous l'espace de noms LabPay\WooCommerce\Vendor\ (PHP-Scoper), puis écrit
 * vendor-prefixed/autoload.php. Aucune autre extension embarquant Guzzle ou les
 * interfaces PSR ne peut ainsi entrer en conflit avec le plugin.
 *
 * Reproductible : PHP-Scoper est figé (tools/php-scoper/composer.lock), les
 * paquets viennent de vendor/composer/installed.json (hors require-dev), et
 * l'autoloader généré est trié et sans date.
 *
 * Usage : php bin/prefix-dependencies.php   (lancé aussi par composer install/update)
 *
 * @package LabPayWooCommerce
 */

// phpcs:disable -- script de build en ligne de commande, hors WordPress.

const LABPAY_WC_PREFIX = 'LabPay\\WooCommerce\\Vendor\\';

// Inutiles en PHP >= 8.1 (exigence du plugin) : jamais embarqués.
const LABPAY_WC_SKIPPED_PACKAGES = array( 'symfony/polyfill-php80', 'symfony/polyfill-mbstring', 'symfony/polyfill-ctype' );

$root   = dirname( __DIR__ );
$tools  = $root . '/tools/php-scoper';
$target = $root . '/vendor-prefixed';

// PHP-Scoper demande PHP 8.2+ (le plugin, lui, tourne dès PHP 8.1 : en CI, vendor-prefixed/ est
// construit une fois en PHP 8.3 puis réutilisé par les tests en PHP 8.1).
if ( PHP_VERSION_ID < 80200 ) {
	$message = 'PHP-Scoper needs PHP 8.2+: vendor-prefixed/ was not rebuilt (run "composer prefix-dependencies" with PHP 8.2 or newer).';
	if ( is_file( $target . '/autoload.php' ) ) {
		fwrite( STDERR, "Warning: $message\n" );
		exit( 0 );
	}
	fwrite( STDERR, "$message\n" );
	exit( 1 );
}

$installed_file = $root . '/vendor/composer/installed.json';
if ( ! is_file( $installed_file ) ) {
	fwrite( STDERR, "vendor/ is missing: run composer install first.\n" );
	exit( 1 );
}

if ( ! is_file( $tools . '/vendor/humbug/php-scoper/bin/php-scoper' ) ) {
	passthru( 'composer --working-dir=' . escapeshellarg( $tools ) . ' install --no-interaction --no-progress --prefer-dist', $code );
	if ( 0 !== $code ) {
		exit( $code );
	}
}

$installed = json_decode( (string) file_get_contents( $installed_file ), true );
$dev       = $installed['dev-package-names'] ?? array();
$packages  = array();
foreach ( $installed['packages'] as $package ) {
	if ( ! in_array( $package['name'], $dev, true ) && ! in_array( $package['name'], LABPAY_WC_SKIPPED_PACKAGES, true ) ) {
		$packages[ $package['name'] ] = $package;
	}
}
ksort( $packages );

if ( ! isset( $packages['labyrinthe/labpay-php'] ) ) {
	fwrite( STDERR, "labyrinthe/labpay-php is not installed.\n" );
	exit( 1 );
}

// Point de départ propre : un fichier supprimé d'une dépendance ne doit pas survivre.
remove_tree( $target );

putenv( 'LABPAY_WC_SCOPER_PACKAGES=' . implode( ',', array_keys( $packages ) ) );
$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tools . '/vendor/humbug/php-scoper/bin/php-scoper' )
	. ' add-prefix --config=' . escapeshellarg( $root . '/scoper.inc.php' ) . ' --force --no-interaction --quiet';
passthru( $command, $code );
if ( 0 !== $code ) {
	exit( $code );
}

// Autoloader : PSR-4 (espaces de noms préfixés) + fichiers à inclure (fonctions).
$psr4  = array();
$files = array();
foreach ( $packages as $name => $package ) {
	foreach ( $package['autoload']['psr-4'] ?? array() as $namespace => $paths ) {
