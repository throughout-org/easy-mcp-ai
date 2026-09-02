<?php
/**
 * Plugin Name: RankOut Connector
 * Plugin URI:  https://rankout.app/wordpress-connector/
 * Description: Connect Claude, ChatGPT & any AI to WordPress. Manage your entire site by chat — content, media, GA4, Search Console, SEO, GEO, AEO, E-E-A-T & more. 233 tools. Free.
 * Version:     2.0.1
 * Author:      RankOut
 * Author URI:  https://rankout.app/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-mcp-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://rankout.app/wordpress-connector/
 */

// A unique Update URI prevents WordPress.org from matching this fork to
// another plugin with the legacy slug. GitHub_Updater supplies RankOut's
// own release metadata and package to WordPress's standard update flow.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EASY_MCP_AI_VERSION' ) ) {
    define( 'EASY_MCP_AI_VERSION', '2.0.1' );
}
if ( ! defined( 'EASY_MCP_AI_PLUGIN_FILE' ) ) {
    define( 'EASY_MCP_AI_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'EASY_MCP_AI_PLUGIN_DIR' ) ) {
    define( 'EASY_MCP_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'EASY_MCP_AI_PLUGIN_URL' ) ) {
    define( 'EASY_MCP_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'EASY_MCP_AI_PLUGIN_BASENAME' ) ) {
    define( 'EASY_MCP_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

require_once EASY_MCP_AI_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Easy_MCP_AI\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Easy_MCP_AI\\Deactivator', 'deactivate' ) );

Easy_MCP_AI\Plugin::instance();

// Some hosts and security plugins (Wordfence, Solid Security, and several
// managed-WordPress hosts) disable Application Passwords by default as a
// hardening measure. RankOut's dashboard depends on one being generated
// for Site Health's WordPress REST checks, so this plugin — whose whole
// purpose is exposing site data to that dashboard — re-enables the
// feature unconditionally wherever it's installed, rather than requiring
// a separate manual fix on every client site.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

add_filter(
    'plugin_action_links_' . EASY_MCP_AI_PLUGIN_BASENAME,
    function ( $links ) {
        $prepend = array(
            'dashboard' => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai' ) ) . '">' . esc_html__( 'Getting Started', 'easy-mcp-ai' ) . '</a>',
            'plugins'   => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-plugin-integrations' ) ) . '">' . esc_html__( 'Plugin', 'easy-mcp-ai' ) . '</a>',
            'abilities'      => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-abilities' ) ) . '">' . esc_html__( 'Abilities', 'easy-mcp-ai' ) . '</a>',
            'external_data'  => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-external-data' ) ) . '">' . esc_html__( 'External Data', 'easy-mcp-ai' ) . '</a>',
        );
        $append = array(
            'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=easy-mcp-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'easy-mcp-ai' ) . '</a>',
        );
        return array_merge( $prepend, $links, $append );
    }
);

add_filter(
    'plugin_row_meta',
    function ( $links, $file ) {
        if ( EASY_MCP_AI_PLUGIN_BASENAME !== $file ) {
            return $links;
        }
        $links[] = '<a href="https://wordpress.org/support/plugin/easy-mcp-ai/reviews/" target="_blank" rel="noopener">' . esc_html__( 'Rate Plugin', 'easy-mcp-ai' ) . '</a>';
        return $links;
    },
    10,
    2
);
