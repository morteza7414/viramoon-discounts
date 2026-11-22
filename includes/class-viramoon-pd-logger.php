<?php
/**
 * Thin wrapper around the WooCommerce logger.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Logger
 */
class Viramoon_PD_Logger {

	/**
	 * Log source shown in WooCommerce > Status > Logs.
	 */
	const SOURCE = 'viramoon-product-discounts';

	/**
	 * Write an informational message.
	 *
	 * @param string $message Message text.
	 * @param array  $context Extra context (product_id, variation_id, ...).
	 * @return void
	 */
	public static function info( $message, array $context = array() ) {
		self::write( 'info', $message, $context );
	}

	/**
	 * Write an error message.
	 *
	 * @param string $message Message text.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Write a message through the WooCommerce logger when available.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message text.
	 * @param array  $context Extra context.
	 * @return void
	 */
	private static function write( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}

		// Never log sensitive data: only scalar identifiers are kept.
		$safe = array( 'source' => self::SOURCE );
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$safe[ sanitize_key( $key ) ] = $value;
			}
		}

		$logger->log( $level, $message, $safe );
	}
}
