<?php
namespace Easy_MCP_AI\Tools\SEO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Easy_MCP_AI\Tools\Base_Tool;

class Smartcrawl_Get_Post_Seo extends Base_Tool {

    use Smartcrawl_Seo;

    const META_TITLE = '_wds_title';
    const META_DESC  = '_wds_metadesc';

    public function get_name() {
        return 'wp_get_seo_meta';
    }

    public function get_description() {
        return 'Gets SmartCrawl Pro SEO metadata for a post, page, or product: SEO title (_wds_title), meta description (_wds_metadesc), and (best-effort, if present) focus keyword, canonical URL, and robots flags. Also returns a naive effective title/description fallback (post title / excerpt) for fields left blank, since SmartCrawl\'s own onpage default patterns are not read by this tool.';
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
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the post, page, or product.',
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

        $title = get_post_meta( $post_id, self::META_TITLE, true );
        $desc  = get_post_meta( $post_id, self::META_DESC, true );

        $extra = array();
        $best_effort_map = array(
            'focus_keyword' => '_wds_focus-keywords',
            'canonical'     => '_wds_canonical',
            'robots_noindex' => '_wds_meta-robots-noindex',
            'robots_nofollow' => '_wds_meta-robots-nofollow',
        );
        foreach ( $best_effort_map as $field => $meta_key ) {
            $value = get_post_meta( $post_id, $meta_key, true );
            if ( '' !== $value && false !== $value ) {
                $extra[ $field ] = $value;
            }
        }

        $effective_title = ( '' !== $title ) ? $title : $post->post_title;
        $effective_desc  = ( '' !== $desc )
            ? $desc
            : wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 30, '…' );

        return array(
            'post_id'               => $post_id,
            'post_type'             => $post->post_type,
            'title'                 => ( '' !== $title ) ? $title : null,
            'description'           => ( '' !== $desc ) ? $desc : null,
            'title_length'          => ( '' !== $title ) ? mb_strlen( $title ) : 0,
            'description_length'    => ( '' !== $desc ) ? mb_strlen( $desc ) : 0,
            'effective_title'       => $effective_title,
            'effective_description' => $effective_desc,
            'extra'                 => $extra,
            'note'                  => 'effective_title/effective_description fall back to the post title/excerpt when SmartCrawl fields are blank; this is a naive approximation, not SmartCrawl\'s own onpage default pattern. "extra" fields are best-effort meta keys and may not exist on all SmartCrawl versions.',
        );
    }
}
