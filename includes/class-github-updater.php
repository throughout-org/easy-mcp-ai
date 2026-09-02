<?php
namespace Easy_MCP_AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hooks the plugin into WordPress's built-in update system using GitHub releases.
 *
 * Flow:
 *  1. On every WordPress update check, fetches the latest release from the
 *     GitHub API (cached via transient, refreshed every 12 hours).
 *  2. If the remote version is newer, injects an update object into
 *     WordPress's update_plugins transient so the standard "Update Available"
 *     notice and one-click update both work normally.
 *  3. After WordPress unzips the GitHub archive, renames the extracted folder
 *     (GitHub names it "{repo}-{version}") back to "easy-mcp-ai" so the plugin
 *     path stays consistent.
 *  4. Exposes a "Check for Updates" link on the Plugins page that busts the
 *     cache and forces a fresh API call.
 */
class GitHub_Updater {

    const TRANSIENT_KEY    = 'rankout_connector_github_release_v1';
    const TRANSIENT_EXPIRY = 12 * HOUR_IN_SECONDS;
    const GITHUB_REPO      = 'throughout-org/rankout-connector';
    const RELEASE_ASSET    = 'rankout-connector.zip';
    const PLUGIN_SLUG      = 'easy-mcp-ai';
    const CHECK_PARAM      = 'easy_mcp_check_update';
    const CHECK_NONCE      = 'easy_mcp_check_update_nonce';

    private $plugin_file;
    private $plugin_basename;
    private $current_version;

    public function __construct( string $plugin_file, string $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename( $plugin_file );
        $this->current_version = $current_version;

        add_action( 'admin_init', array( $this, 'maybe_handle_force_check' ) );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_popup_info' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( $this, 'verify_package_download' ), 10, 4 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_filter( 'plugin_row_meta', array( $this, 'add_check_link' ), 10, 2 );
    }

    /**
     * Download the exact release asset and fail closed unless its SHA-256
     * matches the digest GitHub calculated when the asset was uploaded.
     */
    public function verify_package_download( $reply, $package, $upgrader, $hook_extra ) {
        if ( false !== $reply || empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
            return $reply;
        }

        $release = $this->get_latest_release();
        $asset   = $release ? $this->release_asset( $release ) : null;
        if ( ! $asset || $package !== $asset['url'] || empty( $asset['digest'] ) ) {
            return new \WP_Error( 'rankout_update_unverified', __( 'RankOut Connector could not verify the release package.', 'easy-mcp-ai' ) );
        }

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url( $package, 300 );
        if ( is_wp_error( $file ) ) {
            return $file;
        }

        $expected = strtolower( preg_replace( '/^sha256:/', '', $asset['digest'] ) );
        $actual   = hash_file( 'sha256', $file );
        if ( ! $actual || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
            wp_delete_file( $file );
            return new \WP_Error( 'rankout_update_checksum_failed', __( 'RankOut Connector update verification failed. The package was not installed.', 'easy-mcp-ai' ) );
        }

        return $file;
    }

    // -------------------------------------------------------------------------
    // Force-check handler
    // -------------------------------------------------------------------------

