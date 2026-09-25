<?php
/**
 * Plugin Name: WP MCP Abilities
 * Description: Connecteur MCP générique pour sites WordPress (ACF). Expose des tools WordPress (Abilities API) via MCP (plugin mcp-adapter) pour les agents IA (n8n, Claude, cptr, etc.). Un seul fichier, identique sur tous les sites : les types de contenus, taxonomies et descriptions sont découverts dynamiquement — aucune configuration par site.
 * Version:     5.3.1
 * Author:      krikrak
 * Requires PHP: 7.4
 * Requires WP:  6.9 (Abilities API native)
 *
 * Serveur MCP (tous les sites) : https://{domaine-du-site}/wp-json/mcp/mcp-adapter-default-server
 * Les abilities sont visibles via les méta-tools du serveur par défaut
 * (« mcp-adapter/discover-abilities », « get-ability-info », « execute-ability »).
 *
 * Catégorie / préfixe des abilities : « site » — neutre, identique sur tous
 * les sites (renommable via le filtre wma_category en cas de collision).
 *
 * Abilities exposées (16) :
 *  - site/list-post-types   (lecture)  Types de contenus détectés : labels, descriptions (ACF), compteurs, taxonomies, champs ACF (inventaire).
 *  - site/get-recent-posts  (lecture)  Derniers contenus (inclut type + type_label).
 *  - site/search-posts      (lecture)  Recherche plein texte (multi-types, filtrable par type).
 *  - site/get-post          (lecture)  Détail d'un contenu par ID ou slug (+ champs ACF si actifs).
 *  - site/get-media         (lecture)  Médiathèque : URL, mime, dimensions, alt, etc.
 *  - site/upload-media       (écriture) Import d'un média : base64 (+ filename) ou URL distante (sideload).
 *  - site/delete-media       (écriture) Suppression d'un média — refusée si l'ID n'est pas un média (garde-fou).
 *  - site/list-taxonomies   (lecture)  Taxonomies publiques détectées et leurs types de contenus.
 *  - site/list-terms        (lecture)  Termes d'une taxonomie publique (recherche + pagination).
 *  - site/create-post       (écriture) Création de contenu (draft par défaut).
 *  - site/update-post       (écriture) Mise à jour (vérification optionnelle du type).
 *  - site/delete-post       (écriture) Suppression — post_type REQUIS (garde-fou anti-confusion).
 *  - site/set-post-terms    (écriture) Assignation de termes à un contenu.
 *  - site/create-term       (écriture) Création d'un terme (name, slug, description, parent).
 *  - site/update-term       (écriture) Mise à jour d'un terme (taxonomie vérifiée).
 *  - site/delete-term       (écriture) Suppression d'un terme — refusée si encore assigné (garde-fou).
 *
 * IMPORTANT : toute ability doit porter meta.mcp.public = true pour être
 * exposée sur le serveur MCP par défaut (cf. McpAbilityHelperTrait du plugin
 * mcp-adapter). Le plugin mcp-adapter doit être installé ET activé.
 *
 * NOTE : le core WP valide les entrées ET les sorties contre les schémas JSON
 * (rest_validate_value_from_schema). Les callbacks doivent donc retourner
 * exactement les structures déclarées dans output_schema.
 *
 * DÉCOUVERTE AUTOMATIQUE — aucun réglage par site :
 *  - types de contenus : tous les post types publics avec interface
 *                         (« attachment » exclu) — un CPT ajouté via ACF est
 *                         immédiatement exposé aux agents IA ;
 *  - taxonomies        : toutes les taxonomies publiques avec interface —
 *                         tous les termes sont donc listables ;
 *  - descriptions      : la description de chaque post type (définie dans
 *                         ACF ou register_post_type) est exposée via
 *                         site/list-post-types (champ « description ») et
 *                         reprise dans le texte de contexte des abilities ;
 *  - type par défaut   : « post » s'il est disponible, sinon le premier détecté.
 *
 * Étendre / restreindre sans modifier ce fichier (optionnel), via les filtres :
 *  - wma_category           → préfixe/catégorie des abilities (défaut « site »).
 *  - wma_post_types         → liste des post types autorisés.
 *  - wma_taxonomies         → liste des taxonomies autorisées.
 *  - wma_types_description  → texte de contexte pour les agents.
 *
 * CHAMPS PERSONNALISÉS (ACF) — support conditionnel (rien si ACF est inactif) :
 *  - lecture  : site/get-post renvoie « fields » (valeurs ACF du contenu) ;
 *               site/list-post-types et site/list-taxonomies inventorient les champs
 *               par type/taxonomie (« acf_fields » : name, label, type, required) ;
 *               site/list-terms : include_fields=true ajoute les valeurs ACF de chaque terme ;
 *  - écriture : paramètre « fields » (objet clé => valeur) sur site/create-post,
 *               site/update-post, site/create-term et site/update-term ;
 *  - garde-fou : liste blanche stricte — un champ non déclaré dans ACF pour le
 *               type/la taxonomie ciblé(e) est refusé (message avec la liste valide).
 *
 * GARDE-FOUS (hérités de l'incident v3 : un agent IA avait supprimé des
 * contenus du mauvais type) :
 *  - chaque résultat de lecture porte « type » (slug) et « type_label » (lisible) ;
 *  - site/list-post-types documente les types du site (descriptions + compteurs) ;
 *  - site/delete-post REFUSE de supprimer si le paramètre requis « post_type »
 *    ne correspond pas au type réel du contenu ciblé ;
 *  - site/update-post vérifie le type si « post_type » est fourni ;
 *  - site/delete-term refuse la suppression d'un terme encore assigné
 *    (sauf force=true explicite) ;
 *  - site/delete-media refuse tout ID qui n'est pas un média (« attachment ») ;
 *  - écriture des champs ACF limitée aux champs déclarés pour le type/la
 *    taxonomie (liste blanche stricte) ;
 *  - publication directe refusée sans la capability dédiée au type (draft par défaut) ;
 *  - site/create-post & update-post : parent_id uniquement pour les types
 *    hiérarchiques (même type requis) ; les types SANS éditeur (supports sans
 *    « editor ») stockent automatiquement « content » dans l'extrait.
 *
 * HISTORIQUE :
 *  - v5.0        : factorisation générique — un seul fichier, config par site (domaine).
 *  - v5.1        : connecteur 100 % générique — découverte automatique des types
 *                  et taxonomies, préfixe neutre unique « site », zéro config.
 *  - v5.2        : + site/upload-media (base64 ou URL distante) et site/delete-media
 *                  (garde-fou : l'ID doit être un média « attachment »).
 *  - v5.2.1      : anti-collision de catégories : garde wp_has_ability_category()
 *                  + anti double-chargement du fichier (constante WMA_VERSION).
 *  - v5.3        : + champs personnalisés ACF : lecture (get-post « fields »,
 *                  inventaires acf_fields dans list-post-types/list-taxonomies,
 *                  include_fields sur list-terms) et écriture (« fields » sur
 *                  create/update-post et create/update-term, liste blanche stricte).
 *  - v5.3.1      : nettoyage pour publication publique — suppression des références
 *                  aux sites historiques, version constante WMA_VERSION corrigée.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WMA_VERSION' ) ) {
	return; // Déjà chargé : une seconde copie de ce fichier s'arrête ici.
}
define( 'WMA_VERSION', '5.3.1' );

/* -------------------------------------------------------------------------
 * Découverte automatique
 * ---------------------------------------------------------------------- */

/**
 * Préfixe / catégorie des abilities — neutre, identique sur tous les sites.
 * Étensible via le filtre wma_category (utile en cas de collision).
 *
 * @return string
 */
function wma_category(): string {
	return (string) apply_filters( 'wma_category', 'site' );
}

/**
 * Types de contenus autorisés dans les abilities : tous les post types
 * publics avec interface, « attachment » exclu. Un CPT enregistré via ACF
 * (ou via le thème) est donc exposé automatiquement, sans configuration.
 * Étensible via le filtre wma_post_types.
 *
 * @return string[]
 */
function wma_post_types(): array {
	$types = get_post_types( array( 'public' => true, 'show_ui' => true ), 'names' );
	unset( $types['attachment'] );
	return apply_filters( 'wma_post_types', array_values( $types ) );
}

/**
 * Taxonomies autorisées dans les abilities : toutes les taxonomies publiques
 * avec interface — tous les termes sont donc listables.
 * Étensible via le filtre wma_taxonomies.
 *
 * @return string[]
 */
function wma_taxonomies(): array {
	$taxonomies = get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'names' );
	return apply_filters( 'wma_taxonomies', array_values( $taxonomies ) );
}

/**
 * Type de contenu par défaut des queries/créations : « post » s'il est
 * disponible, sinon le premier type détecté.
 *
 * @return string
 */
