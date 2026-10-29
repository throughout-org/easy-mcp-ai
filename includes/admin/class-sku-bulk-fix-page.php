<?php
namespace Easy_MCP_AI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-off admin tool: bulk-corrects WooCommerce product SKUs for La Rambla Perfumes.
 *
 * Runs entirely inside wp-admin as the logged-in administrator — it does NOT go
 * through the MCP tool registry, OAuth token scopes, or the Plugin Integrations
 * gate, so it is unaffected by tools/list caching or scope configuration.
 *
 * Safe by design:
 *  - Only ever sets `_sku` via WooCommerce's own `$product->set_sku()` + `save()`,
 *    so WooCommerce's native uniqueness validation still applies.
 *  - Defaults to a dry run (preview only, nothing written) until you explicitly
 *    tick "Apply changes" and submit.
 *  - Skips any product whose current SKU already matches the target (no-op row).
 *  - Per-row try/catch — one failure (e.g. duplicate SKU) never aborts the batch.
 *
 * To remove after use: delete this file and the two registration lines in
 * includes/class-plugin.php (search for "Sku_Bulk_Fix_Page").
 */
class Sku_Bulk_Fix_Page {

	const PAGE_SLUG = 'easy-mcp-ai-sku-bulk-fix';
	const NONCE_ACTION = 'easy_mcp_ai_sku_bulk_fix';

