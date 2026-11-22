<?php
/**
 * Plugin Name:       Viramoon Product Discounts | افزونه تخفیف محصولات ویرامون
 * Plugin URI:        https://viramoon.ir/
 * Description:       تعیین تخفیف درصدی یا مبلغ ثابت برای محصولات ساده و متغیر ووکامرس، با اعمال تکی و گروهی، زمان‌بندی و پیش‌نمایش قیمت.
 * Version:           1.3.2
 * Author:            مرتضی جلادت
 * Author URI:        https://viramoon.ir/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       viramoon-product-discounts
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

define( 'VIRAMOON_PD_VERSION', '1.3.2' );
define( 'VIRAMOON_PD_FILE', __FILE__ );
define( 'VIRAMOON_PD_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIRAMOON_PD_URL', plugin_dir_url( __FILE__ ) );
define( 'VIRAMOON_PD_TEXTDOMAIN', 'viramoon-product-discounts' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 * The plugin never touches orders, so it is fully compatible.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-logger.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-notices.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-settings.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-calculator.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-scheduler.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-discount-manager.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-gateway-fees.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-admin-product.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-admin-variations.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-admin-bulk-variations.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-admin-bulk-products.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-ajax.php';
require_once VIRAMOON_PD_PATH . 'includes/class-viramoon-pd-plugin.php';

/**
 * Safety net: resolve any Viramoon_PD_* class from includes/ automatically.
 *
 * This keeps the plugin working even if a require_once line is ever missed.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Viramoon_PD_' ) ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$path = VIRAMOON_PD_PATH . 'includes/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Load translations.
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'viramoon-product-discounts', false, dirname( plugin_basename( VIRAMOON_PD_FILE ) ) . '/languages' );
	}
);

/**
 * Boot the plugin once WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'افزونه «تخفیف محصولات ویرامون» برای کار کردن به ووکامرس نیاز دارد. لطفاً ووکامرس را نصب و فعال کنید.', 'viramoon-product-discounts' );
					echo '</p></div>';
				}
			);
			return;
		}

		Viramoon_PD_Plugin::instance()->init();
	},
	20
);

/**
 * Activation: schedule the daily safety-net job.
 */
register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( Viramoon_PD_Scheduler::HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Viramoon_PD_Scheduler::HOOK_DAILY );
		}
	}
);

/**
 * Deactivation: remove scheduled events only.
 * Product prices are intentionally NOT modified here (see README).
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( Viramoon_PD_Scheduler::HOOK_DAILY );
		Viramoon_PD_Scheduler::unschedule_all();
	}
);
