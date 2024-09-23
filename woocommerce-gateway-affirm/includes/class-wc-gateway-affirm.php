<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Gateway_Affirm
 * Load Affirm
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

class WC_Gateway_Affirm extends WC_Payment_Gateway {


	/**
	 * Transaction type constants
	 */
	const TRANSACTION_MODE_AUTH_AND_CAPTURE = 'capture';
	const TRANSACTION_MODE_AUTH_ONLY        = 'auth_only';

	/**
	 * Checkout type constants
	 */
	const CHECKOUT_MODE_MODAL    = 'modal';
	const CHECKOUT_MODE_REDIRECT = 'redirect';

	/**
	 * Cancel URL redirects
	 */
	const CANCEL_TO_CART     = 'cancel_to_cart';
	const CANCEL_TO_PAYMENT  = 'cancel_to_payment';
	const CANCEL_TO_CHECKOUT = 'cancel_to_checkout';
	const CANCEL_TO_CUSTOM   = 'cancel_to_custom';

	/**
	 * Promo messaging locations on product page
	 */
	const AFTER_PRODUCT_PRICE = 'after_product_price';
	const AFTER_ADD_TO_CART   = 'after_add_to_cart';

	/**
	 * Minimize & Expand
	 */
	const MINIMIZE = 'minimize';
	const EXPAND   = 'expand';

	/**
	 * Supported Countries
	 */
	const USA = 'USA';
	const CAN = 'CAN';

	/**
	 * Countries where Affirm is available as a payment option
	 */
	const AVAILABLE_COUNTRIES = array( 'US', 'AS', 'GU', 'MP', 'PR', 'VI', 'CA' );

	/**
	 * Error tracker constants
	 */
	// Error types
	const INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
	const TRANSACTION_DECLINED = 'TRANSACTION_DECLINED';
	const INVALID_AMOUNT = 'INVALID AMOUNT';
	
	// Max stack frames to send to endpoint
	const MAX_STACK_FRAMES = 10;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->id   = 'affirm';
		$this->icon = $this->checkout_icon();
		$this->has_fields         = false;
		$this->method_title       = __( 'Affirm', 'woocommerce-gateway-affirm' );
		$this->method_description = sprintf(
		/* translators: 1: html starting code 2: html end code */
			__(
				'Works by sending the customer to %1$sAffirm%2$s to enter their payment information.',
				'woocommerce-gateway-affirm'
			),
			'<a href="http://affirm.com/">',
			'</a>'
		);
		$this->supports = array(
			'products',
			'refunds',
		);

		$this->initFormFields();
		$this->init_settings();

		$this->public_key          = $this->get_option( 'public_key' );
		$this->private_key         = $this->get_option( 'private_key' );
		$this->public_key_ca       = $this->get_option( 'public_key_ca' );
		$this->private_key_ca      = $this->get_option( 'private_key_ca' );
		$this->region              = $this->get_option( 'region' );
		$this->debug               = $this->get_option( 'debug' ) === 'yes';
		$this->title               = $this->get_option( 'title' );
		$this->description         = $this->show_inline(); // $this->get_option( 'inline_messaging', 'yes') === 'yes' ?  ' ' : $this->get_option( 'description' );
		$this->testmode            = $this->get_option( 'testmode' ) === 'yes';
		$this->auth_only_mode      = $this->get_option(
			'transaction_mode'
		) === self::TRANSACTION_MODE_AUTH_ONLY;
		$this->checkout_mode       = $this->get_option( 'checkout_mode' );
		$this->cancel_url          = $this->get_option( 'cancel_url' );
		$this->custom_cancel_url   = $this->get_option( 'custom_cancel_url' );
		$this->promo_id            = $this->get_option( 'promo_id' );
		$this->affirm_color        = $this->get_option( 'affirm_color', 'blue' );
		$this->show_learnmore      = $this->get_option( 'show_learnmore', 'yes' ) === 'yes';
		$this->enhanced_analytics  = $this->get_option(
			'enhanced_analytics',
			'yes'
		) === 'yes';
		$this->show_fee            = $this->get_option( 'show_fee', 'yes' ) === 'yes';
		$this->category_ala        = $this->get_option( 'category_ala', 'yes' ) === 'yes';
		$this->product_ala         = $this->get_option( 'product_ala', 'yes' ) === 'yes';
		$this->product_ala_options = $this->get_option( 'product_ala_options' );
		$this->cart_ala            = $this->get_option(
			'cart_ala',
			'yes'
		) === 'yes';
		$this->min                 = $this->get_option( 'min' );
		$this->max                 = $this->get_option( 'max' );
		$this->inline              = $this->get_option(
			'inline_messaging',
			'yes'
		) === 'yes';
		$this->partial_capture     = $this->get_option(
			'partial_capture',
			'yes'
		) === 'yes';
		$this->use_site_language   = $this->get_option( 'language' );
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		add_action(
			'admin_notices',
			array( $this, 'adminNotices' )
		);
		add_action(
			'admin_notices',
			array( $this, 'affirmBanner' )
		);
		add_action(
			'admin_enqueue_scripts',
			array( $this, 'adminEnqueueScripts' )
		);

		if ( ! $this->isValidForUse() ) {
			return;
		}

