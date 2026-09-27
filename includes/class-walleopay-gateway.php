<?php
/**
 * Passerelle de paiement WalleoPay pour WooCommerce.
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_WalleoPay
 */
class WC_Gateway_WalleoPay extends WC_Payment_Gateway {

	/**
	 * Instance unique, reutilisee par le webhook, la page de retour et l'administration.
	 *
	 * @var WC_Gateway_WalleoPay|null
	 */
	protected static $instance = null;

	/**
	 * Client API memorise.
	 *
	 * @var WalleoPay_API|null
	 */
	protected $api = null;

	/**
	 * Retourne l'instance partagee de la passerelle.
	 *
	 * @return WC_Gateway_WalleoPay
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enregistre les points d'entree globaux (une seule fois).
	 *
	 * @return void
	 */
	public static function init_hooks() {
		add_action(
			'woocommerce_update_options_payment_gateways_walleopay',
			array( __CLASS__, 'save_settings' )
		);

		WalleoPay_Webhook::init();
		WalleoPay_Return::init();
	}

	/**
	 * Enregistre les reglages depuis l'ecran d'administration.
	 *
	 * @return void
	 */
	public static function save_settings() {
		self::instance()->process_admin_options();
	}

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->id                 = 'walleopay';
		$this->method_title       = __( 'WalleoPay', 'walleopay' );
		$this->method_description = __( 'Encaissez par MTN Mobile Money, Orange Money ou carte bancaire via WalleoPay. Le client est redirigé vers une page de paiement sécurisée, puis renvoyé sur votre boutique.', 'walleopay' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = apply_filters( 'walleopay_gateway_icon', '' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Mobile Money / Carte (WalleoPay)', 'walleopay' ) );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		if ( null === self::$instance ) {
			self::$instance = $this;
		}
	}

