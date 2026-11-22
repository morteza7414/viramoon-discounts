<?php
/**
 * Offsets payment-gateway commissions onto the cart prices.
 *
 * The gateway is only known during checkout, so this never touches the stored
 * product price (that would poison caches and affect every customer). Instead the
 * cart line price is recalculated on the fly from the untouched base price, and
 * only inside a checkout request: the shop, product page, mini cart and cart page
 * always keep showing the normal Viramoon discount.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Gateway_Fees
 */
class Viramoon_PD_Gateway_Fees {

	/**
	 * Gateway captured from the current request (POST / Store API).
	 *
	 * @var string
	 */
	private static $posted_gateway = '';

	/**
	 * Untouched price of every cart product seen in the current request.
	 *
	 * WooCommerce reuses the very same WC_Product instance for a cart item during
	 * a whole request, so a price written by this class would survive a later
	 * recalculation (for example when the customer switches gateway). Remembering
	 * the original value lets every recalculation start from a clean base instead
	 * of compounding – or leaking – a commission.
	 *
	 * @var array<int,float>
	 */
	private static $base_prices = array();

	/**
	 * True while a Store API (block checkout) checkout request is being handled.
	 *
	 * @var bool
	 */
	private static $rest_checkout = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'adjust_cart_prices' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fee_line' ), 20 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_notice' ) );

		// Capture the freshly selected gateway as early as possible.
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_posted_gateway' ), 5 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'capture_rest_gateway' ), 10, 3 );

		// Record what was charged on the order itself.
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'flag_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'flag_order_from_request' ), 20, 2 );

		// Make sure the cart is recalculated with the submitted gateway before the order is built.
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'recalculate_before_order' ), 5 );
	}

	/**
	 * Recalculate the cart right before the order is created.
	 *
	 * The order is built from the cart line totals, so they must reflect the
	 * gateway the customer actually submitted.
	 *
	 * @return void
	 */
	public static function recalculate_before_order() {
		if ( isset( $_POST['payment_method'] ) && is_string( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::remember_gateway( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Is the current request a checkout (or checkout recalculation) request?
	 *
	 * The gateway commission must never change the plain cart page, because the
	 * customer has not chosen a gateway there yet.
	 *
	 * @return bool
	 */
	private static function is_adjustment_context() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		// Never touch the cart page: no gateway has been chosen there yet.
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		// Block checkout: the Store API checkout route builds the order from the
		// cart and may recalculate the totals before doing so.
		if ( self::$rest_checkout ) {
			return true;
		}

		// Submitting the (classic) checkout form — regardless of the very early
		// hook WooCommerce processes it on, where is_checkout() is not reliable.
		if ( isset( $_POST['woocommerce_checkout_place_order'] ) || isset( $_POST['woocommerce-process-checkout-nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return true;
		}

		if ( isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$wc_ajax = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $wc_ajax, array( 'update_order_review', 'checkout' ), true ) ) {
				return true;
			}
		}

		if ( wp_doing_ajax() && isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$action = sanitize_key( wp_unslash( $_POST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( in_array( $action, array( 'woocommerce_update_order_review', 'woocommerce_checkout' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remember a gateway id for the rest of the request and refresh the session.
	 *
	 * @param string $gateway_id Raw gateway id.
	 * @return void
	 */
	private static function remember_gateway( $gateway_id ) {
		$gateway_id = sanitize_key( $gateway_id );
		if ( '' === $gateway_id ) {
			return;
		}

		self::$posted_gateway = $gateway_id;

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'chosen_payment_method', $gateway_id );
		}
	}

	/**
	 * Capture the gateway from the classic checkout recalculation request.
	 *
	 * @param string|array $post_data Serialized checkout form data.
	 * @return void
	 */
	public static function capture_posted_gateway( $post_data ) {
		$data = array();

		if ( is_array( $post_data ) ) {
			$data = $post_data;
		} elseif ( is_string( $post_data ) && '' !== $post_data ) {
			parse_str( $post_data, $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! empty( $data['payment_method'] ) && is_string( $data['payment_method'] ) ) {
			self::remember_gateway( $data['payment_method'] );
		}
	}

	/**
	 * Capture the gateway from a Store API (block checkout) request.
	 *
	 * @param mixed           $response Response.
	 * @param array|callable  $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function capture_rest_gateway( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/wc/store/' ) ) {
			return $response;
		}

		if ( 0 === strpos( (string) $request->get_route(), '/wc/store/v1/checkout' ) ) {
			self::$rest_checkout = true;
		}

		$method = $request->get_param( 'payment_method' );
		if ( is_string( $method ) && '' !== $method ) {
			self::remember_gateway( $method );
		}

		return $response;
	}

	/**
	 * Return the fee configuration of the currently selected gateway.
	 *
	 * @return array|null
	 */
	public static function get_active_fee() {
		$options = Viramoon_PD_Settings::get_options();

		if ( 'yes' !== $options['gateway_fee_enabled'] ) {
			return null;
		}

		$gateway_id = self::get_chosen_gateway();
		if ( '' === $gateway_id || ! isset( $options['gateway_fees'][ $gateway_id ] ) ) {
			return null;
		}

		$row = $options['gateway_fees'][ $gateway_id ];
		if ( ! is_array( $row ) || 'yes' !== $row['enabled'] ) {
			return null;
		}

		$percent = isset( $row['percent'] ) ? (float) $row['percent'] : 0;
		if ( $percent <= 0 || $percent >= 100 ) {
			return null;
		}

		return array(
			'gateway'  => $gateway_id,
			'percent'  => $percent,
			'mode'     => $options['gateway_fee_mode'],
			'fallback' => ( 'yes' === $options['gateway_fee_fallback'] ),
		);
	}

	/**
	 * Read the gateway chosen in the current session.
	 *
	 * @return string
	 */
	public static function get_chosen_gateway() {
		// Gateway captured earlier in this very request.
		if ( '' !== self::$posted_gateway ) {
			return self::$posted_gateway;
		}

		// During checkout WooCommerce posts the selected gateway with every recalculation.
		if ( isset( $_POST['payment_method'] ) && is_string( $_POST['payment_method'] ) ) {
			$posted = sanitize_key( wp_unslash( $_POST['payment_method'] ) );
			if ( '' !== $posted ) {
				self::$posted_gateway = $posted;
				return self::$posted_gateway;
			}
		}

		if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw  = wp_unslash( $_POST['post_data'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$data = array();

			if ( is_array( $raw ) ) {
				$data = $raw;
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				parse_str( $raw, $data );
			}

			if ( isset( $data['payment_method'] ) && is_string( $data['payment_method'] ) ) {
				$posted = sanitize_key( $data['payment_method'] );
				if ( '' !== $posted ) {
					self::$posted_gateway = $posted;
					return self::$posted_gateway;
				}
			}
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}

		$gateway = WC()->session->get( 'chosen_payment_method' );
		if ( ! is_string( $gateway ) || '' === $gateway ) {
			return '';
		}

		return sanitize_key( $gateway );
	}

	/**
	 * Adjust cart line prices for the selected gateway.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public static function adjust_cart_prices( $cart ) {
		if ( ! $cart instanceof WC_Cart || ! $cart->get_cart() ) {
			return;
		}

		if ( ! self::is_adjustment_context() ) {
			// Roll back anything changed earlier in this request so the mini cart
			// or the cart page never inherit a checkout-only price.
			self::restore_base_prices( $cart );
			return;
		}

		$fee = self::get_active_fee();

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$product = $item['data'];
			$id      = $product->get_id();

			if ( ! array_key_exists( $id, self::$base_prices ) ) {
				self::$base_prices[ $id ] = (float) $product->get_price();
			}

			$base = self::$base_prices[ $id ];

			if ( ! $fee || 'fee_line' === $fee['mode'] ) {
				// No commission for the selected gateway (or a separate fee line
				// is used): roll back any commission applied earlier in the same
				// request so switching gateways never leaks the old price.
				$product->set_price( $base );
				continue;
			}

			$product->set_price( self::calculate_price( $product, $fee, $base ) );
		}
	}

	/**
	 * Put the untouched price back on every cart product we modified.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	private static function restore_base_prices( $cart ) {
		if ( ! self::$base_prices || ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$id = $item['data']->get_id();
			if ( array_key_exists( $id, self::$base_prices ) ) {
				$item['data']->set_price( self::$base_prices[ $id ] );
			}
		}
	}

	/**
	 * Calculate the price to charge for one cart item.
	 *
	 * The calculation always starts from the untouched base price, so calling it
	 * several times in the same request is safe (no compounded discounts).
	 *
	 * @param WC_Product $product Product or variation in the cart.
	 * @param array      $fee     Active fee configuration.
	 * @param float      $base    Untouched price of the product.
	 * @return float
	 */
	private static function calculate_price( $product, array $fee, $base ) {
		$regular      = (float) $product->get_regular_price( 'edit' );
		$has_discount = Viramoon_PD_Discount_Manager::is_active( $product );

		if ( $regular <= 0 ) {
			$regular = $base;
		}

		if ( 'gross_up' === $fee['mode'] ) {
			$price = $base / ( 1 - ( $fee['percent'] / 100 ) );
			return Viramoon_PD_Calculator::apply_rounding( $price );
		}

		// Offset mode: the gateway commission eats into the Viramoon discount.
		if ( ! $has_discount ) {
			if ( $fee['fallback'] ) {
				return Viramoon_PD_Calculator::apply_rounding( $base / ( 1 - ( $fee['percent'] / 100 ) ) );
			}

			return $base;
		}

		$discount = Viramoon_PD_Discount_Manager::get_discount( $product );
		$amount   = (float) $discount['amount'];

		if ( 'percent' === $discount['type'] ) {
			$effective = max( 0, $amount - $fee['percent'] );
			$price     = $regular * ( 1 - ( $effective / 100 ) );
		} else {
			$effective = max( 0, $amount - ( $regular * $fee['percent'] / 100 ) );
			$price     = $regular - $effective;
		}

		return Viramoon_PD_Calculator::round_price( $price, $regular );
	}

	/**
	 * Add the commission as a separate cart fee (fee_line mode).
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public static function add_fee_line( $cart ) {
		if ( ! self::is_adjustment_context() ) {
			return;
		}

		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$fee = self::get_active_fee();
		if ( ! $fee || 'fee_line' !== $fee['mode'] ) {
			return;
		}

		$subtotal = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$subtotal += (float) $item['data']->get_price() * (float) $item['quantity'];
		}

		if ( $subtotal <= 0 ) {
			return;
		}

		$amount = Viramoon_PD_Calculator::apply_rounding( $subtotal * $fee['percent'] / 100 );
		if ( $amount <= 0 ) {
			return;
		}

		$cart->add_fee(
			sprintf(
				/* translators: %s: payment gateway title. */
				__( 'کارمزد درگاه %s', 'viramoon-product-discounts' ),
				self::gateway_title( $fee['gateway'] )
			),
			$amount,
			false
		);
	}

	/**
	 * Show a short explanation above the payment methods.
	 *
	 * @return void
	 */
	public static function render_checkout_notice() {
		$fee = self::get_active_fee();
		if ( ! $fee ) {
			return;
		}

		/**
		 * Allow themes/plugins to hide the notice.
		 *
		 * @param bool $show Whether to show the notice.
		 */
		if ( ! apply_filters( 'viramoon_pd_show_gateway_notice', true ) ) {
			return;
		}

		$percent = rtrim( rtrim( number_format_i18n( $fee['percent'], 2 ), '0' ), '.' );

		switch ( $fee['mode'] ) {
			case 'gross_up':
				$message = sprintf(
					/* translators: %s: gateway commission percentage. */
					__( 'کارمزد %s این درگاه در قیمت محصولات این سفارش لحاظ شده است.', 'viramoon-product-discounts' ),
					$percent . '%'
				);
				break;

			case 'fee_line':
				$message = sprintf(
					/* translators: %s: gateway commission percentage. */
					__( 'کارمزد %s این درگاه به‌صورت یک ردیف جداگانه به سبد خرید اضافه شده است.', 'viramoon-product-discounts' ),
					$percent . '%'
				);
				break;

			default:
				$message = sprintf(
					/* translators: %s: gateway commission percentage. */
					__( 'کارمزد %s این درگاه از تخفیف محصولات این سفارش کسر شده است؛ بنابراین تخفیف نهایی شما کمتر از تخفیف اعلام‌شده روی محصول است.', 'viramoon-product-discounts' ),
					$percent . '%'
				);
		}

		echo '<div class="viramoon-pd-gateway-notice woocommerce-info">';
		echo esc_html( $message );
		echo '</div>';
	}

	/**
	 * Resolve a gateway title from its ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	private static function gateway_title( $gateway_id ) {
		$gateways = Viramoon_PD_Settings::get_gateways();
		if ( isset( $gateways[ $gateway_id ] ) && ! empty( $gateways[ $gateway_id ]->method_title ) ) {
			return $gateways[ $gateway_id ]->method_title;
		}

		return $gateway_id;
	}

	/**
	 * Commission percentage configured for every enabled gateway.
	 *
	 * Passed to the checkout script so it knows when a recalculation is needed.
	 * Gateways with a 0% commission are included on purpose: switching away from
	 * (or to) them still has to recalculate and drop/apply the commission.
	 *
	 * @return array<string,float>
	 */
	public static function get_fee_gateway_map() {
		$options = Viramoon_PD_Settings::get_options();
		$map     = array();

		if ( 'yes' !== $options['gateway_fee_enabled'] ) {
			return $map;
		}

		foreach ( $options['gateway_fees'] as $gateway_id => $row ) {
			if ( ! is_array( $row ) || 'yes' !== $row['enabled'] ) {
				continue;
			}
			$map[ sanitize_key( $gateway_id ) ] = (float) $row['percent'];
		}

		return $map;
	}

	/**
	 * Gateway ids that currently have a commission greater than zero.
	 *
	 * @return array
	 */
	public static function get_fee_gateway_ids() {
		$ids = array();

		foreach ( self::get_fee_gateway_map() as $gateway_id => $percent ) {
			if ( $percent > 0 ) {
				$ids[] = $gateway_id;
			}
		}

		return $ids;
	}

	/**
	 * Store what was charged on the order (classic checkout).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function flag_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			self::write_order_flag( $order );
		}
	}

	/**
	 * Store what was charged on the order (block checkout).
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function flag_order_from_request( $order, $request ) {
		if ( $order instanceof WC_Order ) {
			self::write_order_flag( $order );
		}
	}

	/**
	 * Write order meta + a note describing the gateway adjustment.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function write_order_flag( $order ) {
		$fee = self::get_active_fee();
		if ( ! $fee ) {
			return;
		}

		$order->update_meta_data( '_viramoon_pd_gateway', $fee['gateway'] );
		$order->update_meta_data( '_viramoon_pd_gateway_percent', $fee['percent'] );
		$order->update_meta_data( '_viramoon_pd_gateway_mode', $fee['mode'] );
		$order->save_meta_data();

		$order->add_order_note(
			sprintf(
				/* translators: 1: gateway id, 2: commission percentage, 3: mode. */
				__( 'کارمزد درگاه %1$s (%2$s%%) با روش %3$s روی قیمت اقلام این سفارش اعمال شد.', 'viramoon-product-discounts' ),
				$fee['gateway'],
				$fee['percent'],
				$fee['mode']
			)
		);

		Viramoon_PD_Logger::info(
			'Gateway commission applied to order.',
			array(
				'order_id' => $order->get_id(),
				'gateway'  => $fee['gateway'],
				'mode'     => $fee['mode'],
			)
		);
	}

	/**
	 * Explain what a given mode/fee does, for the settings screen example table.
	 *
	 * Sample: regular price 100 with a 20% Viramoon discount.
	 *
	 * @param string $mode         offset|gross_up|fee_line.
	 * @param float  $fee_percent  Gateway commission percentage.
	 * @param float  $discount_pct Sample discount percentage.
	 * @return array
	 */
	public static function explain_mode( $mode, $fee_percent, $discount_pct = 20 ) {
		$regular    = 100.0;
		$fee        = (float) $fee_percent / 100;
		$discounted = $regular * ( 1 - $discount_pct / 100 );

		switch ( $mode ) {
			case 'gross_up':
				$price = ( $fee < 1 ) ? $discounted / ( 1 - $fee ) : $discounted;
				break;

			case 'fee_line':
				$price = $discounted * ( 1 + $fee );
				break;

			default:
				$effective = max( 0, $discount_pct - (float) $fee_percent );
				$price     = $regular * ( 1 - $effective / 100 );
		}

		return array(
			'customer_percent' => $price,
			'seller_percent'   => $price * ( 1 - $fee ),
		);
	}
}
