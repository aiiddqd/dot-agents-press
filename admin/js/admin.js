/* globals jQuery, DAP */
( function ( $ ) {
	'use strict';

	// ── Copy shortcodes on click ─────────────────────────────────────────────
	$( document ).on( 'click', '.dap-shortcode', function () {
		var $el   = $( this );
		var value = $el.data( 'shortcode' ) || $el.text().trim();

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( function () {
				$el.addClass( 'dap-copied' );
				setTimeout( function () { $el.removeClass( 'dap-copied' ); }, 1500 );
			} );
		} else {
			// Fallback
			var $tmp = $( '<textarea>' ).val( value ).appendTo( 'body' ).select();
			document.execCommand( 'copy' );
			$tmp.remove();
			$el.addClass( 'dap-copied' );
			setTimeout( function () { $el.removeClass( 'dap-copied' ); }, 1500 );
		}
	} );

	// ── Auto-generate slug from name ────────────────────────────────────────
	var $name = $( '#dap-name' );
	var $slug = $( '#dap-slug' );

	if ( $name.length && $slug.length ) {
		var slugEdited = $slug.val() !== '';

		$slug.on( 'input', function () {
			slugEdited = $slug.val() !== '';
		} );

		$name.on( 'input', function () {
			if ( slugEdited ) return;
			$slug.val(
				$name.val()
					.toLowerCase()
					.replace( /[^a-z0-9]+/g, '-' )
					.replace( /^-+|-+$/g, '' )
			);
		} );
	}

	// ── Update model datalist when provider changes ──────────────────────────
	var $provider = $( '#dap-provider' );
	var $model    = $( '#dap-model' );

	if ( $provider.length && $model.length && typeof DAP_MODEL_PRESETS !== 'undefined' ) {
		$provider.on( 'change', function () {
			var models  = DAP_MODEL_PRESETS[ $provider.val() ] || [];
			var $list   = $( '#dap-model-list' );

			$list.empty();
			$.each( models, function ( i, m ) {
				$list.append( $( '<option>' ).val( m ) );
			} );

			// Auto-select first model for the provider if the current value is
			// not in the new list.
			if ( models.length && $.inArray( $model.val(), models ) === -1 ) {
				$model.val( models[ 0 ] );
			}
		} );
	}

} )( jQuery );