	/**
	 * post_id => target SKU. Computed offline from the live product titles
	 * (entity-decoded, whitespace-collapsed, spaces → hyphens; "-2" suffix
	 * appended for the few titles that collide).
	 */
	private function get_corrections() {
		return array(
			106142 => 'ESCENTIC-MOLECULES-MOLECULE-01-EDT-100ML',
			106143 => 'ESCENTRIC-MOLECULES-ESCENTRIC-05-EDT-100ML',
			106166 => "GIVENCHY-L'INTERDIT-ROUGE-EDP-80ML",
			106250 => 'RABANNE-ONE-MILLION-GOLDEN-OUD-PARFUM-100ML',
			106327 => 'VIKTOR-&-ROLF-FLOWER-BOMB-EDP-100ML',
			106352 => 'AMOUAGE-OPUS-XV-KING-BLUE-EDP-100ML',
			106355 => 'AMOUAGE-INTERLUDE-(M)-EDP-100ML',
			106357 => 'AMOUAGE-LILAC-LOVE-HAIR-MIST-50ML',
			106359 => 'ATELIER-FLOU-SLOANE-ROSE-EDP-100ML',
			106363 => 'ARMANI-MY-WAY-EDP-90ML-3PCS',
			106369 => 'ARMANI-STRONGER-WITH-YOU-ABSOLUTELY-EDP-100ML',
			106374 => 'BOUCHERON-POUR-HOMME-EDP-100ML',
			106376 => 'BVLGARI-FALKAR-EDP-125-ML',
			106377 => 'BVLGARI-ONEKH-EDP-125-ML',
			106378 => 'BVLGARI-TYGAR-EDP-125-ML',
			106379 => 'BYREDO-EYES-CLOSED-EDP-100ML',
			106380 => 'CASAMORATI-LIRA-EDP-100ML',
			106381 => 'CAROLINA-HERRERA-BRONZE-TONKA-EDP-100ML',
			106382 => 'CAROLINA-HERRERA-LIMITED-EDITION-EDP-WOMEN-100ML',
			106384 => 'CARTIER-OUD-&-MUSC-PARFUM-45-ML',
			106388 => 'CREED-ACQUA-FIORENTINA-EDP-75ML',
			106389 => 'CHANEL-BLEU-DE-CHANEL-EDP-100ML',
			106394 => 'CHRISTIAN-DIOR-MISS-DIOR-ROSE-ENSSENCE-EDT-100ML',
			106397 => 'CHRISTIAN-DIOR-SAUVAGE-DEO-STK-75-GM',
			106398 => 'CREED-CARMINA-EDP-75ML',
			106400 => 'CREED-WIND-FLOWERS-EDP-75ML',
			106402 => 'DOLCE-&-GABBANA-DEVOTION-EDP-100ML',
			106404 => 'DOLCE-&-GABBANA-KING-M-EDP-100ML',
			106406 => 'DOLCE-&-GABBANA-THE-ONE-M-EDP-100ML',
			106407 => 'DOLCE-&-GABBANA-THE-ONE-M-INTENSE-EDP-100ML',
			106410 => 'EX-NIHILO-GOLD-IMMORTALS-EDP-100ML',
			106428 => 'GUCCI-FLORA-GORGEOUS-ORCHID-EDP-100ML',
			106434 => 'KILIAN-MOONLIGHT-IN-HEAVEN-EDP-50ML',
			106435 => 'ISSEY-MIYAKE-LE-SEL-EDT-100ML',
			106439 => 'LAMCÔME-IDOLE-EDP-75ML',
			106444 => 'MAISON-CRIVELLI-HIBISCUS-MAHAJAD-EXTRAIT-DE-PARFUM-50ML',
			106451 => 'MAISON-MARGIELA-REPLICA-FIREPLACE-EDT-100ML',
			106462 => 'MEMO-IBERIAN-LEATHER-75ML',
			106469 => 'NASOMATTO-BLACK-AFGANO-EDP-30ML',
			106471 => 'NARCISO-RODRIGUEZ-MUSC-NOIR-ROSE-EDP-100ML',
			106473 => 'NISHANE-HACIVAT-EXTRAIT-DE-PARFUM-100ML',
			106474 => 'ORMONDE-JAYNE-SAKURA-EDP-50ML',
			106475 => 'ORMONDE-JAYNE-MONTABACO-INTENSIVO-EDP-88ML',
			106477 => 'PARFUMS-DE-MARLY-PERCIVAL-EDP-200ML',
			106478 => 'PARFUMS-DE-MARLY-SAFANAD-EDP-75ML',
			106503 => 'THOMAS-KOSMALA-N02-EDP-100ML',
			106504 => 'THOMAS-KOSMALA-SUPER-AMBER-11-100ML',
			106508 => 'TOM-FORD-EBENE-FUME-EDP-50ML',
			106509 => 'TOM-FORD-ROSE-DE-CHINE-EDP-50ML',
			106511 => 'VILHELM-BODY-PAINT-EDP-100ML',
			106512 => 'VILHELM-MORNING-CHESS-EDP-100ML',
			106513 => 'VAN-CLEEF-&-ARPELS-3PCS-SET-45ML',
			106514 => 'VAN-CLEEF-&-ARPELS-MOONLIGHT-PATCHOULI-EDP-75ML',
			106515 => "VAN-CLEEF-&-ARPELS-REVE-D'ENCENS-EDP-125ML",
			106516 => 'VERSACE-DYLAN-BLUE-W-EDP-100ML-3PCS',
			106518 => 'VILHELM-ROOM-SERVICE-EDP-100ML',
			106519 => 'VILHELM-STOCKHOLM-1978-EDP-100ML',
			106533 => 'YSL-TUXEDO-EDP-125ML',
			106534 => 'YSL-WILD-LEATHER-EDP-125ML',
			106537 => 'YSL-BABYCAT-EDP-125ML',
			107157 => 'VERSACE-EAU-FRAICHE-MAN-EDT-100ML',
			107161 => 'PENHALIGON_S-MR.-SAM-EDP-75ML',
			107162 => "PENHALIGON'S-ALULA-EDP-100ML",
			107163 => "PENHALIGON'S-BABYLON-EDP-100ML",
			107164 => "PENHALIGON'S-COUSIN-MATTHEW-EDP-75ML",
			107165 => "PENHALIGON'S-HALFETI-EDP-100ML",
			107166 => "PENHALIGON'S-HALFETI-LEATHER-EDP-100-ML",
			107167 => "PENHALIGON'S-LORD-GEORGE-EDP-75ML",
			107168 => "PENHALIGON'S-CHANGING-CONSTANCE-EDP-75ML",
			107169 => 'PARFUMS-DE-MARLY-PERCIVAL-EDP-125ML',
			107175 => 'NISHANE-HUNDRED-SILENT-WAYS-EXTRAIT-DE-PARFUM-100ML',
			107176 => 'NISHANE-ANI-EXTRAIT-DE-PARFUM-100ML',
			107178 => 'NISHANE-HUNDRED-SILENT-WAYS-X-EXTRAIT-DE-PARFUM-100ML',
			107179 => 'MATIÈRE-PREMIÈRE-CRYSTAL-SAFFRON-EDP-100ML',
			107180 => 'YSL-MYSLF-EDP-100ML',
			107181 => 'YSL-MYSLF-LE-PARFUM-100ML',
			107182 => 'MATIÈRE-PREMIÈRE-FRENCH-FLOWER-EDP-100ML',
			107183 => 'MATIÈRE-PREMIÈRE-NEROLI-ORANGE-EDP-100ML',
			107184 => 'MATIÈRE-PREMIÈRE-RADICAL-ROSE-EDP-100ML',
			107186 => 'MATIÈRE-PREMIÈRE-VANILLA-POWDER-EDP-100ML',
			107187 => 'MATIÈRE-PREMIÈRE-SANTAL-AUSTRAL-EDP-100ML',
			107189 => 'GIVENCHY-GARCON-MANQUÉ-EDP-100ML',
			107190 => 'GIVENCHY-ACCORD-PARTICULIÈRE-EDP-100ML',
			107191 => "HERMÈS-TERRE-D'HERMÈS-EDT-100ML",
			107193 => 'HERMÈS-TWILLY-EDP-85ML',
			107194 => 'HERMÈS-TWILLY-POIVREE-EDP-85ML',
			107195 => 'HERMÈS-TWILLY-EAU-GINGER-EDP-85ML',
			107196 => 'HERMÈS-BARENIA-EDP-100ML',
			107197 => "HERMÈS-TERRE-D'HERMÈS-EDP-INTENSE-100ML",
			107202 => 'ESTÉE-LAUDER-BEAUTIFUL-EDP-75ML',
			107203 => 'ESTÉE-LAUDER-DESERT-EDEN-EDP-100ML',
			107204 => 'ESTÉE-LAUDER-KNOWING-EDP-75ML',
			107205 => 'ESTÉE-LAUDER-PLEASURE-W-EDP-100ML',
			107206 => 'ESTÉE-LAUDER-PRIVATE-COLLECTION-EDP-50ML',
			107207 => 'NICOLAÏ-PATCHOULI-INTENSE-EDP-100ML',
			107208 => 'LIQUIDES-IMAGINAIRES-BÊTE-HUMANE-EDP-100ML',
			107209 => 'LIQUIDES-IMAGINAIRES-BLANCHE-BÊTE-EDP-100ML',
			107211 => 'LIQUIDES-IMAGINAIRES-LUNATIQUE-EDP-100ML-2',
			107214 => 'CHRISTIAN-DIOR-SAUVAGE-ELIXIR-EDP-60ML',
			107218 => 'BYREDO-BLANCHE-EDP-100ML',
			107220 => 'LAMCÔME-IDOLE-EDP-75ML-2',
			107223 => 'LAMCÔME-LA-NUIT-TRÉSOR-EDP-100ML',
			107226 => 'LAMCÔME-ORANGE-BIGARADES-EDP-100ML',
			107231 => 'LAMCÔME-ROSES-BERBERANZA-EDP-100ML',
			107232 => 'LAMCÔME-TUBEREUSES-CASTANE-EDP-100ML',
			107244 => "HERMÈS-L'OMBRE-DES-MERVEILLES-100ML",
			107246 => "CHANEL-NO.1-L'EAU-ROUGE-EDP-100ML",
			107249 => 'TOM-FORD-OMBRÉ-LEATHER-EDP-100ML',
			107253 => 'ARMANI-PRIVÉ-ROSE-D-ARABIE-EDP-100ML',
			107263 => 'BYREDO-ANIMALIQUE-EDP-100ML',
			107264 => 'THOMAS-KOSMALA-N10-EDP-100ML',
			107265 => 'BDK-GRIS-CHARNEL-EDP-100ML',
			107266 => 'BORN-TO-STAND-OUT-SUGAR-ADDICT-100ML',
			107267 => 'BORN-TO-STAND-OUT-DRUNK-LOVERS-100ML',
			107270 => 'JEROBAOM-GOZO-EXTRAIT-DE-PARFUM-100ML',
			107271 => 'ORMONDE-JAYNE-AMBRE-ROYAL-EDP-88ML',
			107272 => 'PARFUMS-DE-MARLY-GREENLY-EDP-125ML',
			107273 => 'NARCISO-RODRIGUEZ-FOR-HER-SET-3PCS-EDP-100ML',
			107274 => 'BVLGARI-PETIT-ET-MAMANS-EDT-100ML',
			107276 => 'BDK-IMPADIA-EDP-100ML',
			107355 => 'ESCENTRIC-MOLECULES-ESCENTRIC-02-EDT-100ML',
			107356 => "KILIAN-ANGEL'S-SAHRE-EDP-50ML",
			107357 => "ISSEY-MIYAKE-L'EAU-D'ISSEY-POUR-HOMME-EDT-125ML",
			107358 => 'ELECTIMUS-BLACK-CAVIAR-EDP-100ML',
			107359 => 'MAISON-CRIVELLI-OUD-MARACUJA-EXTRAIT-DE-PARFUM-50ML',
			107360 => 'MEMO-ODEON-EDP-75ML',
			107361 => 'MEMO-MADURAI-EDP-75ML',
			107362 => 'MEMO-FRENCH-LEATHER-EDP-75ML',
			107365 => 'TOM-FORD-OMBRÉ-LEATHER-BODY-SPRAY-150ML',
			107416 => 'ELECTIMUS-ASTRUM-NOVA-EXTRAIT-DE-PARFUM-100ML',
			107469 => "TOM-FORD-ROSE-D'AMALFI-EDP-50ML",
			107472 => 'AMOUAGE-PURPOSE-50-EDP-100ML',
			107487 => 'ARMANI-STRONGER-WITH-YOU-INTENSLY-EDP-100ML',
			107488 => 'BDK-GRIS-CHARNEL-EXTRAIT-EXTRAIT-DE-PARFUM-100ML',
			107489 => 'BURBERRY-HERO-EDP-100ML-SET',
			107491 => 'CREED-OUD-ZARIAN-EDP-100ML',
			107492 => 'ESCENTRIC-MOLECULES-MOLECULE-01-EDT-100ML',
			107493 => 'GOLDFIELD-&-BANKS-INGENIOUS-GINGER-EDP-100ML',
			107496 => 'GOLDFIELD-&-BANKS-PACIFIC-ROCK-FLOWER-EDP-100ML',
			107522 => 'GOLDFIELD-&-BANKS-PURPLE-SUEDE-EDP-100ML',
			107523 => 'GOLDFIELD-&-BANKS-SILKY-WOODS-EDP-100ML',
			107526 => 'GUERLAIN-INSOLENCE-EDP-100ML-SET',
			107527 => 'LIQUIDE-IMMAGINAIRES-LIQUID-GOLD-EDP-100ML',
			107528 => 'MAISON-CRIVELLI-CUIR-INFRAROUGE-EXTRAIT-DE-PARFUM-50ML',
			107529 => 'PARFUMS-DE-MARLY-CASTLEY-EDP-125ML',
			107530 => 'STARDA-AVENUE-MANTAIGNE-EDP-100ML',
			107531 => 'STARDA-GINZA-DISTRICT-EDP-100ML',
			107532 => 'STRADA-5TH-AVENUE-EDP-100ML',
			107533 => 'TOM-FORD-OUD-VOYAGE-EDP-50ML',
			107534 => 'YSL-ATLAS-GARDEN-EDP-125ML',
			107535 => 'YSL-CAPELINE-EDP-125ML-2',
			107559 => 'CAROLINA-HERRERA-STALLION-LEATHER-EDP-100ML',
			107583 => 'CAROLINA-HERRERA-STALLION-LEATHER-SUEDE-EDP-100ML',
			107584 => 'INITIO-SIDE-EFFECT-EDP-100ML',
			107585 => 'MATIERE-PREMIERE-VANILLA-POWDER-EXTRAIT-DE-PARFUM-100ML',
			107586 => 'NARCISO-RODRIGUEZ-FOR-HER-EDP-100ML',
			107587 => 'NISHANE-MEANT-TO-BE-SEEN-EXTRAIT-DE-PARFUM-100ML',
			107588 => 'OMAN-LUXURY-CADEN-EDP-100ML',
			107589 => 'RABANNE-MILLION-GOLD-FOR-HER-EDP-100ML',
			107590 => 'RABANNE-ONE-MILLION-FOR-HIM-EDT-200ML',
			107591 => 'RAMON-BEJAR-BLACK-STONE-EDP-100ML',
			107593 => 'VAN-CLEEF-AND-ARPELS-PRECIOUS-OUD-EDP-75ML',
			107594 => 'XERJOFF-NAXOS-EDP-100ML',
			107599 => 'AMOUAGE-GUIDANCE-46-EDP-100ML',
			107624 => 'CARTIER-OUD-SANTAL-EDP-75ML',
			107674 => 'FUGAZZI-BORN-TO-STAND-OUT-EXTRAIT-DE-PARFUM-50ML',
			107677 => 'FUGAZZI-ORANGE-CRUSH-EXTRAIT-DE-PARFUM-100ML',
			107678 => 'FUGAZZI-VANILLA-HAZE-EXTRAIT-DE-PARFUM-100ML',
			107679 => 'FUGAZZI-GOUDH-EXTRAIT-DE-PARFUM-100ML',
			107680 => 'FUGAZZI-PASSION-FROUDH-EXTRAIT-DE-PARFUM-100ML',
			107681 => 'FUGAZZI-NOCOLOGNE-EXTRAIT-DE-PARFUM-100ML',
			107682 => 'FUGAZZI-ANGEL-DUST-EDP-100ML',
			107683 => 'LENGLING-SEKUSHI-N7-EDP-100ML',
			107685 => 'HORMONE-THIS-IS-NOT-GABA-EDP-100ML',
			107686 => 'HORMONE-THIS-IS-NOT-CAFFEINE-EDP-100ML',
			107687 => 'HORMONE-THIS-IS-NOT-KISSPEPTIN-EDP-100ML',
			107688 => 'HORMONE-THIS-IS-NOT-TESTOSTERONE-EDP-100ML',
			107689 => 'HORMONE-THIS-IS-NOT-ADRENALINE-EDP-100ML',
			107690 => 'HORMONE-THIS-IS-NOT-SEROTONIN-EDP-100ML',
			107691 => 'HORMONE-THIS-IS-NOT-ENDORPHIN-EDP-100ML',
			107692 => 'HORMONE-THIS-IS-NOT-DOPAMINE-EDP-100ML',
			107693 => 'HORMONE-THIS-IS-NOT-OXYTOCIN-EDP-100ML',
			107694 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-01-100ML',
			107695 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-02-100ML',
			107696 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-03-100ML',
			107698 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-04-100ML',
			107700 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-05-100ML',
			107701 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-06-100ML',
			107703 => 'HORMONE-THIS-IS-NOT-MELATONIN-BODY-SPRAY-07-100ML',
			107706 => 'GOLDFIELD-AND-BANKS-TALES-OF-AMBER-EXTRAIT-DE-PARFUM-100ML',
			107707 => 'GOLDFIELD-AND-BANKS-SILKY-WOODS-ELIXIR-EXTRAIT-DE-PARFUM-100ML',
			107709 => 'GOLDFIELD-AND-BANKS-BOHEMIAN-LIME-EDP-100ML',
		);
	}

