<?php
/**
 * Page de retour client apres le passage sur la page de paiement WalleoPay.
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WalleoPay_Return
 */
class WalleoPay_Return {

	/**
	 * Enregistre l'endpoint de retour.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_api_walleopay_return', array( __CLASS__, 'handle' ) );
	}

	/**
	 * URL de retour pour une commande donnee.
	 *
	 * @param WC_Order $order Commande.
	 *
	 * @return string
	 */
	public static function get_return_url( $order ) {
		return add_query_arg(
			array(
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			WC()->api_request_url( 'walleopay_return' )
		);
	}

	/**
	 * Traite le retour du navigateur : on ne fait jamais confiance aux parametres
	 * d'URL, le statut est systematiquement revérifié aupres de l'API.
	 *
	 * @return void
	 */
	public static function handle() {
		$gateway = WC_Gateway_WalleoPay::instance();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- retour externe, la verite vient de l'API.
		$order_id  = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || '' === $order_key || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			wc_add_notice( __( 'Commande introuvable ou lien de retour invalide.', 'walleopay' ), 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$payment_id = (string) $order->get_meta( '_walleopay_payment_id' );

		// REGLE DE SECURITE : re-verification cote serveur avant tout affichage.
		$payment = WalleoPay_Webhook::verify_and_apply( $order, $payment_id, __( 'retour client', 'walleopay' ) );

		if ( is_wp_error( $payment ) ) {
			$gateway->log( 'Retour client : ' . $payment->get_error_message(), 'error' );

			// La commande a peut-etre deja ete validee par le webhook, arrive plus tot.
			if ( $order->is_paid() ) {
				wp_safe_redirect( $gateway->get_return_url( $order ) );
				exit;
			}

			wc_add_notice(
				sprintf(
					/* translators: %s: message d'erreur. */
					__( 'Nous n’avons pas pu vérifier votre paiement auprès de WalleoPay : %s Votre commande reste en attente, aucun double paiement n’a été enregistré.', 'walleopay' ),
					$payment->get_error_message()
				),
				'error'
			);

			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$status = isset( $payment['status'] ) ? (string) $payment['status'] : '';

		switch ( $status ) {
			case 'succeeded':
				if ( WC()->cart && $gateway->should_empty_cart() ) {
					WC()->cart->empty_cart();
				}

				wc_add_notice( __( 'Merci, votre paiement a bien été confirmé.', 'walleopay' ), 'success' );
				wp_safe_redirect( $gateway->get_return_url( $order ) );
				exit;

			case 'awaiting_confirmation':
				if ( WC()->cart && $gateway->should_empty_cart() ) {
					WC()->cart->empty_cart();
				}

				wc_add_notice(
					__( 'Votre paiement est en cours de confirmation. Nous avons bien reçu votre transaction ; la validation définitive peut prendre quelques heures. Vous recevrez un e-mail dès qu’elle sera confirmée.', 'walleopay' ),
					'notice'
				);
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;

			case 'pending':
			case 'processing':
				wc_add_notice(
					__( 'Votre paiement est en cours de confirmation. Cette page se mettra à jour dès que WalleoPay aura confirmé la transaction.', 'walleopay' ),
					'notice'
				);
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;

			case 'cancelled':
				wc_add_notice( __( 'Le paiement a été annulé. Votre panier a été conservé, vous pouvez réessayer.', 'walleopay' ), 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;

			case 'expired':
				wc_add_notice( __( 'Le délai de paiement est dépassé. Vous pouvez relancer le règlement de votre commande.', 'walleopay' ), 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;

			case 'failed':
			default:
				$reason = '';

				if ( ! empty( $payment['failure_message'] ) ) {
					$reason = ' ' . (string) $payment['failure_message'];
				}

				wc_add_notice(
					sprintf(
						/* translators: %s: motif d'echec renvoye par l'operateur. */
						__( 'Le paiement n’a pas abouti.%s Vous pouvez réessayer ou choisir un autre moyen de paiement.', 'walleopay' ),
						$reason
					),
					'error'
				);
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
		}
	}
}
