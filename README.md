# WalleoPay pour WooCommerce

Extension WordPress qui ajoute WalleoPay (MTN Mobile Money, Orange Money, carte bancaire) comme moyen de paiement WooCommerce.

- Version : 1.0.0
- PHP requis : 7.4+
- WordPress : 5.8+ · WooCommerce : 6.0+
- Aucune dépendance Composer, uniquement l'API HTTP de WordPress

## Installation

1. Copiez ce dossier dans `wp-content/plugins/walleopay-for-woocommerce` (ou créez une archive ZIP de son contenu et téléversez-la depuis **Extensions → Ajouter**).
2. Activez **WalleoPay pour WooCommerce** dans **Extensions**.
3. Le lien **Réglages** sous le nom de l'extension amène directement à l'écran de configuration.

## Configuration pas à pas

### 1. Devise de la boutique

Dans **WooCommerce → Réglages → Général**, réglez la devise sur **Franc CFA (XAF)** avec **0 décimale**. WalleoPay travaille en montants entiers, de 100 à 1 000 000 FCFA. Si la devise n'est pas XAF, un avertissement s'affiche dans les réglages de la passerelle.

### 2. Clés secrètes

Dans **WooCommerce → Réglages → Paiements → WalleoPay** :

| Champ | Valeur |
| --- | --- |
| Mode | `Test` pendant la mise au point, `Production` en exploitation |
| Clé secrète de test | `sk_test_…` |
| Clé secrète de production | `sk_live_…` |

