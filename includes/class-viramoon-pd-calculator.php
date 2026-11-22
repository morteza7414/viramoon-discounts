<?php
/**
 * Price math and validation.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Calculator
 */
class Viramoon_PD_Calculator {

	/**
	 * Number of decimals supported by WooCommerce.
	 *
	 * @return int
	 */
	public static function decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/**
	 * Smallest representable price unit.
	 *
	 * @return float
	 */
	public static function unit() {
		return pow( 10, -self::decimals() );
	}

	/**
	 * Round a price with the configured rounding policy (no clamping).
	 *
	 * @param float $price Raw price.
	 * @return float
	 */
	public static function apply_rounding( $price ) {
		$decimals = self::decimals();
		$price    = (float) $price;
		$options  = Viramoon_PD_Settings::get_options();

		if ( 'yes' === $options['rounding_enabled'] ) {
			$step = self::rounding_step( $options );
			if ( $step > 0 ) {
				$price = round( $price / $step ) * $step;
			}
		}

		$price = function_exists( 'wc_format_decimal' )
			? (float) wc_format_decimal( $price, $decimals )
			: (float) number_format( $price, $decimals, '.', '' );

		return $price > 0 ? $price : 0.0;
	}

	/**
	 * Round a discounted price and keep it strictly below the regular price.
	 *
	 * @param float $price   Raw discounted price.
	 * @param float $regular Regular price used as an upper bound.
	 * @return float
	 */
	public static function round_price( $price, $regular = 0 ) {
		$decimals = self::decimals();
		$price    = self::apply_rounding( $price );
		$regular  = (float) $regular;

		if ( $regular > 0 && $price >= $regular ) {
			$price = function_exists( 'wc_format_decimal' )
				? (float) wc_format_decimal( $regular - self::unit(), $decimals )
				: (float) number_format( $regular - self::unit(), $decimals, '.', '' );
			if ( $price < 0 ) {
				$price = 0.0;
			}
		}

		return $price;
	}

	/**
	 * Resolve the rounding step from the options.
	 *
	 * @param array $options Plugin options.
	 * @return float
	 */
	private static function rounding_step( array $options ) {
		switch ( $options['rounding_mode'] ) {
			case 'integer':
				return 1.0;
			case 'nearest_100':
				return 100.0;
			case 'nearest_1000':
				return 1000.0;
			case 'custom':
				return max( 0.0, (float) $options['rounding_step'] );
		}

		return 0.0;
	}

	/**
	 * Compute the final price of a discount.
	 *
	 * @param float  $regular_price Regular price (never the previous sale price).
	 * @param string $type          percent|fixed.
	 * @param float  $amount        Discount amount.
	 * @return float
	 */
	public static function calculate( $regular_price, $type, $amount ) {
		$regular = (float) $regular_price;
		$amount  = (float) $amount;

		if ( 'percent' === $type ) {
			$final = $regular * ( 1 - ( $amount / 100 ) );
		} else {
			$final = $regular - $amount;
		}

		return self::round_price( $final, $regular );
	}

