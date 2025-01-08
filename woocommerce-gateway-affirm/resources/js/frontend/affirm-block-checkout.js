import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'affirm_data', {} );

/**
 * Constants for canMakePayment function: payment availability check;
 * default max & min values are in WC_Gateway_Affirm class/WooCommerce_Gateway_Affirm class
 */
const min = decodeEntities( settings.min ) * 100 || 5000;
const max = decodeEntities( settings.max ) * 100 || 3000000;


/**
 *  Countries where Affirm is available as a payment option
 */
const available_countries = decodeEntities( settings.countries );


/**
 * Affirm icon for the payment option
 */
const iconAffirm = decodeEntities( settings.icon); 

const defaultLabel = __(
	'Affirm Pay over time',
	'woo-gutenberg-products-block'
);

const label = decodeEntities( settings.title ) || defaultLabel;

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	
	return (
		<section style = {{ width: '100%' }}>
			<PaymentMethodLabel text={ label } /> 
			<img style= {{ float: 'right', marginRight: '1em' } } src = { iconAffirm } />
		</section>
	)
};

/**
 * Content component
 */
 const Content = () => {
	return decodeEntities( settings.description || '' );
};

/**
 * Determine where Affirm payment is available for this cart/order.
 * @param {Object} props Incoming props for the component
 * @param {Object} cartTotals to get cart total info 
 * @param {Object} bilingData to get billing country info
 * 
 * @return {boolean}  True if Affirm payment method should be displayed as a payment option.
 */

 const canMakePayment = ( { cartTotals, billingAddress } ) => {

	let total = Number(cartTotals.total_price) || 0;
	if (min > total) {
		// Order total is less than min amount
		return false;
	} 
	if (max < total) {
		// Order total is more than max amount
		return false;
	}
	if (!available_countries.includes(billingAddress.country) || billingAddress.country == '') {
		// Country is not supported
		return false;
	}
	return true;
};

/**
 * Payment method config object.
 */
const AffirmPaymentMethod = {
	name: "affirm",
	label: <Label />,
	placeOrderButtonLabel: __(
		'Continue with Affirm',
		'woo-gutenberg-products-block'
	),
	content: <Content />,
	edit: <Content />,
	canMakePayment,
	ariaLabel: label
};

registerPaymentMethod( AffirmPaymentMethod );
