<?php
/**
 * Simple product discount panel, save handler and product list column.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Admin_Product
 */
class Viramoon_PD_Admin_Product {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'process_product' ), 20 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'verify_product' ), 20 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 20, 2 );
	}

	/**
	 * Add the product data tab.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs['viramoon_pd'] = array(
			'label'    => __( 'تخفیف ویرامون', 'viramoon-product-discounts' ),
			'target'   => 'viramoon_pd_product_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Render the product data panel.
	 *
	 * @return void
	 */
	public static function render_panel() {
		global $post;

		$product = $post instanceof WP_Post ? wc_get_product( $post->ID ) : false;
		if ( ! $product || ! $product->is_type( 'simple' ) ) {
			return;
		}

		$discount = Viramoon_PD_Discount_Manager::get_discount( $product );
		$state    = Viramoon_PD_Discount_Manager::get_state( $product );

		// Self-heal: when the discount is in effect but the stored sale price was
		// wiped (or WooCommerce's `_price` cache is stale), repair it before the
		// panel is rendered so the numbers below always match reality.
		if ( self::maybe_repair_price( $product, $discount, $state ) ) {
			$refreshed = wc_get_product( $post->ID );
			if ( $refreshed instanceof WC_Product ) {
				$product  = $refreshed;
				$discount = Viramoon_PD_Discount_Manager::get_discount( $product );
				$state    = Viramoon_PD_Discount_Manager::get_state( $product );
			}
		}

		$regular  = (float) $product->get_regular_price( 'edit' );
		$sale     = Viramoon_PD_Discount_Manager::stored_sale_price( $product );
		$owned    = Viramoon_PD_Discount_Manager::is_owned( $product, $state );
		$conflict = ( '' !== $sale && ! $owned );

		$now       = time();
		$start_raw = Viramoon_PD_Calculator::parse_datetime( isset( $discount['start'] ) ? $discount['start'] : '' );
		$end_raw   = Viramoon_PD_Calculator::parse_datetime( isset( $discount['end'] ) ? $discount['end'] : '' );
		$start_ts  = is_int( $start_raw ) ? $start_raw : 0;
		$end_ts    = is_int( $end_raw ) ? $end_raw : 0;
		$tz_offset = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		?>
		<div id="viramoon_pd_product_data" class="panel woocommerce_options_panel hidden">
			<div class="viramoon-pd-panel"
				data-regular="<?php echo esc_attr( $regular ); ?>"
				data-decimals="<?php echo esc_attr( Viramoon_PD_Calculator::decimals() ); ?>">
				<?php wp_nonce_field( 'viramoon_pd_save_product', 'viramoon_pd_nonce' ); ?>

				<?php if ( 'yes' === $discount['enabled'] && $start_ts && $start_ts > $now ) : ?>
					<p class="viramoon-pd-window viramoon-pd-window-scheduled">
						<?php esc_html_e( 'این تخفیف زمان‌بندی شده و هنوز شروع نشده است؛ تا آن لحظه ووکامرس قیمت اصلی را نمایش می‌دهد.', 'viramoon-product-discounts' ); ?>
						<br />
						<?php esc_html_e( 'زمان شروع:', 'viramoon-product-discounts' ); ?>
						<strong class="viramoon-pd-ts" data-vp-ts="<?php echo esc_attr( $start_ts ); ?>" data-vp-offset="<?php echo esc_attr( $tz_offset ); ?>"><?php echo esc_html( date_i18n( 'Y/m/d H:i', $start_ts ) ); ?></strong>
					</p>
				<?php elseif ( 'yes' === $discount['enabled'] && $end_ts && $end_ts <= $now ) : ?>
					<p class="viramoon-pd-window viramoon-pd-window-expired">
						<?php esc_html_e( 'تاریخ پایان این تخفیف گذشته است، بنابراین تخفیف اعمال نمی‌شود و قیمت اصلی نمایش داده می‌شود. برای فعال شدن دوباره، تاریخ پایان را پاک کنید یا به آینده ببرید.', 'viramoon-product-discounts' ); ?>
						<br />
						<?php esc_html_e( 'زمان پایان:', 'viramoon-product-discounts' ); ?>
						<strong class="viramoon-pd-ts" data-vp-ts="<?php echo esc_attr( $end_ts ); ?>" data-vp-offset="<?php echo esc_attr( $tz_offset ); ?>"><?php echo esc_html( date_i18n( 'Y/m/d H:i', $end_ts ) ); ?></strong>
					</p>
				<?php elseif ( 'yes' === $discount['enabled'] && '' === $sale ) : ?>
					<p class="viramoon-pd-window viramoon-pd-window-missing">
						<?php esc_html_e( 'تخفیف روی «فعال» است اما قیمت حراج ذخیره نشده است. یک‌بار محصول را ذخیره کنید؛ اگر باز هم خالی ماند، پیام خطای بالای صفحه را ببینید.', 'viramoon-product-discounts' ); ?>
					</p>
				<?php endif; ?>

				<p class="form-field">
					<label for="viramoon_pd_enabled"><?php esc_html_e( 'وضعیت تخفیف', 'viramoon-product-discounts' ); ?></label>
					<select id="viramoon_pd_enabled" name="viramoon_pd[enabled]">
						<option value="yes" <?php selected( $discount['enabled'], 'yes' ); ?>><?php esc_html_e( 'فعال (تخفیف اعمال شود)', 'viramoon-product-discounts' ); ?></option>
						<option value="no" <?php selected( $discount['enabled'], 'no' ); ?>><?php esc_html_e( 'غیرفعال', 'viramoon-product-discounts' ); ?></option>
					</select>
					<span class="description"><?php esc_html_e( 'برای اعمال تخفیف باید این گزینه روی «فعال» باشد.', 'viramoon-product-discounts' ); ?></span>
				</p>

				<p class="form-field">
					<label for="viramoon_pd_type"><?php esc_html_e( 'نوع تخفیف', 'viramoon-product-discounts' ); ?></label>
					<select id="viramoon_pd_type" name="viramoon_pd[type]" class="viramoon-pd-type">
						<option value="percent" <?php selected( $discount['type'], 'percent' ); ?>><?php esc_html_e( 'درصدی', 'viramoon-product-discounts' ); ?></option>
						<option value="fixed" <?php selected( $discount['type'], 'fixed' ); ?>><?php esc_html_e( 'مبلغ ثابت', 'viramoon-product-discounts' ); ?></option>
					</select>
				</p>

				<p class="form-field">
					<label for="viramoon_pd_amount"><?php esc_html_e( 'مقدار تخفیف', 'viramoon-product-discounts' ); ?></label>
					<input type="number" step="0.01" min="0" id="viramoon_pd_amount" name="viramoon_pd[amount]" class="viramoon-pd-amount short" value="<?php echo esc_attr( $discount['amount'] ); ?>" />
					<span class="viramoon-pd-amount-unit"><?php echo esc_html( 'percent' === $discount['type'] ? '%' : Viramoon_PD_Settings::unit_label() ); ?></span>
				</p>

				<p class="form-field">
					<label for="viramoon_pd_start"><?php esc_html_e( 'شروع تخفیف (اختیاری)', 'viramoon-product-discounts' ); ?></label>
					<input type="datetime-local" id="viramoon_pd_start" name="viramoon_pd[start]" value="<?php echo esc_attr( self::to_input_value( $discount['start'] ) ); ?>" />
				</p>

				<p class="form-field">
					<label for="viramoon_pd_end"><?php esc_html_e( 'پایان تخفیف (اختیاری)', 'viramoon-product-discounts' ); ?></label>
					<input type="datetime-local" id="viramoon_pd_end" name="viramoon_pd[end]" value="<?php echo esc_attr( self::to_input_value( $discount['end'] ) ); ?>" />
				</p>

				<p class="form-field viramoon-pd-preview-row">
					<label><?php esc_html_e( 'پیش‌نمایش قیمت', 'viramoon-product-discounts' ); ?></label>
					<span class="viramoon-pd-preview">
						<?php esc_html_e( 'قیمت اصلی:', 'viramoon-product-discounts' ); ?>
						<strong class="viramoon-pd-preview-regular"><?php echo esc_html( Viramoon_PD_Settings::format_amount( $regular ) ); ?></strong>
						&nbsp;|&nbsp;
						<?php esc_html_e( 'قیمت نهایی:', 'viramoon-product-discounts' ); ?>
						<strong class="viramoon-pd-preview-final">—</strong>
						<span class="viramoon-pd-preview-percent"></span>
					</span>
				</p>

				<?php if ( $conflict ) : ?>
					<p class="form-field viramoon-pd-conflict">
						<label for="viramoon_pd_overwrite"><?php esc_html_e( 'حراج موجود', 'viramoon-product-discounts' ); ?></label>
						<label class="viramoon-pd-conflict-label">
							<input type="checkbox" id="viramoon_pd_overwrite" name="viramoon_pd[overwrite]" value="yes" />
							<?php
							printf(
								/* translators: %s: current sale price. */
								esc_html__( 'این محصول قیمت حراج %s دارد که توسط ویرامون ساخته نشده است. با فعال کردن این گزینه، حراج فعلی بازنویسی و پس از پایان تخفیف بازگردانده می‌شود.', 'viramoon-product-discounts' ),
								esc_html( Viramoon_PD_Settings::format_amount( (float) $sale ) )
							);
							?>
						</label>
					</p>
				<?php endif; ?>

				<p class="form-field viramoon-pd-status">
					<label><?php esc_html_e( 'وضعیت فعلی', 'viramoon-product-discounts' ); ?></label>
					<span class="viramoon-pd-status-text"><?php echo esc_html( self::state_label( $state ) ); ?></span>
				</p>

				<?php if ( '' === (string) $product->get_regular_price( 'edit' ) ) : ?>
					<p class="viramoon-pd-warning"><?php esc_html_e( 'قیمت اصلی این محصول تعیین نشده است؛ تا زمانی که قیمت اصلی خالی باشد تخفیف محاسبه نمی‌شود.', 'viramoon-product-discounts' ); ?></p>
				<?php endif; ?>

				<div class="viramoon-pd-diagnostics">
					<p class="viramoon-pd-diagnostics-title"><?php esc_html_e( 'تشخیص وضعیت (برای پشتیبانی)', 'viramoon-product-discounts' ); ?></p>
					<table class="widefat striped">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'قیمت اصلی ثبت‌شده', 'viramoon-product-discounts' ); ?></td>
								<td><?php echo esc_html( '' === (string) $product->get_regular_price( 'edit' ) ? '—' : Viramoon_PD_Settings::format_amount( $regular ) ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'قیمت حراج در دیتابیس', 'viramoon-product-discounts' ); ?></td>
								<td><?php echo esc_html( '' === $sale ? '—' : Viramoon_PD_Settings::format_amount( (float) $sale ) ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'قیمتی که ووکامرس الان می‌فروشد', 'viramoon-product-discounts' ); ?></td>
								<td><?php echo esc_html( Viramoon_PD_Settings::format_amount( (float) $product->get_price() ) ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'مالکیت حراج فعلی', 'viramoon-product-discounts' ); ?></td>
								<td>
									<?php
									if ( '' === $sale ) {
										esc_html_e( 'حراجی وجود ندارد', 'viramoon-product-discounts' );
									} elseif ( $owned ) {
										esc_html_e( 'توسط ویرامون ثبت شده', 'viramoon-product-discounts' );
									} else {
										esc_html_e( 'خارجی (دستی یا افزونه دیگر)', 'viramoon-product-discounts' );
									}
									?>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'اگر «قیمت حراج در دیتابیس» خالی است یعنی چیزی ذخیره نشده و باید پیام خطای بالای صفحه را بررسی کنید. اگر مقدار دارد اما فروشگاه قیمت قدیمی نشان می‌دهد، کش سایت را پاک کنید.', 'viramoon-product-discounts' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Re-apply an in-effect discount whose sale price is missing or stale.
	 *
	 * WooCommerce derives `_price` from `_sale_price` plus the sale window, so a
	 * wiped sale price (or a stale `_price` cache) makes the product look full
	 * price even though the discount definition is saved. This runs when the
	 * product edit screen opens and only touches products the plugin owns.
	 *
	 * @param WC_Product $product  Product being rendered.
	 * @param array      $discount Saved discount definition.
	 * @param array      $state    Saved ownership state.
	 * @return bool True when a repair was written.
	 */
	private static function maybe_repair_price( $product, array $discount, array $state ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( 'yes' !== $discount['enabled'] ) {
			return false;
		}

		if ( '' === trim( (string) $discount['amount'] ) || ! is_numeric( $discount['amount'] ) ) {
			return false;
		}

		$regular = $product->get_regular_price( 'edit' );
		if ( '' === (string) $regular || null === $regular || (float) $regular <= 0 ) {
			return false;
		}

		$now   = time();
		$start = Viramoon_PD_Calculator::parse_datetime( isset( $discount['start'] ) ? $discount['start'] : '' );
		$end   = Viramoon_PD_Calculator::parse_datetime( isset( $discount['end'] ) ? $discount['end'] : '' );

		if ( is_wp_error( $start ) || is_wp_error( $end ) ) {
			return false;
		}

		// A scheduled or finished window is not a defect; leave it untouched.
		if ( $start && (int) $start > $now ) {
			return false;
		}
		if ( $end && (int) $end <= $now ) {
			return false;
		}

		$decimals = Viramoon_PD_Calculator::decimals();
		$expected = wc_format_decimal(
			Viramoon_PD_Calculator::calculate( (float) $regular, $discount['type'], $discount['amount'] ),
			$decimals
		);
		$stored   = Viramoon_PD_Discount_Manager::stored_sale_price( $product );
		$live     = wc_format_decimal( $product->get_price(), $decimals );

		if ( $stored === $expected && $live === $expected ) {
			return false;
		}

		// Never overwrite a sale price that belongs to someone else. An empty
		// sale price is safe to write even when the state was never marked as
		// managed (a save that failed half way through).
		if ( '' !== $stored && ! Viramoon_PD_Discount_Manager::is_owned( $product, $state ) ) {
			return false;
		}

		$result = Viramoon_PD_Discount_Manager::apply(
			$product,
			$discount,
			array(
				'overwrite' => true,
				'source'    => 'admin_self_heal',
				'save'      => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			Viramoon_PD_Logger::error(
				'Discount self-heal failed: ' . $result->get_error_message(),
				array( 'product_id' => $product->get_id() )
			);

			return false;
		}

		Viramoon_PD_Logger::info(
			'Discount self-heal re-applied the sale price.',
			array( 'product_id' => $product->get_id() )
		);

		return true;
	}

	/**
	 * Save the simple product discount.
	 *
	 * Runs through `woocommerce_admin_process_product_object`, which WooCommerce
	 * fires right before it saves the product. The sale price is therefore set on
	 * the object and persisted by WooCommerce itself, so no hook ordering can
	 * overwrite it afterwards.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public static function process_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! isset( $_POST['viramoon_pd_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['viramoon_pd_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'viramoon_pd_save_product' ) ) {
			Viramoon_PD_Notices::push( __( 'بررسی امنیتی فرم تخفیف ناموفق بود؛ تغییرات ذخیره نشد.', 'viramoon-product-discounts' ) );
			return;
		}

		$post_id = $product->get_id();
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		// A variable product never owns a parent-level sale price.
		if ( ! $product->is_type( 'simple' ) ) {
			$state = Viramoon_PD_Discount_Manager::get_state( $product );
			if ( 'yes' === $state['managed'] ) {
				Viramoon_PD_Discount_Manager::clear( $product, true );
			}
			return;
		}

		$raw      = isset( $_POST['viramoon_pd'] ) && is_array( $_POST['viramoon_pd'] ) ? wp_unslash( $_POST['viramoon_pd'] ) : array();
		$discount = self::sanitize_input( $raw );

		if ( 'no' === $discount['enabled'] ) {
			Viramoon_PD_Discount_Manager::save_discount( $post_id, $discount );
			Viramoon_PD_Discount_Manager::clear( $product, true );

			if ( '' !== trim( (string) $discount['amount'] ) ) {
				Viramoon_PD_Notices::push( __( 'وضعیت تخفیف روی «غیرفعال» بود، بنابراین تخفیف اعمال نشد. برای اعمال، وضعیت را «فعال» کنید.', 'viramoon-product-discounts' ), 'warning' );
			}
			return;
		}

		$validation = Viramoon_PD_Calculator::validate( $discount, (float) $product->get_regular_price( 'edit' ) );
		if ( is_wp_error( $validation ) ) {
			Viramoon_PD_Notices::push( $validation->get_error_message() );
			return;
		}

		Viramoon_PD_Discount_Manager::save_discount( $post_id, $discount );

		$result = Viramoon_PD_Discount_Manager::apply(
			$product,
			$discount,
			array(
				'overwrite' => ! empty( $raw['overwrite'] ) && 'yes' === $raw['overwrite'],
				'source'    => 'product_admin',
				'save'      => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			Viramoon_PD_Notices::push( $result->get_error_message() );
			return;
		}

		Viramoon_PD_Notices::push(
			sprintf(
				/* translators: 1: status message, 2: final price. */
				__( '%1$s قیمت نهایی: %2$s', 'viramoon-product-discounts' ),
				$result['message'],
				Viramoon_PD_Settings::format_amount( $result['final_price'] )
			),
			'success'
		);
	}

	/**
	 * Safety net: if something wiped the sale price during the save, put it back.
	 *
	 * Only acts when the stored sale price is empty, so a manual sale price set by
	 * the shop manager is never overwritten.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public static function verify_product( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product || ! $product->is_type( 'simple' ) ) {
			return;
		}

		$state = Viramoon_PD_Discount_Manager::get_state( $product );
		if ( 'yes' !== $state['managed'] ) {
			return;
		}

		if ( '' !== Viramoon_PD_Discount_Manager::stored_sale_price( $product ) ) {
			return;
		}

		$discount = Viramoon_PD_Discount_Manager::get_discount( $product );
		if ( 'yes' !== $discount['enabled'] ) {
			return;
		}

		$result = Viramoon_PD_Discount_Manager::apply(
			$product,
			$discount,
			array(
				'overwrite' => true,
				'source'    => 'verify_after_save',
				'save'      => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			Viramoon_PD_Notices::push( $result->get_error_message() );
			return;
		}

		Viramoon_PD_Notices::push(
			__( 'قیمت حراج پس از ذخیره بازنویسی شده بود و توسط افزونه بازگردانده شد.', 'viramoon-product-discounts' ),
			'warning'
		);
	}

	/**
	 * Sanitize posted discount fields.
	 *
	 * @param array $raw Raw posted data.
	 * @return array
	 */
	public static function sanitize_input( array $raw ) {
		$type = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : 'percent';
		$amount = isset( $raw['amount'] ) ? sanitize_text_field( (string) $raw['amount'] ) : '';

		// Backwards compatible: an amount without an explicit status means "enabled".
		$enabled_raw = isset( $raw['enabled'] ) ? sanitize_key( $raw['enabled'] ) : '';
		if ( in_array( $enabled_raw, array( 'yes', 'no' ), true ) ) {
			$enabled = $enabled_raw;
		} else {
			$enabled = ( '' !== trim( $amount ) ) ? 'yes' : 'no';
		}

		return array(
			'enabled' => $enabled,
			'type'    => in_array( $type, array( 'percent', 'fixed' ), true ) ? $type : 'percent',
			'amount'  => $amount,
			'start'   => isset( $raw['start'] ) ? sanitize_text_field( (string) $raw['start'] ) : '',
			'end'     => isset( $raw['end'] ) ? sanitize_text_field( (string) $raw['end'] ) : '',
		);
	}

	/**
	 * Convert a stored date to a `datetime-local` value.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function to_input_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = str_replace( ' ', 'T', $value );
		return substr( $value, 0, 16 );
	}

	/**
	 * Human readable state label.
	 *
	 * @param array $state State data.
	 * @return string
	 */
	public static function state_label( array $state ) {
		switch ( $state['status'] ) {
			case 'active':
				return __( 'فعال', 'viramoon-product-discounts' );
			case 'scheduled':
				return __( 'زمان‌بندی‌شده (هنوز شروع نشده)', 'viramoon-product-discounts' );
			case 'expired':
				return __( 'پایان‌یافته', 'viramoon-product-discounts' );
			case 'conflict':
				return __( 'تعارض با حراج خارجی', 'viramoon-product-discounts' );
		}

		return __( 'غیرفعال', 'viramoon-product-discounts' );
	}

	/**
	 * Add the summary column to the product list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( 'yes' !== Viramoon_PD_Settings::get( 'show_admin_summary' ) ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'price' === $key ) {
				$new['viramoon_pd'] = __( 'تخفیف ویرامون', 'viramoon-product-discounts' );
			}
		}

		if ( ! isset( $new['viramoon_pd'] ) ) {
			$new['viramoon_pd'] = __( 'تخفیف ویرامون', 'viramoon-product-discounts' );
		}

		return $new;
	}

	/**
	 * Render the summary column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'viramoon_pd' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '—';
			return;
		}

		$discount = Viramoon_PD_Discount_Manager::get_discount( $product );
		$state    = Viramoon_PD_Discount_Manager::get_state( $product );

		if ( 'yes' !== $discount['enabled'] ) {
			echo '—';
			return;
		}

		$amount = 'percent' === $discount['type']
			? $discount['amount'] . '%'
			: Viramoon_PD_Settings::format_amount( (float) $discount['amount'] );

		echo esc_html( $amount ) . '<br /><small>' . esc_html( self::state_label( $state ) ) . '</small>';
	}
}
