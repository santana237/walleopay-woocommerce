<?php
/**
 * Tentatives de paiement d'une commande.
 *
 * @package WalleoPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WalleoPay_Attempts
 *
 * Trouve — ou ouvre — la tentative de paiement a presenter pour une commande.
 *
 * Une commande connait parfois plusieurs paiements : un solde insuffisant, un
 * code PIN refuse, une page laissee expirer au bout de 30 minutes. Chaque
 * paiement porte donc un numero de tentative dans sa reference (« 1234 »,
 * puis « 1234-2 », « 1234-3 »…), et sa cle d'idempotence en decoule.
 *
 * Autrefois, la reference etait le seul numero de commande et la cle ne
 * dependait que de la commande et de son total : apres un echec, « Payer la
 * commande » recevait de l'API la reponse d'origine, rejouee telle quelle, et
 * renvoyait le client sur la page du paiement echoue ; une autre cle se
 * serait heurtee a « reference deja prise ». Le client ne pouvait plus
 * retenter. C'est la regle du module WHMCS, reprise ici :
 *
 *   - un numero ne s'ouvre qu'une fois le precedent clos (echoue, annule ou
 *     expire) : il n'existe jamais deux paiements payables a la fois pour une
 *     commande, et un paiement reussi ou en cours de rapprochement bloque
 *     toute nouvelle demande — deux paiements reussis pour une meme commande
 *     sont exclus ;
 *   - une tentative est toujours relue par GET avant d'etre presentee : une
 *     reponse rejouee decrit le paiement a sa creation, pas tel qu'il est ;
 *   - un paiement trouve sous l'une de ces references n'est traite que s'il
 *     porte la cle de cette commande (metadata.order_key). Une reference est
 *     unique pour tout le compte WalleoPay : une copie de preproduction ou
 *     une seconde boutique branchees sur le meme compte peuvent avoir pris le
 *     meme numero, et l'on n'annule ni ne resert jamais le paiement d'autrui.
 *
 * La classe ne touche ni a la commande ni a WordPress, hors WP_Error : la
 * passerelle lui passe ce qu'il faut et applique le resultat. C'est ce qui
 * permet de l'eprouver sans WooCommerce (tests/Unit/ModulesDeBoutiqueTest).
 */
class WalleoPay_Attempts {

	/**
	 * Numero de tentative le plus eleve qu'une commande puisse atteindre.
	 *
	 * Une tentative ne s'ouvre qu'apres la cloture de la precedente : il
	 * faudrait des centaines d'echecs pour s'en approcher. Au-dela, on
	 * s'arrete plutot que de sonder l'API sans fin.
	 */
	const MAX_ATTEMPT = 999;

	/**
	 * Pas en avant autorises pendant un seul passage au paiement.
	 *
	 * Le cas courant en demande un ou deux. Les autres ne servent qu'a
	 * enjamber un numero deja pris ailleurs, et l'on prefere un message clair
	 * a une rafale d'appels qui buterait sur la limite de debit.
	 */
	const MAX_STEPS = 10;

	/**
	 * Client API du mode courant : get_payment(), create_payment(),
	 * cancel_payment(), was_replayed().
	 *
	 * @var WalleoPay_API
	 */
	protected $api;

	/**
	 * Racine des references : le numero de commande.
	 *
	 * @var string
	 */
	protected $base;

	/**
	 * Identifiant de la commande.
	 *
	 * @var string
	 */
	protected $order_id;

	/**
	 * Cle de la commande (« wc_order_… »), preuve qu'un paiement est le sien.
	 *
	 * @var string
	 */
	protected $order_key;

	/**
	 * Fabrique du corps et de la cle d'une tentative : reference → array( corps, cle ).
	 *
	 * @var callable
	 */
	protected $build;

	/**
	 * Constructeur.
	 *
	 * @param WalleoPay_API $api       Client API du mode courant.
	 * @param string        $base      Racine des references (numero de commande).
	 * @param int|string    $order_id  Identifiant de la commande.
	 * @param string        $order_key Cle de la commande.
	 * @param callable      $build     reference → array( corps, cle d'idempotence ).
	 */
	public function __construct( $api, $base, $order_id, $order_key, $build ) {
		$this->api       = $api;
		$this->base      = (string) $base;
		$this->order_id  = (string) $order_id;
		$this->order_key = (string) $order_key;
		$this->build     = $build;
	}

