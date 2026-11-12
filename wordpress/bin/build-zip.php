<?php
/**
 * Construit dist/labpay-for-woocommerce.zip (installable depuis Extensions > Ajouter) et
 * dist/labpay-for-woocommerce/ (même contenu, pour le déploiement SVN sur wordpress.org).
 *
 * - liste blanche de fichiers : aucun fichier de développement (tests, outils, Composer…) ;
 * - dépendances préfixées reconstruites (bin/prefix-dependencies.php) ;
 * - archive reproductible : fichiers triés, date fixe (SOURCE_DATE_EPOCH ou date de version) ;
 * - vérifications : versions cohérentes, syntaxe PHP de chaque fichier, fichiers interdits.
 *
 * Usage : php bin/build-zip.php [--skip-prefix]
 *
 * @package LabPayWooCommerce
 */

// phpcs:disable -- script de build en ligne de commande, hors WordPress.

const LABPAY_WC_SLUG = 'labpay-for-woocommerce';

$root  = dirname( __DIR__ );
$dist  = $root . '/dist';
$build = $dist . '/' . LABPAY_WC_SLUG;
$zip   = $dist . '/' . LABPAY_WC_SLUG . '.zip';

// Contenu distribué (liste blanche).
$include = array(
	'labpay-for-woocommerce.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'includes',
	'assets',
	'languages',
	'vendor-prefixed',
);

// Jamais distribués, même s'ils se trouvent dans un dossier inclus.
$forbidden = '#(^|/)(\.git|\.github|\.DS_Store|Thumbs\.db|node_modules|tests?|tools|bin|\.wp-tests|vendor)(/|$)|(^|/)(composer\.(json|lock)|phpunit[^/]*|phpcs[^/]*|phpstan[^/]*|scoper\.inc\.php|\.distignore|\.gitignore|\.editorconfig)$#i';

// 1. Versions cohérentes : en-tête, constante, readme.txt (Stable tag).
$main   = (string) file_get_contents( $root . '/labpay-for-woocommerce.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/define\( 'LABPAY_WC_VERSION', '([^']+)' \)/", $main, $constant );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
$version = $header[1] ?? '';
if ( '' === $version || ( $constant[1] ?? '' ) !== $version || ( $stable[1] ?? '' ) !== $version ) {
	fail( sprintf( 'Version mismatch: header %s, constant %s, readme.txt Stable tag %s.', $version, $constant[1] ?? '?', $stable[1] ?? '?' ) );
}
$asset = include $root . '/assets/js/blocks.asset.php';
if ( ( $asset['version'] ?? '' ) !== $version ) {
	fail( 'assets/js/blocks.asset.php version must be ' . $version );
}

// 2. Dépendances préfixées et traductions compilées.
if ( ! in_array( '--skip-prefix', $argv, true ) ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/bin/prefix-dependencies.php' ), $code );
	if ( 0 !== $code ) {
		exit( $code );
	}
}
foreach ( array( 'vendor-prefixed/autoload.php', 'languages/labpay-for-woocommerce.pot', 'languages/labpay-for-woocommerce-fr_FR.mo' ) as $required ) {
	if ( ! is_file( $root . '/' . $required ) ) {
		fail( "$required is missing (composer install, composer i18n)." );
	}
}

// 3. Liste des fichiers.
$files = array();
foreach ( $include as $entry ) {
	$path = $root . '/' . $entry;
	if ( is_file( $path ) ) {
		$files[] = $entry;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		fail( "$entry is missing." );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = $entry . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $path ) + 1 ) );
		if ( 1 === preg_match( $forbidden, substr( $relative, strlen( $entry ) + 1 ) ) ) {
			continue;
		}
		$files[] = $relative;
	}
}
sort( $files, SORT_STRING );

// 4. Copie dans dist/labpay-for-woocommerce/ et archive.
remove_tree( $build );
if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0777, true );
}
