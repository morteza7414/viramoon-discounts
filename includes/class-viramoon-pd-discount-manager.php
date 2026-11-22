<?php
/**
 * Applies and removes discounts using the public WooCommerce product APIs.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Discount_Manager
 */
class Viramoon_PD_Discount_Manager {

	const META_DISCOUNT = '_viramoon_pd_discount';
	const META_STATE    = '_viramoon_pd_state';
	const META_MANAGED  = '_viramoon_pd_managed';
	const META_END_TS   = '_viramoon_pd_end_ts';

	/**
	 * Re-entrancy guard: prevents recursive saves from re-triggering recalculation.
	 *
	 * @var int
	 */
	private static $busy = 0;

	/**
	 * Default discount structure.
	 *
	 * @return array
	 */
	public static function default_discount() {
		return array(
			'enabled' => 'no',
			'type'    => 'percent',
			'amount'  => '',
			'start'   => '',
			'end'     => '',
		);
	}

	/**
	 * Default ownership state.
	 *
	 * @return array
	 */
	public static function default_state() {
		return array(
			'status'              => 'idle',
			'managed'             => 'no',
			'applied_sale_price'  => '',
			'applied_at'          => 0,
			'regular_at_apply'    => '',
			'previous_sale_price' => '',
			'previous_sale_from'  => '',
			'previous_sale_to'    => '',
		);
	}

	/**
	 * Is the manager currently writing prices?
	 *
	 * @return bool
	 */
	public static function is_busy() {
		return self::$busy > 0;
	}

	/**
	 * Read the discount definition of a product/variation.
	 *
	 * @param WC_Product|int $product Product or ID.
	 * @return array
	 */
	public static function get_discount( $product ) {
		$id  = $product instanceof WC_Product ? $product->get_id() : (int) $product;
		$raw = get_post_meta( $id, self::META_DISCOUNT, true );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = self::default_discount();
		$discount = array();
		foreach ( $defaults as $key => $default ) {
			$discount[ $key ] = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;
		}

		return $discount;
	}

	/**
	 * Read the ownership state of a product/variation.
	 *
	 * @param WC_Product|int $product Product or ID.
	 * @return array
	 */
	public static function get_state( $product ) {
		$id  = $product instanceof WC_Product ? $product->get_id() : (int) $product;
		$raw = get_post_meta( $id, self::META_STATE, true );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = self::default_state();
		$state    = array();
		foreach ( $defaults as $key => $default ) {
			$state[ $key ] = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;
		}

		return $state;
	}

	/**
	 * Persist the discount definition.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $discount   Discount data.
	 * @return void
	 */
	public static function save_discount( $product_id, array $discount ) {
		update_post_meta( (int) $product_id, self::META_DISCOUNT, $discount );
	}

	/**
	 * Persist the ownership state and the indexed helper metas.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $state      State data.
	 * @param int   $end_ts     End timestamp (0 when none).
	 * @return void
	 */
	public static function save_state( $product_id, array $state, $end_ts = 0 ) {
		update_post_meta( (int) $product_id, self::META_STATE, $state );
		update_post_meta( (int) $product_id, self::META_MANAGED, ( 'yes' === $state['managed'] ) ? 'yes' : 'no' );
		update_post_meta( (int) $product_id, self::META_END_TS, (int) $end_ts );
	}

	/**
	 * Does this product currently carry a sale price written by this plugin?
	 *
	 * @param WC_Product $product Product or variation.
	 * @param array|null $state   Pre-fetched state.
	 * @return bool
	 */
	public static function is_owned( $product, $state = null ) {
		$state = is_array( $state ) ? $state : self::get_state( $product );

		if ( 'yes' !== $state['managed'] || '' === (string) $state['applied_sale_price'] ) {
			return false;
		}

		return self::stored_sale_price( $product ) === (string) $state['applied_sale_price'];
	}

	/**
	 * Does this product carry a Viramoon discount that is in effect right now?
	 *
	 * Checks the definition, the ownership of the sale price and the configured
	 * date window. A discount scheduled for the future (or already finished) is
	 * not "active", even though its sale price may already be stored.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public static function is_active( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$discount = self::get_discount( $product );
		if ( 'yes' !== $discount['enabled'] ) {
			return false;
		}

		$state = self::get_state( $product );
		if ( ! self::is_owned( $product, $state ) ) {
			return false;
		}

		$now   = time();
		$start = self::parse_discount_timestamp( isset( $discount['start'] ) ? $discount['start'] : '' );
		$end   = self::parse_discount_timestamp( isset( $discount['end'] ) ? $discount['end'] : '' );

		if ( null !== $start && $start > $now ) {
			return false;
		}

		if ( null !== $end && $end <= $now ) {
			return false;
		}

		return true;
	}

	/**
	 * Parse a discount date into a timestamp, ignoring invalid values.
	 *
	 * @param string $value Raw date string.
	 * @return int|null
	 */
	private static function parse_discount_timestamp( $value ) {
		$timestamp = Viramoon_PD_Calculator::parse_datetime( $value );

		return is_int( $timestamp ) ? $timestamp : null;
	}

