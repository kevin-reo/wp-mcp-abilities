<?php
/**
 * Plugin Name: WP MCP Abilities (loader)
 * Description: Charge le connecteur MCP « wp-mcp-abilities » versionné dans son propre dépôt git (sous-dossier de mu-plugins). WordPress ne charge pas les sous-dossiers de mu-plugins : ce loader est le point d'entrée. Mettre à jour = git pull dans wp-content/mu-plugins/wp-mcp-abilities/.
 * Version:     1.0.0
 * Author:      krikrak
 *
 * Le connecteur lui-même (abilities, garde-fous, anti double-chargement via
 * WMA_VERSION) vit dans le sous-dossier « wp-mcp-abilities » — dépôt git
 * autonome : github.com/kevin-reo/wp-mcp-abilities.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/wp-mcp-abilities/wp-mcp-abilities.php';