function wma_default_post_type(): string {
	$types = wma_post_types();

	if ( in_array( 'post', $types, true ) ) {
		return 'post';
	}

	return (string) reset( $types );
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Description des types de contenus, injectée dans les descriptions des
 * abilities pour que les agents IA distinguent les types entre eux.
 * Générée dynamiquement depuis les labels ET les descriptions des post types
 * (telles que définies dans ACF ou register_post_type).
 * Étensible via le filtre wma_types_description.
 *
 * @return string
 */
function wma_types_description(): string {
	$parts = array();
	foreach ( wma_post_types() as $slug ) {
		$obj   = get_post_type_object( $slug );
		$label = $obj ? (string) $obj->labels->name : $slug;
		$desc  = $obj ? trim( (string) $obj->description ) : '';

		$parts[] = $desc
			? sprintf( '"%1$s" = %2$s (%3$s)', $slug, $label, $desc )
			: sprintf( '"%1$s" = %2$s', $slug, $label );
	}

	return (string) apply_filters(
		'wma_types_description',
		'Content types on this site: ' . implode( '; ', $parts ) . '. Each type is distinct: always check the "type" and "type_label" fields of a result before any create, update or delete action.'
	);
}

/**
 * Taxonomies autorisées qui sont hiérarchiques (pour les descriptions des
 * abilities liées au paramètre « parent » des termes).
 *
 * @return string[]
 */
function wma_hierarchical_taxonomies(): array {
	$out = array();
	foreach ( wma_taxonomies() as $taxonomy ) {
		$obj = get_taxonomy( $taxonomy );
		if ( $obj && ! empty( $obj->hierarchical ) ) {
			$out[] = $taxonomy;
		}
	}
	return $out;
}

/**
 * Note « hiérarchique » prête à insérer dans une description d'ability.
 *
 * @return string Ex. « (hierarchical taxonomies only: category, service) ».
 */
function wma_hierarchical_note(): string {
	$hier = wma_hierarchical_taxonomies();
	if ( empty( $hier ) ) {
		return ' (hierarchical taxonomies only)';
	}
	return ' (hierarchical taxonomies only: ' . implode( ', ', $hier ) . ')';
}

/**
 * Label lisible d'un type de contenu (ex: « Article », « Doc »).
 *
 * @param string $post_type Slug du type de contenu.
 * @return string Label singulier lisible.
 */
function wma_type_label( string $post_type ): string {
	$obj = get_post_type_object( $post_type );
	return ( $obj && ! empty( $obj->labels->singular_name ) )
		? (string) $obj->labels->singular_name
		: $post_type;
}

/**
 * Formate un post en tableau conforme au schéma de sortie « post_item ».
 * Inclut systématiquement le slug ET le label lisible du type de contenu.
 *
 * @param WP_Post $post Le post à formater.
 * @return array Structure utilisée dans les outputs des abilities.
 */
function wma_format_post( WP_Post $post ): array {
	return array(
		'id'         => (int) $post->ID,
		'title'      => (string) get_the_title( $post ),
		'slug'       => (string) $post->post_name,
		'status'     => (string) $post->post_status,
		'type'       => (string) $post->post_type,
		'type_label' => wma_type_label( $post->post_type ),
		'date'       => $post->post_date ? (string) mysql2date( 'c', $post->post_date ) : '',
		'author'     => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
		'excerpt'    => (string) wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 40, '…' ),
		'link'       => (string) get_permalink( $post ),
		'edit_url'   => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post, 'raw' ) : '',
	);
}

/**
 * Vérifie qu'un parent est valide pour un type hiérarchique.
 *
 * @param int    $parent_id ID du contenu parent proposé.
 * @param string $post_type Type de contenu cible.
 * @return true|WP_Error
 */
function wma_check_parent( int $parent_id, string $post_type ) {
	$type_obj = get_post_type_object( $post_type );

	if ( ! $type_obj || empty( $type_obj->hierarchical ) ) {
		return new WP_Error(
			'wma_type_not_hierarchical',
			sprintf(
				'Type "%s" is not hierarchical: the parent_id parameter is not allowed.',
				$post_type
			)
		);
	}

	$parent = get_post( $parent_id );

	if ( ! $parent || $parent->post_type !== $post_type ) {
		return new WP_Error(
			'wma_parent_not_found',
			sprintf(
				'Parent %1$d does not exist or is not of type "%2$s" (parents must share the content type).',
				$parent_id,
				$post_type
			)
		);
	}

	return true;
}

/**
 * ACF est-il actif ? Sans ACF, les abilities ignorent simplement les champs
 * personnalisés (aucune erreur, aucune exposition).
 *
 * @return bool
 */
function wma_acf_active(): bool {
	return function_exists( 'get_fields' )
		&& function_exists( 'update_field' )
		&& function_exists( 'acf_get_field_groups' )
		&& function_exists( 'acf_get_fields' );
}

/**
 * Champs ACF déclarés pour un contexte (« post_type » ou « taxonomy »).
 *
 * @param string $kind Contexte : 'post_type' ou 'taxonomy'.
 * @param string $slug  Slug du type de contenu ou de la taxonomie.
 * @return array Map name => tableau décrivant le champ ACF.
 */
function wma_acf_fields( string $kind, string $slug ): array {
	if ( ! wma_acf_active() ) {
		return array();
	}

	$out    = array();
	$groups = acf_get_field_groups( array( $kind => $slug ) );

	foreach ( $groups as $group ) {
		foreach ( acf_get_fields( $group ) as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$out[ $field['name'] ] = $field;
			}
		}
	}

	return $out;
}

/**
 * Inventaire des champs ACF d'un contexte, pour la sortie JSON
 * (site/list-post-types, site/list-taxonomies).
 *
 * @param string $kind Contexte : 'post_type' ou 'taxonomy'.
 * @param string $slug  Slug du type de contenu ou de la taxonomie.
 * @return array[]
 */
function wma_acf_field_list( string $kind, string $slug ): array {
	$list = array();

	foreach ( wma_acf_fields( $kind, $slug ) as $field ) {
		$list[] = array(
			'name'     => (string) $field['name'],
			'label'    => (string) ( $field['label'] ?? $field['name'] ),
			'type'     => (string) ( $field['type'] ?? '' ),
			'required' => ! empty( $field['required'] ),
		);
	}

	return $list;
}

/**
 * Valeurs des champs ACF d'un contenu ou d'un terme (lecture).
 *
 * @param int|string $acf_id ID ACF : ID du post, ou « term_{term_id} » pour un terme.
 * @return array|stdClass|null Map des valeurs ({} si aucune), ou null si ACF inactif.
 */
function wma_get_acf_fields( $acf_id ) {
	if ( ! wma_acf_active() ) {
		return null;
	}

	$fields = get_fields( $acf_id );

	if ( ! is_array( $fields ) || array() === $fields ) {
		return new stdClass();
	}

	return $fields;
}

/**
 * Validation d'une saisie de champs ACF (liste blanche stricte) :
 * un nom non déclaré pour ce type/taxonomie est refusé, avec la liste
 * des champs valides dans le message.
 *
 * @param array|null $fields Saisie de l'agent (clé => valeur).
 * @param string     $kind   Contexte : 'post_type' ou 'taxonomy'.
 * @param string     $slug   Slug du type de contenu ou de la taxonomie.
 * @return null|WP_Error Null si rien à valider ou si tout est connu.
 */
function wma_validate_acf_fields( $fields, string $kind, string $slug ) {
	if ( ! is_array( $fields ) || array() === $fields ) {
		return null;
	}

	if ( ! wma_acf_active() ) {
		return new WP_Error(
			'wma_acf_required',
			'Custom fields require ACF, which is not active on this site.'
		);
	}

	$known   = wma_acf_fields( $kind, $slug );
	$unknown = array();

	foreach ( array_keys( $fields ) as $name ) {
		if ( ! isset( $known[ $name ] ) ) {
			$unknown[] = (string) $name;
		}
	}

	if ( $unknown ) {
		return new WP_Error(
			'wma_unknown_fields',
			sprintf(
				'Unknown custom field(s) for %1$s "%2$s": %3$s. Allowed fields: %4$s.',
				'post_type' === $kind ? 'post type' : 'taxonomy',
				$slug,
				implode( ', ', $unknown ),
				$known ? implode( ', ', array_keys( $known ) ) : '(none declared)'
			)
		);
	}

	return null;
}

/**
 * Application des champs ACF validés (écriture).
 *
 * @param array|null $fields Saisie de l'agent (clé => valeur).
 * @param int|string $acf_id ID ACF : ID du post, ou « term_{term_id} ».
 * @return string[] Noms des champs appliqués.
 */
function wma_apply_acf_fields( $fields, $acf_id ): array {
	if ( ! is_array( $fields ) || array() === $fields || ! wma_acf_active() ) {
		return array();
	}

	$applied = array();
	foreach ( $fields as $name => $value ) {
		update_field( $name, $value, $acf_id );
		$applied[] = (string) $name;
	}

	return $applied;
}


/**
 * Schéma JSON de sortie réutilisable : un post formaté.
 *
 * @param bool $with_content Inclure la propriété « content » (défaut false).
 * @param bool $with_fields   Inclure la propriété « fields » (valeurs ACF, défaut false).
 * @return array Schéma « post_item ».
 */
function wma_post_schema( bool $with_content = false, bool $with_fields = false ): array {
	$properties = array(
		'id'         => array( 'type' => 'integer' ),
		'title'      => array( 'type' => 'string' ),
		'slug'       => array( 'type' => 'string' ),
		'status'     => array( 'type' => 'string' ),
		'type'       => array(
			'type'        => 'string',
			'description' => 'Content type slug: ' . implode( ', ', wma_post_types() ) . '. ALWAYS check this field.',
		),
		'type_label' => array(
			'type'        => 'string',
			'description' => 'Human-readable content type label. ALWAYS check this field.',
		),
		'date'       => array( 'type' => 'string', 'description' => 'ISO 8601' ),
		'author'     => array( 'type' => 'string' ),
		'excerpt'    => array( 'type' => 'string' ),
		'link'       => array( 'type' => 'string' ),
		'edit_url'   => array( 'type' => 'string' ),
	);
	if ( $with_content ) {
		$properties['content'] = array( 'type' => 'string' );
	}
	if ( $with_fields ) {
		$properties['fields'] = array(
			'type'        => 'object',
			'description' => 'ACF custom field values, keyed by field name (requires ACF).',
		);
	}
	return array(
		'type'       => 'object',
		'properties' => $properties,
		'required'   => array( 'id', 'title', 'type', 'type_label', 'link' ),
	);
}

/**
 * Réponse standardisée d'une ability média : élément de médiathèque formaté
 * (utilisé par site/upload-media).
 *
 * @param int    $attach_id ID du média.
 * @param string $message   Message de retour.
 * @return array
 */
