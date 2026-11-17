# La Bibliothèque de Babel en réseau

Serveur Laravel d'une bibliothèque du réseau Babel : projet fil rouge du BUT MMI 3 (IUT de Béziers).

La bibliothèque contient toutes les pages possibles de 3 200 caractères. Aucune page n'est stockée : le moteur calcule le texte d'une page à partir de son adresse, et l'adresse d'une page à partir d'un texte. La base ne garde que ce que les membres enregistrent (marque-pages, collections, annotations, liens courts…).

Le serveur a trois portes :

| Porte | Préfixe | Pour qui |
| --- | --- | --- |
| API publique (réseau) | `/api/v1` | les autres bibliothèques du réseau |
| API de l'application | `/api/v1/app` | l'application web Angular |
| Back-office | `/admin` | les administrateurs |

Le contrat d'API commun est dans [`docs/openapi.yaml`](docs/openapi.yaml) (v1.1.0). C'est lui qui fait foi.

## Prérequis

- PHP 8.3 ou plus, avec l'extension **`gmp`** (`php -m` doit la lister)
- Composer 2
- SQLite (par défaut) ou MySQL

## Installation

```bash
git clone https://github.com/MaxX-J/bibliotheque-de-babel.git babel
cd babel
composer install
cp .env.example .env
php artisan key:generate
php artisan babel:cle
php artisan babel:verifier
php artisan migrate
php artisan bibliotheque:installer <identifiant> "<nom>" --description="…"
```

`php artisan babel:verifier` doit finir par « Tout est prêt ». L'identifiant est celui que remet la plateforme du réseau ; il ne change jamais.

Dans `.env`, renseigner aussi le jeton réseau remis par la plateforme (`BIBLIOTHEQUE_JETON_RESEAU`) : sans lui, `GET /api/v1/textes-bannis` reste fermée.

### La clé du moteur

`php artisan babel:cle` écrit `BABEL_KEY` dans `.env`. Cette clé donne son rangement à la bibliothèque :

- ne jamais la changer après l'ouverture de la bibliothèque (toutes les adresses partagées changeraient de texte) ;
- ne jamais la committer, l'écrire dans le code ou la publier ;
- la sauvegarder en lieu sûr, hors du dépôt.

Seule son empreinte est publiée, avec l'identité de la bibliothèque.

## Lancer le serveur

```bash
php artisan serve
```

## Tests et style

```bash
php artisan test
./vendor/bin/pint --test
```

La CI GitHub Actions lance les deux à chaque push et à chaque pull request. Les tests de l'API vérifient chaque réponse contre les schémas de `docs/openapi.yaml`.

## Fonctions du moteur

| N° | Fonction | Où |
| --- | --- | --- |
| F1 | `lire(adresse)` | moteur fourni |
| F2 | `chercher(texte, mode)` | moteur fourni |
| F3 | `normaliser(texte)` | serveur |
| F4 | `localiser(texte, page)` | application web |
| F5 | `valider(adresse)` | serveur |
| F6 | `voisine(adresse, sens, niveau)` | application web |
| F7 | `auHasard()` | serveur |
| F8 | `empreinte()` | moteur fourni |

Le moteur est le paquet [`but-mmi-beziers/babel-moteur`](https://packagist.org/packages/but-mmi-beziers/babel-moteur).
