<?php
/**
 * Admin notice queue used after product/variation saves.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Notices
 */
class Viramoon_PD_Notices {

	const META_KEY = '_viramoon_pd_notices';

	/**
	 * Queue a notice for the current user.
	 *
	 * @param string $message Message text.
	 * @param string $type    error|success|warning.
	 * @return void
	 */
	public static function push( $message, $type = 'error' ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$queue = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$queue[] = array(
			'message' => (string) $message,
			'type'    => in_array( $type, array( 'error', 'success', 'warning' ), true ) ? $type : 'error',
		);

		update_user_meta( $user_id, self::META_KEY, array_slice( $queue, -20 ) );
	}

	/**
	 * Print and clear the queue.
	 *
	 * @return void
	 */
	public static function render() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$queue = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		delete_user_meta( $user_id, self::META_KEY );

		foreach ( $queue as $notice ) {
			if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
				continue;
			}

			$type = isset( $notice['type'] ) ? $notice['type'] : 'error';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Register the renderer.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}
}