function wma_media_response( int $attach_id, string $message ): array {
	$attachment = get_post( $attach_id );
	$mime        = $attachment ? (string) $attachment->post_mime_type : '';
	$meta       = wp_get_attachment_metadata( $attach_id );
	$meta       = is_array( $meta ) ? $meta : array();

	return array(
		'id'        => (int) $attach_id,
		'title'     => (string) get_the_title( $attach_id ),
		'mime_type' => $mime,
		'url'       => (string) wp_get_attachment_url( $attach_id ),
		'alt_text'  => (string) get_post_meta( $attach_id, '_wp_attachment_image_alt', true ),
		'width'     => (int) ( $meta['width'] ?? 0 ),
		'height'    => (int) ( $meta['height'] ?? 0 ),
		'filesize'  => (int) ( $meta['filesize'] ?? 0 ),
		'edit_url'  => (string) get_edit_post_link( $attach_id, 'raw' ),
		'message'   => $message,
	);
}

/* -------------------------------------------------------------------------
 * 1. Catégorie — doit être enregistrée sur ce hook, AVANT les abilities.
 * ---------------------------------------------------------------------- */
add_action( 'wp_abilities_api_categories_init', static function () {
	$category = wma_category();

	// Garde-fou anti-collision : si la catégorie est déjà déclarée par un autre
	// composant (le slug « site » est générique), on ne la redéclare pas —
	// nos abilities rejoignent alors la catégorie existante.
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $category ) ) {
		return;
	}

	wp_register_ability_category(
		$category,
		array(
			'label'       => 'Site Content',
			'description' => 'Content tools for this WordPress site: read, search, create, update and delete content; manage media and taxonomies.',
		)
	);
} );

/* -------------------------------------------------------------------------
 * 2. Abilities — enregistrées sur ce hook.
 * ---------------------------------------------------------------------- */
