<?php
/**
 * Uninstall routine.
 *
 * Data is only removed when the shop manager explicitly selected the
 * "delete data" policy in the plugin settings.
 *
 * WooCommerce is NOT loaded while uninstall.php runs, so the sale prices are
 * restored through post meta (the same storage layer WooCommerce uses).
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$viramoon_pd_options = get_option( 'viramoon_pd_options', array() );
$viramoon_pd_policy  = ( is_array( $viramoon_pd_options ) && isset( $viramoon_pd_options['data_policy'] ) )
	? $viramoon_pd_options['data_policy']
	: 'keep';

if ( 'delete' !== $viramoon_pd_policy ) {
	return;
}

// Remove scheduled events.
wp_clear_scheduled_hook( 'viramoon_pd_daily_check' );
wp_clear_scheduled_hook( 'viramoon_pd_discount_start' );
wp_clear_scheduled_hook( 'viramoon_pd_discount_end' );

$viramoon_pd_guard = 0;

while ( $viramoon_pd_guard < 200 ) {
	$viramoon_pd_ids = get_posts(
		array(
			'post_type'        => array( 'product', 'product_variation' ),
			'post_status'      => 'any',
			'fields'           => 'ids',
			'posts_per_page'   => 100,
			'suppress_filters' => true,
			'meta_key'         => '_viramoon_pd_managed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	if ( empty( $viramoon_pd_ids ) ) {
		break;
	}

	foreach ( $viramoon_pd_ids as $viramoon_pd_id ) {
		$viramoon_pd_state = get_post_meta( $viramoon_pd_id, '_viramoon_pd_state', true );

		if ( is_array( $viramoon_pd_state ) && isset( $viramoon_pd_state['managed'] ) && 'yes' === $viramoon_pd_state['managed'] ) {
			$viramoon_pd_current = (string) get_post_meta( $viramoon_pd_id, '_sale_price', true );
			$viramoon_pd_applied = isset( $viramoon_pd_state['applied_sale_price'] ) ? (string) $viramoon_pd_state['applied_sale_price'] : '';

			// Only touch the sale price when it is still the one we wrote.
			if ( '' !== $viramoon_pd_applied && $viramoon_pd_current === $viramoon_pd_applied ) {
				$viramoon_pd_previous = isset( $viramoon_pd_state['previous_sale_price'] ) ? (string) $viramoon_pd_state['previous_sale_price'] : '';

				if ( '' !== $viramoon_pd_previous ) {
					update_post_meta( $viramoon_pd_id, '_sale_price', $viramoon_pd_previous );
					update_post_meta( $viramoon_pd_id, '_sale_price_dates_from', isset( $viramoon_pd_state['previous_sale_from'] ) ? (string) $viramoon_pd_state['previous_sale_from'] : '' );
					update_post_meta( $viramoon_pd_id, '_sale_price_dates_to', isset( $viramoon_pd_state['previous_sale_to'] ) ? (string) $viramoon_pd_state['previous_sale_to'] : '' );
				} else {
					delete_post_meta( $viramoon_pd_id, '_sale_price' );
					delete_post_meta( $viramoon_pd_id, '_sale_price_dates_from' );
					delete_post_meta( $viramoon_pd_id, '_sale_price_dates_to' );
				}
			}
		}

		delete_post_meta( $viramoon_pd_id, '_viramoon_pd_discount' );
		delete_post_meta( $viramoon_pd_id, '_viramoon_pd_state' );
		delete_post_meta( $viramoon_pd_id, '_viramoon_pd_managed' );
		delete_post_meta( $viramoon_pd_id, '_viramoon_pd_end_ts' );
	}

	$viramoon_pd_guard++;
}

delete_option( 'viramoon_pd_options' );

delete_metadata( 'user', 0, '_viramoon_pd_notices', '', true );
