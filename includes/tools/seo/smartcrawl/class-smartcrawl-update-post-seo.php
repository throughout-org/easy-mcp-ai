<?php
namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Easy_MCP_AI\Tools\Base_Tool;

/**
 * Writes SmartCrawl Pro's per-post SEO title/description directly via
 * update_post_meta(). Never touches post_content, post_title, or
 * post_modified — this exists specifically so SEO fields can be set
 * without re-saving the post through wp-admin (which, for classic-editor
 * posts, can reformat post_content).
 */
class Smartcrawl_Update_Post_Seo extends Base_Tool {

    use Smartcrawl_Seo;

    const META_TITLE     = '_wds_title';
    const META_DESC      = '_wds_metadesc';
    const TITLE_MAX      = 120;
    const DESC_MAX       = 320;
    const TITLE_WARN_MAX = 65;
    const DESC_WARN_MIN  = 120;
    const DESC_WARN_MAX  = 160;

    public function get_name() {
        return 'wp_update_seo_meta';
    }

    public function get_description() {
        return 'Updates SmartCrawl Pro SEO title (_wds_title) and/or meta description (_wds_metadesc) on a post, page, or product using update_post_meta() only — never touches post_content, post_title, or post_modified. Defaults to a dry run: pass dry_run=false and confirm=true to actually write. Every write is recorded in the change log and can be undone with wp_revert_seo_change.';
    }

    public function get_category() {
        return 'smartcrawl-seo';
    }

    public function get_required_capability() {
        return 'manage_options';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id'     => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the post, page, or product.',
                ),
                'title'       => array(
                    'type'        => 'string',
                    'description' => 'New SEO title (_wds_title). Max 120 characters; a warning is returned if over 65.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'New SEO meta description (_wds_metadesc). Max 320 characters; a warning is returned if outside 120-160.',
                ),
                'dry_run'     => array(
                    'type'        => 'boolean',
                    'description' => 'Preview the change without writing. Defaults to true.',
                    'default'     => true,
                ),
                'confirm'     => array(
                    'type'        => 'boolean',
                    'description' => 'Must be true, together with dry_run=false, to actually write. Defaults to false.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'post_id' ),
        );
    }

    public function execute( array $arguments ) {
        $post_id = $this->parse_required_id( $arguments['post_id'], 'post_id' );

        $post = get_post( $post_id );
        if ( ! $post ) {
            throw new \InvalidArgumentException( 'Post not found.' );
        }

        $has_title = array_key_exists( 'title', $arguments );
        $has_desc  = array_key_exists( 'description', $arguments );

        if ( ! $has_title && ! $has_desc ) {
            throw new \InvalidArgumentException( 'Nothing to update: provide title and/or description.' );
        }

        $warnings = array();
        $planned  = array();

        if ( $has_title ) {
            $new_title = $this->sanitize_seo_field( $arguments['title'], 'title', self::TITLE_MAX );
            $warning   = $this->seo_length_warning( 'title', $new_title, null, self::TITLE_WARN_MAX );
            if ( $warning ) {
                $warnings[] = $warning;
            }
            $planned[ self::META_TITLE ] = array( 'field' => 'title', 'value' => $new_title );
        }

        if ( $has_desc ) {
            $new_desc = $this->sanitize_seo_field( $arguments['description'], 'description', self::DESC_MAX );
            $warning  = $this->seo_length_warning( 'description', $new_desc, self::DESC_WARN_MIN, self::DESC_WARN_MAX );
            if ( $warning ) {
                $warnings[] = $warning;
            }
            $planned[ self::META_DESC ] = array( 'field' => 'description', 'value' => $new_desc );
        }

        $changes = array();
        foreach ( $planned as $meta_key => $info ) {
            $old = get_post_meta( $post_id, $meta_key, true );
            if ( $old === $info['value'] ) {
                continue; // No-op; nothing to record or write.
            }
            $changes[ $meta_key ] = array(
                'field'     => $info['field'],
                'old'       => ( '' !== $old ) ? $old : null,
                'new'       => $info['value'],
                'old_length' => mb_strlen( (string) $old ),
                'new_length' => mb_strlen( $info['value'] ),
            );
        }

        if ( empty( $changes ) ) {
            return array(
                'post_id' => $post_id,
                'updated' => array(),
                'warnings' => $warnings,
                'note'    => 'No changes: the submitted value(s) already match the current SmartCrawl SEO meta.',
            );
        }

        $perform_write = $this->wants_write( $arguments );

        if ( ! $perform_write ) {
            return array(
                'post_id'  => $post_id,
                'dry_run'  => true,
                'would_update' => array_values( $changes ),
                'warnings' => $warnings,
                'note'     => 'No changes were written. Set dry_run=false and confirm=true to apply.',
            );
        }

        // Use the top-level tool name from the active request context (not
        // necessarily $this->get_name()): when this tool is composed internally
        // by wp_bulk_update_seo_meta, the change-log row is written under that
        // outer tool's name, since Change_Context is set once per request.
        $recorded_tool_name = $this->get_name();
        if ( class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) {
            $recorded_tool_name = \Easy_MCP_AI\History\Change_Context::get( 'tool_name', $recorded_tool_name );
        }

        $updated = array();
        foreach ( $changes as $meta_key => $info ) {
            update_post_meta( $post_id, $meta_key, $info['new'] );
            $updated[] = array(
                'field'      => $info['field'],
                'old'        => $info['old'],
                'new'        => $info['new'],
                'old_length' => $info['old_length'],
                'new_length' => $info['new_length'],
                'change_id'  => $this->find_own_change_log_id( $recorded_tool_name, $post_id, $meta_key ),
            );
        }

        $this->invalidate_post_cache( $post_id );

        return array(
            'post_id'  => $post_id,
            'dry_run'  => false,
            'updated'  => $updated,
            'warnings' => $warnings,
        );
    }
}