add_action( 'wp_abilities_api_init', static function () {

	$prefix        = wma_category();
	$post_types    = wma_post_types();
	$taxonomies    = wma_taxonomies();
	$default       = wma_default_post_type();
	$hier_note     = wma_hierarchical_note();
	$tax_list      = implode( ', ', $taxonomies );

	/** Meta MCP : rend l'ability publique sur le serveur MCP par défaut. */
	$meta_public = static function ( bool $readonly ): array {
		return array(
			'mcp'         => array(
				'public' => true, // Requis pour l'exposition MCP via mcp-adapter.
			),
			'annotations' => array(
				'readonly'    => $readonly,
				'destructive' => false,
				'idempotent'  => $readonly,
			),
		);
	};

	/* ---------- Ability : site/list-post-types (lecture) ---------- */

	wp_register_ability(
		$prefix . '/list-post-types',
		array(
			'label'               => 'List Post Types',
			'description'         => 'List the content types of the site with their labels, descriptions, published counts, attached taxonomies and ACF field inventory ("acf_fields": name, label, type, required — when ACF is active). Use this FIRST to understand the content types and their custom fields before searching, creating, updating or deleting content. ' . wma_types_description(),
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_types' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'singular'     => array( 'type' => 'string' ),
								'description'  => array( 'type' => 'string' ),
								'hierarchical' => array( 'type' => 'boolean' ),
								'published'    => array( 'type' => 'integer' ),
								'taxonomies'   => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'acf_fields'   => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'name'     => array( 'type' => 'string' ),
											'label'    => array( 'type' => 'string' ),
											'type'     => array( 'type' => 'string' ),
											'required' => array( 'type' => 'boolean' ),
										),
										'required'   => array( 'name', 'label', 'type' ),
									),
								),
							),
							'required'   => array( 'name', 'label' ),
						),
					),
				),
				'required'   => array( 'post_types' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'read' );
			},
			'execute_callback'    => 'wma_exec_list_post_types',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/get-recent-posts (lecture) ---------- */

	wp_register_ability(
		$prefix . '/get-recent-posts',
		array(
			'label'               => 'Get Recent Posts',
			'description'         => 'Retrieve the most recent content of a given type, newest first. Each result carries "type" and "type_label" identifying its content type. ' . wma_types_description(),
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'numberposts' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 10,
						'description' => 'Number of items to return (1-50, default 10).',
					),
					'post_type'   => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'default'     => $default,
						'description' => 'Content type to query (default "' . $default . '"). ' . wma_types_description(),
					),
					'post_status' => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'private', 'any' ),
						'default'     => 'publish',
						'description' => 'Status filter (default "publish").',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts' => array(
						'type'  => 'array',
						'items' => wma_post_schema(),
					),
					'total' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'posts', 'total' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'read' );
			},
			'execute_callback'    => 'wma_exec_get_recent_posts',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/search-posts (lecture) ---------- */

	wp_register_ability(
		$prefix . '/search-posts',
		array(
			'label'               => 'Search Posts',
			'description'         => 'Full-text search across published content. WARNING: results may mix several content types — each result carries "type" and "type_label", ALWAYS check them before any action. Restrict the search with the post_type parameter when the user mentions a specific kind of content. ' . wma_types_description(),
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query'     => array(
						'type'        => 'string',
						'minLength'   => 2,
						'description' => 'Search terms.',
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => array_merge( $post_types, array( 'any' ) ),
						'default'     => 'any',
						'description' => 'Restrict search to one content type ("any" = all types, default). STRONGLY recommended to narrow down before destructive actions. ' . wma_types_description(),
					),
					'limit'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 10,
						'description' => 'Max results (1-50, default 10).',
					),
				),
				'required'   => array( 'query' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array(
						'type'  => 'array',
						'items' => wma_post_schema(),
					),
					'count'   => array( 'type' => 'integer' ),
				),
				'required'   => array( 'results', 'count' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'read' );
			},
			'execute_callback'    => 'wma_exec_search_posts',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/get-post (lecture, détail) ---------- */

	wp_register_ability(
		$prefix . '/get-post',
		array(
			'label'               => 'Get Post',
			'description'         => 'Retrieve a single content item with its full content, by numeric ID or slug. The result carries "type" and "type_label" identifying its content type — check them before updating or deleting. When ACF is active, the result also carries a "fields" object with the custom field values of the content.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Numeric post ID.',
					),
					'slug'    => array(
						'type'        => 'string',
						'description' => 'Post slug (used if post_id is absent).',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post' => wma_post_schema( true, true ),
				),
				'required'   => array( 'post' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'read' );
			},
			'execute_callback'    => 'wma_exec_get_post',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/create-post (écriture) ---------- */

	wp_register_ability(
		$prefix . '/create-post',
		array(
			'label'               => 'Create Post',
			'description'         => 'Create new content. Choose the type carefully. ' . wma_types_description() . ' Use status "draft" for review (default), "pending", or "publish" (requires publish capability). Hierarchical types accept the optional parent_id (parent must be of the same content type) and menu_order. Types without an editor automatically store the "content" parameter as the excerpt. Taxonomies can be assigned afterwards via ' . $prefix . '/set-post-terms. Optional "fields" object sets ACF custom fields at creation time — only fields declared for the type are accepted (inventory via ' . $prefix . '/list-post-types); requires ACF.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'title'     => array(
						'type'        => 'string',
						'minLength'   => 3,
						'description' => 'Post title.',
					),
					'content'   => array(
						'type'        => 'string',
						'description' => 'Post content (HTML allowed). For types without an editor, stored as the excerpt instead.',
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'publish' ),
						'default'     => 'draft',
						'description' => 'New post status (default "draft").',
					),
					'type'      => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'default'     => $default,
						'description' => 'Content type (default "' . $default . '"). ' . wma_types_description(),
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => 'Optional URL slug (sanitized).',
					),
					'parent_id' => array(
						'type'        => 'integer',
						'description' => 'Optional parent content ID (hierarchical types only). Must be of the same content type.',
					),
					'menu_order' => array(
						'type'        => 'integer',
						'description' => 'Optional sort order (types supporting page attributes).',
					),
					'excerpt'   => array(
						'type'        => 'string',
						'description' => 'Optional excerpt. For types without an editor, the "content" parameter is stored here automatically.',
					),
					'fields'   => array(
						'type'        => 'object',
						'description' => 'Optional ACF custom field values, keyed by field name. Only fields declared in ACF for this content type are accepted — check the field inventory via ' . $prefix . '/list-post-types. Requires ACF.',
					),
				),
				'required'   => array( 'title', 'content' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'status'         => array( 'type' => 'string' ),
					'link'           => array( 'type' => 'string' ),
					'edit_url'       => array( 'type' => 'string' ),
					'fields_applied' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'success', 'id' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'edit_posts' );
			},
			'execute_callback'    => 'wma_exec_create_post',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/update-post (écriture) ---------- */

	wp_register_ability(
		$prefix . '/update-post',
		array(
			'label'               => 'Update Post',
			'description'         => 'Update an existing content item. Provide the post ID and at least one field to change (title, content, status, slug, excerpt, parent_id, menu_order). Optional "post_type" acts as a safety check: if provided and different from the actual content type of the post, the update is REFUSED. Note: types without an editor store the "content" parameter in the excerpt instead. Optional "fields" object updates ACF custom fields — only fields declared for the type are accepted; requires ACF.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => 'ID of the post to update.',
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => 'Optional safety check: expected content type of the post. If it does not match, the update is refused. ' . wma_types_description(),
					),
					'title'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'New title.',
					),
					'content'   => array(
						'type'        => 'string',
						'description' => 'New full content (HTML allowed). Replaces the entire content. For types without an editor, updates the excerpt instead.',
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
						'description' => 'New status.',
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => 'New URL slug (sanitized).',
					),
					'parent_id' => array(
						'type'        => 'integer',
						'description' => 'New parent content ID, 0 to unset (hierarchical types only). Must be of the same content type.',
					),
					'menu_order' => array(
						'type'        => 'integer',
						'description' => 'New sort order (types supporting page attributes).',
					),
					'excerpt'   => array(
						'type'        => 'string',
						'description' => 'New excerpt. For types without an editor, the "content" parameter updates it too.',
					),
					'fields'   => array(
						'type'        => 'object',
						'description' => 'Optional ACF custom field values, keyed by field name. Only fields declared in ACF for this content type are accepted — check the field inventory via ' . $prefix . '/list-post-types. Requires ACF.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'id'             => array( 'type' => 'integer' ),
					'status'         => array( 'type' => 'string' ),
					'link'           => array( 'type' => 'string' ),
					'edit_url'       => array( 'type' => 'string' ),
					'fields_applied' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'success', 'id' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$post = get_post( absint( $input['post_id'] ?? 0 ) );
				return $post ? current_user_can( 'edit_post', $post->ID ) : false;
			},
			'execute_callback'    => 'wma_exec_update_post',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/delete-post (écriture) ---------- */

	wp_register_ability(
		$prefix . '/delete-post',
		array(
			'label'               => 'Delete Post',
			'description'         => 'Delete content by ID. SAFEGUARD: the "post_type" parameter is REQUIRED and must match the actual content type of the post — the deletion is REFUSED if it does not (prevents deleting one kind of content when the user asked to delete another). Verify the ID and its type first via search-posts or get-post. Default moves to trash (recoverable); force=true permanently deletes. ' . wma_types_description(),
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => 'ID of the post to delete.',
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => 'REQUIRED: the content type you intend to delete. Must match the actual type of the post. ' . wma_types_description(),
					),
					'force'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true = permanently delete (default false = move to trash, recoverable).',
					),
				),
				'required'   => array( 'post_id', 'post_type' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'id'         => array( 'type' => 'integer' ),
					'type'       => array( 'type' => 'string' ),
					'type_label' => array( 'type' => 'string' ),
					'title'      => array( 'type' => 'string' ),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'trashed', 'deleted' ),
						'description' => '"trashed" or "deleted".',
					),
					'message'    => array( 'type' => 'string' ),
				),
				'required'   => array( 'success', 'id', 'status' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$post = get_post( absint( $input['post_id'] ?? 0 ) );
				return $post ? current_user_can( 'delete_post', $post->ID ) : false;
			},
			'execute_callback'    => 'wma_exec_delete_post',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/get-media (lecture) ---------- */

	wp_register_ability(
		$prefix . '/get-media',
		array(
			'label'               => 'Get Media',
			'description'         => 'Retrieve media library items with URL, mime type, dimensions, alt text, caption, file size and upload date. Filter by search text or mime type, paginated.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'search' => array(
						'type'        => 'string',
						'description' => 'Filter by title, description or filename.',
					),
					'mime'   => array(
						'type'        => 'string',
						'description' => 'Mime filter, e.g. "image/jpeg" or group "image", "audio", "video".',
					),
					'limit'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 10,
						'description' => 'Max items (1-50, default 10).',
					),
					'offset' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => 'Number of items to skip.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array( 'type' => 'integer' ),
								'title'       => array( 'type' => 'string' ),
								'slug'        => array( 'type' => 'string' ),
								'mime_type'   => array( 'type' => 'string' ),
								'url'         => array( 'type' => 'string' ),
								'alt_text'    => array( 'type' => 'string' ),
								'caption'     => array( 'type' => 'string' ),
								'description' => array( 'type' => 'string' ),
								'width'       => array( 'type' => 'integer' ),
								'height'      => array( 'type' => 'integer' ),
								'filesize'    => array( 'type' => 'integer' ),
								'date'        => array( 'type' => 'string' ),
							),
							'required'   => array( 'id', 'url' ),
						),
					),
					'total'  => array( 'type' => 'integer' ),
					'limit'  => array( 'type' => 'integer' ),
					'offset' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'items', 'total' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'upload_files' );
			},
			'execute_callback'    => 'wma_exec_get_media',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/upload-media (écriture) ---------- */

	wp_register_ability(
		$prefix . '/upload-media',
		array(
			'label'               => 'Upload Media',
			'description'         => 'Upload a media file into the media library. Provide either "data" (base64-encoded file content, with "filename") or "url" (remote file the site downloads itself — PREFER this for large files, MCP payloads have size limits). The file type must be allowed by the site. Optional title, alt_text, caption and description are set at upload time. Returns the created media item with its ID and URL.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'filename'    => array(
						'type'        => 'string',
						'description' => 'File name with extension, e.g. "logo.webp". REQUIRED with "data" (used to store the file and detect its mime type). Optional with "url" (derived from the URL if absent).',
					),
					'data'        => array(
						'type'        => 'string',
						'description' => 'Base64-encoded file content. Provide either "data" or "url".',
					),
					'url'         => array(
						'type'        => 'string',
						'description' => 'Remote URL of the file to import (server-side download). Provide either "url" or "data".',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Optional media title (defaults to the file name without extension).',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'Optional alt text (recommended for images — accessibility).',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'Optional caption.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Optional description.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'        => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ),
					'url'       => array( 'type' => 'string' ),
					'alt_text'  => array( 'type' => 'string' ),
					'width'     => array( 'type' => 'integer' ),
					'height'    => array( 'type' => 'integer' ),
					'filesize'  => array( 'type' => 'integer' ),
					'edit_url'  => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'url' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'upload_files' );
			},
			'execute_callback'    => 'wma_exec_upload_media',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/delete-media (écriture) ---------- */

	wp_register_ability(
		$prefix . '/delete-media',
		array(
			'label'               => 'Delete Media',
			'description'         => 'Delete a media item by ID. SAFEGUARD: only media ("attachment" posts) can be deleted — any other content ID is REFUSED (use site/delete-post for other content types: it requires an explicit post_type). Verify the media ID first via site/get-media. Default tries to move the item to trash (only effective when media trash is enabled on the site — otherwise the deletion is permanent); force=true permanently deletes (unrecoverable).',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'media_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the media item to delete.',
					),
					'force'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true = permanently delete. Default false = move to trash when media trash is enabled (otherwise the deletion is permanent anyway).',
					),
				),
				'required'   => array( 'media_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'id'        => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'trashed', 'deleted' ),
						'description' => '"trashed" or "deleted".',
					),
					'message'   => array( 'type' => 'string' ),
				),
				'required'   => array( 'success', 'id', 'status' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
						return false;
				}
				$post = get_post( absint( $input['media_id'] ?? 0 ) );
				return ( $post && 'attachment' === $post->post_type ) ? current_user_can( 'delete_post', $post->ID ) : false;
			},
			'execute_callback'    => 'wma_exec_delete_media',
			'meta'                => $meta_public( false ),
		)
	);


	/* ---------- Ability : site/list-taxonomies (lecture) ---------- */

	wp_register_ability(
		$prefix . '/list-taxonomies',
		array(
			'label'               => 'List Taxonomies',
			'description'         => 'List public taxonomies of the site (' . $tax_list . ') with the content types they apply to. When ACF is active, each taxonomy also carries an "acf_fields" inventory (name, label, type, required) of its term custom fields.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomies' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'description'  => array( 'type' => 'string' ),
								'public'       => array( 'type' => 'boolean' ),
								'hierarchical' => array( 'type' => 'boolean' ),
								'object_types' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'acf_fields'   => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'name'     => array( 'type' => 'string' ),
											'label'    => array( 'type' => 'string' ),
											'type'     => array( 'type' => 'string' ),
											'required' => array( 'type' => 'boolean' ),
										),
										'required'   => array( 'name', 'label', 'type' ),
									),
								),
							),
							'required'   => array( 'name' ),
						),
					),
				),
				'required'   => array( 'taxonomies' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'read' );
			},
			'execute_callback'    => 'wma_exec_list_taxonomies',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/list-terms (lecture) ---------- */

	wp_register_ability(
		$prefix . '/list-terms',
		array(
			'label'               => 'List Terms',
			'description'         => 'List terms of a taxonomy (' . $tax_list . '), e.g. all categories. Optional text search, pagination, and ACF field values via include_fields=true (requires ACF).',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => array(
						'type'        => 'string',
						'enum'        => $taxonomies,
						'description' => 'Taxonomy slug.',
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search terms by name.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 50,
						'description' => 'Max terms (1-100, default 50).',
					),
					'offset'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => 'Number of terms to skip.',
					),
					'include_fields' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true = also return the ACF custom field values of each term (requires ACF).',
					),
				),
				'required'   => array( 'taxonomy' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'terms'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'term_id' => array( 'type' => 'integer' ),
								'name'    => array( 'type' => 'string' ),
								'slug'    => array( 'type' => 'string' ),
								'count'   => array( 'type' => 'integer' ),
								'parent'  => array( 'type' => 'integer' ),
								'fields'  => array(
									'type'        => 'object',
									'description' => 'ACF custom field values of the term (present when include_fields=true and ACF is active).',
								),
							),
							'required'   => array( 'term_id', 'name' ),
						),
					),
					'count'  => array( 'type' => 'integer' ),
					'total'  => array( 'type' => 'integer' ),
					'offset' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'terms', 'count', 'total' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				return is_user_logged_in() && current_user_can( 'read' );
			},
			'execute_callback'    => 'wma_exec_list_terms',
			'meta'                => $meta_public( true ),
		)
	);

	/* ---------- Ability : site/set-post-terms (écriture) ---------- */

	wp_register_ability(
		$prefix . '/set-post-terms',
		array(
			'label'               => 'Set Post Terms',
			'description'         => 'Assign terms to a content item for a given taxonomy (e.g. set categories). Provide term_ids or term_names (missing names are created). By default terms are appended; set append=false to replace all existing terms.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'ID of the post.',
					),
					'taxonomy'   => array(
						'type'        => 'string',
						'enum'        => $taxonomies,
						'description' => 'Taxonomy slug.',
					),
					'term_ids'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Term IDs to assign.',
					),
					'term_names' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Term names to assign (created if missing).',
					),
					'append'     => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'true = append (default), false = replace existing terms.',
					),
				),
				'required'   => array( 'post_id', 'taxonomy' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'post_id'  => array( 'type' => 'integer' ),
					'taxonomy' => array( 'type' => 'string' ),
					'term_ids' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'message'  => array( 'type' => 'string' ),
				),
				'required'   => array( 'success', 'post_id', 'taxonomy' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$post_id  = absint( $input['post_id'] ?? 0 );
				$taxonomy = (string) ( $input['taxonomy'] ?? '' );
				if ( ! $post_id || ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
					return false;
				}
				$tax_obj = get_taxonomy( $taxonomy );
				if ( ! $tax_obj ) {
					return false;
				}
				return current_user_can( 'edit_post', $post_id )
					&& current_user_can( $tax_obj->cap->assign_terms );
			},
			'execute_callback'    => 'wma_exec_set_post_terms',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/create-term (écriture) ---------- */

	wp_register_ability(
		$prefix . '/create-term',
		array(
			'label'               => 'Create Term',
			'description'         => 'Create a new term in a taxonomy (' . $tax_list . '). Provide the term name; optionally a slug, description and parent term ID' . $hier_note . '. The term name must not already exist in the taxonomy. Consider using ' . $prefix . '/list-terms first to check for existing terms. Optional "fields" object sets ACF term custom fields — only fields declared for the taxonomy are accepted; requires ACF.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'    => array(
						'type'        => 'string',
						'enum'        => $taxonomies,
						'description' => 'Taxonomy slug (' . $tax_list . ').',
					),
					'name'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Term name (human-readable label).',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'Optional URL slug (sanitized from name if absent).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Optional term description.',
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'Optional parent term ID' . $hier_note . '.',
					),
					'fields'      => array(
						'type'        => 'object',
						'description' => 'Optional ACF term custom field values, keyed by field name. Only fields declared in ACF for this taxonomy are accepted — inventory via ' . $prefix . '/list-taxonomies. Requires ACF.',
					),
				),
				'required'   => array( 'taxonomy', 'name' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'term_id'        => array( 'type' => 'integer' ),
					'taxonomy'       => array( 'type' => 'string' ),
					'name'           => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'fields_applied' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
					'required'   => array( 'success', 'term_id', 'taxonomy', 'name' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$taxonomy = (string) ( $input['taxonomy'] ?? '' );
				if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
					return false;
				}
				$tax_obj = get_taxonomy( $taxonomy );
				return $tax_obj && current_user_can( $tax_obj->cap->manage_terms );
			},
			'execute_callback'    => 'wma_exec_create_term',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/update-term (écriture) ---------- */

	wp_register_ability(
		$prefix . '/update-term',
		array(
			'label'               => 'Update Term',
			'description'         => 'Update an existing term: rename it, change its slug, description or parent' . $hier_note . '. Provide the taxonomy and term ID plus at least one field to change. Nothing is changed if no field is provided. Optional "fields" object updates ACF term custom fields — only fields declared for the taxonomy are accepted; requires ACF.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'    => array(
						'type'        => 'string',
						'enum'        => $taxonomies,
						'description' => 'Taxonomy slug the term belongs to.',
					),
					'term_id'     => array(
						'type'        => 'integer',
						'description' => 'ID of the term to update.',
					),
					'name'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'New term name.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'New URL slug (sanitized).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New description (replaces the existing one).',
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'New parent term ID' . $hier_note . '. 0 = no parent.',
					),
					'fields'      => array(
						'type'        => 'object',
						'description' => 'Optional ACF term custom field values, keyed by field name. Only fields declared in ACF for this taxonomy are accepted — inventory via ' . $prefix . '/list-taxonomies. Requires ACF.',
					),
				),
				'required'   => array( 'taxonomy', 'term_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'term_id'        => array( 'type' => 'integer' ),
					'taxonomy'       => array( 'type' => 'string' ),
					'fields_applied' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
					'required'   => array( 'success', 'term_id', 'taxonomy' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$taxonomy = (string) ( $input['taxonomy'] ?? '' );
				if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
					return false;
				}
				$tax_obj = get_taxonomy( $taxonomy );
				return $tax_obj && current_user_can( $tax_obj->cap->edit_terms );
			},
			'execute_callback'    => 'wma_exec_update_term',
			'meta'                => $meta_public( false ),
		)
	);

	/* ---------- Ability : site/delete-term (écriture) ---------- */

	wp_register_ability(
		$prefix . '/delete-term',
		array(
			'label'               => 'Delete Term',
			'description'         => 'Delete a term by ID. SAFEGUARD: deletion is REFUSED while the term is still assigned to content (count > 0) — pass force=true to delete anyway (assignments are removed from contents, contents are NOT deleted). Verify the term and its usage first via ' . $prefix . '/list-terms.',
			'category'            => $prefix,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => array(
						'type'        => 'string',
						'enum'        => $taxonomies,
						'description' => 'Taxonomy slug the term belongs to. REQUIRED safety check.',
					),
					'term_id'  => array(
						'type'        => 'integer',
						'description' => 'ID of the term to delete.',
					),
					'force'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true = delete even if the term is still assigned to content (default false = refuse).',
					),
				),
				'required'   => array( 'taxonomy', 'term_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'term_id'  => array( 'type' => 'integer' ),
					'taxonomy' => array( 'type' => 'string' ),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'deleted', 'refused' ),
						'description' => '"deleted" or "refused" (term still in use).',
					),
					'message'  => array( 'type' => 'string' ),
				),
				'required'   => array( 'success', 'term_id', 'taxonomy', 'status' ),
			),
			'permission_callback' => static function ( $input = array() ) {
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$taxonomy = (string) ( $input['taxonomy'] ?? '' );
				if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
					return false;
				}
				$tax_obj = get_taxonomy( $taxonomy );
				return $tax_obj && current_user_can( $tax_obj->cap->delete_terms );
			},
			'execute_callback'    => 'wma_exec_delete_term',
			'meta'                => $meta_public( false ),
		)
	);
} );

