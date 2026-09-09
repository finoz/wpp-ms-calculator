<?php
/**
 * MS Calculator – HTML renderer
 *
 * Generates the calculator markup, reusing the same field conventions as the
 * sibling fnz-forms plugin (.form-group, .form-cta, <label> after <input>)
 * so both plugins pick up the same theme-level styling automatically.
 *
 * Scoring itself happens entirely client-side: the field → points mapping
 * and the result bands are embedded as a JSON blob (script[type=application/json])
 * next to the form, and assets/ms-calculator.js reads it on submit. No server
 * round-trip, no PHP scoring logic to keep in sync.
 */

defined( 'ABSPATH' ) || exit;

// ── Main render function ──────────────────────────────────────────────────────

/**
 * Build and return the complete calculator HTML string.
 *
 * No submit button: the score/result update live as each field is answered
 * (see assets/ms-calculator.js), matching how a clinician fills this in —
 * click through the known values, read the risk once every field is set.
 *
 * @param string $calc_id     The calculator ID from config.
 * @param array  $calculator  The calculator config array.
 */
function msc_render_calculator( string $calc_id, array $calculator ): string {

	$fields = $calculator['fields'] ?? [];
	$title  = $calculator['title']  ?? '';
	$intro  = $calculator['intro']  ?? '';

	// Config consumed by the frontend JS: only what it needs to score the form.
	$scoring_config = wp_json_encode( [
		'fields'  => $fields,
		'bands'   => $calculator['bands']   ?? [],
		'rule_in' => $calculator['rule_in'] ?? null,
	] );

	$html = '<div class="msc-calculator-wrap">' . "\n";

	if ( $title ) {
		$html .= sprintf( '<h3 class="msc-calculator__title">%s</h3>' . "\n", esc_html( $title ) );
	}
	if ( $intro ) {
		$html .= sprintf( '<p class="msc-calculator__intro">%s</p>' . "\n", esc_html( $intro ) );
	}

	$html .= sprintf(
		'<form class="form msc-calculator" id="%1$s_calculator" data-msc-id="%1$s" novalidate>' . "\n",
		esc_attr( $calc_id )
	);

	foreach ( $fields as $field ) {
		$html .= msc_render_field( $calc_id, $field );
	}

	$html .= "</form>\n";

	// Result panel — always visible, filled in live by JS as fields are answered.
	$html .= sprintf( '<div class="msc-result" id="%s_result" data-msc-result aria-live="polite">' . "\n", esc_attr( $calc_id ) );
	$html .= '	<p class="msc-result__score">Score: <span data-msc-result-score>0</span></p>' . "\n";
	$html .= '	<p class="msc-result__badge" data-msc-result-badge hidden></p>' . "\n";
	$html .= '	<p class="msc-result__label" data-msc-result-label>Fill in all fields to calculate the risk.</p>' . "\n";
	$html .= '	<p class="msc-result__description" data-msc-result-description></p>' . "\n";
	$html .= "</div>\n";

	$html .= sprintf(
		'<script type="application/json" id="%s_msc-config">%s</script>' . "\n",
		esc_attr( $calc_id ),
		$scoring_config
	);

	$html .= "</div>\n";

	return $html;
}

// ── Field dispatcher ──────────────────────────────────────────────────────────

function msc_render_field( string $calc_id, array $f ): string {

	$id       = esc_attr( $calc_id . '_' . $f['id'] );
	$name     = $id;
	$label    = esc_html( $f['label'] ?? $f['id'] );
	$type     = $f['type']    ?? 'select';
	$options  = $f['options'] ?? [];
	$req_attr = ! empty( $f['required'] ) ? ' required' : '';

	return match ( $type ) {
		'radio'          => msc_field_radio( $id, $name, $label, $options, $req_attr ),
		'checkbox-group' => msc_field_checkbox_group( $id, $name, $label, $options ),
		'boolean'        => msc_field_boolean( $id, $name, $label, $req_attr ),
		default          => msc_field_select( $id, $name, $label, $options, $req_attr ),
	};
}

// ── Individual field renderers ────────────────────────────────────────────────

/** Single-choice dropdown — e.g. age range. */
function msc_field_select( string $id, string $name, string $label, array $options, string $req ): string {

	$opts = '';
	foreach ( $options as $opt ) {
		$val   = esc_attr( $opt['value'] ?? $opt['label'] );
		$lbl   = esc_html( $opt['label'] ?? $opt['value'] );
		$opts .= "\t\t<option value=\"{$val}\">{$lbl}</option>\n";
	}

	return <<<HTML
<div class="form-group">
	<select id="{$id}" name="{$name}"{$req}>
		<option value="">{$label}</option>
{$opts}	</select>
	<label for="{$id}">{$label}</label>
</div>

HTML;
}

/** Single-choice radio group — e.g. gender. */
function msc_field_radio( string $id, string $name, string $label, array $options, string $req ): string {

	$inputs = '';
	foreach ( $options as $i => $opt ) {
		$val    = esc_attr( $opt['value'] ?? $opt['label'] );
		$lbl    = esc_html( $opt['label'] ?? $opt['value'] );
		$opt_id = esc_attr( $id . '_' . $i );

		$inputs .= <<<HTML
	<label for="{$opt_id}">
		<input type="radio" id="{$opt_id}" name="{$name}" value="{$val}"{$req}>
		<span>{$lbl}</span>
	</label>

HTML;
	}

	return <<<HTML
<div class="form-group form-group--radio" role="group" aria-label="{$label}">
	<span class="form-group__legend">{$label}</span>
{$inputs}</div>

HTML;
}

/** Multi-choice checkbox group — each ticked option adds its own points. */
function msc_field_checkbox_group( string $id, string $name, string $label, array $options ): string {

	$inputs = '';
	foreach ( $options as $i => $opt ) {
		$val    = esc_attr( $opt['value'] ?? $opt['label'] );
		$lbl    = esc_html( $opt['label'] ?? $opt['value'] );
		$opt_id = esc_attr( $id . '_' . $i );

		$inputs .= <<<HTML
	<label for="{$opt_id}">
		<input type="checkbox" id="{$opt_id}" name="{$name}[]" value="{$val}">
		<span>{$lbl}</span>
	</label>

HTML;
	}

	return <<<HTML
<div class="form-group form-group--checkbox-group" role="group" aria-label="{$label}">
	<span class="form-group__legend">{$label}</span>
{$inputs}</div>

HTML;
}

/** Single boolean checkbox — on/off, worth its own points when checked. */
function msc_field_boolean( string $id, string $name, string $label, string $req ): string {
	return <<<HTML
<div class="form-group form-group--boolean">
	<label for="{$id}">
		<input type="checkbox" id="{$id}" name="{$name}"{$req}>
		<span>{$label}</span>
	</label>
</div>

HTML;
}
