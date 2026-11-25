<?php
namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared constants and helpers for the SmartCrawl Pro SEO tools.
 *
 * SmartCrawl stores per-post SEO as plain postmeta, which is not
 * exposed via REST, so these tools write it directly with
 * get_post_meta()/update_post_meta(). Writing through those core
 * functions (rather than $wpdb) is what lets Easy MCP AI's existing
 * change-log hooks (on update_post_meta/updated_post_meta) capture a
 * before/after row for free, which wp_revert_seo_change relies on.
 */
/*
 * NOTE: PHP traits cannot declare constants. Each class using this trait
 * must define its own: META_TITLE, META_DESC, and (where used) TITLE_MAX,
 * DESC_MAX, TITLE_WARN_MAX, DESC_WARN_MIN, DESC_WARN_MAX, REVERTIBLE_TOOLS,
 * ALLOWED_META_KEYS. `self::` inside a trait method resolves against the
 * class it is mixed into, so this works exactly like shared constants would.
 */
trait Smartcrawl_Seo {

    /**
     * Sanitise and validate a raw title/description value.
     *
     * @throws \InvalidArgumentException On empty, shortcode, Divi token, or over-length input.
     */
    protected function sanitize_seo_field( $value, $label, $max_len ) {
        $value = wp_strip_all_tags( (string) $value );
        $value = trim( preg_replace( '/\s+/', ' ', $value ) );

        if ( '' === $value ) {
            throw new \InvalidArgumentException( sprintf( '%s cannot be empty.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( preg_match( '/\[[a-zA-Z0-9_\-\/]+(\s[^\]]*)?\]/', $value ) ) {
            throw new \InvalidArgumentException( sprintf( '%s cannot contain shortcodes.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( false !== stripos( $value, '@ET-DC@' ) ) {
            throw new \InvalidArgumentException( sprintf( '%s cannot contain Divi Builder tokens.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( mb_strlen( $value ) > $max_len ) {
            throw new \InvalidArgumentException(
                sprintf( '%s exceeds the maximum allowed length of %d characters.', $label, $max_len ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }

        return $value;
    }

    /**
     * Build a soft length warning (over/under the recommended SERP range), or null if within range.
     */
    protected function seo_length_warning( $label, $value, $warn_min, $warn_max ) {
        $len = mb_strlen( $value );
        if ( $len > $warn_max ) {
            return sprintf( '%s is %d characters, longer than the recommended %d. It may be truncated in search results.', $label, $len, $warn_max ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( null !== $warn_min && $len < $warn_min ) {
            return sprintf( '%s is %d characters, shorter than the recommended minimum of %d.', $label, $len, $warn_min ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return null;
    }

    /**
     * Resolve whether this call is an actual write or just a preview.
     */
    protected function wants_write( array $arguments ) {
        $dry_run = isset( $arguments['dry_run'] ) ? $arguments['dry_run'] : true;
        $confirm = isset( $arguments['confirm'] ) ? $arguments['confirm'] : false;
        return ( false === $dry_run || '0' === $dry_run || 0 === $dry_run ) && ( true === $confirm || '1' === $confirm || 1 === $confirm );
    }

    /**
     * Fetch the change-log repository instance, or null if the history subsystem is unavailable.
     */
    protected function change_log_repository() {
        if ( ! class_exists( '\\Easy_MCP_AI\\History\\Change_Log_Repository' ) ) {
            return null;
        }
        return new \Easy_MCP_AI\History\Change_Log_Repository();
    }

    /**
     * Look up the id of the change-log row our own write for this field just produced.
     */
    protected function find_own_change_log_id( $tool_name, $post_id, $meta_key ) {
        $repo = $this->change_log_repository();
        if ( ! $repo ) {
            return null;
        }
        $rows = $repo->query(
            array(
                'tool_name'   => $tool_name,
                'object_type' => 'meta',
                'object_id'   => $post_id . ':' . $meta_key,
            ),
            1,
            0
        );
        if ( empty( $rows ) ) {
            return null;
        }
        return (int) $rows[0]['id'];
    }
}
