/**
 * MS Calculator – frontend scoring engine.
 *
 * No REST calls, no PHP round-trip: the field → points mapping, the result
 * bands, and the optional rule-in threshold travel down as a JSON blob next
 * to the form (see includes/renderer.php). The score updates live as each
 * field is answered — no submit button, no page reload.
 */
(function () {
	'use strict';

	/** Sum the points of the option(s) matching the field's current value(s). */
	function scoreField( field, formData ) {

		if ( field.type === 'boolean' ) {
			return formData.has( field.__name ) ? Number( field.points || 0 ) : 0;
		}

		if ( field.type === 'checkbox-group' ) {
			const selected = formData.getAll( field.__name + '[]' );
			return ( field.options || [] )
				.filter( function ( opt ) { return selected.includes( String( opt.value ?? opt.label ) ); } )
				.reduce( function ( sum, opt ) { return sum + Number( opt.points || 0 ); }, 0 );
		}

		// select | radio
		const value = formData.get( field.__name );
		const match = ( field.options || [] ).find(
			function ( opt ) { return String( opt.value ?? opt.label ) === value; }
		);
		return match ? Number( match.points || 0 ) : 0;
	}

	/** True once every required field has a value in formData. */
	function isComplete( fields, formData ) {
		return fields.every( function ( field ) {
			if ( ! field.required ) return true;
			if ( field.type === 'checkbox-group' ) return true; // optional by nature
			return !! formData.get( field.__name );
		} );
	}

	/** First band whose [min, max] range contains the total (both bounds optional/inclusive). */
	function matchBand( bands, total ) {
		return ( bands || [] ).find( function ( band ) {
			const aboveMin = band.min === undefined || band.min === null || total >= band.min;
			const belowMax = band.max === undefined || band.max === null || total <= band.max;
			return aboveMin && belowMax;
		} ) || null;
	}

	function computeScore( calcId, form, config ) {

		const formData = new FormData( form );
		const fields    = ( config.fields || [] ).map( function ( f ) {
			return Object.assign( {}, f, { __name: calcId + '_' + f.id } );
		} );

		const total = fields.reduce( function ( sum, field ) {
			return sum + scoreField( field, formData );
		}, 0 );

		return {
			total:    total,
			complete: isComplete( fields, formData ),
			band:     matchBand( config.bands, total ),
		};
	}

	function updateResult( calcId, config, result ) {

		const wrap  = document.getElementById( calcId + '_result' );
		if ( ! wrap ) return;

		wrap.querySelector( '[data-msc-result-score]' ).textContent = result.total;

		const badgeEl = wrap.querySelector( '[data-msc-result-badge]' );
		const labelEl = wrap.querySelector( '[data-msc-result-label]' );
		const descEl  = wrap.querySelector( '[data-msc-result-description]' );

		if ( ! result.complete ) {
			badgeEl.hidden      = true;
			labelEl.textContent = 'Fill in all fields to calculate the risk.';
			descEl.textContent  = '';
			return;
		}

		labelEl.textContent = result.band ? result.band.label : '[ placeholder: no band defined for this score ]';
		descEl.textContent  = result.band && result.band.description ? result.band.description : '';

		const ruleIn = config.rule_in;
		if ( ruleIn && result.total >= ruleIn.min_score ) {
			badgeEl.textContent = ruleIn.label || ( 'Score ≥ ' + ruleIn.min_score );
			badgeEl.hidden      = false;
		} else {
			badgeEl.hidden = true;
		}
	}

	document.querySelectorAll( '.msc-calculator' ).forEach( function ( form ) {

		const calcId   = form.dataset.mscId;
		const configEl = document.getElementById( calcId + '_msc-config' );
		if ( ! configEl ) return;

		let config;
		try {
			config = JSON.parse( configEl.textContent );
		} catch ( e ) {
			return;
		}

		const recompute = function () {
			updateResult( calcId, config, computeScore( calcId, form, config ) );
		};

		form.addEventListener( 'change', recompute );
		recompute(); // initial paint (score 0, incomplete)
	} );
})();
