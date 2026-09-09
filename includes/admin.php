<?php
/**
 * MS Calculator – Admin area
 *
 * Loaded only when is_admin() is true (see ms-calculator.php).
 * Adds Settings > MS Calculator with:
 *   - Status banner
 *   - JSON config editor (saved to wp_options)
 *   - README rendered as HTML
 */

defined( 'ABSPATH' ) || exit;

// ── Save: JSON config ─────────────────────────────────────────────────────────

add_action( 'admin_init', static function (): void {

	if (
		! isset( $_POST['msc_save_config'] ) ||
		! check_admin_referer( 'msc_save_config', 'msc_config_nonce' ) ||
		! current_user_can( 'manage_options' )
	) return;

	$raw = wp_unslash( $_POST['msc_config_json'] ?? '' );

	json_decode( $raw );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		set_transient( 'msc_config_save_error', json_last_error_msg(), 30 );
		set_transient( 'msc_config_save_input', $raw, 30 );
		wp_safe_redirect( add_query_arg( 'msc_saved', '0', wp_get_referer() ) );
		exit;
	}

	$pretty = json_encode(
		json_decode( $raw ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	update_option( 'msc_config', $pretty, false );
	wp_safe_redirect( add_query_arg( 'msc_saved', 'config', wp_get_referer() ) );
	exit;
} );

// ── Admin menu page ───────────────────────────────────────────────────────────

add_action( 'admin_menu', static function (): void {
	add_options_page( 'MS Calculator', 'MS Calculator', 'manage_options', 'ms-calculator', 'msc_admin_page' );
} );

function msc_admin_page(): void {

	$readme = MSC_DIR . 'README.md';
	$md     = is_readable( $readme ) ? file_get_contents( $readme ) : ''; // phpcs:ignore

	echo '<div class="wrap"><h1>MS Calculator</h1>';

	// ── Save feedback ─────────────────────────────────────────────────────────
	if ( isset( $_GET['msc_saved'] ) ) {
		match ( $_GET['msc_saved'] ) {
			'config' => print( '<div class="notice notice-success is-dismissible"><p>✓ Configuration saved.</p></div>' ),
			default  => printf(
				'<div class="notice notice-error is-dismissible"><p>✗ Invalid JSON — %s</p></div>',
				esc_html( get_transient( 'msc_config_save_error' ) ?: 'unknown error' )
			),
		};
	}

	// ── Status row ────────────────────────────────────────────────────────────
	$calculators = msc_config()['calculators'] ?? [];
	$source      = get_option( 'msc_config' ) ? 'Admin UI' : 'File / example';

	echo '<div style="margin:1.5em 0">';
	if ( $calculators ) {
		$ids = implode( ' ', array_map(
			static fn( $id ) => '<code>[ms_calculator id=&quot;' . esc_attr( $id ) . '&quot;]</code>',
			array_keys( $calculators )
		) );
		printf(
			'<div class="notice notice-success inline" style="margin:0"><p>✓ %d calculator(s) — %s <em style="color:#888">(%s)</em></p></div>',
			count( $calculators ), $ids, esc_html( $source )
		);
	} else {
		echo '<div class="notice notice-info inline" style="margin:0"><p>ℹ No calculator configured yet — edit the JSON below.</p></div>';
	}
	echo '</div>';

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION 1 – JSON Config editor
	// ═══════════════════════════════════════════════════════════════════════
	echo '<hr style="margin:2em 0"><h2>Calculator configuration</h2>';
	echo '<p style="color:#555;max-width:700px">Define your calculators in JSON: parameters (fields) and their points, '
		. 'plus the result bands. Saved here, it takes priority over any file on disk. '
		. 'See the <a href="#msc-docs">documentation</a> for the full field reference.</p>';

	// Determine textarea content.
	$db_value     = get_option( 'msc_config', '' );
	$failed_input = get_transient( 'msc_config_save_input' );

	if ( false !== $failed_input ) {
		$textarea_value = $failed_input;
		delete_transient( 'msc_config_save_input' );
	} elseif ( $db_value ) {
		$textarea_value = $db_value;
	} else {
		$path    = apply_filters( 'msc_config_path', MSC_CONFIG );
		$path    = is_readable( $path ) ? $path : MSC_DIR . 'config-default.json';
		$decoded = json_decode( file_get_contents( $path ), true ); // phpcs:ignore
		unset( $decoded['_comment'] );
		$textarea_value = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	$page_url = esc_url( admin_url( 'options-general.php?page=ms-calculator' ) );

	?>
	<form method="post" action="<?php echo $page_url; ?>" style="max-width:860px">
		<?php wp_nonce_field( 'msc_save_config', 'msc_config_nonce' ); ?>
		<textarea
			name="msc_config_json"
			id="msc-config-json"
			rows="28"
			spellcheck="false"
			style="width:100%;font-family:monospace;font-size:13px;line-height:1.6;background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;border:1px solid #555;resize:vertical"
		><?php echo esc_textarea( $textarea_value ); ?></textarea>
		<p style="display:flex;align-items:center;gap:1em;margin-top:.5em">
			<button type="submit" name="msc_save_config" class="button button-primary">Save configuration</button>
			<span id="msc-json-status" style="font-size:13px"></span>
		</p>
	</form>
	<script>
	(function () {
		const ta = document.getElementById('msc-config-json');
		const ms = document.getElementById('msc-json-status');
		if (!ta || !ms) return;
		ta.addEventListener('input', function () {
			try   { JSON.parse(ta.value); ms.textContent = '✓ Valid JSON'; ms.style.color = '#46b450'; }
			catch (e) { ms.textContent = '✗ ' + e.message; ms.style.color = '#dc3232'; }
		});
	})();
	</script>

	<?php

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION 2 – Documentation
	// ═══════════════════════════════════════════════════════════════════════
	echo '<hr style="margin:2em 0" id="msc-docs"><h2>Documentation</h2>';
	echo '<div style="max-width:860px">';
	echo $md ? msc_md_to_html( $md ) : '<p>README.md not found.</p>'; // phpcs:ignore
	echo '</div></div>';
}

// ── Markdown → HTML renderer ──────────────────────────────────────────────────
//
// Strategy: extract code blocks and tables into a placeholder map FIRST,
// run all inline transformations on the remainder, then restore.
// This prevents regexes like bold/links from mangling code content.

function msc_md_to_html( string $md ): string {

	$slots = []; // placeholder_token => html_string
	$idx   = 0;

	$slot = static function ( string $html ) use ( &$slots, &$idx ): string {
		$token          = "\x02SLOT{$idx}\x03";
		$slots[ $token ] = $html;
		$idx++;
		return $token;
	};

	// ── 1. Fenced code blocks  ```lang\n…\n```  ───────────────────────────────
	$md = preg_replace_callback(
		'/^```(\w*)\n([\s\S]*?)^```[ \t]*$/m',
		static function ( array $m ) use ( $slot ): string {
			$lang = $m[1] ? ' class="language-' . esc_attr( $m[1] ) . '"' : '';
			return $slot(
				'<pre style="background:#f6f8fa;border:1px solid #e1e4e8;padding:1em;border-radius:4px;overflow:auto;font-size:13px">'
				. '<code' . $lang . '>' . esc_html( $m[2] ) . '</code></pre>'
			);
		},
		$md
	);

	// ── 2. Inline code  `…`  ──────────────────────────────────────────────────
	$md = preg_replace_callback(
		'/`([^`\n]+)`/',
		static fn( array $m ) => $slot( '<code style="background:#f6f8fa;padding:.2em .4em;border-radius:3px;font-size:.9em">' . esc_html( $m[1] ) . '</code>' ),
		$md
	);

	// ── 3. Tables  | … | \n | --- | \n | … |  ────────────────────────────────
	$md = preg_replace_callback(
		'/^(\|.+\|[ \t]*\n)\|[-| :\t]+\|[ \t]*\n((?:\|.+\|[ \t]*\n?)+)/m',
		static function ( array $m ) use ( $slot ): string {

			$parse_row = static function ( string $row ): array {
				return array_map( 'trim', explode( '|', trim( $row, " \t|\n" ) ) );
			};

			$th_cells = $parse_row( $m[1] );
			$thead    = '<thead><tr>';
			foreach ( $th_cells as $cell ) {
				$thead .= '<th style="text-align:left;padding:.45em .9em;border-bottom:2px solid #ddd;white-space:nowrap">' . $cell . '</th>';
			}
			$thead .= '</tr></thead>';

			$tbody = '<tbody>';
			foreach ( array_filter( explode( "\n", trim( $m[2] ) ) ) as $row ) {
				$tbody .= '<tr>';
				foreach ( $parse_row( $row ) as $cell ) {
					$tbody .= '<td style="padding:.4em .9em;border-bottom:1px solid #eee">' . $cell . '</td>';
				}
				$tbody .= '</tr>';
			}
			$tbody .= '</tbody>';

			return $slot(
				'<table style="border-collapse:collapse;width:100%;margin:1em 0">'
				. $thead . $tbody . '</table>'
			);
		},
		$md
	);

	// ── 4. Headers  ───────────────────────────────────────────────────────────
	$md = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $md );
	$md = preg_replace( '/^## (.+)$/m',  '<h2 style="border-bottom:1px solid #ddd;padding-bottom:.3em;margin-top:1.8em">$1</h2>', $md );
	$md = preg_replace( '/^# (.+)$/m',   '<h1>$1</h1>', $md );

	// ── 5. Inline styles  ─────────────────────────────────────────────────────
	$md = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md );
	$md = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md );
	$md = preg_replace( '/^---$/m', '<hr>', $md );

	// ── 6. Paragraphs  ────────────────────────────────────────────────────────
	$blocks = preg_split( '/\n{2,}/', trim( $md ) );
	$html   = '';
	foreach ( $blocks as $block ) {
		$block = trim( $block );
		if ( '' === $block ) continue;
		if ( preg_match( '/^(<|\x02SLOT)/', $block ) ) {
			$html .= $block . "\n\n";
		} else {
			$html .= '<p style="line-height:1.65">' . nl2br( $block ) . "</p>\n\n";
		}
	}

	// ── 7. Restore placeholders  ──────────────────────────────────────────────
	return str_replace( array_keys( $slots ), array_values( $slots ), $html );
}