	public function register_submenu() {
		\add_submenu_page(
			'easy-mcp-ai',
			\__( 'SKU Bulk Fix', 'easy-mcp-ai' ),
			\__( 'SKU Bulk Fix', 'easy-mcp-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions.', 'easy-mcp-ai' ) );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="wrap"><h1>SKU Bulk Fix</h1><div class="notice notice-error"><p>WooCommerce is not active.</p></div></div>';
			return;
		}

		$results = null;
		$applied = false;

		if ( isset( $_POST['easy_mcp_ai_sku_bulk_fix_submit'] ) ) {
			\check_admin_referer( self::NONCE_ACTION );
			$applied = isset( $_POST['apply_changes'] ) && '1' === $_POST['apply_changes'];
			$results = $this->run( $applied );
		}

		$corrections = $this->get_corrections();

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'SKU Bulk Fix — La Rambla', 'easy-mcp-ai' ) . '</h1>';
		echo '<p>' . \esc_html( count( $corrections ) ) . ' products queued. Runs directly via WooCommerce (set_sku + save), bypassing MCP entirely. Defaults to a dry run — tick "Apply changes" to actually write.</p>';

		if ( null !== $results ) {
			$this->render_results( $results, $applied );
		}

		echo '<form method="post">';
		\wp_nonce_field( self::NONCE_ACTION );
		echo '<p><label><input type="checkbox" name="apply_changes" value="1"> ' . \esc_html__( 'Apply changes (uncheck to preview only)', 'easy-mcp-ai' ) . '</label></p>';
		echo '<p><button type="submit" name="easy_mcp_ai_sku_bulk_fix_submit" value="1" class="button button-primary">' . \esc_html__( 'Run', 'easy-mcp-ai' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	private function run( $apply ) {
		$rows = array();
		foreach ( $this->get_corrections() as $post_id => $target_sku ) {
			$row = array(
				'id'     => $post_id,
				'target' => $target_sku,
				'status' => '',
				'detail' => '',
			);

			$product = \wc_get_product( $post_id );
			if ( ! $product ) {
				$row['status'] = 'error';
				$row['detail'] = 'Product not found.';
				$rows[] = $row;
				continue;
			}

			$current_sku = $product->get_sku();
			$row['current'] = $current_sku;

			if ( $current_sku === $target_sku ) {
				$row['status'] = 'skip';
				$row['detail'] = 'Already correct.';
				$rows[] = $row;
				continue;
			}

			if ( ! $apply ) {
				$row['status'] = 'preview';
				$row['detail'] = 'Would update.';
				$rows[] = $row;
				continue;
			}

			try {
				$product->set_sku( $target_sku );
				$product->save();
				$row['status'] = 'success';
				$row['detail'] = 'Updated.';
			} catch ( \Exception $e ) {
				$row['status'] = 'error';
				$row['detail'] = $e->getMessage();
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private function render_results( $rows, $applied ) {
		$counts = array(
			'success' => 0,
			'skip'    => 0,
			'error'   => 0,
			'preview' => 0,
		);
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				++$counts[ $row['status'] ];
			}
		}

		echo '<div class="notice notice-' . ( $counts['error'] > 0 ? 'warning' : 'success' ) . '"><p>';
		echo $applied
			? \esc_html__( 'Run complete.', 'easy-mcp-ai' )
			: \esc_html__( 'Preview only — nothing was written.', 'easy-mcp-ai' );
		echo ' ' . sprintf(
			/* translators: 1: success count 2: skip count 3: error count 4: preview count */
			\esc_html__( '%1$d updated, %2$d already correct, %3$d errors, %4$d would-update (preview).', 'easy-mcp-ai' ),
			(int) $counts['success'],
			(int) $counts['skip'],
			(int) $counts['error'],
			(int) $counts['preview']
		);
		echo '</p></div>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>ID</th><th>Current SKU</th><th>Target SKU</th><th>Status</th><th>Detail</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			if ( 'skip' === $row['status'] ) {
				continue; // keep the table focused on rows that need attention.
			}
			echo '<tr>';
			echo '<td>' . \esc_html( $row['id'] ) . '</td>';
			echo '<td>' . \esc_html( isset( $row['current'] ) ? $row['current'] : '' ) . '</td>';
			echo '<td>' . \esc_html( $row['target'] ) . '</td>';
			echo '<td>' . \esc_html( $row['status'] ) . '</td>';
			echo '<td>' . \esc_html( $row['detail'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
