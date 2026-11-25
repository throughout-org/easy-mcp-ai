<?php
namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Easy_MCP_AI\Tools\Base_Tool;

class Smartcrawl_Bulk_Update_Post_Seo extends Base_Tool {

    use Smartcrawl_Seo;

    const MAX_ITEMS = 20;

    public function get_name() {
        return 'wp_bulk_update_seo_meta';
    }

    public function get_description() {
        return 'Updates SmartCrawl Pro SEO title/description on up to 20 posts/pages/products in one call. Each item is applied independently (one failure does not stop the rest). Defaults to a dry run: pass dry_run=false and confirm=true to actually write all items.';
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
                'items'   => array(
                    'type'        => 'array',
                    'description' => 'Up to 20 items, each { post_id, title?, description? }.',
                    'maxItems'    => self::MAX_ITEMS,
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'post_id'     => array( 'type' => 'integer' ),
                            'title'       => array( 'type' => 'string' ),
                            'description' => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'post_id' ),
                    ),
                ),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'description' => 'Preview the changes without writing. Defaults to true.',
                    'default'     => true,
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'description' => 'Must be true, together with dry_run=false, to actually write. Defaults to false.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'items' ),
        );
    }

    public function execute( array $arguments ) {
        $items = isset( $arguments['items'] ) ? $this->parse_json_param( $arguments['items'], 'items' ) : null;

        if ( empty( $items ) || ! is_array( $items ) ) {
            throw new \InvalidArgumentException( 'items must be a non-empty array.' );
        }

        if ( count( $items ) > self::MAX_ITEMS ) {
            throw new \InvalidArgumentException( sprintf( 'items cannot contain more than %d entries per call.', self::MAX_ITEMS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $dry_run = isset( $arguments['dry_run'] ) ? $arguments['dry_run'] : true;
        $confirm = isset( $arguments['confirm'] ) ? $arguments['confirm'] : false;

        $single = new Smartcrawl_Update_Post_Seo();
        $results = array();

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['post_id'] ) ) {
                $results[] = array(
                    'post_id' => isset( $item['post_id'] ) ? $item['post_id'] : null,
                    'status'  => 'error',
                    'error'   => 'Missing post_id.',
                );
                continue;
            }

            $item_args = array(
                'post_id' => $item['post_id'],
                'dry_run' => $dry_run,
                'confirm' => $confirm,
            );
            if ( array_key_exists( 'title', $item ) ) {
                $item_args['title'] = $item['title'];
            }
            if ( array_key_exists( 'description', $item ) ) {
                $item_args['description'] = $item['description'];
            }

            try {
                $result = $single->execute( $item_args );
                $results[] = array_merge( array( 'status' => 'ok' ), $result );
            } catch ( \Exception $e ) {
                $results[] = array(
                    'post_id' => $item['post_id'],
                    'status'  => 'error',
                    'error'   => \Easy_MCP_AI\MCP\Server::sanitize_error_message( $e->getMessage() ),
                );
            }
        }

        return array(
            'total'   => count( $items ),
            'dry_run' => ! $this->wants_write( array( 'dry_run' => $dry_run, 'confirm' => $confirm ) ),
            'results' => $results,
        );
    }
}
