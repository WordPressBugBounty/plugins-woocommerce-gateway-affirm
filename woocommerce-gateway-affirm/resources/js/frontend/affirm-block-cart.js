import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
const { registerPlugin } = wp.plugins;
const { ExperimentalOrderMeta } = wc.blocksCheckout;

/**
 * Data passed to the frontend from the server-side:
 *
 */ 
const settings = getSetting( 'wc-affirm-block-cart_data', {} );
const affirmColor = decodeEntities( settings.affirmColor);
const learnmore = decodeEntities( settings.learnmore);
const script_url= decodeEntities( settings.script_url);
const public_key = decodeEntities( settings.public_key);
const public_key_ca = decodeEntities( settings.public_key_ca);
const enabled_gateway = decodeEntities( settings.enabled);
const valid_use = decodeEntities( settings.valid_use);
const cart_ala = decodeEntities( settings.cart_ala);
const language_selector = decodeEntities( settings.language_selector);
let site_locale = decodeEntities( settings.site_locale);


//  if affirm.js is already embedded in a component 
let affirm_embedded = false;

let country_code;
let public_api_key = '';
let locale = '';

/**
 * Map currency to country code for available countries
 *
 * @param  string currency_code
 *
 * @return array
 */
function get_country_by_currency(currency_code) {
	const c_map = { 
		'USD': ['US', 'USA'],
		'CAD': ['CA', 'CAN']
	};
	return c_map[ currency_code ];
}

/**
 * Helper function to get public key based on country code.
 *
 *  @param string country_code
 *
 *  @return string
 */
function get_public_key( country_code ) {
	if ( 'CAN' === country_code ) {
		return public_key_ca;
	} else {
		return public_key;
	}
}

/**
 * Helper function to get locale 
 * 
 * @param string currency 
 * @returns string locale
 */
function get_locale( currency ) {
	let locale = 'en_US';
	if ( 'USD' !== currency ) {
		if ( 'site_language' === language_selector ) {
			if ( site_locale === locale ) {
				locale = 'en_CA';
			}
			
		// if language_selector = 'browser_language'
		} else {
			let language = browserLocaleLanguage();
			site_locale = language + '_' + country_code[0];
			if ( site_locale === locale ) {
				locale = 'en_CA';
			}
		}
	}
	return locale;
}

/**
 * Helper function that returns whether the affirm.js script should be enqueued in a component or not.
 * 
 * @return boolean
 */

function possibly_enqueue_affirm_script() {
	if (!enabled_gateway) {
		return false;
	} 
	if (!valid_use) {
		return false;
	} 
	if (!cart_ala) {
		return false;
	} 
	return true;
}

/**
 * Affirm.js runtime script enqueuing
 * 
 */
function affirm_js_cartblock() {
	if ('undefined' === typeof _affirm_config) {
		var _affirm_config = {
			public_api_key: public_api_key,
			script: script_url,
			locale: locale,
			country_code: country_code,
		};
		(function(l, g, m, e, a, f, b) {
			var d, c = l[m] || {},
				h = document.createElement(f),
				n = document.getElementsByTagName(f)[0],
				k = function(a, b, c) {
					return function() {
						a[b]._.push([c, arguments])
					}
				};
			c[e] = k(c, e, "set");
			d = c[e];
			c[a] = {};
			c[a]._ = [];
			d._ = [];
			c[a][b] = k(c, a, b);
			a = 0;
			for (
				b = "set add save post open " +
					"empty reset on off trigger ready setProduct"
					.split(" ");
				a < b.length; a++
			) d[b[a]] = k(c, e, b[a]);
			a = 0;
			for (b = ["get", "token", "url", "items"]; a < b.length; a++)
				d[b[a]] = function() {};
			h.async = !0;
			h.src = g[f];
			n.parentNode.insertBefore(h, n);
			delete g[f];
			d(g);
			l[m] = c
		})(
			window,
			_affirm_config,
			"affirm",
			"checkout",
			"ui",
			"script",
			"ready"
		);
	}
}

/**
 * 
 * @returns User Browser language 
 */
function browserLocaleLanguage() {
	const browserLocale = navigator.languages && navigator.languages.length
    ? navigator.languages[0] : navigator.language;
	return browserLanguage.substring(0,2);
}

/**
 * ExperimentalOrderMeta is a fill that will render in a slot below the Order summary section in the Cart and Checkout blocks.
 * The ExperimentalOrderMeta will automatically pass props to its top level child:
 * cart - which contains cart data
 * extensions - which contains data registered with ExtendSchema::class in wc/store/cart endpoint
 * context - equal to the name of the Block in which the fill is rendered:
 * woocommerce/cart and woocommerce/checkout
 */
 
 const AffirmALAComponent = ( { cart, context  } ) => {
	const total = cart.cartTotals.total_price;
	
	// Renders promo message only for cart page, and does not render for checkout page
	if (context != 'woocommerce/cart')  {
		return <p></p>;
	};

	let currency = cart.cartTotals.currency_code;
	country_code = get_country_by_currency(currency);
	
	// Promo is not rendered if country_code is not CAN or USA 
	if (!country_code) {
		return <p></p>;
	}

	/**
	 *  Promo is not rendered:
	 *  if affirm plugin doesn't enabled, 
	 *  if promo on cart page doesn't enabled,
	 *  if gateway is not valid for use 
	 * 
	 *  */ 
	if (!possibly_enqueue_affirm_script()) {
		return <p></p>;
	};

	locale = get_locale(currency);
	public_api_key = get_public_key(country_code);

	if (!affirm_embedded) {
		affirm_js_cartblock();	
		affirm_embedded = true;
	} else {
		affirm.ui.ready(function(){
			affirm.ui.refresh();
		});
	}

	return (
		<p 
			className="class-affirm-ala-cart-block affirm-as-low-as" 
			data-amount={ total } 
			data-affirm-color={ affirmColor } 
			data-learnmore-show={ learnmore } 
			data-page-type="cart"> 
		</p>
		);
 };
 
 const render = () => {
	 return (
		 <ExperimentalOrderMeta>
			 <AffirmALAComponent />
		 </ExperimentalOrderMeta>
	 );
 };
 
 registerPlugin( 'wc-affirm-block-cart', {
	 render,
	 scope: 'woocommerce-checkout',
 } );