/* -------------------------------------------------------------------------
 * 3. Callbacks d'exécution.
 * ---------------------------------------------------------------------- */

/**
 * site/list-post-types — documente les types de contenus du site.
 *
 * @param array $input (ignoré).
 * @return array
 */
function wma_exec_list_post_types( $input = array() ): array {
	$out = array();

	foreach ( wma_post_types() as $post_type_slug ) {
		$obj = get_post_type_object( $post_type_slug );
		if ( ! $obj ) {
			continue;
		}

		$counts   = wp_count_posts( $post_type_slug );
		$taxonomy = get_object_taxonomies( $post_type_slug, 'names' );

		$out[] = array(
			'name'         => $post_type_slug,
			'label'        => (string) $obj->labels->name,
			'singular'     => (string) $obj->labels->singular_name,
			'description'  => (string) $obj->description,
			'hierarchical' => (bool) $obj->hierarchical,
			'published'    => isset( $counts->publish ) ? (int) $counts->publish : 0,
			'taxonomies'   => array_values( array_intersect( wma_taxonomies(), $taxonomy ) ),
			'acf_fields'   => wma_acf_field_list( 'post_type', $post_type_slug ),
		);
	}

	return array( 'post_types' => $out );
}

/**
 * site/get-recent-posts — liste les derniers contenus.
 *
 * @param array $input {numberposts?, post_type?, post_status?}.
 * @return array
 */
