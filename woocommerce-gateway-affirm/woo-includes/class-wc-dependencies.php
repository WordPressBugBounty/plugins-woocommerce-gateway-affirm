<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WC Dependency Checker
 *
 * Checks if WooCommerce is enabled
 *
 * PHP version 7.2
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */
class WC_Dependencies {

	/**
	 * Active_plugins.
	 *
	 * @var active_plugins
	 */
	private static $active_plugins;

	/**
	 * Init
	 *
	 * @return void
	 */
	public static function init() {

		self::$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			self::$active_plugins = array_merge(
				self::$active_plugins,
				get_site_option( 'active_sitewide_plugins', array() )
			);
		}
	}

	/**
	 * Checks if WooCommerce is active
	 *
	 * @return boolean
	 */
	public static function woocommerce_active_check() {

		if ( ! self::$active_plugins ) {
			self::init();
		}

		return in_array(
			'woocommerce/woocommerce.php',
			self::$active_plugins,
			true
		) || array_key_exists(
			'woocommerce/woocommerce.php',
			self::$active_plugins
		);
	}
}
