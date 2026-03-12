# Guide : Configurer le déploiement automatique GitHub → o2switch

## Étape 1 — Générer une clé SSH sur o2switch

Dans votre **cPanel o2switch** → **Terminal** (ou connectez-vous en SSH) :

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_actions -N ""
```

Cela crée deux fichiers :
- `~/.ssh/github_actions` → **clé privée** (pour GitHub)
- `~/.ssh/github_actions.pub` → **clé publique** (pour o2switch)

Puis autorisez la clé sur le serveur :
```bash
cat ~/.ssh/github_actions.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Affichez la clé privée (vous en aurez besoin à l'étape suivante) :
```bash
cat ~/.ssh/github_actions
```
Copiez tout le contenu (de `-----BEGIN` à `-----END`).

---

## Étape 2 — Ajouter les secrets dans GitHub

Allez sur : **github.com/fxtechnologies/retraite-fiscalite → Settings → Secrets and variables → Actions → New repository secret**

Ajoutez ces 3 secrets :

| Nom du secret | Valeur |
|---|---|
| `SSH_HOST` | `fou.o2switch.net` |
| `SSH_USER` | `fxtechnologies` |
| `SSH_PRIVATE_KEY` | *(la clé privée copiée à l'étape 1)* |

---

## Étape 3 — Uploader .env.php via FTP (une seule fois)

⚠️ Ce fichier ne passe jamais par GitHub (il est dans `.gitignore`).
Uploadez-le **manuellement via FTP** (FileZilla) dans `~/public_html/.env.php`.

Paramètres FTP o2switch :
- Hôte : `ftp.fou.o2switch.net`  
- Identifiant : `fxtechnologies`
- Mot de passe : votre mot de passe cPanel
- Port : `21`

---

## Étape 4 — Premier déploiement

Uploadez tous les fichiers du projet sur GitHub :

```bash
git init
git remote add origin https://github.com/fxtechnologies/retraite-fiscalite.git
git add .
git commit -m "Initial commit"
git push -u origin main
```

GitHub Actions se déclenche automatiquement et déploie sur o2switch. ✅

---

## Workflow quotidien ensuite

```bash
# Modifier app.html localement
git add app.html
git commit -m "Mise à jour du simulateur"
git push
# → Déploiement automatique en ~30 secondes !
```

---

## Vérifier le déploiement

Sur GitHub → **Actions** → vous voyez le statut en temps réel.
- ✅ Vert = déploiement réussi
- ❌ Rouge = erreur (cliquez pour voir les logs)
