=== WalleoPay pour WooCommerce ===
Contributors: walleopay
Tags: woocommerce, paiement, mobile money, mtn momo, orange money
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encaissez par MTN Mobile Money, Orange Money ou carte bancaire sur votre boutique WooCommerce grâce à WalleoPay.

== Description ==

WalleoPay est un agrégateur de paiement camerounais. Cette extension ajoute un moyen de paiement à votre page de commande WooCommerce : le client est redirigé vers une page de paiement sécurisée WalleoPay, choisit son opérateur (MTN Mobile Money, Orange Money) ou sa carte bancaire, puis revient sur votre boutique.

Points clés :

* Redirection vers la page de paiement hébergée par WalleoPay : aucune donnée sensible ne transite par votre serveur.
* Notifications (webhooks) signées en HMAC SHA-256, vérifiées en temps constant et fenêtre de tolérance de 300 secondes.
* **Aucune commande n’est marquée payée sur la seule foi d’un webhook ou d’un retour navigateur.** L’extension ré-interroge systématiquement l’API WalleoPay et contrôle que le montant et la devise correspondent à la commande avant validation.
* Gestion du statut « en attente de confirmation » (paiement au code marchand) : la commande passe en attente avec une note explicative, puis est validée automatiquement une fois le rapprochement terminé.
* Bouton « Rafraîchir le statut WalleoPay » sur l’écran de commande, et action équivalente dans la liste déroulante des actions de commande.
* Compatible avec le stockage haute performance des commandes (HPOS).
* Journalisation via WooCommerce → État → Journaux (source « walleopay »), sans jamais écrire les clés secrètes.
* Aucune dépendance : uniquement l’API HTTP de WordPress.

== Installation ==

1. Copiez le dossier de l’extension dans `wp-content/plugins/walleopay-for-woocommerce`, ou téléversez l’archive ZIP depuis Extensions → Ajouter.
2. Activez « WalleoPay pour WooCommerce ».
3. Rendez-vous dans WooCommerce → Réglages → Paiements → WalleoPay.
4. Choisissez le mode (Test ou Production) et collez la clé secrète correspondante (`sk_test_…` ou `sk_live_…`), disponible dans votre tableau de bord WalleoPay.
5. Dans le tableau de bord WalleoPay, créez un webhook pointant vers l’URL affichée en haut de l’écran de réglages (de la forme `https://votre-boutique.tld/wc-api/walleopay`), puis collez le secret fourni (`whsec_…`) dans le champ « Secret de webhook ».
6. Cochez « Activer le paiement WalleoPay » et enregistrez.

== Frequently Asked Questions ==

= Dans quelle devise dois-je configurer ma boutique ? =

En francs CFA (XAF). WalleoPay encaisse des montants entiers en francs, entre 100 et 1 000 000 FCFA. Un avertissement s’affiche dans les réglages si la devise de la boutique n’est pas XAF.

= Pourquoi une commande reste-t-elle « en attente » ? =

Le statut `awaiting_confirmation` signifie que le client a payé au code marchand et que le rapprochement est effectué manuellement par WalleoPay ; cela peut prendre plusieurs heures. La commande est placée en attente avec une note, puis validée automatiquement dès réception de la confirmation. Vous pouvez aussi cliquer sur « Rafraîchir le statut WalleoPay » dans l’encart de la commande.

= L’URL de notification n’est pas accessible depuis Internet, est-ce grave ? =

Oui : sans webhook, les commandes ne seront validées qu’au retour du client sur la boutique. Si le client ferme son navigateur avant de revenir, il faudra rafraîchir le statut manuellement. Sur un site en développement ou derrière une authentification HTTP, exposez l’URL ou utilisez un tunnel.

= Le webhook est-il suffisant pour valider une commande ? =

Non, et l’extension ne s’y fie jamais. Après vérification de la signature et de l’horodatage, elle ré-interroge `GET /payments/{id}` et n’accepte la commande que si l’API répond `succeeded` avec un montant et une devise identiques à ceux de la commande. En cas d’écart, la commande est placée en attente avec une note, jamais validée.

= Puis-je tester sans argent réel ? =

Oui : choisissez le mode Test et utilisez votre clé `sk_test_…`. Le mode réel découle toujours de la clé utilisée, jamais d’un paramètre de requête.

= Où consulter les échanges avec l’API ? =

Activez « Journalisation » dans les réglages, puis ouvrez WooCommerce → État → Journaux et sélectionnez la source « walleopay ». Les clés secrètes et secrets de webhook y sont systématiquement masqués.

== Changelog ==

= 1.0.0 =
* Première version publique.
* Création de paiement avec clé d’idempotence déterministe et redirection vers la page de paiement WalleoPay.
* Webhook signé (HMAC SHA-256, `hash_equals`, tolérance de 300 s) avec re-vérification obligatoire auprès de l’API.
* Contrôle du montant et de la devise avant toute validation de commande.
* Page de retour client re-vérifiée côté serveur.
* Encart d’administration et action de commande « Rafraîchir le statut WalleoPay ».
* Compatibilité HPOS déclarée.
