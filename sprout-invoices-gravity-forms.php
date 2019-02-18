<?php

/*
 * Plugin Name: Sprout Invoices + Gravity Forms
 * Plugin URI: https://sproutapps.co/sprout-invoices/integrations/
 * Description: Allows for a form submitted by Gravity Forms to create all necessary records to send your client an invoice or estimate.
 * Author: Sprout Apps
 * Version: 1.3.5
 * Author URI: https://sproutapps.co
 * Text Domain: sprout-invoices
 * Domain Path: languages
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SI_GF_INTEGRATION_ADDON_VERSION', '1.3.5' );

add_action( 'gform_loaded', array( 'SI_GF_Integration_Addon_Bootstrap', 'load' ), 5 );

class SI_GF_Integration_Addon_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		require_once( 'inc/SI_GF_Integration_Addon.php' );

		GFAddOn::register( 'SI_GF_Integration_Addon' );
	}
}

function si_gravity_form_int_addon() {
	return SI_GF_Integration_Addon::get_instance();
}