    public function maybe_handle_force_check() {
        if ( ! isset( $_GET[ self::CHECK_PARAM ] ) ) {
            return;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        check_admin_referer( self::CHECK_NONCE );

        $this->bust_cache();

        // Trigger WordPress's own update check so the result appears immediately.
        wp_clean_plugins_cache( true );

        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // WordPress update-system integration
    // -------------------------------------------------------------------------

    public function inject_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $latest = $this->release_version( $release );

        if ( ! $latest ) {
            return $transient;
        }

        if ( version_compare( $this->current_version, $latest, '<' ) ) {
            $update                  = new \stdClass();
            $update->id              = self::GITHUB_REPO;
            $update->slug            = self::PLUGIN_SLUG;
            $update->plugin          = $this->plugin_basename;
            $update->new_version     = $latest;
            $update->url             = 'https://github.com/' . self::GITHUB_REPO;
            $update->package         = $this->zip_url( $release );
            $update->icons           = array();
            $update->banners         = array();
            $update->banners_rtl     = array();
            $update->requires        = '6.0';
            $update->requires_php    = '7.4';
            $update->tested          = '';
            $update->compatibility   = new \stdClass();

            $transient->response[ $this->plugin_basename ] = $update;
        } else {
            // Tell WP this plugin is up to date so it doesn't show stale notices.
            $no_update          = new \stdClass();
            $no_update->id      = self::GITHUB_REPO;
            $no_update->slug    = self::PLUGIN_SLUG;
            $no_update->plugin  = $this->plugin_basename;
            $no_update->new_version = $latest;
            $no_update->url     = 'https://github.com/' . self::GITHUB_REPO;
            $no_update->package = $this->zip_url( $release );
            $transient->no_update[ $this->plugin_basename ] = $no_update;
        }

        return $transient;
    }

    /**
     * Provides the plugin info shown in the "View version X details" popup.
     */
    public function plugin_popup_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== self::PLUGIN_SLUG ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $latest  = $this->release_version( $release );
        if ( ! $latest ) {
            return $result;
        }
        $body    = ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : 'See the <a href="https://github.com/' . self::GITHUB_REPO . '/releases" target="_blank">GitHub releases page</a> for changelog.';

        $info                        = new \stdClass();
        $info->name                  = 'RankOut Connector';
        $info->slug                  = self::PLUGIN_SLUG;
        $info->version               = $latest;
        $info->author                = '<a href="https://rankout.app" target="_blank">RankOut</a>';
        $info->homepage              = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires              = '6.0';
        $info->requires_php          = '7.4';
        $info->downloaded            = 0;
        $info->last_updated          = ! empty( $release['published_at'] ) ? substr( $release['published_at'], 0, 10 ) : '';
        $info->download_link         = $this->zip_url( $release );
        $info->sections              = array(
            'description' => 'Securely connect a WordPress site to RankOut for research, approved changes, and measurement.',
            'changelog'   => $body,
        );

        return $info;
    }

    /**
     * GitHub archives extract as "{repo}-{version}/" — rename to "easy-mcp-ai/".
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        if ( ! isset( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
            return $source;
        }

        global $wp_filesystem;

        // $source has a trailing slash; basename gives us the folder name.
        $source_dir  = rtrim( $source, '/\\' );
        $parent      = dirname( $source_dir );
        $new_source  = trailingslashit( $parent . '/' . self::PLUGIN_SLUG );

        if ( $source_dir === rtrim( $new_source, '/\\' ) ) {
            // Already the right name.
            return $source;
        }

        if ( ! $wp_filesystem->move( $source_dir, $new_source ) ) {
            return new \WP_Error(
                'easy_mcp_ai_rename_failed',
                sprintf( 'Could not rename update folder from %s to %s.', basename( $source_dir ), self::PLUGIN_SLUG )
            );
        }

        return $new_source;
    }

    // -------------------------------------------------------------------------
    // "Check for Updates" link in the plugins list
    // -------------------------------------------------------------------------

    public function add_check_link( array $links, string $file ): array {
        if ( $this->plugin_basename !== $file ) {
            return $links;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }

        $url     = wp_nonce_url(
            add_query_arg( self::CHECK_PARAM, '1', admin_url( 'plugins.php' ) ),
            self::CHECK_NONCE
        );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for Updates', 'easy-mcp-ai' ) . '</a>';

        return $links;
    }

    // -------------------------------------------------------------------------
    // GitHub API
    // -------------------------------------------------------------------------

    private function get_latest_release(): ?array {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( false !== $cached ) {
            return $cached ?: null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache failures for 1 hour so we don't hammer the API on errors.
            set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $release['tag_name'] ) || ! $this->release_asset_url( $release ) ) {
            set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
            return null;
        }

        set_transient( self::TRANSIENT_KEY, $release, self::TRANSIENT_EXPIRY );

        return $release;
    }

    private function bust_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }

    private function release_version( array $release ): ?string {
        $version = isset( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], 'v' ) : '';
        return preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version ) ? $version : null;
    }

    private function release_asset( array $release ): ?array {
        if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
            return null;
        }
        foreach ( $release['assets'] as $asset ) {
            if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::RELEASE_ASSET === $asset['name'] ) {
                $url = esc_url_raw( $asset['browser_download_url'] );
                if ( 'github.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
                    return null;
                }
                return array(
                    'url'    => $url,
                    'digest' => isset( $asset['digest'] ) ? (string) $asset['digest'] : '',
                );
            }
        }
        return null;
    }

    private function release_asset_url( array $release ): ?string {
        $asset = $this->release_asset( $release );
        return $asset ? $asset['url'] : null;
    }

    private function zip_url( array $release ): string {
        return $this->release_asset_url( $release ) ?: '';
    }
}
