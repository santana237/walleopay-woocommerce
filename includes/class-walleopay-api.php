<?php
/**
 * Client HTTP minimal pour l'API WalleoPay.
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WalleoPay_API
 *
 * S'appuie uniquement sur l'API HTTP de WordPress (wp_remote_*).
 * Aucune dependance Composer, aucun appel cURL direct.
 */
class WalleoPay_API {

	/**
	 * URL de base par defaut de l'API.
	 */
	const DEFAULT_BASE_URL = 'https://walleopay.com/api/v1';

	/**
	 * Cle secrete (sk_live_... ou sk_test_...).
	 *
	 * @var string
	 */
	protected $secret_key;

	/**
	 * Journalisation detaillee activee.
	 *
	 * @var bool
	 */
	protected $debug;

	/**
	 * Instance du logger WooCommerce.
	 *
	 * @var WC_Logger_Interface|null
	 */
	protected $logger = null;

	/**
	 * La derniere reponse etait-elle rejouee par l'idempotence ?
	 *
	 * @var bool
	 */
	protected $last_replayed = false;

	/**
	 * Constructeur.
	 *
	 * @param string $secret_key Cle secrete WalleoPay.
	 * @param bool   $debug      Active la journalisation detaillee.
	 */
	public function __construct( $secret_key, $debug = false ) {
		$this->secret_key = trim( (string) $secret_key );
		$this->debug      = (bool) $debug;
	}

	/**
	 * URL de base de l'API, filtrable pour les environnements de developpement.
	 *
	 * @return string
	 */
	public function get_base_url() {
		$base_url = apply_filters( 'walleopay_api_base_url', self::DEFAULT_BASE_URL );

		return untrailingslashit( (string) $base_url );
	}

	/**
	 * Indique si une cle secrete est configuree.
	 *
	 * @return bool
	 */
	public function has_key() {
		return '' !== $this->secret_key;
	}

	/**
	 * Cree un paiement.
	 *
	 * @param array  $payload         Corps de la requete.
	 * @param string $idempotency_key Cle d'idempotence (optionnelle mais recommandee).
	 *
	 * @return array|WP_Error Tableau « data » de la reponse, ou WP_Error.
	 */
	public function create_payment( $payload, $idempotency_key = '' ) {
		$headers = array();

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		return $this->request( 'POST', '/payments', $payload, $headers );
	}

	/**
	 * Recupere un paiement. L'identifiant accepte un « pay_... » ou la reference marchand.
	 *
	 * @param string $id Identifiant WalleoPay ou reference marchand.
	 *
	 * @return array|WP_Error
	 */
	public function get_payment( $id ) {
		$id = (string) $id;

		if ( '' === $id ) {
			return new WP_Error( 'walleopay_missing_id', __( 'Identifiant de paiement manquant.', 'walleopay' ) );
		}

		return $this->request( 'GET', '/payments/' . rawurlencode( $id ) );
	}

	/**
	 * Annule un paiement encore en cours.
	 *
	 * @param string $id Identifiant WalleoPay ou reference marchand.
	 *
	 * @return array|WP_Error
	 */
	public function cancel_payment( $id ) {
		$id = (string) $id;

		if ( '' === $id ) {
			return new WP_Error( 'walleopay_missing_id', __( 'Identifiant de paiement manquant.', 'walleopay' ) );
		}

		return $this->request( 'POST', '/payments/' . rawurlencode( $id ) . '/cancel' );
	}

	/**
	 * La derniere reponse portait-elle l'en-tete « Idempotent-Replay: true » ?
	 *
	 * Une telle reponse est celle de la creation d'origine, rejouee telle
	 * quelle : elle decrit le paiement a sa naissance, pas tel qu'il est. Un
	 * paiement echoue depuis y figure encore « pending », avec sa page morte.
	 * WalleoPay_Attempts le relit donc par GET avant de le presenter.
	 *
	 * @return bool
	 */
	public function was_replayed() {
		return $this->last_replayed;
	}

	/**
	 * Recupere les informations du compte marchand (utile pour tester une cle).
	 *
	 * @return array|WP_Error
	 */
	public function get_account() {
		return $this->request( 'GET', '/account' );
	}