**Où les trouver :** tableau de bord WalleoPay (https://walleopay.com), rubrique **Applications → Clés API** (ou **Applications → Mes services** pour la clé d'un service). Seul le propriétaire du compte obtient une clé secrète, et elle ne s'affiche en clair qu'à sa création : conservez-la dans un gestionnaire de mots de passe. Perdue, elle se renouvelle depuis le même écran.

Le mode (test ou production) découle **de la clé utilisée**, jamais d'un paramètre de requête. La liste déroulante « Mode » indique simplement quelle clé l'extension doit envoyer.

**Le mode test n'est pas une simulation.** Il passe par un vrai canal de paiement : le client est réellement débité et votre solde WalleoPay crédité, commission comprise. Seuls les clés, l'historique et les statistiques restent séparés de la production. Faites vos essais avec de petits montants.

Ne collez jamais une clé secrète dans un thème, un script front-end ou un dépôt public.

### 3. URL de notification (webhook)

Il n'y a **rien à déclarer** côté WalleoPay : l'extension envoie son adresse de notification avec chaque paiement (champ `notify_url`), et WalleoPay la préfère toujours à l'URL de notification par défaut du compte. Elle est affichée en haut de l'écran de réglages de la passerelle, pour vérifier qu'elle répond publiquement, et a cette forme :

```
https://votre-boutique.tld/wc-api/walleopay
```

Le tableau de bord WalleoPay n'a pas d'écran d'abonnement aux événements : il connaît une seule **URL de notification par défaut**, réglée dans **Mon compte → Paramètres → URLs par défaut**. Elle ne sert qu'aux paiements créés sans `notify_url` et aux notifications de remboursement et de reversement. Vous pouvez y mettre l'adresse de la boutique ou la laisser vide : l'extension répond `200` aux événements qui ne concernent aucune commande.

Les notifications de paiement reçues sont `payment.succeeded`, `payment.failed`, `payment.expired`, `payment.cancelled` et `payment.awaiting_confirmation`. Leurs livraisons, avec la réponse de la boutique, se consultent dans **Applications → Notifications**.

### 4. Secret de webhook

Copiez le **secret de signature** (`whsec_…`) affiché dans le tableau de bord WalleoPay, rubrique **Applications → Notifications** (il figure aussi sur l'écran **Clés API**). Il y en a **un seul par compte**, le même en test et en production. Collez-le dans le champ **Secret de webhook** des réglages, puis enregistrez. Sans ce secret, toutes les notifications entrantes sont rejetées avec un code HTTP 401.

### 5. Activation

Cochez **Activer le paiement WalleoPay** et enregistrez. Le moyen de paiement n'apparaît sur la page de commande que si une clé secrète est renseignée pour le mode sélectionné.

## Comment une commande est validée

L'extension ne marque **jamais** une commande payée sur la seule foi d'un webhook ou d'un retour navigateur. À chaque notification, à chaque retour client et à chaque rafraîchissement manuel, elle applique la même séquence :

1. **Signature** — l'en-tête `X-WalleoPay-Signature: t=<timestamp>,v1=<hmac>` est recalculé avec `hash_hmac('sha256', $t . '.' . $corps_brut, $whsec)` et comparé avec `hash_equals` (jamais `==`). Le corps est lu avec `file_get_contents('php://input')`, jamais ré-encodé.
2. **Horodatage** — le `t=` doit avoir moins de 300 secondes, sinon la notification est rejetée (protection contre le rejeu).
3. **Re-vérification API** — appel de `GET /payments/{id}`. Seul le statut renvoyé par l'API fait foi.
4. **Montant et devise** — le `amount` (entier, francs CFA) et la `currency` renvoyés doivent correspondre au total et à la devise de la commande. Quand le client paie la commission (réglage « Qui paie la commission » du compte ou du service), WalleoPay l'ajoute par-dessus : `amount` vaut alors le total **plus** `fee`, et c'est `amount − fee` qui est comparé au total ; une note précise la commission payée. Pour ne rien deviner, l'extension joint à chaque paiement le montant qu'elle demande (métadonnée `requested_amount`), qui doit lui aussi valoir le total. En cas d'écart, une note est ajoutée, la commande passe en attente et **n'est pas validée**.

Correspondance des statuts :

| Statut WalleoPay | Statut WooCommerce |
| --- | --- |
| `succeeded` | `payment_complete()` (WooCommerce choisit `processing` ou `completed`) |
| `awaiting_confirmation` | `on-hold` avec note explicative |
| `failed` | `failed` |
| `cancelled`, `expired` | commande en attente de paiement ou échouée : inchangée, note ajoutée et stock rendu, pour que le client puisse retenter ; commande `on-hold` : `cancelled` |
| `pending`, `processing` | inchangé (statut non définitif) |

Le traitement est idempotent : une commande déjà payée n'est jamais revalidée, même si la notification est rejouée.

Une commande peut compter plusieurs tentatives de paiement (voir plus bas). Seule la dernière présentée au client — celle de la métadonnée `_walleopay_payment_id` — fait encore évoluer la commande : la notification tardive d'une tentative remplacée (page expirée, page annulée parce que le total avait changé) ne la fait plus échouer ni annuler. L'argent, lui, compte d'où qu'il vienne : un paiement réussi ou annoncé au code marchand s'applique quelle que soit sa tentative. Si une tentative plus ancienne règle la commande, la page courante est fermée tant qu'elle attend encore le client. Et si un second paiement réussit malgré tout pour une commande déjà réglée, la commande n'est pas touchée : une note indique le paiement en trop, à rembourser depuis le tableau de bord WalleoPay.

## Fonctionnement côté client

1. Le client choisit WalleoPay et valide sa commande.
2. L'extension appelle `POST /payments` avec la référence de la tentative (le numéro de commande, puis `-2`, `-3`…) et une clé `Idempotency-Key` propre à cette tentative, puis redirige vers le `checkout_url`.
3. Après le paiement, le client revient sur `…/wc-api/walleopay_return?order_id=…&order_key=…`. Cette page **re-vérifie le statut auprès de l'API** avant d'afficher quoi que ce soit, puis redirige vers la page de remerciement, ou vers la page de règlement avec un message clair.
4. Si le statut n'est pas définitif, le client voit « paiement en cours de confirmation » : la commande sera validée par le webhook.

## Tentatives de paiement

Un paiement échoué (solde insuffisant, code PIN refusé), annulé ou expiré (la page de paiement expire au bout de 30 minutes) ne condamne pas la commande : **Payer la commande** ouvre une nouvelle tentative, sous sa propre référence et avec sa propre clé d'idempotence — `1234`, puis `1234-2`, `1234-3`… Autrefois, la clé ne dépendait que de la commande : l'API rejouait la réponse d'origine et renvoyait le client sur la page du paiement échoué, sans possibilité de retenter.

À chaque passage au paiement, l'extension relit l'état réel de la dernière tentative (`GET /payments/{référence}`) avant de décider :

| Dernière tentative | Ce que voit le client |
|---|---|
| En cours (`pending`, `processing`), même total | La même page de paiement. |
| `pending` mais le total a changé | Elle est annulée, puis une nouvelle tentative est ouverte. |
| `processing` mais le total a changé | Un message d'attente : la demande est déjà sur le téléphone du client, on ne l'annule pas. |
| `failed`, `cancelled`, `expired` | Une nouvelle tentative : `1234-2`, puis `1234-3`… |
| `succeeded` ou `awaiting_confirmation` | Aucun nouveau paiement : le client passe par la page de retour, qui applique ce paiement à la commande. |

Une nouvelle tentative ne s'ouvre qu'une fois la précédente close : il n'y a jamais deux paiements payables à la fois pour une même commande, et un paiement réussi ou en cours de rapprochement bloque toute nouvelle demande. Deux passages simultanés ne créent qu'un seul paiement.

Une référence est unique pour tout le compte WalleoPay, test et production confondus. Un numéro déjà pris ailleurs — dans l'autre mode, ou par une copie de préproduction branchée sur le même compte — est enjambé : l'extension ne traite un paiement trouvé sous l'une de ses références que s'il porte la clé de la commande (métadonnée `order_key`), et n'annule ni ne ressert jamais celui d'une autre boutique. Si la boutique change de mode pendant qu'une page est ouverte, cette page est fermée avant qu'une autre ne s'ouvre dans le nouveau mode.

Le filtre `walleopay_create_payment_payload` peut ajuster le corps envoyé, sauf la référence et les métadonnées `order_id` et `order_key`, reposées après lui : les tentatives en dépendent.

Les paiements créés par la version précédente sous le seul numéro de commande restent reconnus comme première tentative.

## Rafraîchir un paiement à la main

Sur l'écran d'une commande, l'encart **WalleoPay** (colonne de droite) affiche l'identifiant du paiement, la référence, le mode, le statut, l'opérateur et la date de dernière vérification. Le bouton **Rafraîchir le statut WalleoPay** rappelle `GET /payments/{id}` et applique exactement la même logique de contrôle. La même action est disponible dans la liste déroulante **Actions de commande**.

## Métadonnées enregistrées sur la commande

| Clé | Contenu |
| --- | --- |
| `_walleopay_payment_id` | Identifiant `pay_…` de la tentative en cours |
| `_walleopay_reference` | Référence de la tentative en cours (numéro de commande, puis `-2`, `-3`…) |
| `_walleopay_mode` | `test` ou `live` au moment de la création |
| `_walleopay_status` | Dernier statut connu de l'API |
| `_walleopay_operator` | Opérateur ayant traité le paiement |
| `_walleopay_checked_at` | Date UTC de la dernière re-vérification |
| `_walleopay_completed` | `yes` une fois `payment_complete()` appliqué |
| `_walleopay_duplicates` | Paiements réussis arrivés pour une commande déjà réglée, à rembourser |

## Dépannage

**Le moyen de paiement n'apparaît pas à la commande.**
Vérifiez que l'extension est activée, que la case « Activer le paiement WalleoPay » est cochée, et qu'une clé secrète est renseignée **pour le mode sélectionné** (une clé de test ne suffit pas si le mode est Production).

**« Clé secrète WalleoPay invalide ou révoquée ».**
La clé ne correspond pas au mode, a été régénérée, ou contient un espace copié par erreur. Recopiez-la depuis le tableau de bord.

**« La vérification d'identité (KYC) du compte WalleoPay n'est pas encore approuvée ».**
Le compte marchand doit être validé avant d'encaisser en production. Le mode Test reste utilisable en attendant — avec de l'argent réel : testez sur de petits montants.

**Les commandes restent en attente alors que le client a payé.**
Le webhook n'arrive probablement pas. Vérifiez que l'URL `…/wc-api/walleopay` répond publiquement (ni authentification HTTP, ni pare-feu, ni `noindex` bloquant), et que le secret `whsec_…` est bien celui affiché dans **Applications → Notifications**. Les livraisons et leurs réponses y sont visibles, et une livraison en échec peut y être rejouée une fois le problème corrigé.

**Le journal indique « Signature invalide ».**
Le secret de webhook ne correspond pas, ou un module de sécurité/cache modifie le corps de la requête. La signature porte sur les octets exacts : tout module qui réécrit le JSON entrant la casse.

**Le journal indique « Horodatage de signature hors tolérance ».**
L'horloge du serveur dérive de plus de 300 secondes. Synchronisez-la (NTP).

**« WalleoPay : montant incohérent ».**
Le montant confirmé ne correspond pas au total de la commande, commission du client mise à part (souvent une devise mal réglée, ou des décimales activées alors que XAF n'en a pas). La commande est volontairement laissée en attente : vérifiez avant de la valider à la main.

**« Un paiement WalleoPay est déjà en cours de validation pour cette commande ».**
La dernière tentative est déjà sur le téléphone du client (`processing`) alors que le total a changé, ou son statut n'est pas lisible. Elle ne peut pas être annulée sans risque : le client valide ou laisse expirer la demande, puis réessaie.

**« Trop de tentatives de paiement pour cette commande ».**
L'extension n'a trouvé aucun numéro de tentative libre en dix essais (numéros pris ailleurs) ou la commande a atteint 999 tentatives. Réglez-la autrement, ou créez une nouvelle commande.

**Erreur 429 / « WalleoPay a temporairement limité les requêtes ».**
La limite est de 120 requêtes par minute et par clé. L'extension respecte l'en-tête `Retry-After` et réessaie une fois. Si l'erreur persiste, espacez les rafraîchissements manuels.

**Activer les journaux.**
Cochez « Journalisation » dans les réglages, puis consultez **WooCommerce → État → Journaux**, source `walleopay`. Les clés `sk_…` et `whsec_…` y sont toujours masquées.

## Développement local

Pour viser une instance locale de l'API :

```php
add_filter( 'walleopay_api_base_url', function () {
    return 'http://127.0.0.1:8000/api/v1';
} );
```

Le corps envoyé à `POST /payments` peut être ajusté via le filtre `walleopay_create_payment_payload( $payload, $order )`, et l'icône du moyen de paiement via `walleopay_gateway_icon`.

## Licence

GPLv2 ou ultérieure.
