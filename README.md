# Retraite & Fiscalité — Guide de déploiement OVH

## Structure du projet

```
retraite-fiscalite/
├── app.html                 ← Application frontend (page unique)
├── merci.html               ← Page de confirmation après paiement
├── .htaccess                ← Configuration Apache OVH
├── .gitignore               ← Fichiers exclus de Git
├── .env.php                 ← ⚠️ CONFIG SECRÈTE — ne pas committer
├── api/
│   ├── create-checkout.php  ← Crée la session Stripe Checkout
│   └── webhook.php          ← Reçoit les événements Stripe
└── logs/                    ← Créé automatiquement par le serveur
```

---

## 1. Configuration initiale

### Étape 1 — Configurer `.env.php`

Ouvrez `.env.php` et remplissez :

```php
define('STRIPE_SECRET_KEY',     'sk_test_VOTRE_CLE_SECRETE');
define('STRIPE_PUBLIC_KEY',     'pk_test_51SQ8yW8Rc...');
define('STRIPE_WEBHOOK_SECRET', 'whsec_VOTRE_SECRET_WEBHOOK');
define('APP_URL',               'https://votre-domaine.ovh.com');
define('ADMIN_EMAIL',           'votre@email.com');
```

> ⚠️ **NE JAMAIS** uploader `.env.php` sur GitHub. Il est dans `.gitignore`.
> Uploadez-le **directement sur OVH via FTP**.

---

## 2. Déploiement sur OVH mutualisé

### Via FTP (FileZilla)

**Paramètres FTP OVH :**
- Hôte : `ftp.cluster0XX.hosting.ovh.net` (voir email OVH)
- Identifiant : votre login OVH
- Mot de passe : votre mot de passe FTP OVH
- Port : `21`

**Fichiers à uploader dans `www/` :**
```
app.html        → www/app.html
merci.html      → www/merci.html
.htaccess       → www/.htaccess
api/            → www/api/
.env.php        → www/.env.php   ← SEULEMENT via FTP, pas GitHub
```

### Via GitHub + déploiement automatique

1. Poussez le repo sur GitHub (sans `.env.php`)
2. Dans OVH Manager → Hébergement → Déploiement Git
3. Connectez votre repo GitHub
4. Uploadez `.env.php` séparément via FTP

---

## 3. Configuration Stripe Webhook

1. Allez sur [dashboard.stripe.com](https://dashboard.stripe.com) → Développeurs → Webhooks
2. Cliquez **"Ajouter un endpoint"**
3. URL : `https://votre-domaine.com/api/webhook.php`
4. Événements à sélectionner :
   - `checkout.session.completed`
   - `customer.subscription.deleted`
   - `invoice.payment_failed`
5. Copiez le **Signing secret** (`whsec_...`) → collez dans `.env.php`

---

## 4. Mise à jour du site

### Méthode rapide (FTP)

Modifiez `app.html` localement puis uploadez via FileZilla.

### Méthode recommandée (GitHub)

```bash
git add .
git commit -m "Description de la modification"
git push origin main
```
Puis déclenchez le déploiement depuis OVH Manager si configuré.

---

## 5. Passer en production

Quand les tests sont validés :

1. Dans Stripe Dashboard → désactivez le mode Test
2. Dans `.env.php`, remplacez :
   - `sk_test_...` → `sk_live_...`
   - `pk_test_...` → `pk_live_51SQ8yF7RA5BfeWE...`
3. Créez un nouveau webhook en mode live avec les mêmes événements
4. Mettez à jour `STRIPE_WEBHOOK_SECRET` avec le nouveau `whsec_`
5. Dans `app.html`, mettez à jour `STRIPE_PUBLIC_KEY` avec la clé live

---

## 6. Tester les paiements (mode test)

Carte de test Stripe :
- Numéro : `4242 4242 4242 4242`
- Date : n'importe quelle date future
- CVC : `123`
- Code postal : `75001`

Carte refusée : `4000 0000 0000 0002`

---

## Prix Stripe configurés

| Produit | Price ID | Montant | Mode |
|---------|----------|---------|------|
| Abonnement Conseiller | `price_1T9vFj8RcM1rCQsNegMXfjs2` | 29 €/mois | Récurrent |
| Rapport Client | `price_1T9vHL8RcM1rCQsN1D1aGttZ` | 10 € | Unique |