	/**
	 * Execute une requete HTTP vers l'API.
	 *
	 * @param string $method  Verbe HTTP.
	 * @param string $path    Chemin relatif (commence par /).
	 * @param array  $body    Corps JSON eventuel.
	 * @param array  $headers En-tetes additionnels.
	 * @param bool   $is_retry Indique qu'il s'agit du reessai apres un 429.
	 *
	 * @return array|WP_Error
	 */
	protected function request( $method, $path, $body = array(), $headers = array(), $is_retry = false ) {
		$this->last_replayed = false;

		if ( ! $this->has_key() ) {
			return new WP_Error(
				'walleopay_missing_key',
				__( 'Aucune clé secrète WalleoPay n’est configurée pour ce mode.', 'walleopay' )
			);
		}

		$url = $this->get_base_url() . $path;

		$args = array(
			'method'      => $method,
			'timeout'     => 45,
			'redirection' => 0,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->secret_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'WalleoPay-WooCommerce/' . WALLEOPAY_WC_VERSION . '; ' . home_url( '/' ),
				),
				$headers
			),
		);

		if ( ! empty( $body ) && 'GET' !== $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->log(
			sprintf(
				'Requête %1$s %2$s %3$s',
				$method,
				$path,
				empty( $body ) ? '' : $this->redact( $body )
			)
		);

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$response = wp_remote_post( $url, $args );
		}

		// Erreur reseau / DNS / TLS.
		if ( is_wp_error( $response ) ) {
			$this->log( 'Erreur réseau : ' . $response->get_error_message(), 'error' );

			return new WP_Error(
				'walleopay_network_error',
				sprintf(
					/* translators: %s: message d'erreur technique. */
					__( 'Impossible de joindre WalleoPay (%s). Veuillez réessayer dans un instant.', 'walleopay' ),
					$response->get_error_message()
				),
				array( 'original' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}

		$replayed            = wp_remote_retrieve_header( $response, 'idempotent-replay' );
		$this->last_replayed = 'true' === strtolower( trim( is_array( $replayed ) ? (string) reset( $replayed ) : (string) $replayed ) );

		// Limitation de debit : un seul reessai, en respectant Retry-After.
		if ( 429 === $status ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

			if ( $retry_after < 1 ) {
				$retry_after = 2;
			}

			if ( $is_retry || $retry_after > 10 ) {
				$this->log( 'Limite de débit atteinte (429), abandon après ' . ( $is_retry ? 'réessai' : 'délai trop long' ) . '.', 'error' );

				return new WP_Error(
					'walleopay_rate_limited',
					__( 'WalleoPay a temporairement limité les requêtes. Veuillez réessayer dans une minute.', 'walleopay' ),
					array( 'status' => 429, 'retry_after' => $retry_after )
				);
			}

			$this->log( 'Limite de débit atteinte (429), réessai dans ' . $retry_after . ' s.', 'warning' );
			sleep( $retry_after );

			return $this->request( $method, $path, $body, $headers, true );
		}

		if ( $status < 200 || $status > 299 ) {
			$error   = self::parse_error( $parsed );
			$type    = $error['type'];
			$message = $error['message'];

			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %d: code HTTP. */
					__( 'WalleoPay a renvoyé une erreur HTTP %d.', 'walleopay' ),
					$status
				);
			}

			$this->log( sprintf( 'Erreur API %1$d (%2$s) : %3$s', $status, $type, $message ), 'error' );

			return new WP_Error(
				'walleopay_api_error',
				$this->translate_error( $type, $message ),
				array(
					'status'  => $status,
					'type'    => $type,
					'message' => $message,
					'fields'  => $error['fields'],
				)
			);
		}

		if ( ! isset( $parsed['data'] ) || ! is_array( $parsed['data'] ) ) {
			$this->log( 'Réponse inattendue (clé « data » absente).', 'error' );

			return new WP_Error(
				'walleopay_unexpected_response',
				__( 'Réponse inattendue de WalleoPay.', 'walleopay' )
			);
		}

		$this->log( sprintf( 'Réponse %1$d %2$s : %3$s', $status, $path, $this->redact( $parsed['data'] ) ) );

		return $parsed['data'];
	}

	/**
	 * Ramene les deux formes d'erreur de l'API a un type, un message et la
	 * liste des champs refuses.
	 *
	 * Les erreurs metier arrivent sous « error » ; les erreurs de validation
	 * sous la forme Laravel « message » + « errors » par champ. Cette seconde
	 * forme passait jusqu'ici pour une erreur HTTP 422 anonyme : impossible
	 * d'y reconnaitre une reference deja prise, que les tentatives de
	 * paiement doivent enjamber (WalleoPay_Attempts::is_taken()).
	 *
	 * @param array $parsed Corps JSON decode.
	 *
	 * @return array type, message, fields (liste des champs refuses).
	 */
	public static function parse_error( $parsed ) {
		$parsed = is_array( $parsed ) ? $parsed : array();
		$error  = array(
			'type'    => '',
			'message' => '',
			'fields'  => array(),
		);

		if ( isset( $parsed['error'] ) && is_array( $parsed['error'] ) ) {
			$error['type']    = isset( $parsed['error']['type'] ) ? (string) $parsed['error']['type'] : '';
			$error['message'] = isset( $parsed['error']['message'] ) ? (string) $parsed['error']['message'] : '';

			return $error;
		}

		if ( isset( $parsed['errors'] ) && is_array( $parsed['errors'] ) ) {
			$messages = array();

			foreach ( $parsed['errors'] as $field => $field_messages ) {
				$error['fields'][] = (string) $field;

				foreach ( (array) $field_messages as $field_message ) {
					$messages[] = (string) $field_message;
				}
			}

			$error['type']    = 'invalid_request';
			$error['message'] = implode( ' ', $messages );
		}

		if ( '' === $error['message'] && isset( $parsed['message'] ) ) {
			$error['message'] = (string) $parsed['message'];
		}

		return $error;
	}

	/**
	 * Traduit les types d'erreur connus en messages exploitables par le marchand.
	 *
	 * @param string $type    Type d'erreur renvoye par l'API.
	 * @param string $message Message brut renvoye par l'API.
	 *
	 * @return string
	 */
	protected function translate_error( $type, $message ) {
		switch ( $type ) {
			case 'authentication_error':
				return __( 'Clé secrète WalleoPay invalide ou révoquée. Vérifiez les réglages de la passerelle.', 'walleopay' );
			case 'merchant_not_active':
			case 'merchant_suspended':
			case 'account_closed':
				// Compte suspendu ou ferme : le message de l'API s'adresse au
				// titulaire (« Ecrivez-nous »), pas au client de la boutique.
				return __( 'Le compte marchand WalleoPay n’est pas actif.', 'walleopay' );
			case 'kyc_not_approved':
				return __( 'La vérification d’identité (KYC) du compte WalleoPay n’est pas encore approuvée.', 'walleopay' );
			case 'service_not_approved':
				return __( 'Ce moyen de paiement n’est pas encore approuvé sur votre compte WalleoPay.', 'walleopay' );
			case 'invalid_request':
				return sprintf(
					/* translators: %s: message renvoye par l'API. */
					__( 'Requête refusée par WalleoPay : %s', 'walleopay' ),
					$message
				);
			case 'not_found':
				return __( 'Paiement introuvable chez WalleoPay.', 'walleopay' );
			case 'idempotency_conflict':
				return __( 'Un paiement différent existe déjà avec la même clé d’idempotence.', 'walleopay' );
			case 'idempotency_in_progress':
				return __( 'Un paiement identique est déjà en cours de traitement. Patientez quelques secondes.', 'walleopay' );
			case 'operator_unknown':
				return __( 'Le numéro de téléphone ne correspond à aucun opérateur pris en charge.', 'walleopay' );
			case 'payout_refused':
				return __( 'Opération refusée par WalleoPay.', 'walleopay' );
			default:
				return $message;
		}
	}

	/**
	 * Masque toute donnee sensible avant journalisation.
	 *
	 * @param mixed $data Donnees a journaliser.
	 *
	 * @return string
	 */
	protected function redact( $data ) {
		if ( is_array( $data ) ) {
			$sensitive = array( 'secret', 'secret_key', 'api_key', 'authorization', 'token', 'webhook_secret' );

			foreach ( $data as $key => $value ) {
				if ( in_array( strtolower( (string) $key ), $sensitive, true ) ) {
					$data[ $key ] = '***';
				} elseif ( is_array( $value ) ) {
					$data[ $key ] = json_decode( $this->redact( $value ), true );
				}
			}

			$data = wp_json_encode( $data );
		}

		$data = (string) $data;

		// Filet de securite : aucune cle sk_/whsec_ ne doit atterrir dans les journaux.
		$data = preg_replace( '/\b(sk_(?:live|test)_|whsec_)[A-Za-z0-9_\-]+/', '$1***', $data );

		if ( '' !== $this->secret_key ) {
			$data = str_replace( $this->secret_key, '***', $data );
		}

		return (string) $data;
	}

	/**
	 * Journalise un message via WC_Logger.
	 *
	 * @param string $message Message.
	 * @param string $level   Niveau (debug, info, warning, error).
	 *
	 * @return void
	 */
	public function log( $message, $level = 'debug' ) {
		if ( ! $this->debug && 'error' !== $level ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}

		if ( ! $this->logger ) {
			return;
		}

		$this->logger->log( $level, $this->redact( $message ), array( 'source' => 'walleopay' ) );
	}
}