	/**
	 * Validate a discount definition.
	 *
	 * @param array $discount      Discount data.
	 * @param float $regular_price Regular price of the product/variation.
	 * @return true|WP_Error
	 */
	public static function validate( array $discount, $regular_price ) {
		$options = Viramoon_PD_Settings::get_options();

		$type = isset( $discount['type'] ) ? (string) $discount['type'] : 'percent';
		if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) ) {
			return new WP_Error( 'viramoon_pd_type', __( 'نوع تخفیف نامعتبر است.', 'viramoon-product-discounts' ) );
		}

		$raw_amount = isset( $discount['amount'] ) ? (string) $discount['amount'] : '';
		if ( '' === trim( $raw_amount ) ) {
			return new WP_Error( 'viramoon_pd_amount_empty', __( 'مقدار تخفیف را وارد کنید.', 'viramoon-product-discounts' ) );
		}

		if ( ! is_numeric( $raw_amount ) ) {
			return new WP_Error( 'viramoon_pd_amount_numeric', __( 'مقدار تخفیف باید عدد باشد.', 'viramoon-product-discounts' ) );
		}

		$amount = (float) $raw_amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'viramoon_pd_amount_positive', __( 'مقدار تخفیف باید بزرگ‌تر از صفر باشد.', 'viramoon-product-discounts' ) );
		}

		$regular = (float) $regular_price;
		if ( $regular <= 0 ) {
			return new WP_Error( 'viramoon_pd_no_regular', __( 'قیمت اصلی محصول تعیین نشده است؛ تخفیف محاسبه نمی‌شود.', 'viramoon-product-discounts' ) );
		}

		$allow_full = ( 'yes' === $options['allow_full_discount'] );

		if ( 'percent' === $type ) {
			if ( $amount > 100 ) {
				return new WP_Error( 'viramoon_pd_percent_max', __( 'درصد تخفیف نباید بیشتر از ۱۰۰ باشد.', 'viramoon-product-discounts' ) );
			}
			if ( $amount >= 100 && ! $allow_full ) {
				return new WP_Error( 'viramoon_pd_percent_full', __( 'تخفیف ۱۰۰ درصدی در تنظیمات افزونه مجاز نشده است.', 'viramoon-product-discounts' ) );
			}
		} elseif ( $amount > $regular && ! $allow_full ) {
			return new WP_Error( 'viramoon_pd_fixed_max', __( 'مبلغ تخفیف از قیمت اصلی بیشتر است.', 'viramoon-product-discounts' ) );
		}

		$start = self::parse_datetime( isset( $discount['start'] ) ? $discount['start'] : '' );
		if ( is_wp_error( $start ) ) {
			return $start;
		}

		$end = self::parse_datetime( isset( $discount['end'] ) ? $discount['end'] : '' );
		if ( is_wp_error( $end ) ) {
			return $end;
		}

		if ( $start && $end && $start >= $end ) {
			return new WP_Error( 'viramoon_pd_dates_order', __( 'تاریخ پایان باید بعد از تاریخ شروع باشد.', 'viramoon-product-discounts' ) );
		}

		$final = self::calculate( $regular, $type, $amount );
		if ( $final <= 0 && ! $allow_full ) {
			return new WP_Error( 'viramoon_pd_zero_price', __( 'این تخفیف قیمت نهایی را صفر می‌کند. برای اجازه دادن به آن، گزینه تخفیف ۱۰۰ درصدی را در تنظیمات فعال کنید.', 'viramoon-product-discounts' ) );
		}

		return true;
	}

	/**
	 * Parse a date string in the site timezone.
	 *
	 * Accepts `Y-m-d H:i`, `Y-m-d H:i:s` and `Y-m-d` (also with a `T` separator).
	 *
	 * @param string $value Raw value.
	 * @return int|null|WP_Error Timestamp, null when empty, WP_Error when invalid.
	 */
	public static function parse_datetime( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( 'T', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, $tz );
			if ( ! $date instanceof DateTimeImmutable ) {
				continue;
			}

			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
				continue;
			}

			return $date->getTimestamp();
		}

		return new WP_Error(
			'viramoon_pd_date_invalid',
			sprintf(
				/* translators: %s: the invalid date string. */
				__( 'تاریخ «%s» معتبر نیست. قالب درست: YYYY-MM-DD HH:MM', 'viramoon-product-discounts' ),
				$value
			)
		);
	}

	/**
	 * Convert a timestamp to a WooCommerce-friendly date string in site time.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function timestamp_to_string( $timestamp ) {
		if ( ! $timestamp ) {
			return '';
		}

		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', (int) $timestamp ) : gmdate( 'Y-m-d H:i:s', (int) $timestamp );
	}
}
