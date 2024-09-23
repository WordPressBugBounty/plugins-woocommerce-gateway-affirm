/**
 * JS for Affirm Admin Order - Partial Capture
 *
 * @package WooCommerce
 */

jQuery( document ).ready( function ( $ ) {
    if ( 0 === $( 'select[name^=action] option[value=wc_capture_charge_affirm]' ).size() ) {
        $( 'select[name^=action]' ).append(
            $( '<option>' ).val( 'wc_capture_charge_affirm' ).text( 'Capture Charge (Affirm)' )
        )
    }
} );
