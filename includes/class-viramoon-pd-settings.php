<?php
/**
 * Plugin settings (WordPress Settings API).
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Settings
 */
class Viramoon_PD_Settings {

	/**
	 * Option name that stores every plugin setting.
	 */
	const OPTION = 'viramoon_pd_options';

	/**
	 * Settings page slug.
	 */
	const PAGE = 'viramoon-pd-settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'rounding_enabled'    => 'no',
			'rounding_mode'       => 'integer',
			'rounding_step'       => '1',
			'allow_full_discount' => 'no',
			'price_display'       => 'symbol',
			'show_admin_summary'  => 'yes',
			'data_policy'         => 'keep',
			'gateway_fee_enabled' => 'no',
			'gateway_fee_mode'    => 'offset',
			'gateway_fee_fallback' => 'no',
			'gateway_fees'        => array(),
		);
	}

	/**
	 * Return the merged option array.
	 *
	 * @return array
	 */
	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$options = array_merge( self::defaults(), $saved );
		if ( ! is_array( $options['gateway_fees'] ) ) {
			$options['gateway_fees'] = array();
		}

		return $options;
	}

	/**
	 * Return a single option value.
	 *
	 * @param string $key Option key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		$options = self::get_options();
		return array_key_exists( $key, $options ) ? $options[ $key ] : null;
	}

	/**
	 * Format an amount for the admin interface according to the plugin setting.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function format_amount( $amount ) {
		$options  = self::get_options();
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$number   = number_format_i18n( (float) $amount, $decimals );

		switch ( $options['price_display'] ) {
			case 'none':
				return $number;
			case 'code':
				return $number . ' ' . ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
			default:
				$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
				return trim( $number . ' ' . $symbol );
		}
	}

	/**
	 * Currency unit label used next to amount inputs.
	 *
	 * @return string
	 */
	public static function unit_label() {
		$options = self::get_options();

		if ( 'none' === $options['price_display'] ) {
			return '';
		}
		if ( 'code' === $options['price_display'] ) {
			return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		}

		return function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
	}

	/**
	 * Register the admin submenu page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'تنظیمات تخفیف ویرامون', 'viramoon-product-discounts' ),
			__( 'تنظیمات ویرامون', 'viramoon-product-discounts' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings, sections and fields.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'viramoon_pd_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'viramoon_pd_general',
			__( 'تنظیمات عمومی', 'viramoon-product-discounts' ),
			array( __CLASS__, 'section_general_intro' ),
			self::PAGE
		);

		add_settings_field(
			'rounding_enabled',
			__( 'گرد کردن قیمت نهایی', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_rounding_enabled' ),
			self::PAGE,
			'viramoon_pd_general'
		);

		add_settings_field(
			'rounding_mode',
			__( 'روش گرد کردن', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_rounding_mode' ),
			self::PAGE,
			'viramoon_pd_general'
		);

		add_settings_field(
			'allow_full_discount',
			__( 'تخفیف ۱۰۰ درصدی', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_allow_full' ),
			self::PAGE,
			'viramoon_pd_general'
		);

		add_settings_field(
			'price_display',
			__( 'واحد نمایش مبلغ', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_price_display' ),
			self::PAGE,
			'viramoon_pd_general'
		);

		add_settings_field(
			'show_admin_summary',
			__( 'خلاصه تخفیف در مدیریت', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_show_summary' ),
			self::PAGE,
			'viramoon_pd_general'
		);

		add_settings_field(
			'data_policy',
			__( 'سیاست داده‌ها', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_data_policy' ),
			self::PAGE,
			'viramoon_pd_general'
		);

		add_settings_section(
			'viramoon_pd_gateway',
			__( 'کارمزد درگاه‌های پرداخت', 'viramoon-product-discounts' ),
			array( __CLASS__, 'section_gateway_intro' ),
			self::PAGE
		);

		add_settings_field(
			'gateway_fee_enabled',
			__( 'وضعیت', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_gateway_enabled' ),
			self::PAGE,
			'viramoon_pd_gateway'
		);

		add_settings_field(
			'gateway_fee_mode',
			__( 'روش اعمال', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_gateway_mode' ),
			self::PAGE,
			'viramoon_pd_gateway'
		);

		add_settings_field(
			'gateway_fee_fallback',
			__( 'محصولات بدون تخفیف', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_gateway_fallback' ),
			self::PAGE,
			'viramoon_pd_gateway'
		);

		add_settings_field(
			'gateway_fees',
			__( 'درصد کارمزد هر درگاه', 'viramoon-product-discounts' ),
			array( __CLASS__, 'field_gateway_fees' ),
			self::PAGE,
			'viramoon_pd_gateway'
		);
	}

	/**
	 * Sanitize the whole option array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		$out['rounding_enabled'] = ( isset( $input['rounding_enabled'] ) && 'yes' === $input['rounding_enabled'] ) ? 'yes' : 'no';

		$modes                 = array( 'integer', 'nearest_100', 'nearest_1000', 'custom' );
		$rounding_mode         = isset( $input['rounding_mode'] ) ? sanitize_key( $input['rounding_mode'] ) : 'integer';
		$out['rounding_mode']  = in_array( $rounding_mode, $modes, true ) ? $rounding_mode : 'integer';

		$step                 = isset( $input['rounding_step'] ) ? (float) $input['rounding_step'] : 1;
		$step                 = max( 0, $step );
		$out['rounding_step'] = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $step, 4 ) : (string) $step;

		$out['allow_full_discount'] = ( isset( $input['allow_full_discount'] ) && 'yes' === $input['allow_full_discount'] ) ? 'yes' : 'no';

		$displays             = array( 'symbol', 'code', 'none' );
		$price_display        = isset( $input['price_display'] ) ? sanitize_key( $input['price_display'] ) : 'symbol';
		$out['price_display'] = in_array( $price_display, $displays, true ) ? $price_display : 'symbol';

		$out['show_admin_summary'] = ( isset( $input['show_admin_summary'] ) && 'yes' === $input['show_admin_summary'] ) ? 'yes' : 'no';

		$policies           = array( 'keep', 'delete' );
		$data_policy        = isset( $input['data_policy'] ) ? sanitize_key( $input['data_policy'] ) : 'keep';
		$out['data_policy'] = in_array( $data_policy, $policies, true ) ? $data_policy : 'keep';

		$out['gateway_fee_enabled'] = ( isset( $input['gateway_fee_enabled'] ) && 'yes' === $input['gateway_fee_enabled'] ) ? 'yes' : 'no';

		$gateway_modes              = array( 'offset', 'gross_up', 'fee_line' );
		$gateway_mode               = isset( $input['gateway_fee_mode'] ) ? sanitize_key( $input['gateway_fee_mode'] ) : 'offset';
		$out['gateway_fee_mode']    = in_array( $gateway_mode, $gateway_modes, true ) ? $gateway_mode : 'offset';
		$out['gateway_fee_fallback'] = ( isset( $input['gateway_fee_fallback'] ) && 'yes' === $input['gateway_fee_fallback'] ) ? 'yes' : 'no';

		$out['gateway_fees'] = array();
		if ( isset( $input['gateway_fees'] ) && is_array( $input['gateway_fees'] ) ) {
			foreach ( $input['gateway_fees'] as $gateway_id => $row ) {
				$gateway_id = sanitize_key( $gateway_id );
				if ( '' === $gateway_id || ! is_array( $row ) ) {
					continue;
				}
				$percent = isset( $row['percent'] ) ? (float) $row['percent'] : 0;
				$percent = max( 0, min( 99.99, $percent ) );

				$out['gateway_fees'][ $gateway_id ] = array(
					'enabled' => ( isset( $row['enabled'] ) && 'yes' === $row['enabled'] ) ? 'yes' : 'no',
					'percent' => function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $percent, 2 ) : (string) $percent,
				);
			}
		}

		return $out;
	}

	/**
	 * Section intro: general.
	 *
	 * @return void
	 */
	public static function section_general_intro() {
		echo '<p>' . esc_html__( 'این تنظیمات روی محاسبه قیمت تخفیف و نمایش آن در پنل مدیریت اثر می‌گذارد.', 'viramoon-product-discounts' ) . '</p>';
	}

	/**
	 * Section intro: gateway fees.
	 *
	 * @return void
	 */
	public static function section_gateway_intro() {
		echo '<p>' . esc_html__( 'درگاه‌های پرداخت اقساطی مانند اسنپ‌پی و دیجی‌پی کارمزد خود را از فروشنده می‌گیرند. با تنظیم درصد کارمزد هر درگاه، می‌توانید این هزینه را هنگام انتخاب همان درگاه از مشتری دریافت کنید. این تنظیم فقط روی صفحه تسویه‌حساب اثر دارد؛ صفحه محصول، فروشگاه و سبد خرید همیشه قیمت با تخفیف عادی را نشان می‌دهند و قیمت ذخیره‌شده محصول هرگز تغییر نمی‌کند.', 'viramoon-product-discounts' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'با تغییر درگاه در صفحه تسویه‌حساب، مبلغ بلافاصله و به‌صورت خودکار بازمحاسبه می‌شود؛ اگر درگاهی با کارمزد صفر (مثل زرین‌پال) انتخاب شود، کارمزد قبلی کاملاً برداشته می‌شود و مشتری همان قیمت تخفیف‌خورده را می‌پردازد.', 'viramoon-product-discounts' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'هشدار مهم: در حالت «کاهش تخفیف»، اگر کارمزد درگاه بیشتر از تخفیف باشد (مثلاً تخفیف ۱۰٪ و کارمزد ۱۵٪)، تخفیف مؤثر صفر می‌شود و مشتری همان قیمت اصلی را می‌پردازد. در این حالت اگر می‌خواهید تخفیف مشتری حفظ شود، روش «افزایش قیمت» را انتخاب کنید.', 'viramoon-product-discounts' ) . '</p>';
	}

	/**
	 * Render the rounding checkbox.
	 *
	 * @return void
	 */
	public static function field_rounding_enabled() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[rounding_enabled]" value="yes" <?php checked( 'yes', $options['rounding_enabled'] ); ?> />
			<?php esc_html_e( 'قیمت نهایی پس از تخفیف گرد شود.', 'viramoon-product-discounts' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the rounding mode select.
	 *
	 * @return void
	 */
	public static function field_rounding_mode() {
		$options = self::get_options();
		$modes   = array(
			'integer'      => __( 'عدد صحیح', 'viramoon-product-discounts' ),
			'nearest_100'  => __( 'نزدیک‌ترین ۱۰۰', 'viramoon-product-discounts' ),
			'nearest_1000' => __( 'نزدیک‌ترین ۱۰۰۰', 'viramoon-product-discounts' ),
			'custom'       => __( 'مقدار دلخواه', 'viramoon-product-discounts' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[rounding_mode]">
			<?php foreach ( $modes as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['rounding_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<label>
				<?php esc_html_e( 'گام دلخواه:', 'viramoon-product-discounts' ); ?>
				<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION ); ?>[rounding_step]" value="<?php echo esc_attr( $options['rounding_step'] ); ?>" class="small-text" />
			</label>
		</p>
		<?php
	}

	/**
	 * Render the full-discount checkbox.
	 *
	 * @return void
	 */
	public static function field_allow_full() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[allow_full_discount]" value="yes" <?php checked( 'yes', $options['allow_full_discount'] ); ?> />
			<?php esc_html_e( 'اجازه تخفیف ۱۰۰ درصدی و رسیدن قیمت نهایی به صفر.', 'viramoon-product-discounts' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'اگر این گزینه غیرفعال باشد، تخفیفی که قیمت نهایی را صفر یا منفی کند رد می‌شود.', 'viramoon-product-discounts' ); ?></p>
		<?php
	}

	/**
	 * Render the price display select.
	 *
	 * @return void
	 */
	public static function field_price_display() {
		$options = self::get_options();
		$items   = array(
			'symbol' => __( 'نماد واحد پول ووکامرس', 'viramoon-product-discounts' ),
			'code'   => __( 'کد واحد پول (مثلاً IRT)', 'viramoon-product-discounts' ),
			'none'   => __( 'بدون واحد', 'viramoon-product-discounts' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[price_display]">
			<?php foreach ( $items as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['price_display'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'واحد پول از تنظیمات ووکامرس خوانده می‌شود و فقط نحوه نمایش مبلغ در رابط این افزونه را تغییر می‌دهد.', 'viramoon-product-discounts' ); ?></p>
		<?php
	}

	/**
	 * Render the admin summary checkbox.
	 *
	 * @return void
	 */
	public static function field_show_summary() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_admin_summary]" value="yes" <?php checked( 'yes', $options['show_admin_summary'] ); ?> />
			<?php esc_html_e( 'نمایش ستون «تخفیف ویرامون» در فهرست محصولات.', 'viramoon-product-discounts' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the data policy select.
	 *
	 * @return void
	 */
	public static function field_data_policy() {
		$options = self::get_options();
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[data_policy]">
			<option value="keep" <?php selected( $options['data_policy'], 'keep' ); ?>><?php esc_html_e( 'نگهداری داده‌ها پس از حذف افزونه', 'viramoon-product-discounts' ); ?></option>
			<option value="delete" <?php selected( $options['data_policy'], 'delete' ); ?>><?php esc_html_e( 'حذف داده‌های افزونه هنگام حذف افزونه', 'viramoon-product-discounts' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'حذف داده‌ها فقط متادیتای اختصاصی این افزونه و تنظیمات آن را پاک می‌کند. اگر تخفیف فعالی در حال اجرا باشد، ابتدا قیمت حراج قبلی (در صورت وجود) بازگردانده و سپس قیمت حراج استاندارد ووکامرس پاک می‌شود؛ قیمت‌های عادی محصول دست‌نخورده می‌مانند.', 'viramoon-product-discounts' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the gateway feature toggle.
	 *
	 * @return void
	 */
	public static function field_gateway_enabled() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[gateway_fee_enabled]" value="yes" <?php checked( 'yes', $options['gateway_fee_enabled'] ); ?> />
			<?php esc_html_e( 'کارمزد درگاه انتخابی هنگام تسویه‌حساب از مشتری گرفته شود.', 'viramoon-product-discounts' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the gateway mode select.
	 *
	 * @return void
	 */
	public static function field_gateway_mode() {
		$options = self::get_options();
		$modes   = array(
			'offset'   => __( 'کاهش تخفیف (تخفیف مؤثر = تخفیف − کارمزد)', 'viramoon-product-discounts' ),
			'gross_up' => __( 'افزایش قیمت به‌اندازه کارمزد (فروشنده دقیقاً مبلغ تخفیف‌خورده را دریافت می‌کند)', 'viramoon-product-discounts' ),
			'fee_line' => __( 'افزودن کارمزد به‌صورت یک ردیف جداگانه در سبد خرید', 'viramoon-product-discounts' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[gateway_fee_mode]">
			<?php foreach ( $modes as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['gateway_fee_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'مثال: تخفیف ۲۰٪ و کارمزد ۱۵٪. در حالت «کاهش تخفیف» مشتری ۵٪ تخفیف می‌گیرد. در حالت «افزایش قیمت» مشتری ۸۰٪ قیمت را می‌پردازد و کارمزد ۱۵٪ روی همان مبلغ محاسبه می‌شود تا فروشنده دقیقاً ۸۰٪ را دریافت کند.', 'viramoon-product-discounts' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the fallback checkbox for products without a discount.
	 *
	 * @return void
	 */
	public static function field_gateway_fallback() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[gateway_fee_fallback]" value="yes" <?php checked( 'yes', $options['gateway_fee_fallback'] ); ?> />
			<?php esc_html_e( 'برای محصولاتی که تخفیف ویرامون ندارند، کارمزد با روش «افزایش قیمت» اعمال شود.', 'viramoon-product-discounts' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'در حالت «کاهش تخفیف» محصولی که تخفیف ندارد تغییری نمی‌کند، مگر این گزینه فعال باشد.', 'viramoon-product-discounts' ); ?></p>
		<?php
	}

	/**
	 * Render the per-gateway fee table.
	 *
	 * @return void
	 */
	public static function field_gateway_fees() {
		$options  = self::get_options();
		$gateways = self::get_gateways();

		if ( empty( $gateways ) ) {
			echo '<p>' . esc_html__( 'هیچ درگاه پرداختی یافت نشد. ابتدا درگاه‌ها را در ووکامرس فعال کنید.', 'viramoon-product-discounts' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped viramoon-pd-gateway-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'درگاه', 'viramoon-product-discounts' ); ?></th>
					<th><?php esc_html_e( 'اعمال کارمزد', 'viramoon-product-discounts' ); ?></th>
					<th><?php esc_html_e( 'درصد کارمزد', 'viramoon-product-discounts' ); ?></th>
					<th><?php esc_html_e( 'نمونه با تخفیف ۲۰٪', 'viramoon-product-discounts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $gateways as $gateway ) : ?>
					<?php
					$gateway_id = isset( $gateway->id ) ? sanitize_key( $gateway->id ) : '';
					if ( '' === $gateway_id ) {
						continue;
					}
					$row     = isset( $options['gateway_fees'][ $gateway_id ] ) ? $options['gateway_fees'][ $gateway_id ] : array();
					$enabled = isset( $row['enabled'] ) ? $row['enabled'] : 'no';
					$percent = isset( $row['percent'] ) ? $row['percent'] : '0';
					$title   = isset( $gateway->method_title ) && $gateway->method_title ? $gateway->method_title : $gateway_id;
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $title ); ?></strong><br />
							<code><?php echo esc_html( $gateway_id ); ?></code>
						</td>
						<td>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[gateway_fees][<?php echo esc_attr( $gateway_id ); ?>][enabled]" value="yes" <?php checked( 'yes', $enabled ); ?> />
						</td>
						<td>
							<input type="number" step="0.01" min="0" max="99.99" class="small-text" name="<?php echo esc_attr( self::OPTION ); ?>[gateway_fees][<?php echo esc_attr( $gateway_id ); ?>][percent]" value="<?php echo esc_attr( $percent ); ?>" />
							%
						</td>
						<td>
							<?php
							$example = Viramoon_PD_Gateway_Fees::explain_mode( $options['gateway_fee_mode'], (float) $percent );
							printf(
								/* translators: 1: percentage the customer pays, 2: percentage the seller receives. */
								esc_html__( 'مشتری %1$s٪ از قیمت اصلی و فروشنده %2$s٪ را دریافت می‌کند.', 'viramoon-product-discounts' ),
								esc_html( number_format_i18n( $example['customer_percent'], 1 ) ),
								esc_html( number_format_i18n( $example['seller_percent'], 1 ) )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Return all registered WooCommerce payment gateways.
	 *
	 * @return array
	 */
	public static function get_gateways() {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		$payment_gateways = WC()->payment_gateways();
		if ( ! $payment_gateways ) {
			return array();
		}

		$gateways = $payment_gateways->payment_gateways();
		return is_array( $gateways ) ? $gateways : array();
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'viramoon-product-discounts' ) );
		}
		?>
		<div class="wrap viramoon-pd-wrap" dir="rtl">
			<h1><?php esc_html_e( 'تنظیمات تخفیف محصولات ویرامون', 'viramoon-product-discounts' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'viramoon_pd_settings' );
				do_settings_sections( self::PAGE );
				submit_button( __( 'ذخیره تنظیمات', 'viramoon-product-discounts' ) );
				?>
			</form>
		</div>
		<?php
	}
}
