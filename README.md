# OneCore

Application de gestion scolaire orientee finance:
- eleves, classes, mensualites, paiements, impayes,
- administration multi-roles (`super_admin`, `admin`, `user`),
- API PHP + frontend React.

## Repository
- GitHub: `https://github.com/Yassinox0/EduFlow.git`

## Stack technique
- Frontend: React + Vite
- Backend: PHP 8.x (API REST)
- Base de donnees: MySQL 8 (Docker recommande)

## Prerequis
- Git
- Node.js + npm
- PHP 8.x
- Composer
- Docker Desktop (ou Docker Engine)
- Client MySQL (`mysql` CLI) recommande

## 1) Cloner le projet
```bash
git clone https://github.com/Yassinox0/EduFlow.git
cd EduFlow
```

## 2) Demarrer MySQL (Docker)
```bash
docker run -d --name salma-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=salma_project -p 3307:3306 mysql:8.0
```

Verifier:
```bash
docker ps
```

## 3) Initialiser la base
Importer la structure:
```bash
mysql -h 127.0.0.1 -P 3307 -u root -p < backend/storage/01_schema.sql
```

Appliquer ensuite toutes les migrations:
```powershell
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1
```

Les anciens dumps SQL historiques ne font pas partie de la synchronisation entre
collaborateurs. Ils peuvent contenir des données sensibles et ne doivent jamais
être importés dans une base réelle.

## 4) Configurer le backend
Fichier `backend/.env`:
```env
APP_ENV=local
APP_DEBUG=true
APP_NAME="OneCore"
APP_URL=http://localhost
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=salma_project
DB_USER=root
DB_PASS=root
# Generate with: php -r "echo base64_encode(random_bytes(48)), PHP_EOL;"
# Paste the generated value here locally, or configure it in the deployment secret manager.
JWT_SECRET=GENERATE_A_BASE64_SECRET_BEFORE_STARTING
JWT_TTL=86400
```

### Installation initiale sécurisée

L’installation est désactivée par défaut. Pour une instance neuve locale, ajoutez temporairement à `backend/.env` une valeur Base64 distincte générée avec `random_bytes(48)` :

```env
INITIAL_SETUP_ENABLED=true
INITIAL_SETUP_SECRET=<secret-de-provisioning-base64>
```

L’opérateur saisit ce secret dans la page `/setup`; il est envoyé uniquement dans l’en-tête `X-Setup-Secret` et n’est jamais stocké par le frontend. Après l’installation, remettez `INITIAL_SETUP_ENABLED=false` et retirez le secret de provisioning.

En production, l’activation exige aussi `INITIAL_SETUP_ALLOWED_IPS` et une restriction indépendante au niveau pare-feu ou reverse proxy. N’exposez jamais `/setup` à Internet avant la fin du provisioning.

Installer les dependances backend:
```bash
cd backend
composer install
```

## 5) Lancer le backend
Option Windows:
```bash
./start-backend.ps1
```

Option manuelle:
```bash
cd backend
php -S 127.0.0.1:8080 -t public public/index.php
```

## 6) Configurer le frontend
Fichier `frontend/.env`:
```env
VITE_API_URL=http://127.0.0.1:8080
VITE_BRAND_NAME=OneCore
VITE_SCHOOL_NAME=Votre ecole
```

Installer et lancer:
```bash
cd frontend
npm install
npm run dev
```

URL frontend: `http://localhost:5173`

## 7) Comptes de test
- super admin: `owner@onecore.local` / `owner123`
- admin: `yassine.benmansour@miranda.com` / `admin123`
- user: `salma@miranda.com` / `user123`

Initialiser ou actualiser les donnees de demonstration Miranda (environnement
`local` uniquement):

```powershell
powershell -ExecutionPolicy Bypass -File .\seed-demo.ps1
```

Comptes professeurs ajoutes:

- francais: `yasmine.benmansour@miranda.com` / `Prof@123`
- arabe: `yahia.benmansour@miranda.com` / `Prof@123`

Le script est idempotent et ne supprime aucune donnee. Il prepare les roles,
parents, eleves, inscriptions, affectations, horaires et cas de paiement
`PAID`, `PARTIAL` et `UNPAID` utilises pour la demonstration.

## 8) Reset mot de passe (super admin)
Endpoint:
- `POST /api/users/{id}/reset-password`

Mot de passe applique apres reset:
- `OneCore@123`

Regles:
- seul `super_admin` peut reset les comptes,
- le super admin ne peut pas reset son propre compte via ce bouton.

## 9) Fonctionnalites importantes ajoutees
- scripts DB canoniques:
  - baseline et migrations via `backend/bin/migrate.php`
  - procédure documentée dans `backend/storage/migrations/README.md`
- classes:
  - saisie du nom uniquement,
  - `code` + `sort_order` auto-generes en sequence.
- eleves:
  - creation,
  - modification,
  - suppression.
- impayes:
  - exclut les dossiers `PAID`,
  - calcule une date d'echeance,
  - tri chronologique par urgence.
- dashboard:
  - KPIs,
  - paiements recents,
  - top impayes.

## 10) Synchroniser la base de donnees

### Pour chaque collaborateur apres un `git pull`

Depuis PowerShell, a la racine du projet:

```powershell
git fetch upstream
git merge upstream/main
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1 -Status
```

Ces commandes appliquent uniquement les nouvelles migrations. Elles ne
suppriment pas les donnees locales existantes.

Pour ajouter ou actualiser les donnees de demonstration MIRANDA:

```powershell
powershell -ExecutionPolicy Bypass -File .\seed-demo.ps1
```

Le seed est facultatif et reserve a `APP_ENV=local`.

### Lorsqu'un developpeur modifie la base

1. Ne jamais modifier une migration deja poussee.
2. Creer un nouveau fichier dans `backend/storage/migrations`:

```text
YYYY_MM_DD_NNN_description.sql
```

3. Ajouter les changements SQL dans cette nouvelle migration.
4. Actualiser `backend/storage/01_schema.sql` pour les nouvelles installations.
5. Tester localement:

```powershell
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1 -Status
```

6. Commiter la migration avec le code qui l'utilise:

```powershell
git add backend/storage/01_schema.sql backend/storage/migrations
git add .
git commit -m "feat(database): describe the database change"
git push upstream HEAD:main
```

Les fichiers `.env`, les sauvegardes MySQL, les photos uploadees et les
donnees reelles des eleves, parents et paiements ne doivent jamais etre
pousses.

## 11) Depannage rapide
- Login "Identifiants invalides ou API indisponible":
  - verifier backend sur `127.0.0.1:8080`,
  - verifier `VITE_API_URL`,
  - verifier que MySQL tourne.
- Erreurs SQL / tables manquantes:
  - executer `.\migrate-database.ps1 -Status`, puis `.\migrate-database.ps1`.
- Port MySQL occupe:
  - changer `-p 3307:3306` et `DB_PORT` en consequence.
