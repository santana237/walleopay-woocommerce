<?php
/**
 * Outils d'administration : encart WalleoPay et rafraichissement manuel du statut.
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WalleoPay_Admin
 */
class WalleoPay_Admin {

	/**
	 * Action admin-post utilisee par le bouton de rafraichissement.
	 */
	const REFRESH_ACTION = 'walleopay_refresh_status';

	/**
	 * Enregistre les hooks d'administration.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh_request' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'register_order_action' ) );
		add_action( 'woocommerce_order_action_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_order_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Identifiant d'ecran de la commande, compatible HPOS et ancien CPT.
	 *
	 * @return array
	 */
	protected static function order_screens() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screens ) ) );
	}

	/**
	 * Ajoute l'encart WalleoPay sur l'ecran de commande.
	 *
	 * @return void
	 */
	public static function register_meta_box() {
		foreach ( self::order_screens() as $screen ) {
			add_meta_box(
				'walleopay-order-panel',
				__( 'WalleoPay', 'walleopay' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Contenu de l'encart.
	 *
	 * @param mixed $post_or_order Objet WP_Post ou WC_Order selon le stockage.
	 *
	 * @return void
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$payment_id = (string) $order->get_meta( '_walleopay_payment_id' );
		$reference  = (string) $order->get_meta( '_walleopay_reference' );
		$mode       = (string) $order->get_meta( '_walleopay_mode' );
		$status     = (string) $order->get_meta( '_walleopay_status' );
		$operator   = (string) $order->get_meta( '_walleopay_operator' );
		$checked_at = (string) $order->get_meta( '_walleopay_checked_at' );

		if ( '' === $payment_id && '' === $reference ) {
			echo '<p>' . esc_html__( 'Aucun paiement WalleoPay n’est associé à cette commande.', 'walleopay' ) . '</p>';

			return;
		}

		echo '<ul style="margin:0;">';
		printf(
			'<li><strong>%1$s</strong> <code>%2$s</code></li>',
			esc_html__( 'Paiement :', 'walleopay' ),
			esc_html( '' !== $payment_id ? $payment_id : '—' )
		);
		printf(
			'<li><strong>%1$s</strong> %2$s</li>',
			esc_html__( 'Référence :', 'walleopay' ),
			esc_html( '' !== $reference ? $reference : '—' )
		);
		printf(
			'<li><strong>%1$s</strong> %2$s</li>',
			esc_html__( 'Mode :', 'walleopay' ),
			esc_html( 'live' === $mode ? __( 'production', 'walleopay' ) : __( 'test', 'walleopay' ) )
		);
		printf(
			'<li><strong>%1$s</strong> %2$s</li>',
			esc_html__( 'Statut WalleoPay :', 'walleopay' ),
			esc_html( '' !== $status ? $status : '—' )
		);

		if ( '' !== $operator ) {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html__( 'Opérateur :', 'walleopay' ),
				esc_html( $operator )
			);
		}

		if ( '' !== $checked_at ) {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html__( 'Dernière vérification :', 'walleopay' ),
				esc_html( $checked_at )
			);
		}

		echo '</ul>';

		if ( 'awaiting_confirmation' === $status ) {
			echo '<p>' . esc_html__( 'Le client a payé au code marchand. Le rapprochement manuel peut prendre plusieurs heures.', 'walleopay' ) . '</p>';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::REFRESH_ACTION,
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			self::REFRESH_ACTION . '_' . $order->get_id()
		);

		printf(
			'<p><a href="%1$s" class="button button-secondary">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Rafraîchir le statut WalleoPay', 'walleopay' )
		);

		echo '<p class="description">' . esc_html__( 'Le statut est relu directement auprès de l’API WalleoPay, puis appliqué à la commande après contrôle du montant et de la devise.', 'walleopay' ) . '</p>';
	}

	/**
	 * Ajoute l'action dans la liste deroulante « Actions de commande ».
	 *
	 * @param array $actions Actions existantes.
	 *
	 * @return array
	 */
	public static function register_order_action( $actions ) {
		$actions[ self::REFRESH_ACTION ] = __( 'Rafraîchir le statut WalleoPay', 'walleopay' );

		return $actions;
	}

	/**
	 * Execute l'action depuis la liste deroulante WooCommerce (nonce gere par WooCommerce).
	 *
	 * @param WC_Order $order Commande.
	 *
	 * @return void
	 */
	public static function handle_order_action( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		self::refresh( $order );
	}

	/**
	 * Execute l'action depuis le bouton de l'encart.
	 *
	 * @return void
	 */
	public static function handle_refresh_request() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( self::REFRESH_ACTION . '_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'Vous n’êtes pas autorisé à effectuer cette action.', 'walleopay' ), 403 );
		}

		$result = self::refresh( $order );

		$redirect = add_query_arg(
			array( 'walleopay_refreshed' => is_wp_error( $result ) ? 'error' : 'ok' ),
			$order->get_edit_order_url()
		);

		if ( is_wp_error( $result ) ) {
			set_transient( 'walleopay_refresh_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Relit le statut aupres de l'API et applique la meme logique que le webhook.
	 *
	 * @param WC_Order $order Commande.
	 *
	 * @return array|WP_Error
	 */
	protected static function refresh( $order ) {
		$result = WalleoPay_Webhook::verify_and_apply(
			$order,
			(string) $order->get_meta( '_walleopay_payment_id' ),
			__( 'rafraîchissement manuel', 'walleopay' )
		);

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: message d'erreur. */
					__( 'WalleoPay : rafraîchissement impossible. %s', 'walleopay' ),
					$result->get_error_message()
				)
			);
		}

		return $result;
	}

	/**
	 * Affiche le resultat du rafraichissement.
	 *
	 * @return void
	 */
	public static function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage, aucune action.
		$state = isset( $_GET['walleopay_refreshed'] ) ? sanitize_text_field( wp_unslash( $_GET['walleopay_refreshed'] ) ) : '';

		if ( '' === $state ) {
			return;
		}

		if ( 'ok' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statut WalleoPay rafraîchi.', 'walleopay' ) . '</p></div>';

			return;
		}

		$message = get_transient( 'walleopay_refresh_error_' . get_current_user_id() );
		delete_transient( 'walleopay_refresh_error_' . get_current_user_id() );

		echo '<div class="notice notice-error is-dismissible"><p>';
		echo esc_html__( 'Rafraîchissement WalleoPay impossible.', 'walleopay' );

		if ( $message ) {
			echo ' ' . esc_html( $message );
		}

		echo '</p></div>';
	}
}
