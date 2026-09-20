<?php
/**
 * Plugin Name:       WalleoPay pour WooCommerce
 * Plugin URI:        https://walleopay.com/integrations/woocommerce
 * Description:       Acceptez les paiements Mobile Money (MTN MoMo, Orange Money) et carte bancaire sur votre boutique WooCommerce via WalleoPay.
 * Version:           1.0.0
 * Author:            WalleoPay
 * Author URI:        https://walleopay.com
 * Requires at least: 5.8
 * Tested up to:      6.6
 * Requires PHP:      7.4
 * Text Domain:       walleopay
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 6.0
 * WC tested up to:   9.1
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

define( 'WALLEOPAY_WC_VERSION', '1.0.0' );
define( 'WALLEOPAY_WC_PLUGIN_FILE', __FILE__ );
define( 'WALLEOPAY_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WALLEOPAY_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Charge les fichiers de traduction.
 *
 * @return void
 */
function walleopay_wc_load_textdomain() {
	load_plugin_textdomain( 'walleopay', false, dirname( WALLEOPAY_WC_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'walleopay_wc_load_textdomain' );

/**
 * Declare la compatibilite avec les tables de commandes personnalisees (HPOS)
 * et avec le checkout par blocs.
 *
 * @return void
 */
function walleopay_wc_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WALLEOPAY_WC_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WALLEOPAY_WC_PLUGIN_FILE, false );
	}
}
add_action( 'before_woocommerce_init', 'walleopay_wc_declare_compatibility' );

/**
 * Avertit l'administrateur si WooCommerce est absent.
 *
 * @return void
 */
function walleopay_wc_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'WalleoPay pour WooCommerce requiert WooCommerce. Veuillez installer et activer WooCommerce.', 'walleopay' );
	echo '</p></div>';
}

/**
 * Point d'entree : charge le plugin une fois WooCommerce disponible.
 *
 * @return void
 */
function walleopay_wc_init() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'walleopay_wc_missing_woocommerce_notice' );

		return;
	}

	require_once WALLEOPAY_WC_PLUGIN_DIR . 'includes/class-walleopay-api.php';
	require_once WALLEOPAY_WC_PLUGIN_DIR . 'includes/class-walleopay-webhook.php';
	require_once WALLEOPAY_WC_PLUGIN_DIR . 'includes/class-walleopay-return.php';
	require_once WALLEOPAY_WC_PLUGIN_DIR . 'includes/class-walleopay-gateway.php';
	require_once WALLEOPAY_WC_PLUGIN_DIR . 'includes/class-walleopay-admin.php';

	WC_Gateway_WalleoPay::init_hooks();
	WalleoPay_Admin::init();
}
add_action( 'plugins_loaded', 'walleopay_wc_init', 11 );

/**
 * Enregistre la passerelle aupres de WooCommerce.
 *
 * @param array $gateways Liste des passerelles.
 *
 * @return array
 */
function walleopay_wc_register_gateway( $gateways ) {
	if ( class_exists( 'WC_Gateway_WalleoPay' ) ) {
		$gateways[] = 'WC_Gateway_WalleoPay';
	}

	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'walleopay_wc_register_gateway' );

/**
 * Ajoute un lien « Reglages » dans la liste des extensions.
 *
 * @param array $links Liens existants.
 *
 * @return array
 */
function walleopay_wc_plugin_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=walleopay' );

	$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Réglages', 'walleopay' ) . '</a>';

	array_unshift( $links, $settings_link );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'walleopay_wc_plugin_action_links' );

/**
 * Vide les regles de reecriture a l'activation (l'endpoint WC API en depend).
 *
 * @return void
 */
function walleopay_wc_activate() {
	if ( ! get_option( 'walleopay_wc_installed_at' ) ) {
		add_option( 'walleopay_wc_installed_at', time() );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'walleopay_wc_activate' );

/**
 * Nettoyage leger a la desactivation.
 *
 * @return void
 */
function walleopay_wc_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'walleopay_wc_deactivate' );