	/**
	 * Reference de la tentative n° $number : la racine seule pour la premiere,
	 * suivie de « -<n> » ensuite.
	 *
	 * La premiere garde la forme historique : les paiements crees par la
	 * version precedente de l'extension restent reconnus.
	 *
	 * @param string $base   Racine.
	 * @param int    $number Numero de tentative.
	 *
	 * @return string
	 */
	public static function reference( $base, $number ) {
		return (int) $number <= 1 ? (string) $base : $base . '-' . (int) $number;
	}

	/**
	 * La tentative a presenter au client.
	 *
	 * @param int                $amount       Total de la commande, en francs entiers.
	 * @param string             $currency     Devise de la commande.
	 * @param string             $current_id   Paiement presente en dernier (meta de la commande), ou ''.
	 * @param WalleoPay_API|null $current_api  Client du mode dans lequel il a ete cree.
	 * @param bool               $current_mode Ce mode est-il encore le mode courant ?
	 *
	 * @return array state : open (payment, created), paid (payment), awaiting
	 *               (payment), busy, exhausted ou error (error : WP_Error).
	 */
	public function open( $amount, $currency, $current_id = '', $current_api = null, $current_mode = true ) {
		$amount   = (int) $amount;
		$currency = strtoupper( (string) $currency );

		/*
		 * 1. Le paiement presente en dernier. La recherche par reference le
		 * retrouverait d'ordinaire, sauf s'il a ete cree dans l'autre mode,
		 * ou si un numero pris par une autre boutique du compte le masque :
		 * il ne doit pas rester payable a cote du suivant.
		 */
		if ( '' !== (string) $current_id ) {
			$api     = null !== $current_api ? $current_api : $this->api;
			$payment = $api->get_payment( (string) $current_id );

			if ( is_wp_error( $payment ) ) {
				// Introuvable, ou illisible avec la cle d'un mode qu'on a quitte
				// (retiree, revoquee) : il n'y a rien a proteger que l'on voie,
				// la recherche du mode courant decide.
				if ( 404 !== self::status_of( $payment ) && $current_mode ) {
					return array(
						'state' => 'error',
						'error' => $payment,
					);
				}
			} elseif ( $this->is_ours( $payment ) ) {
				$outcome = $this->evaluate( $payment, $amount, $currency, $api, (bool) $current_mode );

				if ( 'closed' !== $outcome['state'] ) {
					return $outcome;
				}
			}
		}

		// 2. La derniere tentative existante, par reference.
		$last = $this->last_attempt();

		if ( isset( $last['error'] ) ) {
			return array(
				'state' => 'error',
				'error' => $last['error'],
			);
		}

		$number     = $last['number'];
		$payment    = $last['payment'];
		$created_id = '';
		$fresh      = false;

		for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
			// Un paiement qui n'est pas le sien est un numero pris ailleurs :
			// on l'enjambe sans y toucher. Celui qu'on vient de creer est le
			// sien par construction : le relire autrement ouvrirait une
			// tentative par tour.
			if ( null !== $payment && ( $fresh || $this->is_ours( $payment ) ) ) {
				$outcome = $this->evaluate( $payment, $amount, $currency, $this->api, true );

				if ( 'closed' !== $outcome['state'] ) {
					if ( 'open' === $outcome['state'] ) {
						$outcome['created'] = '' !== $created_id && self::id_of( $outcome['payment'] ) === $created_id;
					}

					return $outcome;
				}
			}

			$fresh = false;
			$number++;

			if ( $number > self::MAX_ATTEMPT ) {
				return array( 'state' => 'exhausted' );
			}

			$reference = self::reference( $this->base, $number );
			$request   = call_user_func( $this->build, $reference );
			$created   = $this->api->create_payment( $request[0], $request[1] );

			if ( ! is_wp_error( $created ) && ! $this->api->was_replayed() ) {
				// Reponse fraiche : elle dit l'etat reel du paiement qui vient
				// de naitre, on l'examine au tour suivant sans la relire.
				$payment    = $created;
				$created_id = self::id_of( $created );
				$fresh      = true;
				continue;
			}

			if ( is_wp_error( $created ) && ! self::is_taken( $created ) ) {
				return array(
					'state' => 'error',
					'error' => $created,
				);
			}

			// Reponse rejouee, ou numero deja pris : seul un GET dit ou en est
			// reellement ce paiement.
			$lookup = $this->api->get_payment( $reference );

			if ( ! is_wp_error( $lookup ) ) {
				$payment = $lookup;
				continue;
			}

			if ( 404 !== self::status_of( $lookup ) ) {
				return array(
					'state' => 'error',
					'error' => $lookup,
				);
			}

			// Numero pris dans l'autre mode — une reference est unique pour tout
			// le compte, test et production confondus — ou jamais cree : on
			// l'enjambe.
			$payment = null;
		}