	/**
	 * Champs de configuration de l'administration.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$notify_url = function_exists( 'WC' ) && WC() ? WC()->api_request_url( 'walleopay' ) : home_url( '/wc-api/walleopay' );

		$this->form_fields = array(
			'enabled'         => array(
				'title'       => __( 'Activation', 'walleopay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activer le paiement WalleoPay', 'walleopay' ),
				'default'     => 'no',
				'description' => __( 'Le moyen de paiement n’apparaît à la commande que si une clé secrète valide est renseignée pour le mode choisi.', 'walleopay' ),
			),
			'title'           => array(
				'title'       => __( 'Titre affiché au client', 'walleopay' ),
				'type'        => 'text',
				'description' => __( 'Nom du moyen de paiement tel qu’il apparaît sur la page de commande.', 'walleopay' ),
				'default'     => __( 'Mobile Money / Carte (WalleoPay)', 'walleopay' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description affichée au client', 'walleopay' ),
				'type'        => 'textarea',
				'description' => __( 'Texte affiché sous le titre du moyen de paiement.', 'walleopay' ),
				'default'     => __( 'Payez avec MTN Mobile Money, Orange Money ou votre carte bancaire. Vous serez redirigé vers la page sécurisée WalleoPay.', 'walleopay' ),
				'desc_tip'    => true,
			),
			'mode'            => array(
				'title'       => __( 'Mode', 'walleopay' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Test (clé sk_test_…)', 'walleopay' ),
					'live' => __( 'Production (clé sk_live_…)', 'walleopay' ),
				),
				'description' => __( 'Le mode réellement appliqué découle de la clé utilisée. Ce réglage indique simplement quelle clé envoyer. Le mode test n’est pas une simulation : il débite réellement le client et crédite votre solde WalleoPay, commission comprise. Faites vos essais avec de petits montants.', 'walleopay' ),
			),
			'test_secret_key' => array(
				'title'       => __( 'Clé secrète de test', 'walleopay' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Commence par sk_test_. À copier depuis votre tableau de bord WalleoPay. Ne la partagez jamais et ne la placez pas dans du code côté navigateur.', 'walleopay' ),
			),
			'live_secret_key' => array(
				'title'       => __( 'Clé secrète de production', 'walleopay' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Commence par sk_live_. À copier depuis votre tableau de bord WalleoPay.', 'walleopay' ),
			),
			'webhook_secret'  => array(
				'title'       => __( 'Secret de webhook', 'walleopay' ),
				'type'        => 'password',
				'default'     => '',
				'description' => sprintf(
					/* translators: %s: URL de notification. */
					__( 'Commence par whsec_. Copiez le secret de signature affiché dans votre tableau de bord WalleoPay, rubrique Notifications : un seul secret par compte, le même en test et en production. L’extension transmet elle-même son adresse de notification (<code>%s</code>) avec chaque paiement. Sans ce secret, les notifications sont rejetées.', 'walleopay' ),
					esc_url_raw( $notify_url )
				),
			),
			'empty_cart'      => array(
				'title'       => __( 'Panier', 'walleopay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Vider le panier une fois le paiement confirmé', 'walleopay' ),
				'default'     => 'yes',
				'description' => __( 'Si l’option est décochée, le panier du client est conservé même après un paiement réussi.', 'walleopay' ),
			),
			'debug'           => array(
				'title'       => __( 'Journalisation', 'walleopay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activer le journal de débogage', 'walleopay' ),
				'default'     => 'no',
				'description' => __( 'Enregistre les échanges avec l’API dans WooCommerce → État → Journaux, source « walleopay ». Les clés secrètes ne sont jamais écrites dans les journaux.', 'walleopay' ),
			),
		);
	}

	/**
	 * Affiche les reglages, precedes d'un rappel de l'URL de notification.
	 *
	 * @return void
	 */
	public function admin_options() {
		$notify_url = WC()->api_request_url( 'walleopay' );

		echo '<h2>' . esc_html__( 'WalleoPay', 'walleopay' ) . '</h2>';
		echo '<p>' . esc_html__( 'Adresse de notification de la boutique. L’extension l’envoie avec chaque paiement et WalleoPay la préfère à l’URL de notification par défaut de votre compte : il n’y a rien à coller dans le tableau de bord.', 'walleopay' ) . '</p>';
		echo '<p><code>' . esc_html( $notify_url ) . '</code></p>';

		if ( 'XAF' !== strtoupper( get_woocommerce_currency() ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'La devise de la boutique n’est pas XAF. WalleoPay encaisse en francs CFA : vérifiez votre configuration avant d’activer le mode production.', 'walleopay' ) . '</p></div>';
		}

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * Mode courant.
	 *
	 * @return string « test » ou « live ».
	 */
	public function get_mode() {
		return 'live' === $this->get_option( 'mode', 'test' ) ? 'live' : 'test';
	}

	/**
	 * Cle secrete du mode courant.
	 *
	 * @param string $mode Mode force (facultatif).
	 *
	 * @return string
	 */
	public function get_secret_key( $mode = '' ) {
		$mode = '' !== $mode ? $mode : $this->get_mode();

		return trim( (string) $this->get_option( 'live' === $mode ? 'live_secret_key' : 'test_secret_key', '' ) );
	}

	/**
	 * Secret de webhook.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		return trim( (string) $this->get_option( 'webhook_secret', '' ) );
	}

	/**
	 * Le panier doit-il etre vide apres paiement ?
	 *
	 * @return bool
	 */
	public function should_empty_cart() {
		return 'yes' === $this->get_option( 'empty_cart', 'yes' );
	}

	/**
	 * Journalisation activee ?
	 *
	 * @return bool
	 */
	public function is_debug() {
		return 'yes' === $this->get_option( 'debug', 'no' );
	}

	/**
	 * Client API pour le mode courant.
	 *
	 * @return WalleoPay_API
	 */
	public function get_api() {
		if ( null === $this->api ) {
			$this->api = new WalleoPay_API( $this->get_secret_key(), $this->is_debug() );
		}

		return $this->api;
	}

	/**
	 * Client API dans le mode utilise lors de la creation du paiement de cette commande.
	 * Evite d'interroger l'API live avec une cle test (et inversement) apres un changement de mode.
	 *
	 * @param WC_Order $order Commande.
	 *
	 * @return WalleoPay_API
	 */
	public function get_api_for_order( $order ) {
		$mode = $order instanceof WC_Order ? (string) $order->get_meta( '_walleopay_mode' ) : '';

		if ( 'test' !== $mode && 'live' !== $mode ) {
			return $this->get_api();
		}

		if ( $mode === $this->get_mode() ) {
			return $this->get_api();
		}

		return new WalleoPay_API( $this->get_secret_key( $mode ), $this->is_debug() );
	}

	/**
	 * Raccourci de journalisation.
	 *
	 * @param string $message Message.
	 * @param string $level   Niveau.
	 *
	 * @return void
	 */
	public function log( $message, $level = 'debug' ) {
		$this->get_api()->log( $message, $level );
	}

	/**
	 * Disponibilite du moyen de paiement a la commande.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( '' === $this->get_secret_key() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Cle d'idempotence deterministe : identique tant que la commande et son montant
	 * ne changent pas, differente des qu'ils changent.
	 *
	 * @param WC_Order $order Commande.
	 *
	 * @return string
	 */
	public function build_idempotency_key( $order ) {
		$fingerprint = implode(
			'|',
			array(
				$order->get_id(),
				$order->get_order_key(),
				(int) round( (float) $order->get_total() ),
				strtoupper( (string) $order->get_currency() ),
			)
		);

		return 'wc_' . $order->get_id() . '_' . substr( hash( 'sha256', $fingerprint ), 0, 32 );
	}

	/**
	 * Cree le paiement et redirige le client vers la page de paiement WalleoPay.
	 *
	 * @param int $order_id Identifiant de commande.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Commande introuvable.', 'walleopay' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$amount = (int) round( (float) $order->get_total() );

		if ( $amount < 100 ) {
			wc_add_notice( __( 'Le montant minimum accepté par WalleoPay est de 100 FCFA.', 'walleopay' ), 'error' );

			return array( 'result' => 'failure' );
		}

		if ( $amount > 1000000 ) {
			wc_add_notice( __( 'Le montant maximum accepté par WalleoPay est de 1 000 000 FCFA.', 'walleopay' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$reference = (string) $order->get_order_number();

		if ( '' === $reference ) {
			$reference = (string) $order->get_id();
		}

		$reference = substr( $reference, 0, 120 );

		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$description = sprintf(
			/* translators: 1: numero de commande, 2: nom de la boutique. */
			__( 'Commande %1$s – %2$s', 'walleopay' ),
			$order->get_order_number(),
			get_bloginfo( 'name' )
		);

		$payload = array(
			'amount'         => $amount,
			'currency'       => strtoupper( (string) $order->get_currency() ),
			'reference'      => $reference,
			'description'    => substr( $description, 0, 255 ),
			'customer_name'  => substr( $customer_name, 0, 120 ),
			'customer_email' => $order->get_billing_email(),
			'customer_phone' => $order->get_billing_phone(),
			'return_url'     => WalleoPay_Return::get_return_url( $order ),
			'cancel_url'     => $order->get_checkout_payment_url(),
			'notify_url'     => WC()->api_request_url( 'walleopay' ),
			'metadata'       => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => (string) $order->get_order_key(),
				'source'    => 'woocommerce',
			),
		);

		/**
		 * Permet d'ajuster le corps envoye a WalleoPay.
		 *
		 * @param array    $payload Corps de la requete.
		 * @param WC_Order $order   Commande.
		 */
		$payload = apply_filters( 'walleopay_create_payment_payload', $payload, $order );

		/*
		 * Le montant demande voyage avec le paiement, apres le filtre : quand le
		 * client paie la commission, `amount` la contient, et seul ce chiffre dit
		 * sans ambiguite ce que la commande reclamait
		 * (WalleoPay_Webhook::settled_amount()).
		 */
		if ( isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) && isset( $payload['amount'] ) ) {
			$payload['metadata']['requested_amount'] = (int) $payload['amount'];
		}

		$payment = $this->get_api()->create_payment( $payload, $this->build_idempotency_key( $order ) );

		if ( is_wp_error( $payment ) ) {
			$this->log( 'Création du paiement refusée pour la commande ' . $order->get_id() . ' : ' . $payment->get_error_message(), 'error' );

			$order->add_order_note(
				sprintf(
					/* translators: %s: message d'erreur. */
					__( 'WalleoPay : création du paiement impossible. %s', 'walleopay' ),
					$payment->get_error_message()
				)
			);

			wc_add_notice( $payment->get_error_message(), 'error' );

			return array( 'result' => 'failure' );
		}

		$checkout_url = isset( $payment['checkout_url'] ) ? (string) $payment['checkout_url'] : '';

		if ( '' === $checkout_url ) {
			$this->log( 'Aucune checkout_url renvoyée pour la commande ' . $order->get_id(), 'error' );
			wc_add_notice( __( 'WalleoPay n’a pas renvoyé de page de paiement. Veuillez réessayer.', 'walleopay' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_walleopay_payment_id', sanitize_text_field( isset( $payment['id'] ) ? (string) $payment['id'] : '' ) );
		$order->update_meta_data( '_walleopay_reference', sanitize_text_field( isset( $payment['reference'] ) ? (string) $payment['reference'] : $reference ) );
		$order->update_meta_data( '_walleopay_mode', sanitize_text_field( isset( $payment['mode'] ) ? (string) $payment['mode'] : $this->get_mode() ) );
		$order->update_meta_data( '_walleopay_status', sanitize_text_field( isset( $payment['status'] ) ? (string) $payment['status'] : 'pending' ) );

		$order->add_order_note(
			sprintf(
				/* translators: 1: identifiant du paiement, 2: mode. */
				__( 'Paiement WalleoPay créé (%1$s, mode %2$s). En attente de confirmation par l’opérateur.', 'walleopay' ),
				isset( $payment['id'] ) ? $payment['id'] : '—',
				isset( $payment['mode'] ) ? $payment['mode'] : $this->get_mode()
			)
		);

		$order->save();

		// Le stock est reserve, mais la commande reste impayee tant que l'API n'a pas confirme.
		wc_maybe_reduce_stock_levels( $order->get_id() );

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}
}