function wma_exec_get_recent_posts( $input = array() ): array {
	$posts = get_posts(
		array(
			'numberposts' => min( 50, max( 1, (int) ( $input['numberposts'] ?? 10 ) ) ),
			'post_type'   => $input['post_type'] ?? wma_default_post_type(),
			'post_status' => $input['post_status'] ?? 'publish',
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);

	return array(
		'posts' => array_map( 'wma_format_post', $posts ),
		'total' => count( $posts ),
	);
}

/**
 * site/search-posts — recherche plein texte, filtrable par type.
 *
 * @param array $input {query, post_type?, limit?}.
 * @return array
 */
function wma_exec_search_posts( $input = array() ): array {
	$posts = get_posts(
		array(
			's'           => (string) ( $input['query'] ?? '' ),
			'numberposts' => min( 50, max( 1, (int) ( $input['limit'] ?? 10 ) ) ),
			'post_type'   => $input['post_type'] ?? 'any',
			'post_status' => 'publish',
			'orderby'     => 'relevance',
		)
	);

	return array(
		'results' => array_map( 'wma_format_post', $posts ),
		'count'   => count( $posts ),
	);
}

/**
 * site/get-post — détail d'un contenu par ID ou slug.
 *
 * @param array $input {post_id?, slug?}.
 * @return array|WP_Error
 */
function wma_exec_get_post( $input = array() ) {
	$post = null;

	if ( ! empty( $input['post_id'] ) ) {
		$post = get_post( absint( $input['post_id'] ) );
	} elseif ( ! empty( $input['slug'] ) ) {
		$found = get_posts(
			array(
				'name'        => sanitize_title( (string) $input['slug'] ),
				'post_type'   => 'any',
				'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
				'numberposts' => 1,
			)
		);
		$post = $found[0] ?? null;
	}

	if ( ! $post ) {
		return new WP_Error(
			'wma_post_not_found',
			'Post not found: provide a valid "post_id" or "slug".'
		);
	}

	$formatted            = wma_format_post( $post );
	$formatted['content'] = (string) $post->post_content;

	$acf = wma_get_acf_fields( $post->ID );
	if ( null !== $acf ) {
		$formatted['fields'] = $acf;
	}

	return array( 'post' => $formatted );
}

/**
 * site/create-post — crée un contenu (draft par défaut).
 *
 * @param array $input {title, content, status?, type?, slug?, parent_id?, menu_order?, excerpt?}.
 * @return array|WP_Error
 */
function wma_exec_create_post( $input = array() ) {
	$status   = $input['status'] ?? 'draft';
	$type     = $input['type'] ?? wma_default_post_type();
	$type_obj = get_post_type_object( $type );

	if ( ! $type_obj ) {
		return new WP_Error(
			'wma_invalid_post_type',
			'Unknown post type. Allowed: ' . implode( ', ', wma_post_types() )
		);
	}

	// La publication immédiate exige la capability dédiée au type de contenu.
	if ( 'publish' === $status && ! current_user_can( $type_obj->cap->publish_posts ) ) {
		return new WP_Error(
			'wma_publish_forbidden',
			'You do not have the capability to publish directly. Use status "draft" or "pending".'
		);
	}

	// Champs personnalisés ACF : validation AVANT création (liste blanche).
	$acf_error = wma_validate_acf_fields( $input['fields'] ?? null, 'post_type', $type );
	if ( is_wp_error( $acf_error ) ) {
		return $acf_error;
	}

	// Types sans éditeur (supports sans « editor ») : le contenu est
	// automatiquement stocké dans l'extrait.
	$supports_editor = post_type_supports( $type, 'editor' );

	$postarr = array(
		'post_title'   => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
		'post_content' => $supports_editor ? wp_kses_post( (string) ( $input['content'] ?? '' ) ) : '',
		'post_status'  => $status,
		'post_type'    => $type,
	);

	if ( ! empty( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
	}
	if ( ! $supports_editor && array_key_exists( 'content', (array) $input ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( (string) $input['content'] );
	} elseif ( ! empty( $input['excerpt'] ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( (string) $input['excerpt'] );
	}

	// Types hiérarchiques : parent facultatif, même type requis.
	if ( ! empty( $input['parent_id'] ) ) {
		$error = wma_check_parent( (int) $input['parent_id'], $type );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$postarr['post_parent'] = (int) $input['parent_id'];
	}
	if ( array_key_exists( 'menu_order', (array) $input ) ) {
		$postarr['menu_order'] = (int) $input['menu_order'];
	}

	$post_id = wp_insert_post( wp_slash( $postarr ), true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$post = get_post( $post_id );

	$applied = wma_apply_acf_fields( $input['fields'] ?? null, (int) $post_id );

	return array(
		'success'        => true,
		'id'             => (int) $post_id,
		'status'         => (string) ( $postarr['post_status'] ?? 'draft' ),
		'link'           => $post ? (string) get_permalink( $post ) : '',
		'edit_url'       => (string) get_edit_post_link( (int) $post_id, 'raw' ),
		'fields_applied' => $applied,
	);
}

/**
 * site/update-post — met à jour un contenu, avec vérification optionnelle du type.
 *
 * @param array $input {post_id, post_type?, title?, content?, status?, slug?, parent_id?, menu_order?, excerpt?}.
 * @return array|WP_Error
 */
function wma_exec_update_post( $input = array() ) {
	$post_id = absint( $input['post_id'] ?? 0 );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error(
			'wma_post_not_found',
			"Post {$post_id} not found."
		);
	}

	// Garde-fou : si un type est fourni, il doit correspondre au type réel.
	$expected_type = (string) ( $input['post_type'] ?? '' );
	if ( '' !== $expected_type && $expected_type !== $post->post_type ) {
		return new WP_Error(
			'wma_type_mismatch',
			sprintf(
				'Safety check failed: post %1$d ("%2$s") is of type "%3$s" (%4$s), but you tried to update it as a "%5$s". Nothing was changed.',
				$post_id,
				get_the_title( $post ),
				$post->post_type,
				wma_type_label( $post->post_type ),
				$expected_type
			)
		);
	}

	// Champs personnalisés ACF : validation AVANT mise à jour (liste blanche).
	$acf_error = wma_validate_acf_fields( $input['fields'] ?? null, 'post_type', $post->post_type );
	if ( is_wp_error( $acf_error ) ) {
		return $acf_error;
	}

	$postarr = array( 'ID' => $post_id );

	if ( ! empty( $input['title'] ) ) {
		$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( array_key_exists( 'content', (array) $input ) ) {
		$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( ! empty( $input['status'] ) ) {
		$new_status = (string) $input['status'];
		if ( 'publish' === $new_status ) {
			$type_obj = get_post_type_object( $post->post_type );
			if ( ! $type_obj || ! current_user_can( $type_obj->cap->publish_posts ) ) {
				return new WP_Error(
					'wma_publish_forbidden',
					'You do not have the capability to publish. Use status "draft", "pending" or "private".'
				);
			}
		}
		$postarr['post_status'] = $new_status;
	}
	if ( ! empty( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
	}
	if ( ! empty( $input['excerpt'] ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( (string) $input['excerpt'] );
	}

	// Types sans éditeur : le contenu met à jour l'extrait au lieu de
	// post_content.
	if ( array_key_exists( 'content', (array) $input ) && ! post_type_supports( $post->post_type, 'editor' ) ) {
		$postarr['post_excerpt'] = sanitize_text_field( (string) $input['content'] );
		unset( $postarr['post_content'] );
	}

	// Types hiérarchiques : parent facultatif, même type requis (0 = sans parent).
	if ( array_key_exists( 'parent_id', (array) $input ) ) {
		$parent_id = (int) $input['parent_id'];
		if ( 0 !== $parent_id ) {
			$error = wma_check_parent( $parent_id, $post->post_type );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		$postarr['post_parent'] = $parent_id;
	}
	if ( array_key_exists( 'menu_order', (array) $input ) ) {
		$postarr['menu_order'] = (int) $input['menu_order'];
	}

	if ( count( $postarr ) < 2 && empty( $input['fields'] ) ) {
		return new WP_Error(
			'wma_nothing_to_update',
			'Nothing to update: provide at least one field among title, content, status, slug, excerpt, parent_id, menu_order, or ACF fields.'
		);
	}

	if ( count( $postarr ) >= 2 ) {
		$result = wp_update_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	} else {
		$result = $post_id;
	}

	$updated = get_post( $result );

	$applied = wma_apply_acf_fields( $input['fields'] ?? null, (int) $result );

	return array(
		'success'        => true,
		'id'             => (int) $result,
		'status'         => $updated ? (string) $updated->post_status : '',
		'link'           => $updated ? (string) get_permalink( $updated ) : '',
		'edit_url'       => (string) get_edit_post_link( (int) $result, 'raw' ),
		'fields_applied' => $applied,
	);
}

/**
 * site/delete-post — supprime un contenu, avec garde-fou sur le type REQUIS.
 *
 * Le paramètre post_type doit correspondre au type réel du contenu ciblé.
 * Un agent qui confondrait deux types de contenus obtient un refus
 * explicite au lieu d'une suppression erronée.
 *
 * @param array $input {post_id, post_type, force?}.
 * @return array|WP_Error
 */
function wma_exec_delete_post( $input = array() ) {
	$post_id = absint( $input['post_id'] ?? 0 );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error(
			'wma_post_not_found',
			"Post {$post_id} not found."
		);
	}

	// Garde-fou : le type fourni doit correspondre au type réel du contenu.
	$expected_type = (string) ( $input['post_type'] ?? '' );
	if ( $expected_type !== $post->post_type ) {
		return new WP_Error(
			'wma_type_mismatch',
			sprintf(
				'Safety check failed: post %1$d ("%2$s") is of type "%3$s" (%4$s), but you requested deletion of a "%5$s". Nothing was deleted. If this is really the content you want to delete, call again with post_type="%3$s".',
				$post_id,
				get_the_title( $post ),
				$post->post_type,
				wma_type_label( $post->post_type ),
				$expected_type
			)
		);
	}

	$type_label = wma_type_label( $post->post_type );
	$force      = ! empty( $input['force'] );

	if ( $force ) {
		$result  = wp_delete_post( $post_id, true );
		$status  = 'deleted';
		$message = sprintf( 'Post %1$d "%2$s" (%3$s) permanently deleted.', $post_id, get_the_title( $post ), $type_label );
	} else {
		$result  = wp_trash_post( $post_id );
		$status  = 'trashed';
		$message = sprintf( 'Post %1$d "%2$s" (%3$s) moved to trash. Use force=true for permanent deletion.', $post_id, get_the_title( $post ), $type_label );
	}

	if ( ! $result ) {
		return new WP_Error(
			'wma_delete_failed',
			"Failed to delete post {$post_id}."
		);
	}

	return array(
		'success'    => true,
		'id'         => $post_id,
		'type'       => (string) $post->post_type,
		'type_label' => $type_label,
		'title'      => (string) get_the_title( $post ),
		'status'     => $status,
		'message'    => $message,
	);
}

/**
 * site/get-media — liste la médiathèque.
 *
 * @param array $input {search?, mime?, limit?, offset?}.
 * @return array
 */
function wma_exec_get_media( $input = array() ): array {
	$limit  = min( 50, max( 1, (int) ( $input['limit'] ?? 10 ) ) );
	$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

	$args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $limit,
		'offset'         => $offset,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	if ( ! empty( $input['search'] ) ) {
		$args['s'] = (string) $input['search'];
	}
	if ( ! empty( $input['mime'] ) ) {
		$args['post_mime_type'] = sanitize_mime_type( (string) $input['mime'] ) ?: (string) $input['mime'];
	}

	$query = new WP_Query( $args );

	$items = array();
	foreach ( $query->posts as $attachment ) {
		$meta = wp_get_attachment_metadata( $attachment->ID );
		$meta = is_array( $meta ) ? $meta : array();

		$items[] = array(
			'id'          => (int) $attachment->ID,
			'title'       => (string) get_the_title( $attachment ),
			'slug'        => (string) $attachment->post_name,
			'mime_type'   => (string) $attachment->post_mime_type,
			'url'         => (string) wp_get_attachment_url( $attachment->ID ),
			'alt_text'    => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $attachment->post_excerpt,
			'description' => (string) $attachment->post_content,
			'width'       => (int) ( $meta['width'] ?? 0 ),
			'height'      => (int) ( $meta['height'] ?? 0 ),
			'filesize'    => (int) ( $meta['filesize'] ?? 0 ),
			'date'        => $attachment->post_date ? (string) mysql2date( 'c', $attachment->post_date ) : '',
		);
	}

	return array(
		'items'  => $items,
		'total'  => (int) $query->found_posts,
		'limit'  => $limit,
		'offset' => $offset,
	);
}

/**
 * site/upload-media — importe un média (base64 ou URL distante).
 *
 * Deux modes d'import :
 *  - "data"    : contenu du fichier encodé en base64 (+ "filename" requis) ;
 *  - "url"     : fichier distant que le site télécharge lui-même (sideload).
 * Les types de fichiers non autorisés par le site sont refusés par WordPress.
 *
 * @param array $input {filename?, data?|url?, title?, alt_text?, caption?, description?}.
 * @return array|WP_Error
 */
function wma_exec_upload_media( $input = array() ) {
	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$data     = isset( $input['data'] ) ? (string) $input['data'] : '';
	$url      = isset( $input['url'] ) ? (string) $input['url'] : '';
	$filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';

	if ( '' === $data && '' === $url ) {
		return new WP_Error(
			'wma_no_file',
			'Provide either "data" (base64 file content + "filename") or "url" (remote file to import).'
		);
	}

	$max_bytes = (int) wp_max_upload_size();

	// Champs de description du média.
	$post_data = array();
	if ( ! empty( $input['title'] ) ) {
		$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( ! empty( $input['caption'] ) ) {
		$post_data['post_excerpt'] = sanitize_text_field( (string) $input['caption'] );
	}
	if ( ! empty( $input['description'] ) ) {
		$post_data['post_content'] = sanitize_text_field( (string) $input['description'] );
	}

	$attach_id = 0;

	if ( '' !== $data ) {
		// Mode base64 : le paramètre "filename" est requis.
		if ( '' === $filename ) {
			return new WP_Error(
				'wma_filename_required',
				'The "filename" parameter (with extension) is required with base64 "data".'
			);
		}

		// Tolérance : data-URI ("data:image/png;base64,…") et espaces/retours ligne.
		$pos = strpos( $data, 'base64,' );
		if ( 0 === strpos( $data, 'data:' ) && false !== $pos ) {
			$data = substr( $data, $pos + 7 );
		}
		$data = preg_replace( '/\s+/', '', $data );

		$bits = base64_decode( $data, true );
		if ( false === $bits ) {
			return new WP_Error( 'wma_invalid_base64', 'The "data" parameter is not valid base64 content.' );
		}

		if ( $max_bytes && strlen( $bits ) > $max_bytes ) {
			return new WP_Error(
				'wma_file_too_large',
				sprintf( 'File exceeds the maximum upload size of this site (%s).', size_format( $max_bytes ) )
			);
		}

		$upload = wp_upload_bits( $filename, null, $bits );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wma_upload_failed', (string) $upload['error'] );
		}

		$filetype = wp_check_filetype_and_ext( $upload['file'], $filename );
		$mime     = ! empty( $filetype['type'] ) ? (string) $filetype['type'] : (string) $upload['type'];

		if ( empty( $post_data['post_title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( preg_replace( '/\.[^.]+$/', '', wp_basename( $filename ) ) );
		}

		$attach_id = wp_insert_attachment(
			array_merge(
				array(
					'post_mime_type' => $mime,
					'guid'          => (string) $upload['url'],
					'post_status'   => 'inherit',
				),
				$post_data
			),
			$upload['file'],
			0,
			true
		);

		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
	} else {
		// Mode URL : le site télécharge le fichier lui-même (sideload).
		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'wma_download_failed', 'Could not download the remote file: ' . $tmp->get_error_message() );
		}

		if ( $max_bytes && (int) @filesize( $tmp ) > $max_bytes ) {
			@unlink( $tmp );
			return new WP_Error(
				'wma_file_too_large',
				sprintf( 'File exceeds the maximum upload size of this site (%s).', size_format( $max_bytes ) )
			);
		}

		// Nom de fichier : paramètre ou déduit de l'URL (extension requise).
		$name = '' !== $filename ? $filename : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			@unlink( $tmp );
			return new WP_Error(
				'wma_filename_required',
				'Could not determine a valid file name with extension from the URL. Provide the "filename" parameter (e.g. "image.jpg").'
			);
		}

		$file_array = array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload( $file_array, 0, null, $post_data );

		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp );
			return new WP_Error( 'wma_sideload_failed', 'Media import failed: ' . $attach_id->get_error_message() );
		}
	}

	// Texte alternatif (accessibilité — images en particulier).
	if ( ! empty( $input['alt_text'] ) ) {
		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
	}

	return wma_media_response( (int) $attach_id, 'Media uploaded successfully.' );
}

/**
 * site/delete-media — supprime un média, avec garde-fou sur le type.
 *
 * Seuls les médias (post type « attachment ») peuvent être supprimés :
 * tout autre ID de contenu est explicitement refusé.
 *
 * @param array $input {media_id, force?}.
 * @return array|WP_Error
 */
function wma_exec_delete_media( $input = array() ) {
	$media_id = absint( $input['media_id'] ?? 0 );
	$media    = get_post( $media_id );

	if ( ! $media ) {
		return new WP_Error(
			'wma_media_not_found',
			"Media {$media_id} not found."
		);
	}

	// Garde-fou : seul un média (« attachment ») peut être supprimé ici.
	if ( 'attachment' !== $media->post_type ) {
		return new WP_Error(
			'wma_not_media',
			sprintf(
				'Safety check failed: post %1$d ("%2$s") is of type "%3$s" — only media ("attachment") can be deleted with this tool. If you really intend to delete this content, use site/delete-post with post_type="%3$s".',
				$media_id,
				get_the_title( $media ),
				$media->post_type
			)
		);
	}

	$force = ! empty( $input['force'] );

	if ( $force ) {
		$result = wp_delete_attachment( $media_id, true );
		$status = 'deleted';
	} else {
		// Corbeille des médias si activée sur le site, sinon suppression définitive.
		$result = wp_delete_attachment( $media_id );
		$status = ( 'trash' === get_post_status( $media_id ) ) ? 'trashed' : 'deleted';
	}

	if ( ! $result ) {
		return new WP_Error(
			'wma_delete_failed',
			"Failed to delete media {$media_id}."
		);
	}

	if ( 'deleted' === $status ) {
		$message = $force
			? sprintf( 'Media %1$d ("%2$s") permanently deleted.', $media_id, get_the_title( $media ) )
			: sprintf( 'Media %1$d ("%2$s") permanently deleted (media trash is not enabled on this site).', $media_id, get_the_title( $media ) );
	} else {
		$message = sprintf( 'Media %1$d ("%2$s") moved to trash. Use force=true for permanent deletion.', $media_id, get_the_title( $media ) );
	}

	return array(
		'success'   => true,
		'id'        => $media_id,
		'title'     => (string) get_the_title( $media ),
		'mime_type' => (string) $media->post_mime_type,
		'status'    => $status,
		'message'   => $message,
	);
}


/**
 * site/list-taxonomies — liste les taxonomies autorisées.
 *
 * @param array $input (ignoré).
 * @return array
 */
function wma_exec_list_taxonomies( $input = array() ): array {
	$out = array();

	foreach ( wma_taxonomies() as $taxonomy_slug ) {
		$taxonomy = get_taxonomy( $taxonomy_slug );
		if ( ! $taxonomy ) {
			continue;
		}
		$out[] = array(
			'name'         => $taxonomy_slug,
			'label'        => (string) $taxonomy->label,
			'description'  => (string) $taxonomy->description,
			'public'       => (bool) $taxonomy->public,
			'hierarchical' => (bool) $taxonomy->hierarchical,
			'object_types' => array_values( (array) $taxonomy->object_type ),
			'acf_fields'   => wma_acf_field_list( 'taxonomy', $taxonomy_slug ),
		);
	}

	return array( 'taxonomies' => $out );
}

/**
 * site/list-terms — liste les termes d'une taxonomie.
 *
 * @param array $input {taxonomy, search?, limit?, offset?}.
 * @return array|WP_Error
 */
function wma_exec_list_terms( $input = array() ) {
	$taxonomy = (string) ( $input['taxonomy'] ?? '' );

	if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
		return new WP_Error(
			'wma_invalid_taxonomy',
			'Invalid taxonomy. Allowed: ' . implode( ', ', wma_taxonomies() )
		);
	}

	$limit  = min( 100, max( 1, (int) ( $input['limit'] ?? 50 ) ) );
	$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
	$search = (string) ( $input['search'] ?? '' );

	$args = array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'number'     => $limit,
		'offset'     => $offset,
	);
	if ( '' !== $search ) {
		$args['search'] = $search;
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	$total_args = array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'fields'     => 'count',
	);
	if ( '' !== $search ) {
		$total_args['search'] = $search;
	}
	$total = (int) get_terms( $total_args );

	$include_fields = ! empty( $input['include_fields'] );

	$out = array();
	foreach ( $terms as $term ) {
		$item = array(
			'term_id' => (int) $term->term_id,
			'name'    => (string) $term->name,
			'slug'    => (string) $term->slug,
			'count'   => (int) $term->count,
			'parent'  => (int) $term->parent,
		);

		if ( $include_fields ) {
			$acf = wma_get_acf_fields( 'term_' . (int) $term->term_id );
			if ( null !== $acf ) {
				$item['fields'] = $acf;
			}
		}

		$out[] = $item;
	}

	return array(
		'terms'  => $out,
		'count'  => count( $out ),
		'total'  => $total,
		'offset' => $offset,
	);
}

/**
 * site/set-post-terms — assigne des termes à un contenu.
 *
 * @param array $input {post_id, taxonomy, term_ids?|term_names?, append?}.
 * @return array|WP_Error
 */
function wma_exec_set_post_terms( $input = array() ) {
	$post_id  = absint( $input['post_id'] ?? 0 );
	$taxonomy = (string) ( $input['taxonomy'] ?? '' );
	$post     = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error(
			'wma_post_not_found',
			"Post {$post_id} not found."
		);
	}

	if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
		return new WP_Error(
			'wma_invalid_taxonomy',
			'Invalid taxonomy. Allowed: ' . implode( ', ', wma_taxonomies() )
		);
	}

	$term_ids   = isset( $input['term_ids'] ) ? array_map( 'absint', (array) $input['term_ids'] ) : array();
	$term_names = isset( $input['term_names'] ) ? array_map( 'sanitize_text_field', (array) $input['term_names'] ) : array();
	$append     = ! array_key_exists( 'append', (array) $input ) ? true : (bool) $input['append'];

	if ( empty( $term_ids ) && empty( $term_names ) ) {
		return new WP_Error(
			'wma_no_terms',
			'Provide term_ids or term_names.'
		);
	}

	// Priorité aux IDs, sinon création/assignation par noms.
	$terms = ! empty( $term_ids ) ? $term_ids : $term_names;

	$result = wp_set_object_terms( $post_id, $terms, $taxonomy, $append );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result = array_map( 'intval', (array) $result );
	sort( $result );

	return array(
		'success'  => true,
		'post_id'  => $post_id,
		'taxonomy' => $taxonomy,
		'term_ids' => $result,
		'message'  => sprintf(
			'Assigned %d term(s) to post %d in taxonomy "%s"%s.',
			count( $result ),
			$post_id,
			$taxonomy,
			$append ? ' (appended)' : ' (replaced)'
		),
	);
}

/**
 * site/create-term — crée un terme dans une taxonomie autorisée.
 *
 * @param array $input {taxonomy, name, slug?, description?, parent?}.
 * @return array|WP_Error
 */
function wma_exec_create_term( $input = array() ) {
	$taxonomy = (string) ( $input['taxonomy'] ?? '' );

	if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
		return new WP_Error(
			'wma_invalid_taxonomy',
			'Invalid taxonomy. Allowed: ' . implode( ', ', wma_taxonomies() )
		);
	}

	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	if ( '' === $name ) {
		return new WP_Error( 'wma_invalid_term_name', 'Term name cannot be empty.' );
	}

	// Le nom ne doit pas déjà exister dans cette taxonomie.
	$existing = get_term_by( 'name', $name, $taxonomy );
	if ( $existing && ! is_wp_error( $existing ) ) {
		return new WP_Error(
			'wma_term_exists',
			sprintf(
				'Term "%s" already exists in taxonomy "%s" (term_id %d). Use ' . wma_category() . '/update-term to modify it.',
				$name,
				$taxonomy,
				(int) $existing->term_id
			)
		);
	}

	$args = array();
	if ( ! empty( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( array_key_exists( 'description', (array) $input ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}
	if ( ! empty( $input['parent'] ) ) {
		$parent_id = absint( $input['parent'] );
		$tax_obj  = get_taxonomy( $taxonomy );
		if ( ! $tax_obj || ! $tax_obj->hierarchical ) {
			return new WP_Error(
				'wma_taxonomy_not_hierarchical',
				sprintf( 'Taxonomy "%s" is not hierarchical: the parent parameter is not allowed.', $taxonomy )
			);
		}
		if ( ! get_term_by( 'id', $parent_id, $taxonomy ) ) {
			return new WP_Error(
				'wma_parent_not_found',
				sprintf( 'Parent term %d does not exist in taxonomy "%s".', $parent_id, $taxonomy )
			);
		}
		$args['parent'] = $parent_id;
	}

	// Champs personnalisés ACF : validation AVANT création (liste blanche).
	$acf_error = wma_validate_acf_fields( $input['fields'] ?? null, 'taxonomy', $taxonomy );
	if ( is_wp_error( $acf_error ) ) {
		return $acf_error;
	}

	$result = wp_insert_term( $name, $taxonomy, $args );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$term_id = (int) $result['term_id'];
	$term    = get_term_by( 'id', $term_id, $taxonomy );

	$applied = wma_apply_acf_fields( $input['fields'] ?? null, 'term_' . $term_id );

	return array(
		'success'        => true,
		'term_id'        => $term_id,
		'taxonomy'       => $taxonomy,
		'name'           => (string) ( $term ? $term->name : $name ),
		'slug'           => (string) ( $term ? $term->slug : '' ),
		'fields_applied' => $applied,
	);
}

/**
 * site/update-term — met à jour un terme, taxonomie vérifiée.
 *
 * @param array $input {taxonomy, term_id, name?, slug?, description?, parent?}.
 * @return array|WP_Error
 */
function wma_exec_update_term( $input = array() ) {
	$taxonomy = (string) ( $input['taxonomy'] ?? '' );
	$term_id  = absint( $input['term_id'] ?? 0 );

	if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
		return new WP_Error(
			'wma_invalid_taxonomy',
			'Invalid taxonomy. Allowed: ' . implode( ', ', wma_taxonomies() )
		);
	}

	$term = get_term_by( 'id', $term_id, $taxonomy );

	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error(
			'wma_term_not_found',
			sprintf( 'Term %d not found in taxonomy "%s".', $term_id, $taxonomy )
		);
	}

	$args = array();

	if ( ! empty( $input['name'] ) ) {
		$new_name = sanitize_text_field( (string) $input['name'] );

		// Garde-fou : pas de collision avec un autre terme de la même taxonomie.
		if ( $new_name !== $term->name ) {
			$existing = get_term_by( 'name', $new_name, $taxonomy );
			if ( $existing && ! is_wp_error( $existing ) && (int) $existing->term_id !== $term_id ) {
				return new WP_Error(
					'wma_term_exists',
					sprintf(
						'Cannot rename: term "%s" (term_id %d) already exists in taxonomy "%s".',
						$new_name,
						(int) $existing->term_id,
						$taxonomy
					)
				);
			}
		}

		$args['name'] = $new_name;
	}
	if ( ! empty( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( array_key_exists( 'description', (array) $input ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}
	if ( array_key_exists( 'parent', (array) $input ) ) {
		$parent_id = absint( $input['parent'] );
		$tax_obj  = get_taxonomy( $taxonomy );
		if ( ! $tax_obj || ! $tax_obj->hierarchical ) {
			return new WP_Error(
				'wma_taxonomy_not_hierarchical',
				sprintf( 'Taxonomy "%s" is not hierarchical: the parent parameter is not allowed.', $taxonomy )
			);
		}
		if ( 0 !== $parent_id ) {
			if ( $parent_id === $term_id ) {
				return new WP_Error( 'wma_invalid_parent', 'A term cannot be its own parent.' );
			}
			$parent = get_term_by( 'id', $parent_id, $taxonomy );
			if ( ! $parent || is_wp_error( $parent ) ) {
				return new WP_Error(
					'wma_parent_not_found',
					sprintf( 'Parent term %d does not exist in taxonomy "%s".', $parent_id, $taxonomy )
				);
			}
		}
		$args['parent'] = $parent_id;
	}

	if ( empty( $args ) && empty( $input['fields'] ) ) {
		return new WP_Error(
			'wma_nothing_to_update',
			'Nothing to update: provide at least one field among name, slug, description, parent, or ACF fields.'
		);
	}

	if ( ! empty( $args ) ) {
		$result = wp_update_term( $term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	} else {
		$result = array( 'term_id' => $term_id );
	}

	$applied = wma_apply_acf_fields( $input['fields'] ?? null, 'term_' . (int) $result['term_id'] );

	return array(
		'success'        => true,
		'term_id'        => (int) $result['term_id'],
		'taxonomy'       => $taxonomy,
		'fields_applied' => $applied,
	);
}

/**
 * site/delete-term — supprime un terme, avec garde-fou d'usage.
 *
 * Refuse la suppression si le terme est encore assigné à des contenus
 * (count > 0), sauf si force=true est explicitement fourni.
 *
 * @param array $input {taxonomy, term_id, force?}.
 * @return array|WP_Error
 */
function wma_exec_delete_term( $input = array() ) {
	$taxonomy = (string) ( $input['taxonomy'] ?? '' );
	$term_id  = absint( $input['term_id'] ?? 0 );

	if ( ! in_array( $taxonomy, wma_taxonomies(), true ) ) {
		return new WP_Error(
			'wma_invalid_taxonomy',
			'Invalid taxonomy. Allowed: ' . implode( ', ', wma_taxonomies() )
		);
	}

	$term = get_term_by( 'id', $term_id, $taxonomy );

	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error(
			'wma_term_not_found',
			sprintf( 'Term %d not found in taxonomy "%s".', $term_id, $taxonomy )
		);
	}

	$force = ! empty( $input['force'] );

	// Garde-fou : refuser la suppression d'un terme encore assigné.
	if ( (int) $term->count > 0 && ! $force ) {
		return array(
			'success'  => false,
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'status'   => 'refused',
			'message'  => sprintf(
				'Term "%s" (id %d, taxonomy "%s") is still assigned to %d content item(s). Deletion REFUSED. Use force=true to delete anyway (assignments are removed from contents, contents are NOT deleted).',
				$term->name,
				$term_id,
				$taxonomy,
				(int) $term->count
			),
		);
	}

	$result = wp_delete_term( $term_id, $taxonomy );

	if ( ! $result || is_wp_error( $result ) ) {
		$error = is_wp_error( $result ) ? $result : new WP_Error( 'wma_delete_failed', "Failed to delete term {$term_id}." );
		return $error;
	}

	return array(
		'success'  => true,
		'term_id'  => $term_id,
		'taxonomy' => $taxonomy,
		'status'   => 'deleted',
		'message'  => sprintf( 'Term "%s" (id %d) deleted from taxonomy "%s"%s.', $term->name, $term_id, $taxonomy, $force && (int) $term->count > 0 ? ' (was assigned to ' . (int) $term->count . ' content item(s))' : '' ),
	);
}