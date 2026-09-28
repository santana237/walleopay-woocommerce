<?php
/**
 * Gestion des notifications (webhooks) WalleoPay et application du statut aux commandes.
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WalleoPay_Webhook
 */
class WalleoPay_Webhook {

	/**
	 * Tolerance maximale, en secondes, entre l'horodatage signe et l'heure du serveur.
	 */
	const SIGNATURE_TOLERANCE = 300;

	/**
	 * Statuts definitifs cote WalleoPay.
	 *
	 * @return array
	 */
	public static function final_statuses() {
		return array( 'succeeded', 'failed', 'cancelled', 'expired' );
	}

	/**
	 * Enregistre l'endpoint de notification.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_api_walleopay', array( __CLASS__, 'handle' ) );
	}

	/**
	 * URL a coller dans le tableau de bord WalleoPay.
	 *
	 * @return string
	 */
	public static function get_notify_url() {
		return WC()->api_request_url( 'walleopay' );
	}

	/**
	 * Traite la requete de notification entrante.
	 *
	 * @return void
	 */
	public static function handle() {
		$gateway = WC_Gateway_WalleoPay::instance();

		// Le corps DOIT etre lu brut : la signature porte sur les octets exacts.
		$raw_body = file_get_contents( 'php://input' );

		if ( ! is_string( $raw_body ) || '' === $raw_body ) {
			$gateway->log( 'Webhook reçu avec un corps vide.', 'error' );
			self::respond( 400, 'empty_body' );
		}

		$signature_header = isset( $_SERVER['HTTP_X_WALLEOPAY_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WALLEOPAY_SIGNATURE'] ) )
			: '';
		$event_header     = isset( $_SERVER['HTTP_X_WALLEOPAY_EVENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WALLEOPAY_EVENT'] ) )
			: '';
		$delivery_header  = isset( $_SERVER['HTTP_X_WALLEOPAY_DELIVERY'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WALLEOPAY_DELIVERY'] ) )
			: '';

		$secret = $gateway->get_webhook_secret();

		if ( '' === $secret ) {
			$gateway->log( 'Webhook reçu mais aucun secret de webhook n’est configuré.', 'error' );
			self::respond( 401, 'webhook_secret_not_configured' );
		}

		$verified = self::verify_signature( $raw_body, $signature_header, $secret );

		if ( is_wp_error( $verified ) ) {
			$gateway->log( 'Webhook rejeté (' . $verified->get_error_code() . ') livraison ' . $delivery_header, 'error' );
			self::respond( 401, $verified->get_error_code() );
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			$gateway->log( 'Webhook au format inattendu, livraison ' . $delivery_header, 'error' );
			self::respond( 400, 'invalid_payload' );
		}

		$event = isset( $payload['event'] ) ? (string) $payload['event'] : $event_header;
		$data  = $payload['data'];

		$gateway->log( sprintf( 'Webhook « %1$s » reçu (livraison %2$s).', $event, $delivery_header ) );

		// Les evenements de virement ne concernent pas les commandes WooCommerce.
		if ( 0 === strpos( $event, 'payout.' ) ) {
			self::respond( 200, 'ignored_event' );
		}

		$order = self::find_order( $data );

		if ( ! $order ) {
			$gateway->log( 'Aucune commande WooCommerce ne correspond à ce webhook.', 'error' );
			// 200 : inutile que WalleoPay rejoue indefiniment une notification orpheline.
			self::respond( 200, 'order_not_found' );
		}

		$payment_id = isset( $data['id'] ) ? (string) $data['id'] : (string) $order->get_meta( '_walleopay_payment_id' );

		if ( '' === $payment_id ) {
			$payment_id = (string) $order->get_meta( '_walleopay_reference' );
		}

		/*
		 * REGLE DE SECURITE : on ne fait jamais confiance au contenu du webhook pour
		 * marquer une commande payee. On re-interroge systematiquement l'API.
		 */
		$result = self::verify_and_apply( $order, $payment_id, __( 'notification WalleoPay', 'walleopay' ) );

		if ( is_wp_error( $result ) ) {
			$gateway->log( 'Échec du traitement du webhook : ' . $result->get_error_message(), 'error' );

			// 500 : WalleoPay pourra rejouer la notification.
			self::respond( 500, $result->get_error_code() );
		}

		self::respond( 200, 'ok' );
	}

	/**
	 * Verifie la signature HMAC et la fraicheur de l'horodatage.
	 *
	 * @param string $raw_body Corps brut de la requete.
	 * @param string $header   Contenu de l'en-tete X-WalleoPay-Signature.
	 * @param string $secret   Secret de webhook (whsec_...).
	 *
	 * @return true|WP_Error
	 */
	public static function verify_signature( $raw_body, $header, $secret ) {
		if ( '' === (string) $header ) {
			return new WP_Error( 'missing_signature', __( 'Signature absente.', 'walleopay' ) );
		}

		$timestamp = '';
		$signature = '';

		foreach ( explode( ',', (string) $header ) as $part ) {
			$part  = trim( $part );
			$pair  = explode( '=', $part, 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = trim( $pair[1] );
			} elseif ( 'v1' === $pair[0] ) {
				$signature = trim( $pair[1] );
			}
		}

		if ( '' === $timestamp || '' === $signature ) {
			return new WP_Error( 'malformed_signature', __( 'Signature mal formée.', 'walleopay' ) );
		}

		// 2. Fraicheur de l'horodatage (protection contre le rejeu).
		$age = abs( time() - (int) $timestamp );

		if ( $age > self::SIGNATURE_TOLERANCE ) {
			return new WP_Error( 'stale_signature', __( 'Horodatage de signature hors tolérance.', 'walleopay' ) );
		}

		// 1. Comparaison a temps constant, jamais avec « == ».
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'invalid_signature', __( 'Signature invalide.', 'walleopay' ) );
		}

		return true;
	}

	/**
	 * Retrouve la commande WooCommerce liee a un paiement.
	 *
	 * @param array $data Objet « data » du paiement.
	 *
	 * @return WC_Order|false
	 */
	public static function find_order( $data ) {
		$order = false;

		if ( isset( $data['metadata'] ) && is_array( $data['metadata'] ) && ! empty( $data['metadata']['order_id'] ) ) {
			$candidate = wc_get_order( absint( $data['metadata']['order_id'] ) );

			if ( $candidate instanceof WC_Order ) {
				$order_key = isset( $data['metadata']['order_key'] ) ? (string) $data['metadata']['order_key'] : '';

				if ( '' === $order_key || hash_equals( (string) $candidate->get_order_key(), $order_key ) ) {
					$order = $candidate;
				}
			}
		}

		if ( ! $order && ! empty( $data['reference'] ) ) {
			$order = self::find_order_by_reference( (string) $data['reference'] );
		}

		return $order;
	}

	/**
	 * Retrouve une commande a partir de la reference marchand.
	 *
	 * @param string $reference Reference envoyee a WalleoPay.
	 *
	 * @return WC_Order|false
	 */
	public static function find_order_by_reference( $reference ) {
		$reference = trim( (string) $reference );

		if ( '' === $reference ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_walleopay_reference',
						'value'   => $reference,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $orders ) ) {
			$order = wc_get_order( absint( $orders[0] ) );

			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		// Repli : la reference est le numero de commande, souvent egal a
		// l'identifiant, suivi du numero de tentative a partir de la deuxieme
		// (« 1234-2 », voir WalleoPay_Attempts::reference()).
		if ( preg_match( '/^(\d+)(?:-\d+)?$/', $reference, $matches ) ) {
			$order = wc_get_order( absint( $matches[1] ) );

			if ( $order instanceof WC_Order && 'walleopay' === $order->get_payment_method() ) {
				return $order;
			}
		}

		return false;
	}

	/**
	 * Coeur de la regle de securite : re-interroge l'API, controle le montant et la
	 * devise, puis applique le statut a la commande.
	 *
	 * Utilise par le webhook, la page de retour client et le bouton d'administration.
	 *
	 * Une commande peut compter plusieurs tentatives de paiement
	 * (WalleoPay_Attempts) : « 1234 », puis « 1234-2 »… Seule la derniere
	 * presentee au client (meta « _walleopay_payment_id ») dit encore ou en est
	 * la commande. La notification tardive d'une tentative remplacee — la
	 * page expiree d'hier, celle que l'extension a annulee parce que le total
	 * avait change — ne la fait plus echouer ni annuler, et ne detourne plus
	 * la page de retour vers un paiement mort. L'argent, lui, compte d'ou
	 * qu'il vienne : un paiement reussi ou annonce au code marchand
	 * s'applique quelle que soit sa tentative.
	 *
	 * @param WC_Order $order      Commande concernee.
	 * @param string   $payment_id Identifiant WalleoPay (ou reference marchand).
	 * @param string   $context    Contexte, pour les notes de commande.
	 *
	 * @return array|WP_Error Objet « data » du paiement verifie, ou WP_Error.
	 */
	public static function verify_and_apply( $order, $payment_id, $context = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'invalid_order', __( 'Commande introuvable.', 'walleopay' ) );
		}

		$gateway = WC_Gateway_WalleoPay::instance();
		$api     = $gateway->get_api_for_order( $order );

		$payment_id = (string) $payment_id;

		if ( '' === $payment_id ) {
			$payment_id = (string) $order->get_meta( '_walleopay_payment_id' );
		}

		if ( '' === $payment_id ) {
			$payment_id = (string) $order->get_meta( '_walleopay_reference' );
		}

		if ( '' === $payment_id ) {
			return new WP_Error( 'missing_payment_id', __( 'Aucun paiement WalleoPay n’est associé à cette commande.', 'walleopay' ) );
		}

		// 3. Verite unique : l'API, jamais le webhook ni le navigateur.
		$payment = $api->get_payment( $payment_id );

		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$status = isset( $payment['status'] ) ? (string) $payment['status'] : '';

		if ( '' === $status ) {
			return new WP_Error( 'missing_status', __( 'Statut de paiement absent de la réponse WalleoPay.', 'walleopay' ) );
		}

		$verified_id = isset( $payment['id'] ) ? (string) $payment['id'] : '';
		$current_id  = (string) $order->get_meta( '_walleopay_payment_id' );
		$is_current  = '' === $verified_id || '' === $current_id || $verified_id === $current_id;
		$money       = 'succeeded' === $status || 'awaiting_confirmation' === $status;

		// Commande deja reglee par un autre paiement : celui-ci ne change plus
		// rien a la commande. S'il a reussi, le client a paye deux fois, et
		// le marchand doit le savoir avant le rapprochement.
		if ( '' !== $verified_id && $order->is_paid() && $verified_id !== (string) $order->get_transaction_id() ) {
			if ( 'succeeded' === $status ) {
				self::flag_duplicate( $order, $payment );
			}

			return $payment;
		}

		if ( ! $is_current && ! $money ) {
			$gateway->log(
				sprintf(
					'Commande %1$d : la tentative %2$s (%3$s) a été remplacée par %4$s, rien n’est appliqué.',
					$order->get_id(),
					$verified_id,
					$status,
					$current_id
				)
			);

			return $payment;
		}

		// Statut deja connu pour cette tentative : evite de repeter une note a
		// chaque relecture (notification, retour client, rafraichissement).
		$previous_status = $is_current ? (string) $order->get_meta( '_walleopay_status' ) : '';

		self::store_payment_meta( $order, $payment );

		// 4. Controle du montant et de la devise avant toute validation.
		if ( 'succeeded' === $status || 'awaiting_confirmation' === $status ) {
			$mismatch = self::check_amount_and_currency( $order, $payment );

			if ( is_wp_error( $mismatch ) ) {
				$order->add_order_note( $mismatch->get_error_message() );
				$order->update_status( 'on-hold', __( 'Vérification manuelle requise (WalleoPay).', 'walleopay' ) );
				$gateway->log( 'Commande ' . $order->get_id() . ' : ' . $mismatch->get_error_message(), 'error' );

				return $mismatch;
			}
		}

		self::apply_status( $order, $payment, $context, $previous_status );

		// Une tentative plus ancienne vient de regler la commande : la page
		// courante, si elle attend encore le client, n'a plus d'objet.
		if ( ! $is_current && 'succeeded' === $status && $order->is_paid() ) {
			self::close_superseded( $api, $current_id );
		}

		return $payment;
	}

	/**
	 * Ferme la tentative courante quand une autre a deja regle la commande.
	 *
	 * Seulement si elle attend encore le client (« pending ») : une demande
	 * deja partie vers son telephone ne s'annule pas sans risque, et un echec
	 * ici ne change rien a la commande, deja reglee. Si elle aboutit malgre
	 * tout, flag_duplicate() le signalera.
	 *
	 * @param WalleoPay_API $api        Client du mode de la commande.
	 * @param string        $payment_id Tentative courante.
	 *
	 * @return void
	 */
	protected static function close_superseded( $api, $payment_id ) {
		$current = $api->get_payment( $payment_id );

		if ( is_wp_error( $current ) || ! isset( $current['status'] ) || 'pending' !== $current['status'] ) {
			return;
		}

		$cancel = $api->cancel_payment( $payment_id );

		if ( is_wp_error( $cancel ) ) {
			WC_Gateway_WalleoPay::instance()->log( 'Tentative ' . $payment_id . ' non fermée : ' . $cancel->get_error_message(), 'error' );
		}
	}

	/**
	 * Signale, une seule fois, un paiement reussi arrive pour une commande
	 * deja reglee par un autre.
	 *
	 * L'extension n'ouvre jamais deux tentatives payables a la fois, mais une
	 * page d'echec encore ouverte dans un autre onglet peut etre relancee par
	 * le client. La commande n'est pas touchee : la note dit quoi rembourser.
	 *
	 * @param WC_Order $order   Commande deja reglee.
	 * @param array    $payment Paiement reussi, relu aupres de l'API.
	 *
	 * @return void
	 */
	protected static function flag_duplicate( $order, $payment ) {
		$payment_id = isset( $payment['id'] ) ? (string) $payment['id'] : '';
		$known      = $order->get_meta( '_walleopay_duplicates' );
		$known      = is_array( $known ) ? $known : array();

		if ( '' === $payment_id || in_array( $payment_id, $known, true ) ) {
			return;
		}

		$known[] = $payment_id;
		$order->update_meta_data( '_walleopay_duplicates', $known );

		$order->add_order_note(
			sprintf(
				/* translators: 1: identifiant du paiement en trop, 2: transaction qui a regle la commande. */
				__( 'WalleoPay : le paiement %1$s a réussi alors que la commande était déjà réglée (%2$s). Vérifiez s’il faut le rembourser depuis votre tableau de bord WalleoPay.', 'walleopay' ),
				$payment_id,
				'' !== (string) $order->get_transaction_id() ? (string) $order->get_transaction_id() : '—'
			)
		);

		$order->save();

		WC_Gateway_WalleoPay::instance()->log( 'Commande ' . $order->get_id() . ' : second paiement réussi ' . $payment_id . '.', 'error' );
	}

	/**
	 * Controle que le montant et la devise renvoyes par l'API correspondent a la commande.
	 *
	 * @param WC_Order $order   Commande.
	 * @param array    $payment Objet paiement renvoye par l'API.
	 *
	 * @return true|WP_Error
	 */
	public static function check_amount_and_currency( $order, $payment ) {
		$api_amount   = isset( $payment['amount'] ) ? (int) $payment['amount'] : -1;
		$api_currency = isset( $payment['currency'] ) ? strtoupper( (string) $payment['currency'] ) : '';

		$order_amount   = (int) round( (float) $order->get_total() );
		$order_currency = strtoupper( (string) $order->get_currency() );

		if ( null === self::settled_amount( $payment, $order_amount ) ) {
			return new WP_Error(
				'walleopay_amount_mismatch',
				sprintf(
					/* translators: 1: montant renvoye par WalleoPay, 2: total de la commande. */
					__( 'WalleoPay : montant incohérent. Le paiement porte sur %1$d alors que la commande totalise %2$d. La commande n’a PAS été validée, une vérification manuelle est nécessaire.', 'walleopay' ),
					$api_amount,
					$order_amount
				)
			);
		}

		if ( '' !== $api_currency && $api_currency !== $order_currency ) {
			return new WP_Error(
				'walleopay_currency_mismatch',
				sprintf(
					/* translators: 1: devise renvoyee par WalleoPay, 2: devise de la commande. */
					__( 'WalleoPay : devise incohérente. Le paiement est en %1$s alors que la commande est en %2$s. La commande n’a PAS été validée, une vérification manuelle est nécessaire.', 'walleopay' ),
					$api_currency,
					$order_currency
				)
			);
		}

		return true;
	}

	/**
	 * Part du paiement qui regle la commande, ou null si elle ne correspond pas.
	 *
	 * Quand le client paie la commission, WalleoPay l'ajoute par-dessus le
	 * montant demande : `amount` vaut alors le total de la commande PLUS
	 * `fee`, et `net` le total seul. Comparer `amount` au total echouait donc
	 * a tous les coups, et chaque commande finissait « En attente » pour
	 * montant incoherent.
	 *
	 * L'API ne dit pas qui porte la commission : l'extension glisse donc le
	 * montant qu'elle demande dans les metadonnees (`requested_amount`).
	 * Present, il doit valoir le total de la commande, et le paiement doit en
	 * etre l'une des deux formes : le montant seul, ou le montant plus la
	 * commission. Absent (paiement cree par une version anterieure), les deux
	 * formes sont essayees sur le total. Un montant vraiment different bloque
	 * toujours la commande.
	 *
	 * @param array $payment  Objet paiement renvoye par l'API.
	 * @param int   $expected Montant attendu (total de la commande, en francs).
	 *
	 * @return array|null amount (regle), customer_fee (commission payee par le client, 0 sinon).
	 */
	public static function settled_amount( $payment, $expected ) {
		$amount   = isset( $payment['amount'] ) ? (int) $payment['amount'] : -1;
		$fee      = isset( $payment['fee'] ) ? (int) $payment['fee'] : 0;
		$expected = (int) $expected;

		if ( isset( $payment['metadata']['requested_amount'] ) && (int) $payment['metadata']['requested_amount'] !== $expected ) {
			return null;
		}

		if ( $amount === $expected ) {
			return array(
				'amount'       => $expected,
				'customer_fee' => 0,
			);
		}

		// `net` est ce que le marchand touche : quand il est fourni, il doit
		// tomber lui aussi sur le total de la commande.
		if ( $fee > 0 && $amount - $fee === $expected
			&& ( ! isset( $payment['net'] ) || (int) $payment['net'] === $expected ) ) {
			return array(
				'amount'       => $expected,
				'customer_fee' => $fee,
			);
		}

		return null;
	}

	/**
	 * Enregistre les metadonnees utiles du paiement sur la commande.
	 *
	 * @param WC_Order $order   Commande.
	 * @param array    $payment Objet paiement.
	 *
	 * @return void
	 */
	protected static function store_payment_meta( $order, $payment ) {
		if ( ! empty( $payment['id'] ) ) {
			$order->update_meta_data( '_walleopay_payment_id', sanitize_text_field( (string) $payment['id'] ) );
		}

		if ( ! empty( $payment['reference'] ) ) {
			$order->update_meta_data( '_walleopay_reference', sanitize_text_field( (string) $payment['reference'] ) );
		}

		if ( ! empty( $payment['mode'] ) ) {
			$order->update_meta_data( '_walleopay_mode', sanitize_text_field( (string) $payment['mode'] ) );
		}

		if ( ! empty( $payment['operator'] ) ) {
			$order->update_meta_data( '_walleopay_operator', sanitize_text_field( (string) $payment['operator'] ) );
		}

		if ( isset( $payment['status'] ) ) {
			$order->update_meta_data( '_walleopay_status', sanitize_text_field( (string) $payment['status'] ) );
		}

		$order->update_meta_data( '_walleopay_checked_at', gmdate( 'c' ) );
		$order->save();
	}

	/**
	 * Applique le statut du paiement a la commande. Idempotent.
	 *
	 * @param WC_Order $order           Commande.
	 * @param array    $payment         Objet paiement verifie aupres de l'API.
	 * @param string   $context         Contexte pour les notes.
	 * @param string   $previous_status Statut deja connu de cette tentative, '' sinon.
	 *
	 * @return void
	 */
	public static function apply_status( $order, $payment, $context = '', $previous_status = '' ) {
		$status     = isset( $payment['status'] ) ? (string) $payment['status'] : '';
		$payment_id = isset( $payment['id'] ) ? (string) $payment['id'] : '';
		$operator   = isset( $payment['operator'] ) ? (string) $payment['operator'] : '';
		$suffix     = '' !== $context ? sprintf( ' (source : %s)', $context ) : '';

		switch ( $status ) {
			case 'succeeded':
				// Idempotence : ne jamais rejouer un paiement deja complete.
				if ( $order->is_paid() || $order->get_meta( '_walleopay_completed' ) ) {
					return;
				}

				$order->add_order_note(
					sprintf(
						/* translators: 1: identifiant du paiement, 2: operateur, 3: contexte. */
						__( 'Paiement WalleoPay confirmé auprès de l’API. Identifiant : %1$s. Opérateur : %2$s.%3$s', 'walleopay' ),
						$payment_id,
						'' !== $operator ? $operator : '—',
						$suffix
					)
				);

				// Le debit du client depasse alors le total de la commande :
				// la note l'explique avant qu'on ne le decouvre au rapprochement.
				$settled = self::settled_amount( $payment, (int) round( (float) $order->get_total() ) );

				if ( null !== $settled && $settled['customer_fee'] > 0 ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: commission payee par le client, 2: montant debite. */
							__( 'WalleoPay : le client a payé la commission (%1$d) en plus du total de la commande. Montant débité : %2$d.', 'walleopay' ),
							$settled['customer_fee'],
							isset( $payment['amount'] ) ? (int) $payment['amount'] : 0
						)
					);
				}

				$order->update_meta_data( '_walleopay_completed', 'yes' );
				$order->save();

				// WooCommerce choisit lui-meme processing ou completed.
				$order->payment_complete( $payment_id );

				if ( WC()->cart && WC_Gateway_WalleoPay::instance()->should_empty_cart() ) {
					WC()->cart->empty_cart();
				}
				break;

			case 'awaiting_confirmation':
				if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
					return;
				}

				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: identifiant du paiement, 2: contexte. */
						__( 'WalleoPay : le client a payé au code marchand, le rapprochement manuel est en cours. La commande sera validée automatiquement une fois confirmée. Identifiant : %1$s.%2$s', 'walleopay' ),
						$payment_id,
						$suffix
					)
				);
				break;

			case 'failed':
				if ( $order->is_paid() || $order->has_status( 'failed' ) ) {
					return;
				}

				$reason = '';

				if ( ! empty( $payment['failure_message'] ) ) {
					$reason = (string) $payment['failure_message'];
				} elseif ( ! empty( $payment['failure_code'] ) ) {
					$reason = (string) $payment['failure_code'];
				}

				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: motif d'echec, 2: contexte. */
						__( 'Paiement WalleoPay échoué. Motif : %1$s.%2$s', 'walleopay' ),
						'' !== $reason ? $reason : __( 'non précisé', 'walleopay' ),
						$suffix
					)
				);
				break;

			case 'cancelled':
			case 'expired':
				if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
					return;
				}

				$note = 'expired' === $status
					? sprintf(
						/* translators: %s: contexte. */
						__( 'Paiement WalleoPay expiré avant confirmation.%s', 'walleopay' ),
						$suffix
					)
					: sprintf(
						/* translators: %s: contexte. */
						__( 'Paiement WalleoPay annulé.%s', 'walleopay' ),
						$suffix
					);

				/*
				 * La commande attend encore son paiement : elle reste payable.
				 * Une page laissee expirer ou abandonnee par le client annulait
				 * la commande, et « Payer la commande » — ou la page de retour
				 * elle-meme, qui y renvoie le client pour « relancer le
				 * reglement » — se heurtait a une commande annulee. La tentative
				 * suivante s'ouvre desormais sous sa propre reference
				 * (WalleoPay_Attempts). Le stock, lui, est rendu comme le
				 * faisait l'annulation : la tentative suivante le reserve a
				 * nouveau, et une commande jamais reglee finit annulee par
				 * WooCommerce au terme de la reservation de stock.
				 */
				if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
					if ( $previous_status === $status ) {
						return;
					}

					$order->add_order_note( $note );

					if ( function_exists( 'wc_maybe_increase_stock_levels' ) ) {
						wc_maybe_increase_stock_levels( $order->get_id() );
					}
					break;
				}

				// En attente d'un rapprochement qui n'a pas abouti : la
				// commande est close, comme avant.
				$order->update_status( 'cancelled', $note );
				break;

			default:
				// pending / processing : statut non definitif, on laisse la commande en attente.
				$order->add_order_note(
					sprintf(
						/* translators: 1: statut, 2: contexte. */
						__( 'WalleoPay : statut « %1$s », non définitif. Aucune action appliquée.%2$s', 'walleopay' ),
						$status,
						$suffix
					)
				);
				break;
		}
	}

	/**
	 * Termine la requete avec un code HTTP et une courte reponse JSON.
	 *
	 * @param int    $code   Code HTTP.
	 * @param string $reason Motif technique.
	 *
	 * @return void
	 */
	protected static function respond( $code, $reason ) {
		status_header( (int) $code );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'received' => ( $code >= 200 && $code < 300 ), 'reason' => $reason ) );
		exit;
	}
}
