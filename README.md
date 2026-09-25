# WP MCP Abilities

Connecteur MCP **100 % générique** pour sites WordPress : **un seul fichier** `wp-mcp-abilities.php`, **zéro configuration**. Les types de contenus, taxonomies et descriptions sont **découverts dynamiquement** — utilisable sur n'importe quel site WordPress, idéal avec des custom post types et champs personnalisés ACF.

> **Un fichier, aucun réglage par site.** Déployé tel quel sur tous les sites, il s'adapte automatiquement aux contenus réels de chacun : post types et taxonomies publics, descriptions ACF comprises. Un CPT ajouté dans ACF devient immédiatement utilisable par les agents IA.

---

## Dépendances

| Dépendance | Rôle |
|---|---|
| **WordPress 6.9+** | Fournit l'**Abilities API** native (`wp_register_ability`, hooks `wp_abilities_api_init` / `wp_abilities_api_categories_init`) |
| **[mcp-adapter](https://github.com/WordPress/mcp-adapter)** (plugin officiel WordPress) | Expose les abilities via le protocole **MCP** (tools HTTP/STDIO, serveur par défaut) — doit être installé **et activé** |
| **ACF** | Enregistre les custom post types et taxonomies découverts par le connecteur + expose les champs personnalisés (support conditionnel : sans ACF, aucune erreur, aucun champ exposé) |

Serveur MCP (identique sur tous les sites) :

```
https://{domaine-du-site}/wp-json/mcp/mcp-adapter-default-server
```

Les abilities sont visibles via les méta-tools du serveur par défaut : `mcp-adapter/discover-abilities`, `get-ability-info`, `execute-ability`. Toute ability doit porter `meta.mcp.public = true` pour être exposée — géré automatiquement par ce fichier.

## Découverte automatique

Aucune liste à maintenir, aucun domaine à déclarer :

| Découverte | Règle |
|---|---|
| **Types de contenus** | Tous les post types publics avec interface (`public` + `show_ui`), `attachment` exclu. Les types internes (`wp_block`, `acf-field`, templates…) sont exclus d'office (`public => false`) |
| **Taxonomies** | Toutes les taxonomies publiques avec interface — **tous les termes** sont donc listables (`category`, `post_tag`, taxonomies ACF…) |
| **Description des post types** | La description de chaque type (définie dans **ACF** ou `register_post_type`) est exposée par `site/list-post-types` (champ `description`) **et** reprise dans le texte de contexte injecté dans les descriptions des abilities |
| **Type par défaut** | `post` s'il est disponible, sinon le premier type détecté |

Chaque résultat de lecture porte `type` (slug) et `type_label` (label lisible), quel que soit le site.

## Champs personnalisés (ACF)

Support **conditionnel** : sans ACF, le connecteur ignore simplement les champs personnalisés (aucune erreur, aucun paramètre exposé en pratique).

| Surface | Comportement |
|---|---|
| `site/get-post` | Ajoute `fields` : valeurs ACF du contenu, formatées selon les réglages des champs |
| `site/list-post-types` / `site/list-taxonomies` | Ajoutent `acf_fields` par type/taxonomie : inventaire `name`, `label`, `type`, `required` |
| `site/list-terms` | `include_fields=true` ajoute `fields` par terme (off par défaut — payload plus lourd) |
| `site/create-post` / `site/update-post` | Paramètre d'entrée `fields` (objet `name => value`), appliqué via `update_field()` |
| `site/create-term` / `site/update-term` | Paramètre d'entrée `fields`, appliqué via `update_field( 'term_X' )` |

**Liste blanche stricte** : un nom de champ non déclaré dans ACF pour le type/la taxonomie visé(e) est refusé, avec la liste des champs valides dans le message d'erreur — l'agent ne peut pas créer de metas orphelins. La validation a lieu **avant** toute écriture : rien n'est créé à moitié si un champ est inconnu.

```json
{
  "title": "Nouvelle étude de cas",
  "type": "projets",
  "fields": { "client": "Acme", "annee": 2026 }
}
```

Le retour des écritures inclut `fields_applied` (liste des champs appliqués). Les valeurs suivent les **formats de retour ACF** configurés sur le site (un champ image réglé sur « ID » renvoie un ID, sur « array » un tableau avec l'URL, etc.).

## Les 16 abilities

Préfixe / catégorie neutre : **`site`** — identique sur tous les sites.

| Ability | Type | Description |
|---|---|---|
| `site/list-post-types` | lecture | Types de contenus détectés : labels, descriptions, compteurs publiés, taxonomies attachées, inventaire `acf_fields` |
| `site/get-recent-posts` | lecture | Derniers contenus d'un type (filtre statut, 1–50) |
| `site/search-posts` | lecture | Recherche plein texte, filtrable par type (`any` par défaut) |
| `site/get-post` | lecture | Détail d'un contenu par ID ou slug (contenu complet + `fields` ACF si actifs) |
| `site/get-media` | lecture | Médiathèque : URL, mime, dimensions, alt, légende, taille (pagination) |
| `site/upload-media` | écriture | Import d'un média : base64 (`data` + `filename`) ou URL distante (`url`, sideload serveur) — respecte les mimes autorisés et la taille max du site |
| `site/delete-media` | écriture | Suppression d'un média — **refusée** si l'ID n'est pas un média ; corbeille si activée, sinon définitive (`force=true` pour forcer) |
| `site/list-taxonomies` | lecture | Taxonomies publiques détectées, leurs types de contenus et inventaire `acf_fields` |
| `site/list-terms` | lecture | Termes d'une taxonomie publique (recherche + pagination) — `include_fields=true` pour les valeurs ACF de chaque terme |
| `site/create-post` | écriture | Création de contenu — `draft` par défaut, `parent_id`/`menu_order` pour les types hiérarchiques, `fields` pour les champs ACF |
| `site/update-post` | écriture | Mise à jour (title, content, status, slug, excerpt, parent_id, menu_order, `fields` ACF) |
| `site/delete-post` | écriture | Suppression — `post_type` **REQUIS** (garde-fou anti-confusion) |
| `site/set-post-terms` | écriture | Assignation de termes (par IDs ou noms, ajout ou remplacement) |
| `site/create-term` | écriture | Création d'un terme (name, slug, description, parent, `fields` ACF) |
| `site/update-term` | écriture | Mise à jour d'un terme (taxonomie vérifiée, `fields` ACF) |
| `site/delete-term` | écriture | Suppression d'un terme — **refusée** si encore assigné (sauf `force=true`) |

## Garde-fous (anti-incident IA)

Hérités de l'incident v3 (un agent avait supprimé des contenus du mauvais type) et généralisés :

- Chaque résultat de lecture porte `type` (slug) **et** `type_label` (lisible) — l'agent doit les vérifier.
- `site/list-post-types` documente les types du site, avec leur description et leur nombre de contenus publiés.
- `site/delete-post` refuse la suppression si le `post_type` fourni ne correspond pas au type réel ; `site/update-post` vérifie le type si fourni.
- `site/delete-term` refuse la suppression d'un terme encore assigné à des contenus (`force=true` pour forcer).
- `site/delete-media` refuse tout ID qui n'est pas un média (`attachment`) : impossible de supprimer un contenu via l'outil média.
- Écriture des champs ACF : liste blanche stricte — seuls les champs déclarés pour le type/la taxonomie sont acceptés, validation avant toute écriture.
- Lectures **status-aware** : un contenu non publié n'est lisible que par qui peut l'éditer ; les statuts ≠ `publish` exigent `edit_posts`.
- Publication directe refusée sans la capability dédiée au type (`publish_posts`) ; création en `draft` par défaut.
- `parent_id` uniquement pour les types **hiérarchiques** (parent de même type requis) ; `menu_order` ignoré sur les types non concernés.
- Les types **sans éditeur** (supports sans `editor`) stockent automatiquement le paramètre `content` dans l'extrait.
- Le core WP valide les entrées **et** les sorties contre les schémas JSON (`rest_validate_value_from_schema`) : les callbacks retournent exactement les structures déclarées.

## Sécurité

Toutes les abilities exigent un utilisateur **connecté** + la capability adaptée :

| Opération | Vérification |
|---|---|
| Lecture (posts, types, taxonomies) | `read` |
| Médiathèque (lire / importer) | `upload_files` |
| Supprimer un média | `delete_post` (sur le média ciblé) |
| Créer | `edit_posts` + `publish_posts` si `publish` |
| Mettre à jour | `edit_post` (sur le contenu ciblé) |
| Supprimer | `delete_post` (sur le contenu ciblé) |
| Termes | capabilities dédiées de la taxonomie : `assign_terms`, `manage_terms`, `edit_terms`, `delete_terms` |

L'exposition MCP (`meta.mcp.public = true`) ne contourne aucune permission : chaque exécution repasse par le `permission_callback`.

Lectures **status-aware** : un contenu non publié (brouillon, privé, pending) n'est lisible que par un compte capable de l'éditer — `get-post` exige `edit_post` sur le contenu ciblé, et `get-recent-posts` exige `edit_posts` pour tout statut ≠ `publish`.

Import média par URL : schémas http(s) uniquement, hôte **public** requis (plages privées/boucle locale/réservées refusées avant tout téléchargement) — désactivable via le filtre `wma_allow_url_sideload`. La garde couvre une résolution DNS (pas le DNS rebinding) : pour un cloisonnement strict, désactiver le sideload.

Anonyme : le serveur MCP répond **401 dès le handshake** (`initialize` inclus) — aucune découverte ni exécution sans authentification WordPress (vérifié sur site réel).

## Filtres (optionnels)

Sans toucher au fichier, un petit snippet `mu-plugin` peut ajuster un site particulier :

| Filtre | Rôle | Défaut |
|---|---|---|
| `wma_category` | Préfixe/catégorie des abilities (utile en cas de collision) | `site` |
| `wma_post_types` | Restreindre/étendre les types de contenus exposés | tous les post types publics avec UI |
| `wma_taxonomies` | Restreindre/étendre les taxonomies exposées | toutes les taxonomies publiques avec UI |
| `wma_types_description` | Remplacer le texte de contexte des agents | auto-généré depuis labels + descriptions |
| `wma_allow_url_sideload` | Désactiver l'import média par URL distante (anti-SSRF strict) | `true` |

```php
// Ex. masquer le type « post » sur un site de documentation :
add_filter( 'wma_post_types', function ( $types ) {
    return array_values( array_diff( $types, array( 'post' ) ) );
} );
```

## Installation

### Prérequis

| Élément | Détail |
|---|---|
| WordPress **6.9+** | Fournit l'**Abilities API** native (sinon les abilities ne s'enregistrent pas — le fichier reste silencieux) |
| Plugin **mcp-adapter** | [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter) — **requis** : ce connecteur ne fait qu'enregistrer des abilities, c'est mcp-adapter qui les expose via le protocole MCP |
| **ACF** (optionnel) | Active la lecture/écriture des champs personnalisés ; sans ACF, aucune erreur, aucun champ exposé |
| PHP **7.4+** | — |

### 1. Installer et activer le plugin mcp-adapter

- **Depuis l'admin WordPress** : `Extensions → Ajouter` → chercher **MCP Adapter** → Installer → **Activer** ;
- ou **depuis GitHub** : [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter) → installer le zip manuellement.

Sans ce plugin actif, `wp-mcp-abilities.php` reste inactif côté MCP : les abilities existent dans WordPress mais ne sont jamais exposées.

### 2. Déposer le connecteur en mu-plugin

Copier `wp-mcp-abilities.php` **tel quel** dans `wp-content/mu-plugins/` (créer le dossier s'il n'existe pas) :

```bash
cp wp-mcp-abilities.php {racine-du-site}/wp-content/mu-plugins/
```

Spécificités des mu-plugins :

- **aucune activation** : chargé automatiquement dès le dépôt du fichier ;
- **pas de mise à jour automatique** : mettre à jour = remplacer le fichier ;
- **pas de désactivation** : pour retirer le connecteur, supprimer le fichier.

Le fichier est **identique sur tous les sites** : la découverte automatique s'adapte aux contenus réels de chacun.

### 3. Brancher un client MCP

| Réglage | Valeur |
|---|---|
| Transport | HTTP |
| URL | `https://{domaine}/wp-json/mcp/mcp-adapter-default-server` |
| Authentification | compte WordPress — **mot de passe d'application** recommandé (`Utilisateurs → Profil → Mots de passe d'application`) |

Le client voit trois **méta-tools** : `mcp-adapter/discover-abilities` (liste), `mcp-adapter/get-ability-info` (détail), `mcp-adapter/execute-ability` (exécution).

### 4. Vérifier l'installation

1. `mcp-adapter/discover-abilities` → les **16 abilities `site/…`** doivent apparaître (catégorie « Site Content »).
2. Exécuter `site/list-post-types` → doit lister les types du site **avec leurs descriptions** (+ inventaire `acf_fields` si ACF actif).
3. Test d'écriture complet : `site/create-post` en `draft` (avec un `fields` ACF), vérifier dans l'admin, puis `site/delete-post` avec le bon `post_type`.

En cas de notice « Ability category "site" is already registered », voir la section [Dépannage](#dépannage).

## Dépannage

### Notice « Ability category "site" is already registered »

Le registre des catégories d'abilities est **global** : si la catégorie `site` est déclarée deux fois dans la même requête, WP 6.9 émet un `_doing_it_wrong`. Le connecteur v5.2.1 est protégé des deux causes :

1. **Double chargement du fichier** (deux copies `.php` dans `mu-plugins/`, ou inclusion par un thème/plugin) — neutralisé par la constante `WMA_VERSION` : une seconde copie s'arrête immédiatement.
2. **Collision avec un autre composant** qui déclarerait aussi `site` — neutralisé par la garde `wp_has_ability_category()` : le connecteur ne redéclare pas, ses abilities rejoignent la catégorie existante (renommables via le filtre `wma_category` au besoin).

Le plugin mcp-adapter n'utilise que la catégorie `mcp-adapter` — il ne peut pas être le coupable. Pour identifier un éventuel autre déclarant sur le site :

```bash
ls -la wp-content/mu-plugins/                                   # copies en double ?
wp plugin list --status=must-use                                # mu-plugins chargés
grep -rn "wp_register_ability_category" wp-content/themes/ \
     wp-content/plugins/ wp-content/mu-plugins/ --include="*.php"   # qui déclare une catégorie ?
grep -rn "wp-mcp-abilities" wp-content/ --include="*.php" \
     | grep -v "mu-plugins/wp-mcp-abilities.php"                 # inclusion ailleurs ?
```

> Si un autre composant déclare `site` **après** le connecteur (les mu-plugins chargent en premier), c'est lui qui déclenche la notice : corriger côté coupable, ou renommer notre catégorie via `add_filter( 'wma_category', fn() => 'wma-content' );`.

## Arborescence

```
wp-mcp-abilities/
├── .gitignore                 ← exclut les références locales
├── README.md                 ← ce fichier
└── wp-mcp-abilities.php      ← connecteur générique v5.4 (à déployer)
```

## Historique

| Version | Fichier | Apport |
|---|---|---|
| v5.0 | `wp-mcp-abilities.php` | Factorisation en un fichier unique (config par domaine) |
| **v5.1** | **`wp-mcp-abilities.php`** | **Connecteur 100 % générique** : découverte automatique des types et taxonomies, descriptions ACF exposées, préfixe neutre unique `site/`, zéro configuration |
| **v5.2** | `wp-mcp-abilities.php` | + `site/upload-media` (base64 ou URL distante — garde-fous : taille max du site, mimes autorisés) et `site/delete-media` (garde-fou : l'ID doit être un média) |
| **v5.2.1** | `wp-mcp-abilities.php` | Fix anti-collision : garde `wp_has_ability_category()` avant l'enregistrement de la catégorie + anti double-chargement du fichier (constante `WMA_VERSION`) |
| **v5.3** | `wp-mcp-abilities.php` | **Champs personnalisés ACF** : lecture (`fields` dans get-post, inventaires `acf_fields`, `include_fields` sur list-terms) et écriture (`fields` sur create/update-post et create/update-term, liste blanche stricte) |
| **v5.4** | `wp-mcp-abilities.php` | **Durcissement sécurité** : lectures status-aware (les non-publiés ne sont lisibles que par qui peut les éditer), garde anti-SSRF sur l'import média par URL (http(s), hôte public) + filtre `wma_allow_url_sideload` |

## Licence

Distribué sous licence **GPL-2.0-or-later** — voir [LICENSE](LICENSE).