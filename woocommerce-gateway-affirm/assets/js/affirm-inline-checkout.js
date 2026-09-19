/**
 * JS for inline checkout
 *
 * @package WooCommerce
 */

jQuery( document ).ready(
	function ( $ ) {
		let affirmInitCheckout = true;

		if (affirmInlineCheckout.affirmInlineEnabled) {
			var checkoutForm = $( 'form.checkout' );

			// WooCommerce only fires `updated_checkout` on the body once its own
			// update_order_review AJAX request has finished recalculating cart
			// totals, including any fee added via woocommerce_cart_calculate_fees.
			// Refreshing here, instead of racing a fixed setTimeout against that
			// same AJAX request, guarantees the amount sent to Affirm always
			// reflects the final, fee-inclusive cart total.
			$( document.body ).on(
				'updated_checkout',
				function () {
					if ($( '#payment_method_affirm' ).prop( 'checked' )) {
						getAffirmInlineCheckoutobject()
					}
				}
			)

			// Cover the initial page-load case the same way, instead of guessing
			// with a fixed timer: force a recalculation and let the listener
			// above pick up the result once it's actually settled. If a
			// recalculation is already in flight (e.g. WooCommerce's own
			// automatic init_checkout trigger), WooCommerce coalesces this into
			// that same request rather than firing a second one.
			if (affirmInlineCheckout.affirmSelected) {
				$( document.body ).trigger( 'update_checkout' );
			}

			let checkoutObject
			let checkoutFormData
			function getAffirmInlineCheckoutobject()
			{
				let formData = checkoutForm.serialize()
				if (checkoutFormData !== formData) {
					$( '.payment_box.payment_method_affirm' ).attr( 'id', 'affirm-inline-checkout' )
					checkoutFormData = formData
					const nonce = '&affirm_checkout_nonce=' + affirmInlineCheckout.affirmInlineEndpointNonce
					$.post(
						affirmInlineCheckout.affirmInlineEndpoint,
						formData + nonce,
						function (response) {
							if (response != checkoutObject) {
								checkoutObject = response
								if (affirmInitCheckout) {
									affirm.ui.ready(
										function () {
											affirm.checkout( checkoutObject )
											affirm.checkout.inline(
												{
													merchant: {
														inline_container: "affirm-inline-checkout"
													}
												}
											);
										}
									)
									affirmInitCheckout = false
								} else {
									affirm.checkout.inline(
										{
											container: "affirm-inline-checkout",
											data: checkoutObject,
										}
									);
								}
							}
						},
					)
				}
			}
		}
	}
)