	/**
	 * Read the sale price that is actually persisted in the database.
	 *
	 * During an admin save the product object already carries the submitted (empty)
	 * sale price, so the object must never be trusted for ownership checks.
	 *
	 * @param WC_Product|int $product Product, variation or ID.
	 * @return string
	 */
	public static function stored_sale_price( $product ) {
		$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;

		return (string) get_post_meta( $id, '_sale_price', true );
	}

	/**
	 * Apply a discount to a product or variation.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param array      $discount Discount data.
	 * @param array      $args     {
	 *     @type bool   $overwrite Overwrite a foreign sale price.
	 *     @type string $source    Caller identifier for logging.
	 *     @type bool   $save      Persist immediately. Pass false when the caller
	 *                            runs before WooCommerce saves the object itself.
	 * }
	 * @return array|WP_Error
	 */
	public static function apply( $product, array $discount, array $args = array() ) {
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'viramoon_pd_product', __( 'محصول یافت نشد.', 'viramoon-product-discounts' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'overwrite' => false,
				'source'    => 'manual',
				'save'      => true,
			)
		);

		$product_id = $product->get_id();
		$regular    = $product->get_regular_price( 'edit' );

		if ( '' === (string) $regular || null === $regular ) {
			return new WP_Error( 'viramoon_pd_no_regular', __( 'قیمت اصلی تعیین نشده است؛ تخفیف اعمال نشد.', 'viramoon-product-discounts' ) );
		}

		$validation = Viramoon_PD_Calculator::validate( $discount, (float) $regular );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$final = Viramoon_PD_Calculator::calculate( (float) $regular, $discount['type'], $discount['amount'] );

		$start_ts = Viramoon_PD_Calculator::parse_datetime( isset( $discount['start'] ) ? $discount['start'] : '' );
		$end_ts   = Viramoon_PD_Calculator::parse_datetime( isset( $discount['end'] ) ? $discount['end'] : '' );

		if ( is_wp_error( $start_ts ) ) {
			return $start_ts;
		}
		if ( is_wp_error( $end_ts ) ) {
			return $end_ts;
		}

		$now = time();

		// The window is already over: never apply, and clean up anything we own.
		if ( $end_ts && (int) $end_ts <= $now ) {
			self::finish_discount( $product_id );
			$state          = self::get_state( $product_id );
			$state['status'] = 'expired';
			self::save_state( $product_id, $state, 0 );

			return array(
				'status'      => 'expired',
				'final_price' => 0.0,
				'message'     => __( 'بازه زمانی این تخفیف به پایان رسیده است؛ قیمت حراج اعمال نشد.', 'viramoon-product-discounts' ),
			);
		}

		$state        = self::get_state( $product );
		$current_sale = self::stored_sale_price( $product );
		$owned        = self::is_owned( $product, $state );

		if ( '' !== $current_sale && ! $owned ) {
			if ( empty( $args['overwrite'] ) ) {
				return new WP_Error(
					'viramoon_pd_conflict',
					__( 'این محصول یا متغیر در حال حاضر قیمت حراج دارد که توسط افزونه ویرامون ایجاد نشده است. برای بازنویسی، گزینه «بازنویسی حراج موجود» را فعال کنید.', 'viramoon-product-discounts' )
				);
			}

			// Remember the foreign sale so it can be restored when our discount ends.
			$state['previous_sale_price'] = $current_sale;
			$state['previous_sale_from']  = (string) get_post_meta( $product_id, '_sale_price_dates_from', true );
			$state['previous_sale_to']    = (string) get_post_meta( $product_id, '_sale_price_dates_to', true );
		} elseif ( ! $owned ) {
			$state['previous_sale_price'] = '';
			$state['previous_sale_from']  = '';
			$state['previous_sale_to']    = '';
		}

		$sale_price = wc_format_decimal( $final, Viramoon_PD_Calculator::decimals() );

		self::$busy++;
		try {
			$product->set_sale_price( $sale_price );
			$product->set_date_on_sale_from( $start_ts ? Viramoon_PD_Calculator::timestamp_to_string( $start_ts ) : '' );
			$product->set_date_on_sale_to( $end_ts ? Viramoon_PD_Calculator::timestamp_to_string( $end_ts ) : '' );
			if ( ! empty( $args['save'] ) ) {
				$product->save();
			}
		} finally {
			self::$busy--;
		}

		$state['status']             = ( $start_ts && (int) $start_ts > $now ) ? 'scheduled' : 'active';
		$state['managed']            = 'yes';
		$state['applied_sale_price'] = $sale_price;
		$state['applied_at']         = $now;
		$state['regular_at_apply']   = (string) $regular;

		self::save_state( $product_id, $state, $end_ts ? (int) $end_ts : 0 );
		Viramoon_PD_Scheduler::schedule( $product_id, $start_ts, $end_ts );
		if ( ! empty( $args['save'] ) ) {
			self::clear_cache( $product );
		}

		Viramoon_PD_Logger::info(
			'Discount applied.',
			array(
				'product_id' => $product_id,
				'source'     => $args['source'],
				'status'     => $state['status'],
			)
		);

