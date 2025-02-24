<?php

/**
 * MOSS Easy Digital Downloads integration
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

class MOSS_Integration_EDD extends MOSS_Integration_Base {

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function init() {

		$this->source = 'edd';
		$this->name = __( 'Easy Digital Downloads', 'vat_moss' );
		$this->post_type = 'download';
		$instance = $this;
		add_action( 'moss_integration_instance', function( $instance_array ) use($instance)
		{
			if (function_exists('EDD'))
				$instance_array[$instance->source] = $instance;

			return $instance_array;
		}, 10 );

	}

	/**
	 * Returns an associative array of the VAT type names indexed by type
	 */
	function get_vat_type_names()
	{
		return \lyquidity\edd_vat\get_vat_rate_types();		
	}

	/**
	 * Returns an array of VAT information:
	 *	id				Database id for the sale
	 *	purchase_key	Unique purchase identifier
	 *  vrn				The VAT number of the buyer
	 *	date			DateTime of the completed transaction
	 *	correlation_id	Existing correlation_id (if any)
	 *	buyer			The name of the buyer
	 *	values			An array of sale values before any taxes indexed by the indicator.  
	 *						0: Goods, 2: Triangulated sale, 3: Services (reverse charge)
	 *						Values with the same indicator will be accumulated
	 *
	 * If you have more than one sale to a client the value of those sales can be aggregated
	 * If the sale is across two service types (different indicators) then they need to appear as different entries
	 *
	 * @string	startDate				strtotime() compatible date of the earliest record to return
	 * @string	endDate					strtotime() compatible date of the latest record to return
	 * @boolean	includeSubmitted		True is the results should include previously submitted records (submission_id does not exist in meta-data)
	 * @boolean	includeSubmittedOnly	True if the results should only include selected items
	 */
	public function get_vat_information($startDate, $endDate, $includeSubmitted = false, $includeSubmittedOnly = false)
	{
		$establishment_country = \lyquidity\vat_moss\get_establishment_country(); 

		$meta_query = array();
		$meta_query[] = array(
			'key'		=> '_edd_completed_date',
			'value'		=> array($startDate, $endDate),
			'compare'	=> 'BETWEEN',
			'type'		=> 'DATE'
		);

		if (!$includeSubmitted)
		{
			$meta_query[] = array(
				'key'     => 'moss_submission_id',
				'compare' => 'NOT EXISTS'
			);
		}

		else if ($includeSubmittedOnly)
		{
			$meta_query[] = array(
				'key'     => 'moss_submission_id',
				'compare' => 'EXISTS'
			);
		}

		$args = array(
			'post_type' 		=> 'edd_payment',
			'posts_per_page' 	=> -1,
			'fields'			=> 'ids',
			'post_status'		=> array( 'publish','edd_subscription' ),
			'orderby'			=> array( 'meta_value_num' => 'ASC' ),
			'meta_query'		=> $meta_query
		);

		$payments = new \WP_Query( $args );

		$vat_payments = array();

		if( $payments->posts )
		{
			$eu_states = array_flip( WordPressPlugin::$eu_states );

			foreach( $payments->posts as $payment_id ) {

				$payment_meta = edd_get_payment_meta( $payment_id );
				$user_info = maybe_unserialize( $payment_meta['user_info'] );
				$country_code	= $user_info['address']['country'];
				$currency_code	= $payment_meta['currency'];

				// If there is a VAT number then the record does not apply
				if ( isset($user_info['vat_number']) && !empty($user_info['vat_number']) ) continue;

				if (isset($eu_states[$establishment_country])) // Union reporting
				{
					// Should exclude sale to buyers in the establishment country
					if ($country_code === $establishment_country) continue;
					if (!isset($eu_states[$country_code])) continue;
				}

				$submission_id	= get_post_meta( $payment_id, 'moss_submission_id', true);
				$buyer			= sprintf("%1s %2s", $user_info['first_name'], $user_info['last_name']);
				$purchase_key	= get_post_meta( $payment_id, '_edd_payment_purchase_key', true);
				$date			= get_post_meta( $payment_id, '_edd_completed_date', true);
				$payment_rate	= isset( $user_info['vat_rate'] ) ? $user_info['vat_rate'] : 0;
				$first			= true;
				$cart_details	= maybe_unserialize( $payment_meta['cart_details'] );

				foreach( $cart_details as $key => $item )
				{
					if ($item['price'] == 0) continue;

					$class = \lyquidity\edd_vat\vat_class_to_use( $item['id'] );
					if ($class === VAT_EXEMPT_CLASS) continue;

					// Look up the correct set of class rates for this item
					$rate_type = VAT_GROUP_CLASS_REDUCED;
					if ( VAT_STANDARD_CLASS == $class )
					{
						if ( function_exists( '\\lyquidity\\vat_moss\\vat_rate_type_to_use' ) ) 
							$rate_type = vat_rate_type_to_use( $item[ 'id' ] );
					}
					else
					if ( function_exists('\lyquidity\edd_vat\get_vat_rates') )
					{
						$class_rates = \lyquidity\edd_vat\get_vat_rates($class);

						// Filter the rate for each country
						$country_rate = array_filter($class_rates, function($class_rate) use($country_code)
							{
								return $class_rate['country'] === $country_code;
							});

						// If one exists, take the first or create a default
						$country_rate = !is_array($country_rate) || count($country_rate) == 0
							? array('country' => $country_code, 'rate' => null, 'global' => true, 'state' => null, 'group' => VAT_GROUP_CLASS_REDUCED)
							: reset($country_rate);

						$rate_type = isset( $country_rate['group'] ) ? $country_rate['group'] : VAT_GROUP_CLASS_REDUCED;
					}

					$vat_payment = array();

					// error_log( print_r( $item, true ) );

					/*
						"name"	=> "Book"
						"id"	=> "376"
						"item_number" => {
							"id"		=> "376"
							"options"	=> {}
							"quantity"	=> 1
						}
						"item_price"	=> 7.5
						"quantity"		=> 1
						"discount"		=> 1.5
						"subtotal"		=> 7.5
						"tax"			=> 0.6
						"fees"			=> {
							[amount] => -8.85
							[label] => 15% Bundle Discount - StereoDelta
							[no_tax] => 1
							[type] => fee
							[download_id] => 74
							[price_id] => 
						}
						"price"			=> 6.6
						"vat_rate"		=> 0.1
					 */

					/*
						'id'			=> $payment->id,
						'item_id'		=> 0,
						'first'			=> true,
						'purchase_key'	=> $payment->purchase_key,
						'date'			=> $payment->date,
						'submission_id'	=> isset($payment->submission_id) ? $payment->submission_id : 0,
						'net'			=> apply_filters( 'moss_get_net_transaction_amount', $payment->value - $payment->tax, $payment->id ),
						'tax'			=> $payment->tax,
						'vat_rate'		=> $payment->vat_rate,
						'vat_type'		=> $payment->vat_type,
						'country_code'	=> $payment->country
					 */

					$totalfees = array_reduce( $item['fees'], function( $carry, $fee )
					{
						$carry += $fee['amount'];
						return $carry;
					}, 0 );

					// error_log( "Total fees: {$item['price']} - {$item['tax']} + $totalfees" );

					$vat_payment['id']				= $payment_id;
					$vat_payment['item_id']			= $item['id'];
					$vat_payment['first']			= $first;
					$vat_payment['purchase_key']	= $purchase_key;
					$vat_payment['date']			= $date;
					$vat_payment['submission_id']	= $submission_id;
					$vat_payment['net']				= round( apply_filters( 'moss_get_net_transaction_amount', $item['price'] - $item['tax'] + $totalfees, $payment_id), 2);
					$vat_payment['tax']				= round( $item['tax'], 2 );					
					$vat_payment['vat_rate']		= round( isset( $item['vat_rate'] ) ?  $item['vat_rate'] : $payment_rate, 3 );
					$vat_payment['vat_type']		= $rate_type;
					$vat_payment['country_code']	= $country_code;
					$vat_payment['currency_code']	= $currency_code;

					$vat_payments[] = $vat_payment;
					$first = false;
				}
			}
		}

		return $vat_payments;
	}
	
	/**
	 * Called by the integration controller to allow the integration to update sales records with
	 *
	 * @int submission_id The id of the MOSS submission that references the sale record
	 * @string correlation_id The HMRC generated correlation_id of the submission in which the sales record is included
	 * @array ids An array of sales record ids
	 *
	 * @return An error message, an array of messages or FALSE if every thing is OK
	 */
	function update_vat_information($submission_id, $correlation_id, $ids)
	{
		if (!$submission_id || !is_numeric($submission_id))
		{
			return __('The submission id is not valid', 'vat_moss');
		}

		if (!$ids || !is_array($ids))
		{
			return __('The VAT sales records passed are not an array', 'vat_moss');
		}
		
		try
		{
			foreach($ids as $id => $value)
			{
				update_post_meta($id, 'moss_submission_id', $submission_id);

				if (!empty($correlation_id))
					update_post_meta($id, 'correlation_id', $submission_id);
			}
		}
		catch(Exception $ex)
		{
			return array(__('An error occurred updating MOSS sales record meta data', 'vat_moss'), $ex->getMessage());
		}

		return false;
	}

	/**
	 * Called to allow the integration to retrieve information from specific records
	 *
	 * @array source_ids An array of sources and record ids
	 *
	 * @return An error message, an array of messages or of payments if everything is OK
	 *
	 * array(
	 *	'status' => 'success',
	 *	'information' => array(
	 *		'id'			=> 0,
	 *		'vrn'			=> 'GB123456789',
	 *		'purchase_key'	=> '...',
	 *		'values'		=> array(
	 *							  'indicator' (0|2|3) => sale amounts accumulated
	 *						   )
	 *	)
	 * )
	 *
	 * array(
	 *	'status' => 'error',
	 *	'messages' => array(
	 *		'',
	 *		''
	 *	)
	 * )
	 */
	function get_vat_record_information($source_ids)
	{
		if (!is_array($source_ids))
		{
			return array('status' => 'error', 'messages' => array( __( 'Invalid source', 'vat_moss' ) ) );
		}

		$vat_payments = array();

		foreach( $source_ids as $key => $payment_id ) {

			$payment_meta = edd_get_payment_meta( $payment_id );
			$user_info = maybe_unserialize( $payment_meta['user_info'] );
			$currency_code	= $payment_meta['currency'];

			// If there is a VAT number then the record does not apply
//			if ( isset($user_info['vat_number']) && !empty($user_info['vat_number']) ) continue;

			$submission_id	= get_post_meta( $payment_id, 'moss_submission_id', true);
			$buyer			= sprintf("%1s %2s", $user_info['first_name'], $user_info['last_name']);
			$purchase_key	= get_post_meta( $payment_id, '_edd_payment_purchase_key', true);
			$date			= get_post_meta( $payment_id, '_edd_completed_date', true);
			$payment_rate	= isset( $user_info['vat_rate'] ) ? $user_info['vat_rate'] : 0;
			$country_code	= $user_info['address']['country'];
			$first 			= true;
			$cart_details	= maybe_unserialize( $payment_meta['cart_details'] );

			foreach( $cart_details as $key => $item )
			{
				if ($item['price'] == 0) continue;
	
				$class = \lyquidity\edd_vat\vat_class_to_use( $item['id'] );
				if ($class === VAT_EXEMPT_CLASS) continue;

				// Look up the correct set of class rates for this item
				$rate_type = VAT_GROUP_CLASS_REDUCED;
				if ( VAT_STANDARD_CLASS == $class )
				{
					if ( function_exists( '\\lyquidity\\vat_moss\\vat_rate_type_to_use' ) ) 
						$rate_type = vat_rate_type_to_use( $item[ 'id' ] );
				}
				else
				if ( function_exists('\lyquidity\edd_vat\get_vat_rates') )
				{
					$class_rates = \lyquidity\edd_vat\get_vat_rates($class);

					// Filter the rate for each country
					$country_rate = array_filter($class_rates, function($class_rate) use($country_code)
						{
							return $class_rate['country'] === $country_code;
						});

					// If one exists, take the first or create a default
					$country_rate = !is_array($country_rate) || count($country_rate) == 0
						? array('country' => $country_code, 'rate' => null, 'global' => true, 'state' => null, 'group' => VAT_GROUP_CLASS_REDUCED)
						: reset($country_rate);

					$rate_type = isset( $country_rate['group'] ) ? $country_rate['group'] : VAT_GROUP_CLASS_REDUCED;
				}

				$vat_payment = array();

				/*
					"name"	=> "Book"
					"id"	=> "376"
					"item_number" => {
						"id"		=> "376"
						"options"	=> {}
						"quantity"	=> 1
					}
					"item_price"	=> 7.5
					"quantity"		=> 1
					"discount"		=> 1.5
					"subtotal"		=> 7.5
					"tax"			=> 0.6
					"fees"			=> {
						[amount] => -8.85
						[label] => 15% Bundle Discount - StereoDelta
						[no_tax] => 1
						[type] => fee
						[download_id] => 74
						[price_id] => 
					}
					"price"			=> 6.6
					"vat_rate"		=> 0.1
				 */

				/*
					'id'			=> $payment->id,
					'item_id'		=> 0,
					'first'			=> true,
					'purchase_key'	=> $payment->purchase_key,
					'date'			=> $payment->date,
					'submission_id'	=> isset($payment->submission_id) ? $payment->submission_id : 0,
					'net'			=> apply_filters( 'moss_get_net_transaction_amount', $payment->value - $payment->tax, $payment->id ),
					'tax'			=> $payment->tax,
					'vat_rate'		=> $payment->vat_rate,
					'vat_type'		=> $payment->vat_type,
					'country_code'	=> $payment->country,
					'currency_code'	=> $payment->currency_code
				 */

				$totalfees = array_reduce( $item['fees'], function( $carry, $fee )
				{
					$carry += $fee['amount'];
					return $carry;
				}, 0 );

				// error_log( "Total fees: {$item['price']} - {$item['tax']} + $totalfees" );

				$vat_payment['id']				= $payment_id;
				$vat_payment['item_id']			= $item['id'];
				$vat_payment['fist']			= $first;
				$vat_payment['purchase_key']	= $purchase_key;
				$vat_payment['date']			= $date;
				$vat_payment['submission_id']	= $submission_id;
				$vat_payment['net']				= round( apply_filters( 'moss_get_net_transaction_amount', $item['price'] - $item['tax'] + $totalfees, $payment_id), 2);
				$vat_payment['tax']				= round( $item['tax'], 2 );					
				$vat_payment['vat_rate']		= round( isset( $item['vat_rate'] ) ?  $item['vat_rate'] : $payment_rate, 3 );
				$vat_payment['vat_type']		= $rate_type;
				$vat_payment['country_code']	= $country_code;
				$vat_payment['currency_code']	= $currency_code;

				$vat_payments[] = $vat_payment;
				$first = false;
			}
		}

		return array( 'status' => 'success', 'information' => $vat_payments );
	}

	/**
	 * Called by the integration controller to remove MOSS submission references for a set of post ids
	 *
	 * @array ids An array of sales record ids
	 *
	 * @return An error message, an array of messages or FALSE if every thing is OK
	 */
	 function delete_vat_information($ids)
	 {
		if (!$ids || !is_array($ids))
		{
			return __("The VAT sales records passed are not an array", 'vat_moss');
		}
		
		try
		{
			foreach($ids as $id => $value)
			{
				delete_post_meta($id, 'moss_submission_id');
				delete_post_meta($id, 'correlation_id');
			}
		}
		catch(Exception $ex)
		{
			return array(__('An error occurred deleting MOSS sales record meta data', 'vat_moss'), $ex->getMessage());
		}
		
	 }

}
new MOSS_Integration_EDD;