		add_action(
			'woocommerce_api_' . strtolower( get_class( $this ) ),
			array( $this, 'handleWcApi' )
		);
		add_action(
			'woocommerce_review_order_before_payment',
			array( $this, 'reviewOrderBeforePayment' )
		);
		add_action(
			'wp_enqueue_scripts',
			array( $this, 'enqueueScripts' )
		);
	}

	/**
	 * Check for the Affirm POST back.
	 *
	 * If the customer completes signing up for the loan,
	 * Affirm has the client browser POST to
	 * https://{$domain}/wc-api/WC_Gateway_Affirm?action=complete_checkout
	 *
	 * The POST includes the checkout_token from
	 * affirm that the server can then use to complete
	 * capturing the payment.
	 * By doing it this way,
	 * it "fits" with the Affirm way of working.
	 *
	 * @throws Exception If checkout token is missing.
	 */
	public function handleWcApi() {

		try {
			$this->log(
				__FUNCTION__,
				'Start redirect for Affirm Auth'
			);
			$transaction_step = 'auth';
			$action = isset( $_GET['action'] ) ?
				wc_clean( wp_unslash( $_GET['action'] ) ) :
				'';
			if ( 'complete_checkout' !== $action ) {
				$this->log(
					__FUNCTION__,
					'Sorry, but that endpoint is not supported.'
				);
				throw new Exception(
					__(
						'Sorry, but that endpoint is not supported.',
						'woocommerce-gateway-affirm'
					)
				);
			}
			// phpcs:ignore
			$checkout_token = isset( $_POST['checkout_token'] ) ?
				// phpcs:ignore
				wc_clean( $_POST['checkout_token'] ) :
				'';
			if ( empty( $checkout_token ) ) {
				$this->log(
					__FUNCTION__,
					'No token was provided by Affirm.'
				);
				throw new Exception(
					__(
						'Checkout failed. No token was provided by Affirm. You may wish to try a different payment method.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			// In case there's an active request that still using session after
			// udpated to 1.0.4. Session fallback can be removed after two releases.
			$order_id = ( ! empty( $_GET['order_id'] ) ) ?
				absint( wc_clean( wp_unslash( $_GET['order_id'] ) ) ) :
				WC()->session->order_awaiting_payment;

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$this->log(
					__FUNCTION__,
					'Order is not available.'
				);
				throw new Exception(
					__(
						'Sorry, but that order is not available. Please try checking out again.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			// TODO: After two releass from 1.0.4, makes order_key a required field.
			if ( ! empty( $_GET['order_key'] )
				&& ! $order->key_is_valid( wp_kses( wp_unslash( $_GET['order_key'] ), array() ) )
			) {
				$this->log(
					__FUNCTION__,
					'Order key is not available.'
				);
				throw new Exception(
					__(
						'Sorry, but that order is not available. Please try checking out again.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			$this->log(
				__FUNCTION__,
				"Processing payment for '.
                    'order {$order_id} with checkout token {$checkout_token}."
			);

			if ( $this->testmode ) {
				$this->log( __FUNCTION__, 'Sandbox mode is enabled' );
			} else {
				$this->log( __FUNCTION__, 'Production mode is enabled' );
			}

			// Authenticate the token with Affirm.
			include_once 'class-wc-gateway-affirm-charge-api.php';
			$charge_api   = new WC_Gateway_Affirm_Charge_API( $this, $order_id );
			$country_code = $this->get_country_by_currency( $order->get_currency() );

			$result = $charge_api->request_charge_id_for_token( $checkout_token, $country_code[1] );
			if ( is_wp_error( $result ) ) {
				$this->log(
					__FUNCTION__,
					'Error in charge authorization: ' . $result->get_error_message()
				);
				throw new Exception(
					__(
						'Checkout failed. Unable to exchange token with Affirm. Please try checking out again later, or try a different payment source.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			$validates         = $result['validates'];
			$charge_id         = $result['charge_id'];
			$amount_validation = $result['amount_validation'];
			$authorized_amount = $result['authorized_amount'];
			$this->log(
				__FUNCTION__,
				"Received charge id {$charge_id} for order {$order_id}."
			);

			if ( ! $validates ) {

				$country_code = $this->get_country_by_currency( $order->get_currency() );
				$charge_api->void_charge( $charge_id, $country_code[1] );
				$this->log(
					__FUNCTION__,
					'Order mismatch for Affirm token.'
				);
				throw new Exception(
					__(
						'Checkout failed. Order mismatch for Affirm token. Please try checking out again later, or try a different payment source.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			if ( ! $amount_validation ) {
				$country_code = $this->get_country_by_currency( $order->get_currency() );
				$charge_api->void_charge( $charge_id, $country_code[1] );
				$order->update_status(
					'cancelled',
					__( 'Affirm total mismatch.', 'woocommerce-gateway-affirm' )
				);
				$this->log(
					__FUNCTION__,
					'Price mismatch'
				);
				throw new Exception(
					__(
						'Checkout failed. Your cart amount has changed since starting your Affirm application. Please try again.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			if ( ! $order->needs_payment() ) {
				$country_code = $this->get_country_by_currency( $order->get_currency() );
				$charge_api->void_charge( $charge_id, $country_code[1] );
				$this->log(
					__FUNCTION__,
					'Order no longer needs payment'
				);
				throw new Exception(
					__(
						'Checkout failed. This order has already been paid.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			// Save the charge ID on the order.
			$this->updateOrderMeta( $order_id, 'charge_id', $charge_id );

			// Save total authorized amount.
			$this->updateOrderMeta( $order_id, 'authorized_amount', $authorized_amount );

			// Add an order meta for partial capture setting enabled.
			if ( $this->partial_capture ) {
				$this->setIsPartialCaptureEnabled( $order );
			}

			// Auth and possibly capture the charge.
			if ( $this->auth_only_mode ) {

				$order->add_order_note(
					sprintf(
					/* translators: 1: charge amount 2: charge id */
						__(
							'Authorized charge of %1$s (charge ID %2$s)',
							'woocommerce-gateway-affirm'
						),
						wc_price( $order->get_total() ),
						$charge_id
					)
				);
				$order->set_transaction_id( $charge_id );
				$this->setOrderAuthOnlyFlag( $order );
				$order->update_status( 'on-hold' );
				$order->save();
				$this->log(
					__FUNCTION__,
					"Info: Auth completed successfully '.
                        'for order id $order_id with '.
                        'token $checkout_token and charge id $charge_id"
				);

				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} else {
				$transaction_step = 'capture';
				if ( ! $this->capture_charge( $order ) ) {
					$this->log(
						__FUNCTION__,
						'Unable to capture'
					);
					throw new Exception(
						__(
							'Checkout failed. Unable to capture charge with Affirm. Please try checking out again later, or try a different payment source.',
							'woocommerce-gateway-affirm'
						)
					);
				}

				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			}
		} catch ( Exception $e ) {
			if ( ! empty( $e ) ) {
				$message = $e->getMessage();
				$this->log( __FUNCTION__, $message );

				// Capture errors reported in capture_charge() so we can ignore them
				$transaction_declined = strpos($message, 'Sorry') !== false || strpos($message, 'Checkout') !== false;

				if ( $transaction_step == 'auth' ) {
					$this->post_affirm_error_tracker(
						$transaction_step,
						isset($order) ? $order : null,
						$transaction_declined ? self::TRANSACTION_DECLINED : self::INTERNAL_SERVER_ERROR,
						$transaction_declined ? null : $e,
						$message
					);
				}
				
				wc_add_notice( $e->getMessage(), 'error' );
				wp_safe_redirect( WC()->cart->get_checkout_url() );
			}
		} // End try().
	}

	/**
	 * Returns the payment gateway id
	 *
	 * @since 1.4.0
	 * @return string payment gateway id
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Return order id
	 *
	 * @param object $order order.
	 *
	 * @return string
	 * @since  1.4.0
	 */
	public function get_order_id( $order ) {
		return version_compare( WC_VERSION, '3.0', '<' ) ? $order->id() : $order->get_id();
	}

	/**
	 * Check order payment method
	 *
	 * @param \WC_Order $order the order object.
	 * @since 1.4.0
	 * @return string payment gateway id
	 */
	public function check_payment_method( $order ) {
		$payment_method = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ? $order->payment_method : $order->get_payment_method();

		return $this->get_id() === $payment_method;
	}

	/**
	 * Return total auth amount
	 *
	 * @param \WC_Order $order the order object.
	 *
	 * @return integer
	 * @since  1.4.0
	 */
	public function get_order_auth_amount( $order ) {
		$order_id = $this->get_order_id( $order );
		$amount   = $this->getOrderMeta( $order_id, 'authorized_amount' ) ? $this->getOrderMeta( $order_id, 'authorized_amount' ) : $order->get_total();
		return intval( $amount );
	}

	/**
	 * Return captured amount
	 *
	 * @param \WC_Order $order the order object.
	 *
	 * @return integer
	 * @since  1.4.0
	 */
	public function get_order_captured_total( $order ) {
		$order_id = $this->get_order_id( $order );
		$amount   = $this->getOrderMeta( $order_id, 'captured_total' ) ? $this->getOrderMeta( $order_id, 'captured_total' ) : 0;
		return intval( $amount );
	}

	/**
	 * Return remaining auth amount
	 *
	 * @param \WC_Order $order the order object.
	 *
	 * @return integer
	 * @since  1.4.0
	 */
	public function get_order_auth_remaining( $order ) {
		$order_id = $this->get_order_id( $order );
		$amount   = $this->get_order_auth_amount( $order ) - $this->get_order_captured_total( $order );
		return intval( $amount );
	}


	/**
	 * Captures a charge on an order
	 *
	 * @param object $order order.
	 * @param mixed  $amount capture amount of either null or a numeric value.
	 *
	 * @return boolean
	 * @since  1.0.0
	 */
	public function capture_charge( $order, $amount = 0 ) {
		try {
			$this->log(
				__FUNCTION__,
				'Start Affirm capture'
			);
			$order = wc_get_order( $order );

			$order_id = $this->get_order_id( $order );

			include_once 'class-wc-gateway-affirm-charge-api.php';
			$charge_api = new WC_Gateway_Affirm_Charge_API( $this, $order_id );

			$charge_id    = $this->getOrderMeta( $order_id, 'charge_id' );
			$country_code = $this->get_country_by_currency( $order->get_currency() );
			$result       = $charge_api->capture_charge( $charge_id, $amount, $country_code[1] );

			if ( false === $result || is_wp_error( $result ) ) {
				$_message = "Error: Unable to capture charge {$amount} with Affirm for order {$order_id} using charge id {$charge_id}";
				$this->log(
					__FUNCTION__,
					$_message
				);
				$this->post_affirm_error_tracker(
					'capture',
					$order,
					self::TRANSACTION_DECLINED,
					null,
					$_message
				);
				return false;
			}

			$fee_amount      = $result['fee'] ? $result['fee'] : $result['fee_amount']; // Affirm provides amounts in cents.
			$captured_amount = $result['captured_amount']; // Affirm provides amounts in cents.
			$event_id        = $result['event_id'];

			// Save the fee amount on the order.
			if ( isset( $fee_amount ) ) {
				$existing_fee_amount = $this->getFee( $order_id );
				$new_fee_amount      = $existing_fee_amount + intval( $fee_amount );
				$this->updateOrderMeta( $order_id, 'fee_amount', $new_fee_amount );
			}

			// Save the captured amount on the order.
			$prev_captured_total = intval( $this->getOrderMeta( $order_id, 'captured_total' ) );
			$new_captured_total  = $captured_amount + $prev_captured_total;
			$this->updateOrderMeta( $order_id, 'captured_total', $new_captured_total );
			$order->add_order_note(
				sprintf(
				/* translators: 1: charge price 2: charge id */
					__(
						'Captured charge of %1$s (charge ID %2$s / event ID %3$s)',
						'woocommerce-gateway-affirm'
					),
					wc_price( $captured_amount / 100 ),
					$charge_id,
					$event_id
				)
			);

			$authorized_amount = intval( $this->get_order_auth_amount( $order ) );
			if ( $authorized_amount === $new_captured_total ) {
					$order->payment_complete( $charge_id );
					$this->clearOrderAuthOnlyFlag( $order );
			} else {
					// Set partially captured flag.
					$this->setPartiallyCapturedFlag( $order );
			}
			$this->log(
				__FUNCTION__,
				"Info: Successfully captured {$captured_amount} for order {$order_id}"
			);

			return true;
		} catch ( Exception $e ) {
			$this->post_affirm_error_tracker(
				'capture',
				$order,
				self::INTERNAL_SERVER_ERROR,
				$e
			);
			throw $e;
		}
	}

	/**
	 * Void a charge in an order.
	 *
	 * @param int|WC_Order $order Order ID or Order object.
	 *
	 * @since  1.0.1
	 * @return bool Returns true when succeed
	 */
	public function void_charge( $order ) {
		try {
			$this->log(
				__FUNCTION__,
				'Start Affirm void'
			);
			if ( ! is_object( $order ) ) {
				$order = wc_get_order( $order );
			}

			$order_id = version_compare(
				WC_VERSION,
				'3.0',
				'<'
			) ?
				$order->id() :
				$order->get_id();

			include_once 'class-wc-gateway-affirm-charge-api.php';
			$charge_api = new WC_Gateway_Affirm_Charge_API( $this, $order_id );

			$charge_id = $this->getOrderMeta( $order_id, 'charge_id' );

			if ( ! $charge_api->voidCharge( $charge_id ) ) {
				/* translators: 1: charge id */
				$order->add_order_note(
					sprintf(
						/* translators: 1: charge id */
						__(
							'Unable to void charge %s',
							'woocommerce-gateway-affirm'
						)
					),
					$charge_id
				);

				$this->log(
					__FUNCTION__,
					"Error: Unable to void charge with Affirm '.
					'for order {$order_id} using charge id {$charge_id}"
				);
				$this->post_affirm_error_tracker(
					'void',
					$order,
					self::TRANSACTION_DECLINED,
					null,
					'Unable to void'
				);

				return false;
			}

			$this->clearOrderAuthOnlyFlag( $order );

			$order->add_order_note(
				sprintf(
				/* translators: 1: charge id */
					__(
						'Authorized charge %s has been voided',
						'woocommerce-gateway-affirm'
					),
					$charge_id
				)
			);

			$this->log(
				__FUNCTION__,
				"Info: Successfully voided {$charge_id} for order {$order_id}"
			);

			return true;	
		} catch ( Exception $e ) {
			$this->post_affirm_error_tracker(
				'void',
				$order,
				self::INTERNAL_SERVER_ERROR,
				$e
			);
			throw $e;
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function initFormFields() {

		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __(
					'Enable/Disable',
					'woocommerce-gateway-affirm'
				),
				'label'       => __(
					'Enable Affirm',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'This controls whether or not this gateway is enabled within WooCommerce.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'title'               => array(
				'title'       => __( 'Title', 'woocommerce-gateway-affirm' ),
				'type'        => 'text',
				'description' => __(
					'This controls the title which the user sees during checkout.',
					'woocommerce-gateway-affirm'
				),
				'default'     => __(
					'Affirm Pay over time',
					'woocommerce-gateway-affirm'
				),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'woocommerce-gateway-affirm' ),
				'type'        => 'text',
				'description' => __(
					'This controls the description which the user sees during checkout.',
					'woocommerce-gateway-affirm'
				),
				'default'     => __(
					'You will be redirected to Affirm to securely complete your purchase. It\'s quick and easy—get a real-time decision!',
					'woocommerce-gateway-affirm'
				),
				'desc_tip'    => true,
			),
			'account_settings'    => array(
				'title'   => __(
					'Account Settings',
					'woocommerce-gateway-affirm'
				),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'expand',
				'options' => array(
					self::MINIMIZE => __(
						'Minimize',
						'woocommerce-gateway-affirm'
					),
					self::EXPAND   => __(
						'Expand',
						'woocommerce-gateway-affirm'
					),
				),
			),
			'testmode'            => array(
				'title'       => __(
					'Affirm Sandbox',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Place the payment gateway in development mode.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'no',
			),
			'region'              => array(
				'title'       => __(
					'Region',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __(
					'Which region are these API keys for',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'USA',
				'options'     => array(
					'USA' => __( 'US', 'woocommerce-gateway-affirm' ),
					'CAN' => __( 'CA', 'woocommerce-gateway-affirm' ),
				),
			),
			'public_key'          => array(
				'title'       => __(
					'Public API Key',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'text',
				'description' => sprintf(
				/* translators: 1: html starting code 2: html end code */
					__(
						'This is the public key assigned by Affirm and available from your %1$smerchant dashboard%2$s .',
						'woocommerce-gateway-affirm'
					),
					'<a target="_blank" href="https://www.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
					'</a>',
					'<a target="_blank" href="https://sandbox.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
					'</a>'
				),
				'default'     => '',
			),
			'private_key'         => array(
				'title'       => __(
					'Private API Key',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'text',
				'description' => sprintf(
				/* translators: 1: html starting code 2: html end code */
					__(
						'This is the private key assigned by Affirm and available from your %1$smerchant dashboard%2$s.',
						'woocommerce-gateway-affirm'
					),
					'<a target="_blank" href="https://www.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
					'</a>',
					'<a target="_blank" href="https://sandbox.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
					'</a>'
				),
				'default'     => '',
			),
			'public_key_ca'       => array(
				'title'       => __(
					'Public API Key',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'text',
				'description' => sprintf(
				/* translators: 1: html starting code 2: html end code */
					__(
						'This is the public key assigned by Affirm and available from your %1$smerchant dashboard%2$s .',
						'woocommerce-gateway-affirm'
					),
					'<a target="_blank" href="https://www.affirm.ca/dashboard/" class="woocommerce_affirm_merchant_dashboard_link_ca">',
					'</a>',
					'<a target="_blank" href="https://sandbox.affirm.ca/dashboard/" class="woocommerce_affirm_merchant_dashboard_link_ca">',
					'</a>'
				),
				'default'     => '',
			),
			'private_key_ca'      => array(
				'title'       => __(
					'Private API Key',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'text',
				'description' => sprintf(
				/* translators: 1: html starting code 2: html end code */
					__(
						'This is the private key assigned by Affirm and available from your %1$smerchant dashboard%2$s.',
						'woocommerce-gateway-affirm'
					),
					'<a target="_blank" href="https://www.affirm.ca/dashboard/" class="woocommerce_affirm_merchant_dashboard_link_ca">',
					'</a>',
					'<a target="_blank" href="https://sandbox.affirm.ca/dashboard/" class="woocommerce_affirm_merchant_dashboard_link_ca">',
					'</a>'
				),
				'default'     => '',
			),
			'language'            => array(
				'title'       => __(
					'Language Selector',
					'woocommerce-gateway-affirm'
				),
				'description' => __(
					'Display Affirm using the browsers language or Wordpress Site language.<br>Note: Affirm Currently only supports the following languages, en_US, en_CA, fr_CA',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'defualt'     => 'site_anguage',
				'options'     => array(
					'site_language'    => __( 'Site Language', 'woocommerce-gateway-affirm' ),
					'browser_language' => __( 'Browser Language', 'woocommerce-gateway-affirm' ),
				),
			),
			'transaction_mode'    => array(
				'title'       => __(
					'Transaction Mode',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __(
					'Select how transactions should be processed.',
					'woocommerce-gateway-affirm'
				),
				'default'     => self::TRANSACTION_MODE_AUTH_AND_CAPTURE,
				'options'     => array(
					self::TRANSACTION_MODE_AUTH_AND_CAPTURE => __(
						'Authorize and Capture',
						'woocommerce-gateway-affirm'
					),
					self::TRANSACTION_MODE_AUTH_ONLY => __(
						'Authorize Only',
						'woocommerce-gateway-affirm'
					),
				),
			),
			'partial_capture'     => array(
				'title'       => __(
					'Enable Partial Capture',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Allow orders to be partially captured multiple times.<br>Note: Please ensure your account is enabled for this feature by reaching out to merchanthelp@affirm.com.<br>Note: Partial capture ONLY available for orders placed in USD.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'no',
			),

			'checkout_mode'       => array(
				'title'       => __(
					'Checkout Mode',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __(
					'Select redirect or modal as checkout mode experience.',
					'woocommerce-gateway-affirm'
				),
				'default'     => self::CHECKOUT_MODE_MODAL,
				'options'     => array(
					self::CHECKOUT_MODE_MODAL    => __(
						'Modal',
						'woocommerce-gateway-affirm'
					),
					self::CHECKOUT_MODE_REDIRECT => __(
						'Redirect',
						'woocommerce-gateway-affirm'
					),
				),
			),
			'inline_messaging'    => array(
				'title'       => __(
					'Inline Checkout Messaging',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Enable/Disable Inline checkout value props on the checkout page when Affirm is selected as a payment method.<br>Note: Value props only shows for orders placed in USD.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'no',
			),
			'cancel_url'          => array(
				'title'       => __(
					'Cancel Affirm Page',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __(
					'Choose where to send user if payment is cancelled',
					'woocommerce-gateway-affirm'
				),
				'default'     => self::CANCEL_TO_CART,
				'options'     => array(
					self::CANCEL_TO_CART     => __(
						'Cart Page',
						'woocommerce-gateway-affirm'
					),
					self::CANCEL_TO_PAYMENT  => __(
						'Payment Page',
						'woocommerce-gateway-affirm'
					),
					self::CANCEL_TO_CHECKOUT => __(
						'Checkout Page',
						'woocommerce-gateway-affirm'
					),
					self::CANCEL_TO_CUSTOM   => __(
						'Custom URL',
						'woocommerce-gateway-affirm'
					),
				),
			),
			'custom_cancel_url'   => array(
				'title'       => __(
					'Permalink/Custom Cancel URL',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'text',
				'description' => __(
					'Specify where to redirect users when Custom URL is selected for when Affirm payment is cancelled  (Use Permalink or URL in the same domain)',
					'woocommerce-gateway-affirm'
				),
				'default'     => '',
			),
			'ala_settings'        => array(
				'title'   => __(
					'Promotional Messaging Settings',
					'woocommerce-gateway-affirm'
				),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'expand',
				'options' => array(
					self::MINIMIZE => __( 'Minimize', 'woocommerce-gateway-affirm' ),
					self::EXPAND   => __( 'Expand', 'woocommerce-gateway-affirm' ),
				),
			),
			'category_ala'        => array(
				'title'       => __(
					'Category Promo Messaging',
					'woocommerce-gateway-affirm'
				),
				'label'       => __(
					'Enable category promotional messaging',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Show promotional messaging at category level pages.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
			),
			'product_ala'         => array(
				'title'       => __(
					'Product Promo Messaging',
					'woocommerce-gateway-affirm'
				),
				'label'       => __(
					'Enable product promotional messaging',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Show promotional messaging at product level pages.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
			),
			'product_ala_options' => array(
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __(
					'Choose where the promotional messaging gets rendered on product page',
					'woocommerce-gateway-affirm'
				),
				'default'     => self::AFTER_PRODUCT_PRICE,
				'options'     => array(
					self::AFTER_PRODUCT_PRICE => __(
						'After product price (Default)',
						'woocommerce-gateway-affirm'
					),
					self::AFTER_ADD_TO_CART   => __(
						'After add to cart',
						'woocommerce-gateway-affirm'
					),
				),
			),
			'cart_ala'            => array(
				'title'       => __(
					'Cart Promo Messaging',
					'woocommerce-gateway-affirm'
				),
				'label'       => __(
					'Enable cart promotional messaging',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Show promotional messaging on cart.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
			),
			'affirm_color'        => array(
				'title'       => __( 'Affirm Color', 'woocommerce-gateway-affirm' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __(
					'Affirm logo/text color on the promotional payment messaging.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'blue',
				'options'     => array(
					'blue'  => __( 'Default', 'woocommerce-gateway-affirm' ),
					'black' => __( 'Black', 'woocommerce-gateway-affirm' ),
					'white' => __( 'White', 'woocommerce-gateway-affirm' ),
				),
			),
			'show_learnmore'      => array(
				'title'       => __( 'Show Learn More', 'woocommerce-gateway-affirm' ),
				'type'        => 'checkbox',
				'description' => __(
					'Show Learn More link in promotional payment messaging.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
			),
			'promo_id'            => array(
				'title'       => __( 'Affirm Promo ID', 'woocommerce-gateway-affirm' ),
				'type'        => 'text',
				'description' => sprintf(
				/* translators: 1: html starting code 2: html end code */
					__(
						'Promo ID is provided by your Affirm technical contact. If present, it will display customized messaging in the rendered marketing components. For more information, please reach out to %1$sAffirm Merchant Help%2$s.',
						'woocommerce-gateway-affirm'
					),
					'<a target="_blank" href="https://docs.affirm.com/Contact_Us">',
					'</a>'
				),
				'default'     => '',
			),
			'advance_settings'    => array(
				'title'   => __(
					'Advance Settings',
					'woocommerce-gateway-affirm'
				),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'minimize',
				'options' => array(
					self::MINIMIZE => __( 'Minimize', 'woocommerce-gateway-affirm' ),
					self::EXPAND   => __( 'Expand', 'woocommerce-gateway-affirm' ),
				),
			),
			'min'                 => array(
				'title'       => __(
					'Order Minimum',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'text',
				'description' => 'Set min amount for Affirm to appear at checkout.  Please ensure amount is greater than or equal to the minimum checkout amount in your Affirm account settings. Please reach out to merchanthelp@affirm.com for additional details.',
				'default'     => '50',
			),
			'max'                 => array(
				'title'       => __( 'Order Maximum', 'woocommerce-gateway-affirm' ),
				'type'        => 'text',
				'description' => 'Set max amount for Affirm to appear at checkout.',
				'default'     => '30000',
			),
			'debug'               => array(
				'title'       => __(
					'Debug',
					'woocommerce-gateway-affirm'
				),
				'label'       => __(
					'Enable debugging messages',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Sends debug messages to the WooCommerce System Status log.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
			),
			'enhanced_analytics'  => array(
				'title'       => __(
					'Enable enhanced analytics',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Enable analytics to optimize Affirm implementation and to maximize conversion rates.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
			),
			'show_fee'            => array(
				'title'       => __(
					'Display Affirm fee',
					'woocommerce-gateway-affirm'
				),
				'type'        => 'checkbox',
				'label'       => __( 'Display merchant fee', 'woocommerce-gateway-affirm' ),
				'description' => __(
					'Display the portion of the captured amount that represents the merchant fee. For any refunds initiated outside of WooCommerce, refunded fee will not be reflected in the shown amount.',
					'woocommerce-gateway-affirm'
				),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Don't even allow administration of this extension if the currency is not
	 * supported.
	 *
	 * @since  1.0.0
	 * @return boolean
	 */
	private function isValidForAdministration() {
		if ( ! $this->supported_currency() ) {
			return false;
		}

		return true;
	}

	/**
	 * Admin Warning Message
	 *
	 * @since  1.0.0
	 */
	public function admin_options() {
		if ( $this->isValidForAdministration() ) {
			parent::admin_options();
		} else {
			?>
			<div class="inline error">
				<p>
					<strong>
						<?php
						esc_html_e(
							'Gateway Disabled',
							'woocommerce-gateway-affirm'
						);
						?>
					</strong>:
					<?php
					esc_html_e(
						'Affirm does not support your store currency.',
						'woocommerce-gateway-affirm'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Check for required settings, and if SSL is enabled
	 *
	 * @return string
	 */
	public function adminNotices() {
		static $admin_notice_displayed = false;
		if ( !$admin_notice_displayed ) {
			$admin_notice_displayed = true;
			if ( 'no' === $this->enabled ) {
				return;
			}

			$general_settings_url  = admin_url(
				'admin.php?page=wc-settings'
			);
			$checkout_settings_url = admin_url(
				'admin.php?page=wc-settings&tab=checkout'
			);
			$affirm_settings_url   = admin_url(
				'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_affirm'
			);
			// @codingStandardsIgnoreStart
			// Check required fields.;
			if ( $this->check_api_key_empty() ) {
				echo '<div class="error"><p>' . sprintf(
					/* translators: 1) HTML anchor open tag 2) HTML anchor closing tag */
					esc_html__(
						'Affirm: One or more of your keys is missing. Please enter your keys %1$shere%2$s.',
						'woocommerce-gateway-affirm'
					),
					'<a href="' . esc_url( $affirm_settings_url ) . '">',
					'</a>'
				) . '</p></div>';
				return;
			}

			// Check for duplicate keys.
			if ( $this->public_key === $this->private_key && strlen($this->public_key) > 1 ) {
				echo '<div class="error"><p>' . esc_html__(
					'Affirm: You have entered the same key in one or more fields. Each key must be unique. Please check and re-enter.',
					'woocommerce-gateway-affirm'
				) . '</p></div>';
				return;
			}

			// Check Currency.
			if ( ! $this->supported_currency() ) {
				echo '<div class="error"><p>' . esc_html__(
					'Affirm: Affirm only supports USD or CAD for currency.',
					'woocommerce-gateway-affirm'
				) . '</p></div>';
				return;
			}

			// Show message if enabled and FORCE SSL is disabled and WordpressHTTPS
			// plugin is not detected.
			if ( ! wc_checkout_is_https() ) {
				echo '<div class="error"><p>' . sprintf(
					/* translators: 1) HTML anchor open tag 2) HTML anchor closing tag */
					esc_html__(
						'Affirm: The %1$sforce SSL option%2$s is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - Affirm will only work in test mode.',
						'woocommerce-gateway-affirm'
					),
					'<a href="' . esc_url( $checkout_settings_url ) . '">',
					'</a>'
				) . '</p></div>';
			}
			// @codingStandardsIgnoreEnd
		}
	}

	/**
	 * Don't allow use of this extension if the currency is not supported or if
	 * setup is incomplete.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Returns true if gateway is valid for use
	 */
	public function isValidForUse() {
		if ( $this->isCurrentPageRequiresSsl() && ! is_ssl() ) {
			return false;
		}

		if ( ! $this->supported_currency() ) {
			return false;
		}

		if ( empty( $this->public_key ) && empty( $this->public_key_ca )  ) {
			return false;
		}

		if ( empty( $this->private_key ) && empty( $this->private_key_ca ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks if current page requires SSL.
	 *
	 * @since 1.0.6
	 *
	 * @return bool Returns true if current page requires SSL
	 */
	public function isCurrentPageRequiresSsl() {
		if ( $this->testmode ) {
			return false;
		}

		return is_checkout();
	}

	/**
	 * Get URLs
	 *
	 * @param object $order order.
	 *
	 * @return  bool
	 * @since   1.0.0
	 * @version 1.0.9
	 */
	public function get_transaction_url( $order ) {
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			return false;
		}

		if ( $this->testmode ) {
			$server = 'sandbox.affirm.com';
		} else {
			$server = 'affirm.com';
		}

		return 'https://' .
			$server .
			'/dashboard/#/details/' .
			urlencode( $transaction_id );
	}

	/**
	 * Affirm only supports US customers
	 *
	 * @return  bool
	 * @since   1.0.0
	 * @version 1.0.9
	 */
	public function is_available() {
		if ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = wc_get_order( $order_id );
			$total    = $order->get_total();
		} else {
			$total = 0;
			if ( WC()->cart ) {
				$total = WC()->cart->cart_contents_total;
			}
		}

		$is_available = ( 'yes' === $this->enabled ) ? true : false;
		if ( ! WC()->customer ) {
			return false;
		}

		$country = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ?
			WC()->customer->get_country() :
			WC()->customer->get_billing_country();
		$min     = $this->get_option( 'min' ) ?: 1;
		$max     = $this->get_option( 'max' ) ?: 300000;

		$available_country = $this::AVAILABLE_COUNTRIES;

		if ( ! in_array( $country, $available_country, true ) && '' !== $country ) {
			if ( is_checkout() ) {
				$this->log(
					__FUNCTION__,
					"Country not Supported, {$country}"
				);	
			}

			$is_available = false;
		} elseif ( $min > $total ) {
			if ( is_checkout() ) {
				$this->log(
					__FUNCTION__,
					'Order total is less than min amount'
				);
			}
			$is_available = false;
		} elseif ( $max < $total ) {
			if ( is_checkout() ) {
				$this->log(
					__FUNCTION__,
					'Order total is more than max amount'
				);
			}
			$is_available = false;
		}
		return $is_available;

	}


	/**
	 * Affirm is different. We can't redirect to their server after validating the
	 * shipping and billing info the user supplies - their Javascript object
	 * needs to do the redirection, but we still want to validate the user info,
	 * so we'll land here when the customer clicks Place Order and after WooCommerce
	 * has validated the customer info and created the order. So, we'll redirect to
	 * ourselves with some query args to prompt us to embed the Affirm JavaScript
	 * bootstrap and an Affirm formatted version of the cart
	 *
	 * @param string $order_id order id.
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public function process_payment( $order_id ) {
		$order        = wc_get_order( $order_id );
		$order_key    = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ?
			$order->order_key :
			$order->get_order_key();
		$query_vars   = WC()->query->get_query_vars();
		$order_pay    = $query_vars['order-pay'];
		$checkout_url = rtrim(
			get_permalink(
				wc_get_page_id( 'checkout' )
			),
			'/'
		);
		$nonce = wp_create_nonce( 'affirm-checkout-order-' . $order_id );
		$redirect_url = add_query_arg(
			array(
				'affirm'    => '1',
				'order_id'  => $order_id,
				'nonce'     => $nonce,
				'key'       => $order_key,
				'cart_hash' => WC()->cart->get_cart_hash(),
			),
			$checkout_url . '/' . $order_pay . '/' . $order_id . '/'
		);

		$this->updateOrderMeta($order_id, 'checkout_nonce', $nonce, true);

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Can the order be refunded via Affirm?
	 *
	 * @param object $order order.
	 *
	 * @return bool
	 */
	public function canRefundOrder( $order ) {
		return (
			$order && (
				$this->issetOrderAuthOnlyFlag( $order ) ||
				$order->get_transaction_id()
			)
		);
	}

	/**
	 * Process a refund if supported
	 *
	 * @param int    $order_id      order id.
	 * @param float  $refund_amount refund amount.
	 * @param string $reason        reason.
	 *
	 * @return boolean|WP_Error
	 */
	public function process_refund( $order_id, $refund_amount = null, $reason = '' ) {
		try {
			$this->log(
				__FUNCTION__,
				"Info: Beginning processing refund/void for order $order_id"
			);
			$transaction_step = 'refund';

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$this->log( __FUNCTION__, "Error: Order {$order_id} could not be found." );
				return new WP_Error(
					'error',
					__(
						'Refund failed: Unable to retrieve order',
						'woocommerce-gateway-affirm'
					)
				);
			}

			$order_total = floatval( $order->get_total() );
			if ( ! $refund_amount ) {
				$refund_amount = $order_total;
			}

			if ( ! $this->canRefundOrder( $order ) ) {
				$this->log(
					__FUNCTION__,
					"Error: Order {$order_id} is not refundable. It was neither authorized nor captured. The customer may have abandoned the order."
				);
				return new WP_Error(
					'error',
					__(
						'Refund failed: The order is not refundable. It was neither authorized nor captured. The customer may have abandoned the order.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			include_once 'class-wc-gateway-affirm-charge-api.php';
			$charge_api = new WC_Gateway_Affirm_Charge_API( $this, $order_id );

			// Only an auth?  Just void and cancel the whole thing.
			if ( $this->issetOrderAuthOnlyFlag( $order ) && ! $this->getPartiallyCapturedFlag( $order ) ) {
				$transaction_step = 'void';
				// Floating point comparison to cents accuracy (Affirm only does USD or CAD).
				$is_a_full_refund = (
						abs( $order_total - $refund_amount ) < 0.01
				);
				if ( ! $is_a_full_refund ) {
					$this->log(
						__FUNCTION__,
						"Error: A partial refund of an '.
							'auth-only order {$order_id} was attempted.'.
							' You cannot partially refund '.
							'an order until it has been captured."
					);
					return new WP_Error(
						'error',
						__(
							'Refund failed: You cannot partially refund an order until it has been captured.',
							'woocommerce-gateway-affirm'
						)
					);
				}

				// Otherwise, proceed.
				$charge_id    = $order->get_transaction_id();
				$country_code = $this->get_country_by_currency( $order->get_currency() );
				$result       = $charge_api->void_charge( $charge_id, $country_code[1] );

				if ( false === $result || is_wp_error( $result ) ) {
					$this->log(
						__FUNCTION__,
						"Error: An error occurred'.
							' while attempting to void order {$order_id}."
					);

					$_message = 'Refund failed: The order had been authorized, and not captured, but voiding the order unexpectedly failed.';
					$this->post_affirm_error_tracker(
						$transaction_step,
						$order,
						self::TRANSACTION_DECLINED,
						null,
						$_message
					);
					return new WP_Error(
						'error',
						__(
							'Refund failed: The order had been authorized, and not captured, but voiding the order unexpectedly failed.',
							'woocommerce-gateway-affirm'
						)
					);
				}

				$order->add_order_note(
					sprintf(
					/* translators: 1: reason for void */
						__( 'Voided - Reason: %s', 'woocommerce-gateway-affirm' ),
						esc_html( $reason )
					)
				);

				$this->clearOrderAuthOnlyFlag( $order );
				$this->log( __FUNCTION__, "Info: Successfully voided order {$order_id}" );

				return true;
			}

			// Otherwise, process a refund.

			$refund_amount_in_cents = intval( 100 * $refund_amount );
			$charge_id              = $order->get_transaction_id();
			$country_code           = $this->get_country_by_currency( $order->get_currency() );
			$result                 = $charge_api->refund_charge( $charge_id, $refund_amount_in_cents, $country_code[1] );

			if ( false === $result || is_wp_error( $result ) ) {
				$this->log(
					__FUNCTION__,
					"Error: An error occurred '.
						'while attempting to refund order {$order_id}."
				);
				$_message = 'Refund failed: The order had been authorized and captured, but refunding the order unexpectedly failed.';
				$this->post_affirm_error_tracker(
					$transaction_step,
					$order,
					self::TRANSACTION_DECLINED,
					null,
					$_message
				);
				return new WP_Error(
					'error',
					__(
						'Refund failed: The order had been authorized and captured, but refunding the order unexpectedly failed.',
						'woocommerce-gateway-affirm'
					)
				);
			}

			// Update fee amount on the order.
			$fee_refunded        = intval( $result['fee_refunded'] );
			$existing_fee_amount = $this->getFee( $order_id );
			$new_fee_amount      = $existing_fee_amount - $fee_refunded;
			$this->updateOrderMeta( $order_id, 'fee_amount', $new_fee_amount );

			$order->add_order_note(
				sprintf(
				/* translators: 1: refund amount 2: refund id 3: reason */
					__(
						'Refunded %1$s - Refund ID: %2$s - Reason: %3$s',
						'woocommerce-gateway-affirm'
					),
					wc_price( 0.01 * $result['amount'] ),
					esc_html( $result['id'] ),
					esc_html( $reason )
				)
			);

			$this->log(
				__FUNCTION__,
				"Info: Successfully refunded {$refund_amount} for order {$order_id}"
			);

			return true;
		} catch ( Exception $e ) {
			$this->post_affirm_error_tracker(
				$transaction_step,
				isset($order) ? $order : null,
				self::INTERNAL_SERVER_ERROR,
				$e
			);
			throw $e;
		}
	}


	/**
	 * We'll hook here to embed the Affirm JavaScript
	 * object bootstrapper into the checkout page
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function reviewOrderBeforePayment() {

		if ( ! $this->isCheckoutAutoPostPage() ) {
			return;
		}

		$order = $this->validateOrderFromRequest();
		if ( false === $order ) {
			wp_die(
				esc_attr(
					__(
						'Checkout using Affirm failed. Please try checking out again later, or try a different payment source.',
						'woocommerce-gateway-affirm'
					)
				)
			);
		}
	}


	/**
	 * If we see the query args indicating
	 * that the Affirm bootstrap and Affirm-formatted cart
	 * is/should be loaded, return true
	 *
	 * @since  1.0.0
	 * @return boolean
	 */
	private function isCheckoutAutoPostPage() {
		if ( ! is_checkout() ) {
			return false;
		}

		if ( ! isset( $_GET['affirm'] )
			|| ! isset( $_GET['order_id'] )
			|| ! isset( $_GET['nonce'] )
		) {
			return false;
		}

		return true;
	}


	/**
	 * Return the appropriate order based on the query args, with nonce protection.
	 *
	 * @since  1.0.0
	 * @return object
	 */
	private function validateOrderFromRequest() {
		if ( empty( $_GET['order_id'] ) ) {
			return false;
		}

		$order_id = wc_clean( wp_unslash( $_GET['order_id'] ) );
		if ( ! is_numeric( $order_id ) ) {
			return false;
		}
		
		$order_id = absint( $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		return $order;
	}

	/**
	 * Encode and enqueue the cart contents for use by Affirm's JavaScript object
	 *
	 * @since   1.0.0
	 * @version 1.0.10
	 *
	 * @return void
	 */
	public function enqueueScripts() {
		if ( ! $this->isCheckoutAutoPostPage() ) {
			return;
		}

		$order = $this->validateOrderFromRequest();
		if ( false === $order ) {
			return;
		}

		$order_id = absint( wc_clean( wp_unslash( $_GET['order_id'] ) ) );
		$nonce = wc_clean( wp_unslash( $_GET['nonce'] ) );
		if ( $this->notValidCheckoutNonce( $order_id, $nonce ) ) {
			return;
		}



		// We made it this far,
		// let's fire up affirm and embed the order data in an affirm friendly way.
		wp_enqueue_script(
			'woocommerce_affirm',
			plugins_url(
				'assets/js/affirm-checkout.js',
				dirname( __FILE__ )
			),
			array( 'jquery', 'jquery-blockui' ),
			WC_GATEWAY_AFFIRM_VERSION,
			true
		);

		$order_id  = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ? $order->id : $order->get_id();
		$order_key = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ? $order->order_key : $order->get_order_key();

		$confirmation_url = add_query_arg(
			array(
				'action'    => 'complete_checkout',
				'order_id'  => $order_id,
				'order_key' => $order_key,
			),
			WC()->api_request_url( get_class( $this ) )
		);

		if ( self::CANCEL_TO_CART === $this->cancel_url ) {
			$cancel_url = html_entity_decode( $order->get_cancel_order_url() );
		} elseif ( self::CANCEL_TO_PAYMENT === $this->cancel_url ) {
			$cancel_url = html_entity_decode( $order->get_checkout_payment_url() );
		} elseif ( self::CANCEL_TO_CUSTOM === $this->cancel_url
			&& '' !== $this->custom_cancel_url
		) {
			$custom_url = $this->custom_cancel_url;
			$cancel_url = html_entity_decode(
				$order->get_cancel_order_url( html_entity_decode( $custom_url ) )
			);
		} else {
			$cancel_url = wc_get_checkout_url();
		}

		$total_discount = floor( 100 * $order->get_total_discount() );
		$total_tax      = floor( 100 * $order->get_total_tax() );
		$total_shipping = version_compare( WC_VERSION, '3.0', '<' ) ?
			$order->get_total_shipping() :
			$order->get_shipping_total();
		$total_shipping = ! empty( $order->get_shipping_method() ) ?
			floor( 100 * $total_shipping ) :
			0;
		$total          = floor( strval( 100 * $order->get_total() ) );

		$affirm_data = array(
			'merchant'        => array(
				'user_confirmation_url' => $confirmation_url,
				'user_cancel_url'       => $cancel_url,
			),
			'items'           => $this->getItemsFormattedForAffirm( $order ),
			'discounts'       => array(
				'discount' => array(
					'discount_amount' => $total_discount,
				),
			),
			'metadata'        => array(
				'order_key'        => $order_key,
				'platform_type'    => 'WooCommerce',
				'platform_version' => WOOCOMMERCE_VERSION,
				'platform_affirm'  => WC_GATEWAY_AFFIRM_VERSION,
				'mode'             => $this->checkout_mode,
			),
			'tax_amount'      => $total_tax,
			'shipping_amount' => $total_shipping,
			'total'           => $total,
			'order_id'        => $order_id,
		);

		$old_wc = version_compare( WC_VERSION, '3.0', '<' );

		$affirm_data += array(
			'currency' => $old_wc ?
				$order->get_order_currency() :
				$order->get_currency(),
			'billing'  => array(
				'name'         => array(
					'first' => $old_wc ?
						$order->billing_first_name :
						$order->get_billing_first_name(),
					'last'  => $old_wc ?
						$order->billing_last_name :
						$order->get_billing_last_name(),
				),
				'address'      => array(
					'street1'      => $old_wc ?
						$order->billing_address_1 :
						$order->get_billing_address_1(),
					'street2'      => $old_wc ?
						$order->billing_address_2 :
						$order->get_billing_address_2(),
					'city'         => $old_wc ?
						$order->billing_city :
						$order->get_billing_city(),
					'region1_code' => $old_wc ?
						$order->billing_state :
						$order->get_billing_state(),
					'postal_code'  => $old_wc ?
						$order->billing_postcode :
						$order->get_billing_postcode(),
					'country'      => $old_wc ?
						$order->billing_country :
						$order->get_billing_country(),
				),
				'email'        => $old_wc ?
					$order->billing_email :
					$order->get_billing_email(),
				'phone_number' => $old_wc ?
					$order->billing_phone :
					$order->get_billing_phone(),
			),
			'shipping' => array(
				'name'    => array(
					'first' => $old_wc ?
						$order->shipping_first_name :
						$order->get_shipping_first_name(),
					'last'  => $old_wc ?
						$order->shipping_last_name :
						$order->get_shipping_last_name(),
				),
				'address' => array(
					'street1'      => $old_wc ?
						$order->shipping_address_1 :
						$order->get_shipping_address_1(),
					'street2'      => $old_wc ?
						$order->shipping_address_2 :
						$order->get_shipping_address_2(),
					'city'         => $old_wc ?
						$order->shipping_city :
						$order->get_shipping_city(),
					'region1_code' => $old_wc ?
						$order->shipping_state :
						$order->get_shipping_state(),
					'postal_code'  => $old_wc ?
							$order->shipping_postcode :
							$order->get_shipping_postcode(),
					'country'      => $old_wc ?
						$order->shipping_country :
						$order->get_shipping_country(),
				),
			),
		);

		/**
		 * If for some reason shipping info is empty (e.g. shipping is disabled),
		 * use billing address.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-affirm/issues/81#event-1109051257
		 */
		foreach ( array( 'name', 'address' ) as $field ) {
			$shipping_field = array_filter( $affirm_data['shipping'][ $field ] );
			if ( empty( $shipping_field ) ) {
				$affirm_data['shipping'][ $field ]
					= $affirm_data['billing'][ $field ];
			}
		}

		wp_localize_script(
			'woocommerce_affirm',
			'affirmData',
			// Initiate Affirm checkout data.
			apply_filters(
				'wc_gateway_affirm_initiate_checkout_data',
				$affirm_data
			)
		);
	}

	/**
	 * Helper to encode the items in the cart for use by Affirm's JavaScript object
	 *
	 * @param object $order order.
	 *
	 * @return array
	 * @since  1.0.0
	 */
	private function getItemsFormattedForAffirm( $order ) {

		$items = array();

		foreach ( (array) $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$display_name   = $item->get_name();
			$sku            = '';
			$unit_price     = 0;
			$qty            = $item->get_quantity();
			$item_image_url = wc_placeholder_img_src();
			$item_url       = '';

			if ( 'fee' === $item['type'] ) {

				$unit_price = $item['line_total'];

			} else {
				$product    = $item->get_product();
				$sku        = $this->clean( $product->get_sku() );
				$unit_price = floor(
					100.0 * $order->get_item_subtotal( $item, false )
				); // cents please.

				$item_image_id    = $product->get_image_id();
				$image_attributes = wp_get_attachment_image_src( $item_image_id );
				if ( is_array( $image_attributes ) ) {
					$item_image_url = $image_attributes[0];
				}

				$item_url = $product->get_permalink();

			}

			$items[] = array(
				'display_name'   => $display_name,
				'sku'            => $sku ? $sku : $product->get_id(),
				'unit_price'     => $unit_price,
				'qty'            => $qty,
				'item_image_url' => $item_image_url,
				'item_url'       => $item_url,
			);

		} // End foreach().

		return $items;

	}

	/**
	 * Helper to enqueue admin scripts
	 *
	 * @param object $hook hook.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function adminEnqueueScripts( $hook ) {

		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		if ( ! isset( $_GET['section'] ) ) {
			return;
		}

		if ( 'wc_gateway_affirm' === wc_clean( wp_unslash( $_GET['section'] ) )
			|| 'affirm' === wc_clean( wp_unslash( $_GET['section'] ) )
		) {

			wp_register_script(
				'woocommerce_affirm_admin',
				plugins_url(
					'assets/js/affirm-admin.js',
					dirname( __FILE__ )
				),
				array( 'jquery' ),
				WC_GATEWAY_AFFIRM_VERSION,
				false
			);

			$admin_array = array(
				'sandboxedApiKeysURI' =>
					'https://sandbox.affirm.com/dashboard/#/apikeys',
				'apiKeysURI'          =>
					'https://affirm.com/dashboard/#/apikeys',
			);

			wp_localize_script(
				'woocommerce_affirm_admin',
				'affirmAdminData',
				$admin_array
			);
			wp_enqueue_script( 'woocommerce_affirm_admin' );
		}
	}


	/**
	 * Helper methods to check order auth flag
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function issetOrderAuthOnlyFlag( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->getOrderMeta( $order_id, 'authorized_only' );
	}

	/**
	 * Helper methods to set order auth flag
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function setOrderAuthOnlyFlag( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->updateOrderMeta( $order_id, 'authorized_only', true );
	}

	/**
	 * Helper methods to clear order auth flag
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function clearOrderAuthOnlyFlag( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->deleteOrderMeta( $order_id, 'authorized_only' );
	}

	/**
	 * Helper methods to set if the order is created with partial capture enabled setting
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function setIsPartialCaptureEnabled( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->updateOrderMeta( $order_id, 'partial_capture_enabled', true );
	}

	/**
	 * Helper methods to determine if the order is created with partial capture enabled setting
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function getIsPartialCaptureEnabled( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->getOrderMeta( $order_id, 'partial_capture_enabled' );
	}

	/**
	 * Helper methods to set partially captured flag once the order is at least partially captured
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function setPartiallyCapturedFlag( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->updateOrderMeta( $order_id, 'partially_captured', true );
	}

	/**
	 * Helper methods to get partially captured flag
	 *
	 * @param object $order order.
	 *
	 * @return bool|int
	 */
	public function getPartiallyCapturedFlag( $order ) {
		$order_id = $this->get_order_id( $order );
		return $this->getOrderMeta( $order_id, 'partially_captured' );
	}

	/**
	 * Helper methods to get Affirm fee from order metadata
	 *
	 * @param string $order_id order id.
	 *
	 * @return bool|int
	 */
	public function getFee( $order_id ) {
		return $this->getOrderMeta( $order_id, 'fee_amount' ) ? intval( $this->getOrderMeta( $order_id, 'fee_amount' ) ) : 0;
	}

	/**
	 * Helper methods to update order meta with scoping for this extension
	 *
	 * @param string $order_id order id.
	 * @param string $key      key.
	 * @param string $value    value.
	 *
	 * @return bool|int
	 */
	public function updateOrderMeta( $order_id, $key, $value ) {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$order = wc_get_order( $order_id );
			$order->update_meta_data( "_wc_gateway_{$this->id}_{$key}", $value );
			$update = $order->save();
			return $update;
		} else {
			return update_post_meta( $order_id, "_wc_gateway_{$this->id}_{$key}", $value );
		}
	}

	/**
	 * Helper methods to get order meta with scoping for this extension
	 *
	 * @param string $order_id order id.
	 * @param string $key      key.
	 *
	 * @return bool|int
	 */
	public function getOrderMeta( $order_id, $key ) {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$order = wc_get_order( $order_id );
			return $order->get_meta("_wc_gateway_{$this->id}_{$key}");
		} else {
			return get_post_meta( $order_id, "_wc_gateway_{$this->id}_{$key}", true );
		}
	}

	/**
	 * Helper methods to delete order meta with scoping for this extension
	 *
	 * @param string $order_id order id.
	 * @param string $key      key.
	 *
	 * @return bool|int
	 */
	public function deleteOrderMeta( $order_id, $key ) {
		return delete_post_meta( $order_id, "_wc_gateway_{$this->id}_{$key}" );
	}

	/**
	 * Logs action
	 *
	 * @param string $context context.
	 * @param string $message message.
	 *
	 * @return void
	 */
	public function log( $context, $message ) {
		if ( $this->debug ) {
			if ( empty( $this->log ) ) {
				$this->log = new WC_Logger();
			}

			$this->log->add(
				'woocommerce-gateway-' . $this->id,
				$context . ' - ' . $message
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:disable WordPress.PHP.DevelopmentFunctions
				error_log( $context . ' - ' . $message );
			}
		}
	}

	/**
	 * Removes all special characters
	 *
	 * @param string $sku sku.
	 *
	 * @return string
	 */
	private function clean( $sku ) {
		$sku = str_replace( ' ', '-', $sku ); // Replaces all spaces with hyphens.
		return preg_replace( '/[^A-Za-z0-9\-]/', '', $sku ); // Removes special chars.
	}

	/**
	 * Changes Affirm icon on checkout
	 *
	 * @return string
	 */
	private function checkout_icon() {
		if ( $this->get_option( 'affirm_color' ) === 'white' ) {
			return plugin_dir_url( __DIR__ ) . 'assets/images/white_logo-transparent_bg.png';
		} elseif ( $this->get_option( 'affirm_color' ) === 'black' ) {
			return plugin_dir_url( __DIR__ ) . 'assets/images/all_black_logo-transparent_bg.png';
		} else {
			return plugin_dir_url( __DIR__ ) . 'assets/images/blue_logo-transparent_bg.png';
		}
	}

	/**
	 * Map currency to coutnry code
	 *
	 * @param string $currency_code currency_code.
	 *
	 * @return string
	 */
	public function get_country_by_currency( $currency_code ) {
		$c_map = array(
			'USD' => array( 'US', 'USA' ),
			'CAD' => array( 'CA', 'CAN' ),
		);

		return $c_map[ $currency_code ];
	}

	/**
	 * Check supported currency
	 */
	public function supported_currency() {
		$currency = array( 'USD', 'CAD' );
		if ( in_array( get_woocommerce_currency(), $currency, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get Keys based on region.
	 *
	 *  @param string $type type.
	 *  @param string $country_code country_code.
	 *
	 *  @return string
	 */
	public function get_key( $type, $country_code ) {
		if ( self::CAN === $country_code ) {
			return $this->get_option( $type . '_key_ca' );
		} else {
			return $this->get_option( $type . '_key' );
		}
	}

	/**
	 * Show inline checkout.
	 *
	 *  @return string
	 */
	private function show_inline() {
		$inline_enabled   = $this->get_option( 'inline_messaging', 'yes' ) === 'yes';
		$currency_enabled = get_woocommerce_currency() === 'USD';

		if ( ! $currency_enabled ) {
			return $this->get_option( 'description' );
		}

		if ( ! $inline_enabled ) {
			return $this->get_option( 'description' );
		}

		return ' ';
	}

	/**
	 * Check if any of the API keys are empty
	 *
	 *  @return boolean
	 */
	private function check_api_key_empty() {
		$us_public = $this->public_key;
		$us_private = $this->private_key;
		$ca_public = $this->public_key_ca;
		$ca_private =  $this->private_key_ca;

		if ( empty($us_public) && empty($us_private) && empty($ca_public) && empty($ca_private)  ) {
			return true;
		}
		
		if ( (isset($us_public) || isset($us_private)) && strlen($us_public) > 1 || strlen($us_private) > 1 ) {
			if( empty($us_public) || empty($us_private) ) {
				return true;
			}
		}

		if ( (isset($ca_public) || isset($ca_private) ) && strlen($ca_public) > 1 || strlen($ca_private) > 1 ) {
			if( empty($ca_public) || empty($ca_private) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if nonce in URL is the same as in checkout meta data
	 *
	 *  @return boolean
	 */
	private function notValidCheckoutNonce($order_id, $nonce) {
		$checkout_nonce =  $this->getOrderMeta($order_id, 'checkout_nonce');
		return $checkout_nonce != $nonce;
	}

	public function affirmBanner(){
		static $banner_displayed = false;
		$enabled = $this->enabled  === 'yes' ? true : false;
		if ( !$banner_displayed ) {
			if ( $this->check_api_key_empty() || !$enabled ) {
				?>
					<div class='error' id='affirm_activation_banner' style='background-color: black;'>
						<style>
							.affirm_parent {
								border: 1px solid black;
								margin: 1rem;
								text-align: left;
							}

							.affirm_child_l1 {
								display: inline-block;
								padding: 1rem 1rem;
								vertical-align: middle;
								color: #ffffff;
								width: 70%;
							}

							.affirm_child {
								display: inline-block;
								padding: 1rem 1rem;
								vertical-align: middle;
								width: 45%;
								color: #ffffff;
								front-size: 16px
							}

							.affirm_child_img {
								display: inline-block;
								padding: 1rem 1rem;
								vertical-align: middle;
								width: 20%
							}
						</style>
						<div class='affirm_parent'>
							<div style='color:white; float:right; cursor:pointer' onclick='hide_affirm_activation_banner()'>X</div>
                            <div class='affirm_child_img'>
                                <img style='width:100%; display: inline-block' src='<?php echo esc_url( plugin_dir_url(__DIR__) . 'assets/images/affirm_goodies.png' ) ?>' />
                            </div>
                            <div class='affirm_child_l1'>
                                <p style='color:#ffffff; font-size:20px; padding-left:16px; font-weight:600'>Launch <img style='height:24px; display: inline-block' src='<?php echo esc_url( plugin_dir_url(__DIR__) . 'assets/images/affirm_logo_white.png' ) ?>'/> in just 2 steps</p>
                                <div class='affirm_child'>
                                    <h2 style='color: #ffffff; font-weight:700'>1. Apply for Affirm</h2>
                                    <p>If you haven’t already signed up for Affirm, you must first apply for a merchant account to receive your API keys.</p>
                                    <a href='https://www.affirm.com/business/partners/woocommerce?utm_source=WooCommerce&utm_medium=partner&utm_campaign=woocommerce_product' target='_blank'><img style='display: inline-block; height: 30px' src='<?php echo esc_url( plugin_dir_url(__DIR__) . 'assets/images/affirm_link_out.png' ) ?>' /></a>
                                </div>
                                <div class='affirm_child'>
                                    <h2 style='color: #ffffff; font-weight:700'>2. Enter your Affirm API keys</h2>
                                    <p>Use the API keys found in your <a style='color: #FFCA61' href='https://www.affirm.com/dashboard/' target='_blank'>Affirm merchant dashboard</a>  for the plugin on the WooCommerce settings page.
                                    </p>
                                    <br>
                                    <a  href='/wp-admin/admin.php?page=wc-settings&tab=checkout&section=affirm'><img style='display: inline-block; height: 30px' src='<?php echo esc_url( plugin_dir_url(__DIR__) . 'assets/images/affirm_enter_api_key.png' ) ?>' /></a>
                                </div>
                            </div>
						</div>
					</div>
					<script>
                        sessionStorage.hide_affirm_activation_banner ? hide_affirm_activation_banner() : false;

                        function hide_affirm_activation_banner() {
                            const banner = document.getElementById('affirm_activation_banner');
                            banner.style.display = 'none'
                            sessionStorage['hide_affirm_activation_banner'] = true;
                        }
					</script>
				<?php
				$banner_displayed = true;		
			}
		}
	}

	/**
	 * Helper to POST json data of an error to Affirm
	 *
	 * @param string $transaction_step The transaction step that the error occurred on
	 * @param WC_Order $order order
	 * @param string $error_type What kind of error occurred
	 * @param string $error_message The error message, defaults to error_type if none
	 * @param Exception $exception The uncaught exception (optional)
	 *
	 * @since  2.1.1
	 * @return string
	 */
	protected function post_affirm_error_tracker(
		$transaction_step,
		$order=null,
		$error_type=self::INTERNAL_SERVER_ERROR,
		$exception=null,
		$error_message=''
	) {
		if ( $this->testmode ) {
			$server = 'https://api.global-sandbox.affirm.com/';
		} else {
			$server = 'https://api.global.affirm.com/';
		}
		$url = $server . 'api/v1/partnersolutions/platform/tracker';

		if ($order) {
			$country_code = $this->get_country_by_currency( $order->get_currency() )[1];
		} else {
			$country_code = empty( $this->public_key ) ? self::CAN : self::USA;
		}
		
		$options = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization'   => 'Basic ' . base64_encode(
					$this->get_key(
						'public',
						$country_code
					) . ':' . $this->get_key(
						'private',
						$country_code
					)
				),
				'Content-Type'    => 'application/json',
				'Country-Code'    => $country_code,
			),
			'blocking' => false,
			'timeout' => 0.01,
		);

		// Format body
		$body = array(
			'extension_data' => array(
				'platform' => 'woocommerce',
				'environment' => $this->testmode ? 'sandbox' : 'live',
				'language' => 'php',
				'code_version'=> phpversion(),
				'extension_version' => WC_GATEWAY_AFFIRM_VERSION,
				'platform_version' => WC_VERSION
			),
			'transaction_step'=>$transaction_step
		);
		if ( is_null($exception) ) {
			$body['error_data'] = array(
				'error_type' => $error_type,
				'error_message' => $error_message ? $error_message : $error_type
			);
		} else {
			$body['error_data'] = array(
				'error_type'=>$error_type,
                'error_message'=>$error_message ? $error_message : $exception->getMessage(),
                'error_class'=>get_class($exception),
                'trace'=>$this->format_stack_traces($exception)
			);
		}
		$options['body'] = wp_json_encode( $body );

		return wp_safe_remote_post( $url, $options );
	}

	/**
     * Format the stack traces for the error tracker endpoint
     * @param \Exception $exception
     * @return array
     */
    private function format_stack_traces(Exception $exception)
    {
        $frames = array_slice($exception->getTrace(), 0, self::MAX_STACK_FRAMES);

        $trace = [];
        foreach ($frames as $frame) {
            array_push($trace, array(
                "filename"=>$frame['file'],
                "lineno"=>$frame['line'],
                "method"=>$frame['function']
			));
        }

        return $trace;
    }
}
