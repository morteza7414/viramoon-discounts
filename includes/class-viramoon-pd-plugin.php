<?php
/**
 * Plugin bootstrap: wires every module together.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Plugin
 */
class Viramoon_PD_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Viramoon_PD_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Viramoon_PD_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init() {
		Viramoon_PD_Scheduler::init();
		Viramoon_PD_Gateway_Fees::init();

		add_action( 'woocommerce_update_product', array( 'Viramoon_PD_Discount_Manager', 'maybe_refresh' ) );
		add_action( 'woocommerce_update_product_variation', array( 'Viramoon_PD_Discount_Manager', 'maybe_refresh' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( VIRAMOON_PD_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		if ( ! is_admin() ) {
			return;
		}

		Viramoon_PD_Notices::init();
		Viramoon_PD_Settings::init();
		Viramoon_PD_Admin_Product::init();
		Viramoon_PD_Admin_Variations::init();
		Viramoon_PD_Admin_Bulk_Variations::init();
		Viramoon_PD_Admin_Bulk_Products::init();
		Viramoon_PD_Ajax::init();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the checkout helper only where a gateway commission can apply.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$fees = Viramoon_PD_Gateway_Fees::get_fee_gateway_map();
		if ( empty( Viramoon_PD_Gateway_Fees::get_fee_gateway_ids() ) ) {
			return;
		}

		wp_enqueue_script(
			'viramoon-pd-checkout',
			VIRAMOON_PD_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			VIRAMOON_PD_VERSION,
			true
		);

		wp_localize_script(
			'viramoon-pd-checkout',
			'viramoonPDCheckout',
			array(
				'gateways' => array_keys( $fees ),
				'fees'     => $fees,
			)
		);
	}

	/**
	 * Add a settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . Viramoon_PD_Settings::PAGE );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'تنظیمات', 'viramoon-product-discounts' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Load CSS/JS only on the screens that need them.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$settings_screen = 'woocommerce_page_' . Viramoon_PD_Settings::PAGE;
		$bulk_screen     = 'woocommerce_page_' . Viramoon_PD_Admin_Bulk_Products::PAGE;

		if ( ! in_array( $screen->id, array( 'product', $bulk_screen, $settings_screen ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'viramoon-pd-admin',
			VIRAMOON_PD_URL . 'assets/css/admin.css',
			array(),
			VIRAMOON_PD_VERSION
		);

		if ( $settings_screen === $screen->id ) {
			return;
		}

		wp_enqueue_script(
			'viramoon-pd-jalali',
			VIRAMOON_PD_URL . 'assets/js/jalali-datepicker.js',
			array(),
			VIRAMOON_PD_VERSION,
			true
		);

		wp_enqueue_script(
			'viramoon-pd-admin',
			VIRAMOON_PD_URL . 'assets/js/admin.js',
			array( 'viramoon-pd-jalali' ),
			VIRAMOON_PD_VERSION,
			true
		);

		wp_localize_script(
			'viramoon-pd-admin',
			'viramoonPD',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'viramoon_pd_admin' ),
				'decimals' => Viramoon_PD_Calculator::decimals(),
				'currency' => array(
					'symbol'  => get_woocommerce_currency_symbol(),
					'code'    => get_woocommerce_currency(),
					'display' => Viramoon_PD_Settings::get( 'price_display' ),
					'unit'    => Viramoon_PD_Settings::unit_label(),
				),
				'settings' => array(
					'roundingEnabled' => ( 'yes' === Viramoon_PD_Settings::get( 'rounding_enabled' ) ),
					'roundingMode'    => Viramoon_PD_Settings::get( 'rounding_mode' ),
					'roundingStep'    => (float) Viramoon_PD_Settings::get( 'rounding_step' ),
					'allowFull'       => ( 'yes' === Viramoon_PD_Settings::get( 'allow_full_discount' ) ),
				),
				'i18n'     => array(
					'confirmApply' => __( 'تخفیف روی موارد پیش‌نمایش‌شده اعمال شود؟', 'viramoon-product-discounts' ),
					'confirmClear' => __( 'تخفیف ویرامون از موارد انتخاب‌شده حذف شود؟ این عمل قابل بازگشت نیست.', 'viramoon-product-discounts' ),
					'previewFirst' => __( 'ابتدا پیش‌نمایش بگیرید.', 'viramoon-product-discounts' ),
					'noSelection'  => __( 'هیچ محصولی انتخاب نشده است.', 'viramoon-product-discounts' ),
					'processing'   => __( 'در حال پردازش…', 'viramoon-product-discounts' ),
					'updated'      => __( 'به‌روزرسانی‌شده', 'viramoon-product-discounts' ),
					'skipped'      => __( 'ردشده', 'viramoon-product-discounts' ),
					'total'        => __( 'کل', 'viramoon-product-discounts' ),
					'error'        => __( 'خطا در ارتباط با سرور.', 'viramoon-product-discounts' ),
					'loading'      => __( 'در حال بارگذاری…', 'viramoon-product-discounts' ),
					'empty'        => __( 'محصولی پیدا نشد.', 'viramoon-product-discounts' ),
					'done'         => __( 'پایان عملیات.', 'viramoon-product-discounts' ),
				),
			)
		);
	}
}