		return array( 'state' => 'exhausted' );
	}

	/**
	 * Derniere tentative existante : numero (0 si aucune) et paiement.
	 *
	 * Les numeros s'ouvrent l'un apres l'autre et forment une suite continue :
	 * une recherche exponentielle puis dichotomique trouve le dernier en
	 * quelques appels. Les sonder un a un finirait par buter sur la limite de
	 * 120 requetes par minute et par cle.
	 *
	 * @return array number + payment, ou error (WP_Error).
	 */
	protected function last_attempt() {
		$found   = 0;
		$payment = null;
		$missing = 0;
		$probe   = 1;

		while ( 0 === $missing ) {
			$lookup = $this->lookup( $probe );

			if ( ! is_wp_error( $lookup ) ) {
				$found   = $probe;
				$payment = $lookup;

				if ( $probe >= self::MAX_ATTEMPT ) {
					break;
				}

				$probe = min( $probe * 2, self::MAX_ATTEMPT );
				continue;
			}

			if ( 404 !== self::status_of( $lookup ) ) {
				return array( 'error' => $lookup );
			}

			// La premiere reference est la seule que l'ancienne version ait pu
			// prendre dans l'autre mode : absente ici, elle ne prouve pas que
			// la suite est vide.
			if ( 1 === $probe ) {
				$next = $this->lookup( 2 );

				if ( ! is_wp_error( $next ) ) {
					$found   = 2;
					$payment = $next;
					$probe   = 4;
					continue;
				}

				if ( 404 !== self::status_of( $next ) ) {
					return array( 'error' => $next );
				}
			}

			$missing = $probe;
		}

		while ( $missing - $found > 1 ) {
			$middle = intdiv( $found + $missing, 2 );
			$lookup = $this->lookup( $middle );

			if ( ! is_wp_error( $lookup ) ) {
				$found   = $middle;
				$payment = $lookup;
			} elseif ( 404 === self::status_of( $lookup ) ) {
				$missing = $middle;
			} else {
				return array( 'error' => $lookup );
			}
		}

		return array(
			'number'  => $found,
			'payment' => $payment,
		);
	}

	/**
	 * GET d'une tentative par sa reference.
	 *
	 * @param int $number Numero de tentative.
	 *
	 * @return array|WP_Error
	 */
	protected function lookup( $number ) {
		return $this->api->get_payment( self::reference( $this->base, $number ) );
	}

	/**
	 * Ce que l'on peut faire d'une tentative existante.
	 *
	 * @param array         $payment  Paiement tel que relu aupres de l'API.
	 * @param int           $amount   Total de la commande.
	 * @param string        $currency Devise de la commande.
	 * @param WalleoPay_API $api      Client du mode du paiement.
	 * @param bool          $same_mode Le paiement est-il du mode courant ?
	 *
	 * @return array state : closed (on peut en ouvrir une autre), open, paid,
	 *               awaiting, busy ou error.
	 */
	protected function evaluate( $payment, $amount, $currency, $api, $same_mode ) {
		$status     = isset( $payment['status'] ) ? (string) $payment['status'] : '';
		$payment_id = self::id_of( $payment );

		// Argent recu, ou annonce : un second paiement ferait payer le client
		// deux fois. La page de retour relit celui-ci et l'applique.
		if ( 'succeeded' === $status ) {
			return array(
				'state'   => 'paid',
				'payment' => $payment,
			);
		}

		if ( 'awaiting_confirmation' === $status ) {
			return array(
				'state'   => 'awaiting',
				'payment' => $payment,
			);
		}

		if ( in_array( $status, array( 'failed', 'cancelled', 'expired' ), true ) ) {
			return array( 'state' => 'closed' );
		}

		// Statut inconnu : on n'ouvre rien a cote d'un paiement qu'on ne sait
		// pas lire.
		if ( ! in_array( $status, array( 'pending', 'processing' ), true ) ) {
			return array( 'state' => 'busy' );
		}

		$same_amount   = null !== WalleoPay_Webhook::settled_amount( $payment, $amount );
		$same_currency = isset( $payment['currency'] ) && strtoupper( (string) $payment['currency'] ) === $currency;

		if ( $same_mode && $same_amount && $same_currency ) {
			// Meme demande : on la reprend. Sans page a montrer, on attend
			// plutot que d'annuler un paiement qui n'a rien de perime.
			return ! empty( $payment['checkout_url'] )
				? array(
					'state'   => 'open',
					'payment' => $payment,
				)
				: array( 'state' => 'busy' );
		}

		// Le total ou le mode a change depuis. Une demande deja partie vers le
		// telephone du client ne s'annule pas sans risque : l'operateur
		// pourrait debiter un paiement que l'on croit mort.
		if ( 'processing' === $status || '' === $payment_id ) {
			return array( 'state' => 'busy' );
		}

		$cancel = $api->cancel_payment( $payment_id );

		if ( is_wp_error( $cancel ) ) {
			return array(
				'state' => 'error',
				'error' => $cancel,
			);
		}

		$after = isset( $cancel['status'] ) ? (string) $cancel['status'] : '';

		// Annulation sans effet : on ne superpose pas une seconde page a la
		// premiere.
		if ( '' === $after || 'pending' === $after || 'processing' === $after ) {
			return array( 'state' => 'busy' );
		}

		// Le paiement a pu aboutir juste avant l'annulation : on le relit comme
		// les autres, sans jamais rappeler l'annulation.
		return $this->evaluate( $cancel, $amount, $currency, $api, $same_mode );
	}

	/**
	 * Ce paiement appartient-il a cette commande ?
	 *
	 * La cle de commande est un secret propre a chaque commande de chaque
	 * boutique : elle distingue deux commandes « 1234 » de deux boutiques
	 * branchees sur le meme compte. Les paiements des versions precedentes la
	 * portent deja.
	 *
	 * @param array $payment Paiement relu.
	 *
	 * @return bool
	 */
	public function is_ours( $payment ) {
		$metadata = isset( $payment['metadata'] ) && is_array( $payment['metadata'] ) ? $payment['metadata'] : array();

		if ( isset( $metadata['order_key'] ) && '' !== (string) $metadata['order_key'] ) {
			return '' !== $this->order_key && hash_equals( $this->order_key, (string) $metadata['order_key'] );
		}

		return false;
	}

	/**
	 * Refus qui signale un numero de tentative deja pris : reference unique
	 * (validation 422 sur le champ « reference ») ou cle d'idempotence deja
	 * servie pour un autre corps.
	 *
	 * @param WP_Error $error Erreur renvoyee par WalleoPay_API.
	 *
	 * @return bool
	 */
	public static function is_taken( $error ) {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$type   = isset( $data['type'] ) ? (string) $data['type'] : '';
		$fields = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();

		if ( 'idempotency_conflict' === $type ) {
			return true;
		}

		return 422 === self::status_of( $error ) && in_array( 'reference', $fields, true );
	}

	/**
	 * Code HTTP porte par une erreur de l'API, 0 pour une erreur reseau.
	 *
	 * @param WP_Error $error Erreur.
	 *
	 * @return int
	 */
	public static function status_of( $error ) {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Identifiant « pay_… » d'un paiement, chaine vide s'il manque.
	 *
	 * @param array $payment Paiement.
	 *
	 * @return string
	 */
	protected static function id_of( $payment ) {
		return is_array( $payment ) && isset( $payment['id'] ) ? (string) $payment['id'] : '';
	}
}
