<?php
namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Easy_MCP_AI\Tools\Base_Tool;
use Easy_MCP_AI\History\Change_Log_Repository;

class Smartcrawl_Revert_Change extends Base_Tool {

    use Smartcrawl_Seo;

    const META_TITLE = '_wds_title';
    const META_DESC  = '_wds_metadesc';

    /** Tool names allowed to be the origin of a revertible change-log row. */
    const REVERTIBLE_TOOLS = array( 'wp_update_seo_meta', 'wp_bulk_update_seo_meta' );

    /** Meta keys this tool is allowed to restore. */
    const ALLOWED_META_KEYS = array( self::META_TITLE, self::META_DESC );

    public function get_name() {
        return 'wp_revert_seo_change';
    }

    public function get_description() {
        return 'Reverts a single SmartCrawl SEO meta change previously made by wp_update_seo_meta or wp_bulk_update_seo_meta, using the before/after value recorded in the change log at the time. change_id is the id returned by those tools (or by wp_history_list/wp_history_get). Defaults to a dry run: pass dry_run=false and confirm=true to actually write. Refuses to revert if the field has been changed again since, to avoid overwriting newer data.';
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
                'change_id' => array(
                    'type'        => 'integer',
                    'description' => 'The change-log row id to revert (returned as "change_id" by wp_update_seo_meta / wp_bulk_update_seo_meta).',
                ),
                'dry_run'   => array(
                    'type'        => 'boolean',
                    'description' => 'Preview the revert without writing. Defaults to true.',
                    'default'     => true,
                ),
                'confirm'   => array(
                    'type'        => 'boolean',
                    'description' => 'Must be true, together with dry_run=false, to actually write. Defaults to false.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'change_id' ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! class_exists( '\\Easy_MCP_AI\\History\\Change_Log_Repository' ) ) {
            throw new \RuntimeException( 'Change history logging is not available on this site.' );
        }

        $change_id = $this->parse_required_id( $arguments['change_id'], 'change_id' );

        $repo = new Change_Log_Repository();
        $row  = $repo->find( $change_id );

        if ( ! $row ) {
            throw new \InvalidArgumentException( 'Change not found.' );
        }

        if ( ! in_array( $row['tool_name'], self::REVERTIBLE_TOOLS, true ) || 'meta' !== $row['object_type'] ) {
            throw new \InvalidArgumentException( 'This change was not made by wp_update_seo_meta or wp_bulk_update_seo_meta and cannot be reverted with wp_revert_seo_change.' );
        }

        $parts = explode( ':', (string) $row['object_id'], 2 );
        if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) {
            throw new \InvalidArgumentException( 'Malformed change-log entry; cannot determine the post and field to revert.' );
        }
        $post_id  = (int) $parts[0];
        $meta_key = $parts[1];

        if ( ! in_array( $meta_key, self::ALLOWED_META_KEYS, true ) ) {
            throw new \InvalidArgumentException( 'This change did not touch a SmartCrawl SEO field and cannot be reverted with wp_revert_seo_change.' );
        }

        $before = json_decode( (string) $row['before_value'], true );
        $after  = json_decode( (string) $row['after_value'], true );

        if ( ! is_array( $before ) || '[REDACTED]' === ( $before['value'] ?? null ) ) {
            throw new \InvalidArgumentException( 'The previous value for this change was not recorded (or was redacted) and cannot be restored.' );
        }

        $restore_to    = $before['value'] ?? '';
        $expected_prev = is_array( $after ) ? ( $after['value'] ?? null ) : null;
        $current       = get_post_meta( $post_id, $meta_key, true );
        $current       = ( '' !== $current ) ? $current : null;

        if ( $current !== $expected_prev ) {
            return array(
                'change_id' => $change_id,
                'post_id'   => $post_id,
                'field'     => ( self::META_TITLE === $meta_key ) ? 'title' : 'description',
                'reverted'  => false,
                'note'      => 'This field has been changed again since this change-log entry was recorded. Refusing to revert to avoid overwriting a newer value. Use wp_update_seo_meta directly if you still want to set it to the old value.',
            );
        }

        $field = ( self::META_TITLE === $meta_key ) ? 'title' : 'description';

        if ( ! $this->wants_write( $arguments ) ) {
            return array(
                'change_id'     => $change_id,
                'post_id'       => $post_id,
                'field'         => $field,
                'dry_run'       => true,
                'current_value' => $current,
                'restoring_to'  => ( '' !== $restore_to ) ? $restore_to : null,
                'note'          => 'No changes were written. Set dry_run=false and confirm=true to apply.',
            );
        }

        update_post_meta( $post_id, $meta_key, $restore_to );
        $this->invalidate_post_cache( $post_id );

        $recorded_tool_name = $this->get_name();
        if ( class_exists( '\\Easy_MCP_AI\\History\\Change_Context' ) ) {
            $recorded_tool_name = \Easy_MCP_AI\History\Change_Context::get( 'tool_name', $recorded_tool_name );
        }

        return array(
            'change_id'       => $change_id,
            'post_id'         => $post_id,
            'field'           => $field,
            'dry_run'         => false,
            'reverted'        => true,
            'restored_value'  => ( '' !== $restore_to ) ? $restore_to : null,
            'revert_change_id' => $this->find_own_change_log_id( $recorded_tool_name, $post_id, $meta_key ),
        );
    }
}
