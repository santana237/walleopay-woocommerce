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

**Où les trouver :** tableau de bord WalleoPay (https://walleopay.com), section **Développeurs → Clés d'API**. La clé secrète ne s'affiche en clair qu'une fois : conservez-la dans un gestionnaire de mots de passe.

Le mode (test ou production) découle **de la clé utilisée**, jamais d'un paramètre de requête. La liste déroulante « Mode » indique simplement quelle clé l'extension doit envoyer.

Ne collez jamais une clé secrète dans un thème, un script front-end ou un dépôt public.

### 3. URL de notification (webhook)

L'URL à coller dans WalleoPay est affichée en haut de l'écran de réglages de la passerelle. Elle a cette forme :

```
https://votre-boutique.tld/wc-api/walleopay
```

Dans le tableau de bord WalleoPay, section **Développeurs → Webhooks** :

1. Créez un point de terminaison avec cette URL.
2. Abonnez-le aux événements `payment.succeeded`, `payment.failed`, `payment.expired`, `payment.cancelled`, `payment.awaiting_confirmation`.
3. Copiez le **secret de signature** (`whsec_…`) affiché à la création.

### 4. Secret de webhook

Collez le `whsec_…` dans le champ **Secret de webhook** des réglages, puis enregistrez. Sans ce secret, toutes les notifications entrantes sont rejetées avec un code HTTP 401.

### 5. Activation

Cochez **Activer le paiement WalleoPay** et enregistrez. Le moyen de paiement n'apparaît sur la page de commande que si une clé secrète est renseignée pour le mode sélectionné.

## Comment une commande est validée

L'extension ne marque **jamais** une commande payée sur la seule foi d'un webhook ou d'un retour navigateur. À chaque notification, à chaque retour client et à chaque rafraîchissement manuel, elle applique la même séquence :

1. **Signature** — l'en-tête `X-WalleoPay-Signature: t=<timestamp>,v1=<hmac>` est recalculé avec `hash_hmac('sha256', $t . '.' . $corps_brut, $whsec)` et comparé avec `hash_equals` (jamais `==`). Le corps est lu avec `file_get_contents('php://input')`, jamais ré-encodé.
2. **Horodatage** — le `t=` doit avoir moins de 300 secondes, sinon la notification est rejetée (protection contre le rejeu).
3. **Re-vérification API** — appel de `GET /payments/{id}`. Seul le statut renvoyé par l'API fait foi.
4. **Montant et devise** — le `amount` (entier, francs CFA) et la `currency` renvoyés doivent correspondre au total et à la devise de la commande. En cas d'écart, une note est ajoutée, la commande passe en attente et **n'est pas validée**.

Correspondance des statuts :

| Statut WalleoPay | Statut WooCommerce |
| --- | --- |
| `succeeded` | `payment_complete()` (WooCommerce choisit `processing` ou `completed`) |
| `awaiting_confirmation` | `on-hold` avec note explicative |
| `failed` | `failed` |
| `cancelled`, `expired` | `cancelled` |
| `pending`, `processing` | inchangé (statut non définitif) |

Le traitement est idempotent : une commande déjà payée n'est jamais revalidée, même si la notification est rejouée.

## Fonctionnement côté client

1. Le client choisit WalleoPay et valide sa commande.
2. L'extension appelle `POST /payments` avec une clé `Idempotency-Key` déterministe (dérivée de l'identifiant, de la clé, du total et de la devise de la commande) puis redirige vers le `checkout_url`.
3. Après le paiement, le client revient sur `…/wc-api/walleopay_return?order_id=…&order_key=…`. Cette page **re-vérifie le statut auprès de l'API** avant d'afficher quoi que ce soit, puis redirige vers la page de remerciement, ou vers la page de règlement avec un message clair.
4. Si le statut n'est pas définitif, le client voit « paiement en cours de confirmation » : la commande sera validée par le webhook.

## Rafraîchir un paiement à la main

Sur l'écran d'une commande, l'encart **WalleoPay** (colonne de droite) affiche l'identifiant du paiement, la référence, le mode, le statut, l'opérateur et la date de dernière vérification. Le bouton **Rafraîchir le statut WalleoPay** rappelle `GET /payments/{id}` et applique exactement la même logique de contrôle. La même action est disponible dans la liste déroulante **Actions de commande**.

## Métadonnées enregistrées sur la commande

| Clé | Contenu |
| --- | --- |
| `_walleopay_payment_id` | Identifiant `pay_…` |
| `_walleopay_reference` | Référence marchand (numéro de commande) |
| `_walleopay_mode` | `test` ou `live` au moment de la création |
| `_walleopay_status` | Dernier statut connu de l'API |
| `_walleopay_operator` | Opérateur ayant traité le paiement |
| `_walleopay_checked_at` | Date UTC de la dernière re-vérification |
| `_walleopay_completed` | `yes` une fois `payment_complete()` appliqué |

## Dépannage

**Le moyen de paiement n'apparaît pas à la commande.**
Vérifiez que l'extension est activée, que la case « Activer le paiement WalleoPay » est cochée, et qu'une clé secrète est renseignée **pour le mode sélectionné** (une clé de test ne suffit pas si le mode est Production).

**« Clé secrète WalleoPay invalide ou révoquée ».**
La clé ne correspond pas au mode, a été régénérée, ou contient un espace copié par erreur. Recopiez-la depuis le tableau de bord.

**« La vérification d'identité (KYC) du compte WalleoPay n'est pas encore approuvée ».**
Le compte marchand doit être validé avant d'encaisser en production. Le mode Test reste utilisable en attendant.

**Les commandes restent en attente alors que le client a payé.**
Le webhook n'arrive probablement pas. Vérifiez que l'URL `…/wc-api/walleopay` répond publiquement (ni authentification HTTP, ni pare-feu, ni `noindex` bloquant), et que le secret `whsec_…` est bien celui du point de terminaison configuré. Les livraisons et leurs réponses sont visibles dans le tableau de bord WalleoPay.

**Le journal indique « Signature invalide ».**
Le secret de webhook ne correspond pas, ou un module de sécurité/cache modifie le corps de la requête. La signature porte sur les octets exacts : tout module qui réécrit le JSON entrant la casse.

**Le journal indique « Horodatage de signature hors tolérance ».**
L'horloge du serveur dérive de plus de 300 secondes. Synchronisez-la (NTP).

**« WalleoPay : montant incohérent ».**
Le montant confirmé ne correspond pas au total de la commande (souvent une devise mal réglée, ou des décimales activées alors que XAF n'en a pas). La commande est volontairement laissée en attente : vérifiez avant de la valider à la main.

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
