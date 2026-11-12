<?php
/**
 * Prépare la suite de tests : télécharge WordPress et WooCommerce dans .wp-tests/
 * (ignoré par git), crée la base de test et écrit .wp-tests/wp-tests-config.php.
 *
 * Usage :
 *   php bin/install-wp-tests.php [--wp=latest|6.4.5] [--wc=latest|8.5.2]
 *        [--db-name=labpay_wp_tests] [--db-user=root] [--db-pass=] [--db-host=127.0.0.1]
 *        [--from=<dossier .wp-tests existant>]   (copie WordPress et WooCommerce au lieu de les télécharger)
 *
 * Variables d'environnement équivalentes : WP_VERSION, WC_VERSION, DB_NAME, DB_USER,
 * DB_PASSWORD, DB_HOST. La base indiquée est créée si besoin ; aucune autre n'est touchée.
 *
 * @package LabPayWooCommerce
 */

// phpcs:disable -- outil en ligne de commande, hors WordPress.

$options = getopt( '', array( 'wp::', 'wc::', 'db-name::', 'db-user::', 'db-pass::', 'db-host::', 'from::' ) );
$option  = static function ( string $name, string $env, string $default ) use ( $options ): string {
	if ( isset( $options[ $name ] ) && is_string( $options[ $name ] ) ) {
		return $options[ $name ];
	}
	$value = getenv( $env );

	return false !== $value ? $value : $default;
};

$wp_version = $option( 'wp', 'WP_VERSION', 'latest' );
$wc_version = $option( 'wc', 'WC_VERSION', 'latest' );
$db_name    = $option( 'db-name', 'DB_NAME', 'labpay_wp_tests' );
$db_user    = $option( 'db-user', 'DB_USER', 'root' );
$db_pass    = $option( 'db-pass', 'DB_PASSWORD', '' );
$db_host    = $option( 'db-host', 'DB_HOST', '127.0.0.1' );

if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $db_name ) ) {
	fail( "Invalid database name: $db_name" );
}

$root = dirname( __DIR__ ) . '/.wp-tests';
if ( ! is_dir( $root ) && ! mkdir( $root, 0777, true ) ) {
	fail( "Cannot create $root" );
}

$from = $option( 'from', 'WP_TESTS_FROM', '' );
if ( '' !== $from ) {
	// Copie locale (même WordPress et même WooCommerce qu'une autre suite de tests) : aucun téléchargement.
	foreach ( array( 'wordpress' => 'wordpress/wp-load.php', 'plugins/woocommerce' => 'plugins/woocommerce/woocommerce.php' ) as $dir => $probe ) {
		if ( ! is_file( "$from/$probe" ) ) {
			fail( "--from: $from/$probe is missing." );
		}
		if ( ! is_file( "$root/$probe" ) ) {
			copy_tree( "$from/$dir", "$root/$dir" );
		}
	}
	$wp_version = $wc_version = 'copied from ' . $from;
} else {
// WordPress.
$wp_url = 'latest' === $wp_version ? 'https://wordpress.org/latest.zip' : "https://downloads.wordpress.org/release/wordpress-$wp_version.zip";
install_zip( $wp_url, "$root/downloads/wordpress-$wp_version", "$root/wordpress", 'wordpress', $wp_version );

// WooCommerce (extension officielle, depuis wordpress.org).
$wc_url = 'latest' === $wc_version ? 'https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip' : "https://downloads.wordpress.org/plugin/woocommerce.$wc_version.zip";
install_zip( $wc_url, "$root/downloads/woocommerce-$wc_version", "$root/plugins/woocommerce", 'woocommerce', $wc_version );
}

// Base de test dédiée.
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
try {
	[ $host, $port ] = array_pad( explode( ':', $db_host, 2 ), 2, null );
	$mysqli          = new mysqli( $host, $db_user, $db_pass, '', null !== $port ? (int) $port : 3306 );
	$mysqli->query( "CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
	$mysqli->close();
} catch ( mysqli_sql_exception $e ) {
	fail( 'MySQL: ' . $e->getMessage() );
}

$config = "<?php\n// Généré par bin/install-wp-tests.php.\n"
	. "define( 'ABSPATH', " . var_export( "$root/wordpress/", true ) . " );\n"
	. "define( 'WP_DEFAULT_THEME', 'default' );\n"
	. "define( 'WP_DEBUG', true );\n"
	. "define( 'DB_NAME', " . var_export( $db_name, true ) . " );\n"
	. "define( 'DB_USER', " . var_export( $db_user, true ) . " );\n"
	. "define( 'DB_PASSWORD', " . var_export( $db_pass, true ) . " );\n"
	. "define( 'DB_HOST', " . var_export( $db_host, true ) . " );\n"
	. "define( 'DB_CHARSET', 'utf8mb4' );\n"
	. "define( 'DB_COLLATE', '' );\n"
	. "\$table_prefix = 'wptests_';\n"
	. "define( 'WP_TESTS_DOMAIN', 'example.org' );\n"
	. "define( 'WP_TESTS_EMAIL', 'admin@example.org' );\n"
	. "define( 'WP_TESTS_TITLE', 'LabPay for WooCommerce tests' );\n"
	. "define( 'WP_PHP_BINARY', 'php' );\n"
	. "define( 'WPLANG', '' );\n";
file_put_contents( "$root/wp-tests-config.php", $config );

echo "WordPress ($wp_version) and WooCommerce ($wc_version) are in .wp-tests/, database `$db_name` is ready.\n";

