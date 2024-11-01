<?php

/**
 * MOSS Admin notices
 *
 * @package     vat-moss
 * @subpackage  Includes
 * @copyright   Copyright (c) 2014, Lyquidity Solutions Limited
 * @License:	GNU Version 2 or Any Later Version
 * @since       1.0
 */

namespace lyquidity\vat_moss;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Notices
 *
 * Outputs admin notices
 *
 * @package VAT MOSS
 * @since 1.0
*/
function admin_notices() {

	$integrations = MOSS_WP_Integrations::get_integrations_list();
	if (isset( $integrations['wooc'] ) && !( class_exists('Aelia\WC\EU_VAT_Assistant\WC_Aelia_EU_VAT_Assistant') || class_exists('WC_EU_VAT_Compliance') ))
	{
		echo "<div class='error'><p>" . __("The Aelia EU VAT Assistant or the Simba EU VAT Compliance (Premium) plug-in must be installed to use the WooCommerce integration.", "vat_moss") . "</p></div>";				
	}

	if (isset( $integrations['edd'] ) && !class_exists('lyquidity\edd_vat\WordPressPlugin'))
	{
		echo "<div class='error'><p>" . __("The Lyquidity VAT plugin for EDD must be installed to use the EDD integration.", "vat_moss") . "</p></div>";				
	}

	if (!isset($_REQUEST['page']) || $_REQUEST['page'] !== 'moss-submissions-settings') return;

	$settings =  vat_moss()->settings;
	$vat_number = $settings->get( 'vat_number', '' );

	$out = new \StdClass();
	$country = get_establishment_country();
	if (!perform_simple_check("$country$vat_number", $out))
	{
		echo "<div class='error'><p>$out->message</p></div>";
	}
	
	$fixed_establishment = vat_moss()->settings->get( 'fixed_establishment', '' );
	if ( !is_bool( $fixed_establishment ) || ( is_bool( $fixed_establishment ) && !$fixed_establishment ))
	{
		echo "<div class='error'><p>" . __("The option to confirm the plugin will be used only for single establishment companies has not been checked. This plug-in cannot be use by companies with registrations in multiple EU states.", "vat_moss") . "</p></div>";		
	}

	$names = array(VAT_MOSS_ACTIVATION_ERROR_NOTICE, VAT_MOSS_ACTIVATION_UPDATE_NOTICE, VAT_MOSS_DEACTIVATION_ERROR_NOTICE, VAT_MOSS_DEACTIVATION_UPDATE_NOTICE);
	array_walk($names, function($name) {

		$message = get_transient($name);
		delete_transient($name);

		if (empty($message)) return;
		$class = strpos($name,"UPDATE") === FALSE ? "error" : "updated";
		echo "<div class='$class'><p>$message</p></div>";

	});

}
add_action('admin_notices', '\lyquidity\vat_moss\admin_notices');

/** 
 * Presents settings within a table that shows a column for the settings and one for an adverts page
 * @param string $product The slug of the current product
 * @param function $callback A callback function that will render the settings.
 * @return void
 */ 
function advert( $product, $version, $callback )
{
	ob_start();
	$gif = admin_url() . "images/xit.gif";
?>
	<table style="height: 100%; width: 100%;"> <!-- This style is needed so cells will respect the height directive in FireFox -->
		<tr>
			<td style="vertical-align: top;" >
				<?php echo $callback(); ?>
			</td>
			<td style="width: 242px; vertical-align:top; height: 100%; position: relative;">
				<style>
					#product-list-close button:hover {
						background-position: -10px !important;
					}
				</style>
				<div id="product-list-close" style="position: absolute; top: 26px; right: 22px;" >
					<button style="float: right; background: url(<?php echo $gif; ?>) no-repeat; border: none; cursor: pointer; display: inline-block; padding: 0; overflow: hidden; margin: 8px 0 0 0; text-indent: -9999px; width: 10px; height: 10px" >
						<span class="screen-reader-text">Remove Product List</span>
						<span aria-hidden="true">×</span>
					</button>
				</div>
				<div id="product-list-wrap" style="width: 100%; height: 100%; display: inline-block; background-color: white; border: 1px solid #ccc;" ></div>
				<script>
					function receiveMessage()
					{
						if (event.origin !== "https://www.wproute.com")
							return;
						
						// The data should be the outerheight of the iframe
						var height = event.data + 0;
						
						var iframe = jQuery('#product-list-frame');
						// Set a minimum height on the outer table but only if its not already there
						var table = iframe.parents('table');
						if ( table.height() > height + 30 ) return;
						table.css( 'min-height', ( height + 30 ) + 'px' );
					}
					window.addEventListener("message", receiveMessage, false);

					function iframeLoad(e) 
					{
						var iframe = jQuery('#product-list-frame');
						iframe[0].contentWindow.postMessage( "height", "https://www.wproute.com/" );

						// This is only for IE
						if ( window.navigator.userAgent.indexOf("MSIE ") == -1 && window.navigator.userAgent.indexOf("Trident/") == -1 ) return;
						// May need to do this for Opera as well
						iframe.closest('div').height( iframe.closest('td').height() - 2 );
						jQuery(window).on( 'resize', function(e) 
						{
							// Resize down to begin with.  This is because a maximize after a minimize results in the maximized div having the height of the minimized div
							iframe.closest('div').height( 10 ); 
							iframe.closest('div').height( iframe.closest('td').height() - 2 ); 
						} )
					}
					jQuery(document).ready(function ($) {
						var target = $('#product-list-wrap');
						target.html( '<iframe onload="iframeLoad();" id="product-list-frame" src="https://www.wproute.com/?action=product_list&product=<?php echo $product; ?>&version=<?php echo $version; ?>" height="100%" width="100%">' );
						$( '#product-list-close button' ).on( 'click', function(e) {
							e.preventDefault();
							$( '#product-list-close').closest('td').hide();
						} );
					} );
				</script>
			</td>
		</tr>
	</table>
<?php

	echo ob_get_clean();
}
