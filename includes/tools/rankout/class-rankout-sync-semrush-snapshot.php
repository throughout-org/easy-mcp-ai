<?php
namespace Easy_MCP_AI\Tools\Rankout;

use Easy_MCP_AI\Semrush\Semrush_Client;
use Easy_MCP_AI\Semrush\Semrush_Validators;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rankout_Sync_Semrush_Snapshot extends Base_Tool {

	public function get_name() {
		return 'wp_rankout_sync_semrush_snapshot';
	}

	public function get_description() {
		return 'Pulls a Semrush domain overview plus top organic keywords, optionally backlinks, then authenticates to Rankout and upserts a monthly SEO snapshot for a Rankout client. Use this when a Rankout client page says it has no SEO data yet.';
	}

	public function get_category() {
		return 'rankout';
	}

	public function get_required_capability() {
		return 'manage_options';
	}

	public function get_annotations() {
		return array(
			'title'           => 'Rankout sync Semrush SEO snapshot',
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'openWorldHint'   => true,
		);
	}

	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'rankout_api_base', 'rankout_admin_email', 'rankout_admin_password', 'client_id', 'domain' ),
			'properties' => array(
				'rankout_api_base'     => array( 'type' => 'string', 'description' => 'Rankout API base URL, for example http://127.0.0.1:8077 or https://api.example.com.' ),
				'rankout_admin_email'  => array( 'type' => 'string', 'description' => 'Rankout admin email used to request a short-lived admin token.' ),
				'rankout_admin_password' => array( 'type' => 'string', 'description' => 'Rankout admin password used only for this sync request.' ),
				'client_id'            => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Rankout client ID, such as 1 from /clients/1.' ),
				'domain'               => array( 'type' => 'string', 'description' => 'Bare domain to fetch from Semrush, for example example.com.' ),
				'database'             => array( 'type' => 'string', 'default' => 'us', 'description' => 'Semrush regional database code.' ),
				'month'                => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Snapshot month. Defaults to the current UTC month.' ),
				'year'                 => array( 'type' => 'integer', 'minimum' => 2000, 'description' => 'Snapshot year. Defaults to the current UTC year.' ),
				'keyword_limit'        => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				'include_backlinks'    => array( 'type' => 'boolean', 'default' => true, 'description' => 'Try Semrush backlinks overview/list. If the Semrush plan lacks backlinks access, the sync still completes with organic data.' ),
			),
		);
	}

	public function execute( array $arguments ) {
		try {
			$this->validate_required( $arguments, array( 'rankout_api_base', 'rankout_admin_email', 'rankout_admin_password', 'client_id', 'domain' ) );

			$api_base = $this->normalize_api_base( (string) $arguments['rankout_api_base'] );
			$email    = sanitize_email( (string) $arguments['rankout_admin_email'] );
			$password = (string) $arguments['rankout_admin_password'];
			$client_id = $this->parse_required_id( $arguments['client_id'], 'client_id' );
			$domain   = $this->normalize_domain( (string) $arguments['domain'] );
			$database = trim( (string) ( $arguments['database'] ?? 'us' ) );
			$month    = isset( $arguments['month'] ) ? (int) $arguments['month'] : (int) gmdate( 'n' );
			$year     = isset( $arguments['year'] ) ? (int) $arguments['year'] : (int) gmdate( 'Y' );
			$keyword_limit = isset( $arguments['keyword_limit'] ) ? (int) $arguments['keyword_limit'] : 20;
			$include_backlinks = ! array_key_exists( 'include_backlinks', $arguments ) || (bool) $arguments['include_backlinks'];

			if ( '' === $email || '' === $password ) {
				throw new \InvalidArgumentException( 'Rankout admin email and password are required.' );
			}
			if ( $month < 1 || $month > 12 ) {
				throw new \InvalidArgumentException( 'month must be between 1 and 12.' );
			}
			if ( $year < 2000 || $year > 3000 ) {
				throw new \InvalidArgumentException( 'year must be between 2000 and 3000.' );
			}
			if ( $keyword_limit < 1 || $keyword_limit > 100 ) {
				throw new \InvalidArgumentException( 'keyword_limit must be between 1 and 100.' );
			}

			Semrush_Validators::validate_bare_domain( $domain );
			Semrush_Validators::validate_database( $database );

			$semrush = new Semrush_Client();
			$raw     = array(
				'source'            => 'easy-mcp-ai',
				'provider'          => 'semrush',
				'domain'            => $domain,
				'database'          => $database,
				'synced_at'         => gmdate( 'c' ),
				'warnings'          => array(),
				'semrush_units_estimated' => 0,
			);

			$overview = $semrush->report( 'domain_rank', array(
				'domain'         => $domain,
				'database'       => $database,
				'export_columns' => 'Dn,Rk,Or,Ot,Oc,Ad,At,Ac',
			) );
			$raw['domain_overview'] = $overview;
			$raw['semrush_units_estimated'] += (int) ( $overview['_units_cost'] ?? 0 );
			$overview_row = $overview['items'][0] ?? array();

			$keywords = $semrush->report( 'domain_organic', array(
				'domain'         => $domain,
				'database'       => $database,
				'display_limit'  => $keyword_limit,
				'display_sort'   => 'tr_desc',
				'export_columns' => 'Ph,Po,Pp,Pd,Nq,Cp,Ur,Tr,Tc,Co,Nr,Td',
			) );
			$raw['organic_keywords'] = $keywords;
			$raw['semrush_units_estimated'] += (int) ( $keywords['_units_cost'] ?? 0 );

			$backlinks_total = null;
			$referring_domains = null;
			$backlinks = array();

			if ( $include_backlinks ) {
				try {
					$backlinks_overview = $semrush->report( 'backlinks_overview', array(
						'target'      => $domain,
						'target_type' => 'root_domain',
					) );
					$raw['backlinks_overview'] = $backlinks_overview;
					$raw['semrush_units_estimated'] += (int) ( $backlinks_overview['_units_cost'] ?? 0 );
					$backlinks_row = $backlinks_overview['items'][0] ?? array();
					$backlinks_total = $this->first_int( $backlinks_row, array( 'backlinks_num', 'backlinks', 'total_backlinks', 'total' ) );
					$referring_domains = $this->first_int( $backlinks_row, array( 'domains_num', 'referring_domains', 'refdomains', 'domains' ) );
				} catch ( \Throwable $e ) {
					$raw['warnings'][] = 'Backlinks overview skipped: ' . $e->getMessage();
				}

				try {
					$backlinks_report = $semrush->report( 'backlinks', array(
						'target'        => $domain,
						'target_type'   => 'root_domain',
						'display_limit' => 20,
						'display_sort'  => 'page_ascore_desc',
					) );
					$raw['backlinks'] = $backlinks_report;
					$raw['semrush_units_estimated'] += (int) ( $backlinks_report['_units_cost'] ?? 0 );
					$backlinks = $this->map_backlinks( $backlinks_report['items'] ?? array() );
				} catch ( \Throwable $e ) {
					$raw['warnings'][] = 'Backlinks list skipped: ' . $e->getMessage();
				}
			}

			$payload = array(
				'month'              => $month,
				'year'               => $year,
				'domain_authority'   => $this->first_int( $overview_row, array( 'Rk' ) ),
				'organic_keywords'   => $this->first_int( $overview_row, array( 'Or' ) ),
				'organic_traffic'    => $this->first_int( $overview_row, array( 'Ot' ) ),
				'backlinks_total'    => $backlinks_total,
				'referring_domains'  => $referring_domains,
				'site_audit_score'   => null,
				'site_audit_issues'  => null,
				'top_keywords'       => $this->map_keywords( $keywords['items'] ?? array() ),
				'backlinks'          => $backlinks,
				'audit_issues'       => array(),
				'raw_data'           => $raw,
			);

			$token = $this->rankout_login( $api_base, $email, $password );
			$response = $this->rankout_post_snapshot( $api_base, $client_id, $token, $payload );

			return array(
				'synced'       => true,
				'client_id'    => $client_id,
				'domain'       => $domain,
				'month'        => $month,
				'year'         => $year,
				'snapshot_id'  => $response['id'] ?? null,
				'metrics'      => array(
					'domain_authority'  => $payload['domain_authority'],
					'organic_keywords'  => $payload['organic_keywords'],
					'organic_traffic'   => $payload['organic_traffic'],
					'backlinks_total'   => $payload['backlinks_total'],
					'referring_domains' => $payload['referring_domains'],
				),
				'warnings'     => $raw['warnings'],
				'rankout_path' => '/admin/clients/' . $client_id . '/seo',
			);
		} catch ( \Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	private function normalize_api_base( string $api_base ): string {
		$api_base = rtrim( trim( $api_base ), '/' );
		$parts = wp_parse_url( $api_base );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			throw new \InvalidArgumentException( 'rankout_api_base must be a valid http or https URL.' );
		}
		return $api_base;
	}

	private function normalize_domain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = preg_replace( '#^www\.#', '', $domain );
		$domain = strtok( $domain, '/?#' );
		return (string) $domain;
	}

	private function rankout_login( string $api_base, string $email, string $password ): string {
		$response = wp_remote_post( $api_base . '/admin/login', array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'email'    => $email,
				'password' => $password,
			) ),
		) );
		$data = $this->decode_response( $response, 'Rankout login failed' );
		if ( empty( $data['token'] ) || ! is_string( $data['token'] ) ) {
			throw new \RuntimeException( 'Rankout login response did not include an admin token.' );
		}
		return $data['token'];
	}

	private function rankout_post_snapshot( string $api_base, int $client_id, string $token, array $payload ): array {
		$response = wp_remote_post( $api_base . '/admin/clients/' . $client_id . '/seo', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
		) );
		return $this->decode_response( $response, 'Rankout SEO snapshot sync failed' );
	}

	private function decode_response( $response, string $prefix ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $prefix . ': ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) && isset( $data['detail'] ) ? (string) $data['detail'] : trim( $body );
			throw new \RuntimeException( $prefix . ': HTTP ' . $code . ( '' !== $detail ? ' - ' . $detail : '' ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private function first_int( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] && '' !== $row[ $key ] ) {
				return (int) $row[ $key ];
			}
		}
		return null;
	}

	private function map_keywords( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'keyword'       => (string) ( $row['Ph'] ?? '' ),
				'position'      => isset( $row['Po'] ) ? (int) $row['Po'] : null,
				'volume'        => isset( $row['Nq'] ) ? (int) $row['Nq'] : null,
				'prev_position' => isset( $row['Pp'] ) ? (int) $row['Pp'] : null,
				'url'           => (string) ( $row['Ur'] ?? '' ),
			);
		}
		return array_values( array_filter( $out, static function ( $item ) {
			return '' !== $item['keyword'];
		} ) );
	}

	private function map_backlinks( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$source_url = (string) ( $row['source_url'] ?? $row['url_from'] ?? $row['Source URL'] ?? $row['source_page'] ?? '' );
			$out[] = array(
				'url'         => $source_url,
				'domain_from' => (string) ( $row['source_domain'] ?? $row['domain_from'] ?? $row['Source Domain'] ?? '' ),
				'anchor'      => (string) ( $row['anchor'] ?? $row['anchor_text'] ?? $row['Anchor'] ?? '' ),
			);
		}
		return array_values( array_filter( $out, static function ( $item ) {
			return '' !== $item['url'] || '' !== $item['domain_from'];
		} ) );
	}
}
