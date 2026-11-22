<?php
/**
 * Scheduling of discount start/end events.
 *
 * Primary engine: WooCommerce Action Scheduler (bundled with WooCommerce).
 * Fallback: WP-Cron single events.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Scheduler
 */
class Viramoon_PD_Scheduler {

	const HOOK_START = 'viramoon_pd_discount_start';
	const HOOK_END   = 'viramoon_pd_discount_end';
	const HOOK_DAILY = 'viramoon_pd_daily_check';
	const GROUP      = 'viramoon-product-discounts';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_START, array( __CLASS__, 'handle_start' ) );
		add_action( self::HOOK_END, array( __CLASS__, 'handle_end' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'handle_daily' ) );
	}

	/**
	 * Schedule start/end actions for a product or variation.
	 *
	 * @param int      $product_id Product or variation ID.
	 * @param int|null $start_ts   Start timestamp or null.
	 * @param int|null $end_ts     End timestamp or null.
	 * @return void
	 */
	public static function schedule( $product_id, $start_ts, $end_ts ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		self::unschedule( $product_id );

		$now = time();
		if ( $start_ts && (int) $start_ts > $now ) {
			self::schedule_single( self::HOOK_START, $product_id, (int) $start_ts );
		}
		if ( $end_ts ) {
			self::schedule_single( self::HOOK_END, $product_id, (int) $end_ts );
		}
	}

	/**
	 * Schedule one action with Action Scheduler, falling back to WP-Cron.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $product_id Product ID.
	 * @param int    $timestamp  Unix timestamp.
	 * @return void
	 */
	private static function schedule_single( $hook, $product_id, $timestamp ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, array( $product_id ), self::GROUP, true );
			return;
		}

		wp_schedule_single_event( $timestamp, $hook, array( $product_id ) );
	}

	/**
	 * Remove every scheduled action for a product.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function unschedule( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		$args = array( $product_id );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_START, $args, self::GROUP );
			as_unschedule_all_actions( self::HOOK_END, $args, self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK_START, $args );
		wp_clear_scheduled_hook( self::HOOK_END, $args );
	}

	/**
	 * Remove every scheduled action created by this plugin (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_START, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_END, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK_START );
		wp_clear_scheduled_hook( self::HOOK_END );
	}

	/**
	 * Start handler: refresh the price if the regular price changed meanwhile.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function handle_start( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}

		$state = Viramoon_PD_Discount_Manager::get_state( $product );
		if ( 'yes' !== $state['managed'] ) {
			return;
		}

		Viramoon_PD_Discount_Manager::refresh_if_regular_changed( $product );
		Viramoon_PD_Discount_Manager::clear_cache( $product );
	}

	/**
	 * End handler: restore the previous sale price or clear the sale.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function handle_end( $product_id ) {
		Viramoon_PD_Discount_Manager::finish_discount( (int) $product_id );
	}

	/**
	 * Daily safety net for missed end events (bounded, batched).
	 *
	 * @return void
	 */
	public static function handle_daily() {
		$ids = get_posts(
			array(
				'post_type'        => array( 'product', 'product_variation' ),
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 50,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_viramoon_pd_managed',
						'value' => 'yes',
					),
					array(
						'key'     => '_viramoon_pd_end_ts',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_viramoon_pd_end_ts',
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			self::handle_end( (int) $id );
		}

		if ( ! empty( $ids ) ) {
			Viramoon_PD_Logger::info( 'Daily safety net processed discounts.', array( 'count' => count( $ids ) ) );
		}
	}
}