		return array(
			'status'      => $state['status'],
			'final_price' => (float) $sale_price,
			'message'     => ( 'scheduled' === $state['status'] )
				? __( 'تخفیف ذخیره شد و در زمان شروع فعال می‌شود.', 'viramoon-product-discounts' )
				: __( 'تخفیف اعمال شد.', 'viramoon-product-discounts' ),
		);
	}

	/**
	 * Remove a discount applied by this plugin and restore a previous sale price.
	 *
	 * @param WC_Product $product          Product or variation.
	 * @param bool       $restore_previous Restore a foreign sale price that we overwrote.
	 * @return bool True when the sale price was modified.
	 */
	public static function clear( $product, $restore_previous = true ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = $product->get_id();
		$state      = self::get_state( $product );
		$owned      = self::is_owned( $product, $state );
		$modified   = false;

		if ( $owned ) {
			if ( $restore_previous && self::previous_sale_is_usable( $state ) ) {
				$product->set_sale_price( (string) $state['previous_sale_price'] );
				$product->set_date_on_sale_from( (string) $state['previous_sale_from'] );
				$product->set_date_on_sale_to( (string) $state['previous_sale_to'] );
			} else {
				$product->set_sale_price( '' );
				$product->set_date_on_sale_from( '' );
				$product->set_date_on_sale_to( '' );
			}

			self::$busy++;
			try {
				$product->save();
			} finally {
				self::$busy--;
			}

			$modified = true;
		}

		$state['status']              = 'idle';
		$state['managed']             = 'no';
		$state['applied_sale_price']  = '';
		$state['previous_sale_price'] = '';
		$state['previous_sale_from']  = '';
		$state['previous_sale_to']    = '';

		self::save_state( $product_id, $state, 0 );
		Viramoon_PD_Scheduler::unschedule( $product_id );
		self::clear_cache( $product );

		return $modified;
	}

	/**
	 * End-of-window handler (idempotent).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function finish_discount( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}

		$state = self::get_state( $product );
		if ( 'yes' !== $state['managed'] ) {
			return;
		}

		if ( ! self::is_owned( $product, $state ) ) {
			// Somebody else changed the sale price meanwhile: never touch it.
			$state['status']  = 'conflict';
			$state['managed'] = 'no';
			self::save_state( $product_id, $state, 0 );
			Viramoon_PD_Logger::info( 'Sale price changed externally; discount end skipped.', array( 'product_id' => (int) $product_id ) );
			return;
		}

		self::clear( $product, true );
		$state           = self::get_state( $product_id );
		$state['status'] = 'expired';
		self::save_state( $product_id, $state, 0 );

		Viramoon_PD_Logger::info( 'Discount window ended.', array( 'product_id' => (int) $product_id ) );
	}

	/**
	 * Recompute the sale price when the regular price changed while a discount is active.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return void
	 */
	public static function refresh_if_regular_changed( $product ) {
		if ( self::is_busy() || ! $product instanceof WC_Product ) {
			return;
		}

		$discount = self::get_discount( $product );
		if ( 'yes' !== $discount['enabled'] ) {
			return;
		}

		$state = self::get_state( $product );
		if ( 'yes' !== $state['managed'] || ! self::is_owned( $product, $state ) ) {
			return;
		}

		$regular = (string) $product->get_regular_price( 'edit' );
		if ( '' === $regular || (string) $state['regular_at_apply'] === $regular ) {
			return;
		}

		$result = self::apply(
			$product,
			$discount,
			array(
				'overwrite' => true,
				'source'    => 'regular_price_change',
			)
		);

		if ( is_wp_error( $result ) ) {
			Viramoon_PD_Logger::error(
				'Could not recalculate discount after a regular price change: ' . $result->get_error_message(),
				array( 'product_id' => $product->get_id() )
			);
		}
	}

	/**
	 * Hook callback for WooCommerce product save events.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function maybe_refresh( $product_id ) {
		if ( self::is_busy() ) {
			return;
		}

		$product = wc_get_product( (int) $product_id );
		if ( $product ) {
			self::refresh_if_regular_changed( $product );
		}
	}

	/**
	 * Flush WooCommerce product caches.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return void
	 */
	public static function clear_cache( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$id = $product->get_id();
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
			if ( $product->is_type( 'variation' ) ) {
				wc_delete_product_transients( $product->get_parent_id() );
			}
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		}
	}

	/**
	 * Is the remembered foreign sale price still meaningful?
	 *
	 * @param array $state State data.
	 * @return bool
	 */
	private static function previous_sale_is_usable( array $state ) {
		if ( '' === (string) $state['previous_sale_price'] ) {
			return false;
		}

		$to = (string) $state['previous_sale_to'];
		if ( '' !== $to ) {
			if ( is_numeric( $to ) ) {
				return (int) $to > time();
			}

			$ts = Viramoon_PD_Calculator::parse_datetime( $to );
			if ( is_int( $ts ) && $ts <= time() ) {
				return false;
			}
		}

		return true;
	}
}
