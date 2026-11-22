<?php
/**
 * Per-variation discount fields inside the WooCommerce variations metabox.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Admin_Variations
 */
class Viramoon_PD_Admin_Variations {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_fields' ), 20, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'process_variation' ), 20, 2 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'verify_variation' ), 20, 2 );
	}

	/**
	 * Safety net for variations: restore the sale price if it was wiped on save.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 * @return void
	 */
	public static function verify_variation( $variation_id, $loop ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return;
		}

		$state = Viramoon_PD_Discount_Manager::get_state( $variation );
		if ( 'yes' !== $state['managed'] ) {
			return;
		}

		if ( '' !== Viramoon_PD_Discount_Manager::stored_sale_price( $variation ) ) {
			return;
		}

		$discount = Viramoon_PD_Discount_Manager::get_discount( $variation );
		if ( 'yes' !== $discount['enabled'] ) {
			return;
		}

		Viramoon_PD_Discount_Manager::apply(
			$variation,
			$discount,
			array(
				'overwrite' => true,
				'source'    => 'verify_after_save',
				'save'      => true,
			)
		);
	}

	/**
	 * Render the discount fields of one variation.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 * @return void
	 */
	public static function render_fields( $loop, $variation_data, $variation ) {
		$variation_id = isset( $variation->ID ) ? (int) $variation->ID : 0;
		if ( ! $variation_id ) {
			return;
		}

		$product_variation = wc_get_product( $variation_id );
		if ( ! $product_variation ) {
			return;
		}

		$discount = Viramoon_PD_Discount_Manager::get_discount( $product_variation );
		$state    = Viramoon_PD_Discount_Manager::get_state( $product_variation );
		$regular  = (float) $product_variation->get_regular_price( 'edit' );
		$sale     = Viramoon_PD_Discount_Manager::stored_sale_price( $product_variation );
		$owned    = Viramoon_PD_Discount_Manager::is_owned( $product_variation, $state );
		$conflict = ( '' !== $sale && ! $owned );
		$prefix   = 'viramoon_pd_variation[' . (int) $loop . ']';
		?>
		<div class="viramoon-pd-variation"
			data-regular="<?php echo esc_attr( $regular ); ?>"
			data-decimals="<?php echo esc_attr( Viramoon_PD_Calculator::decimals() ); ?>">
			<?php wp_nonce_field( 'viramoon_pd_save_variation', 'viramoon_pd_variation_nonce' ); ?>
			<p class="viramoon-pd-variation-title"><?php esc_html_e( 'تخفیف ویرامون', 'viramoon-product-discounts' ); ?></p>

			<p class="form-row form-row-first">
				<label>
					<?php esc_html_e( 'وضعیت', 'viramoon-product-discounts' ); ?>
					<select name="<?php echo esc_attr( $prefix ); ?>[enabled]">
						<option value="yes" <?php selected( $discount['enabled'], 'yes' ); ?>><?php esc_html_e( 'فعال', 'viramoon-product-discounts' ); ?></option>
						<option value="no" <?php selected( $discount['enabled'], 'no' ); ?>><?php esc_html_e( 'غیرفعال', 'viramoon-product-discounts' ); ?></option>
					</select>
				</label>
			</p>

			<p class="form-row form-row-last">
				<label>
					<?php esc_html_e( 'نوع', 'viramoon-product-discounts' ); ?>
					<select name="<?php echo esc_attr( $prefix ); ?>[type]" class="viramoon-pd-type">
						<option value="percent" <?php selected( $discount['type'], 'percent' ); ?>><?php esc_html_e( 'درصدی', 'viramoon-product-discounts' ); ?></option>
						<option value="fixed" <?php selected( $discount['type'], 'fixed' ); ?>><?php esc_html_e( 'مبلغ ثابت', 'viramoon-product-discounts' ); ?></option>
					</select>
				</label>
			</p>

			<p class="form-row form-row-first">
				<label>
					<?php esc_html_e( 'مقدار', 'viramoon-product-discounts' ); ?>
					<input type="number" step="0.01" min="0" class="viramoon-pd-amount short" name="<?php echo esc_attr( $prefix ); ?>[amount]" value="<?php echo esc_attr( $discount['amount'] ); ?>" />
				</label>
			</p>

			<p class="form-row form-row-last viramoon-pd-preview-row">
				<label><?php esc_html_e( 'قیمت نهایی', 'viramoon-product-discounts' ); ?></label>
				<span class="viramoon-pd-preview">
					<strong class="viramoon-pd-preview-final">—</strong>
					<span class="viramoon-pd-preview-percent"></span>
				</span>
			</p>

			<p class="form-row form-row-first">
				<label>
					<?php esc_html_e( 'شروع', 'viramoon-product-discounts' ); ?>
					<input type="datetime-local" name="<?php echo esc_attr( $prefix ); ?>[start]" value="<?php echo esc_attr( Viramoon_PD_Admin_Product::to_input_value( $discount['start'] ) ); ?>" />
				</label>
			</p>

			<p class="form-row form-row-last">
				<label>
					<?php esc_html_e( 'پایان', 'viramoon-product-discounts' ); ?>
					<input type="datetime-local" name="<?php echo esc_attr( $prefix ); ?>[end]" value="<?php echo esc_attr( Viramoon_PD_Admin_Product::to_input_value( $discount['end'] ) ); ?>" />
				</label>
			</p>

			<?php if ( $conflict ) : ?>
				<p class="form-row viramoon-pd-conflict">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[overwrite]" value="yes" />
						<?php
						printf(
							/* translators: %s: current sale price. */
							esc_html__( 'حراج فعلی (%s) توسط ویرامون ساخته نشده است؛ برای بازنویسی آن را تیک بزنید.', 'viramoon-product-discounts' ),
							esc_html( Viramoon_PD_Settings::format_amount( (float) $sale ) )
						);
						?>
					</label>
				</p>
			<?php endif; ?>

			<p class="form-row viramoon-pd-status">
				<small><?php echo esc_html( Viramoon_PD_Admin_Product::state_label( $state ) ); ?></small>
				<?php if ( $regular <= 0 ) : ?>
					<span class="viramoon-pd-warning"><?php esc_html_e( 'قیمت اصلی ندارد؛ تخفیف اعمال نمی‌شود.', 'viramoon-product-discounts' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save one variation discount.
	 *
	 * Runs through `woocommerce_admin_process_variation_object`, which WooCommerce
	 * fires right before it saves each variation, so the sale price set here is
	 * persisted by WooCommerce itself.
	 *
	 * @param WC_Product $variation Variation being saved.
	 * @param int        $loop      Loop index.
	 * @return void
	 */
	public static function process_variation( $variation, $loop ) {
		if ( ! $variation instanceof WC_Product ) {
			return;
		}

		if ( ! isset( $_POST['viramoon_pd_variation_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['viramoon_pd_variation_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'viramoon_pd_save_variation' ) ) {
			Viramoon_PD_Notices::push( __( 'بررسی امنیتی فرم متغیر ناموفق بود؛ تغییرات ذخیره نشد.', 'viramoon-product-discounts' ) );
			return;
		}

		$variation_id = $variation->get_id();

		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		if ( ! $variation->is_type( 'variation' ) ) {
			return;
		}

		$all = isset( $_POST['viramoon_pd_variation'] ) && is_array( $_POST['viramoon_pd_variation'] )
			? wp_unslash( $_POST['viramoon_pd_variation'] )
			: array();

		$raw = isset( $all[ $loop ] ) && is_array( $all[ $loop ] ) ? $all[ $loop ] : array();
		$discount = Viramoon_PD_Admin_Product::sanitize_input( $raw );

		if ( 'no' === $discount['enabled'] ) {
			Viramoon_PD_Discount_Manager::save_discount( $variation_id, $discount );
			Viramoon_PD_Discount_Manager::clear( $variation, true );
			return;
		}

		$validation = Viramoon_PD_Calculator::validate( $discount, (float) $variation->get_regular_price( 'edit' ) );
		if ( is_wp_error( $validation ) ) {
			Viramoon_PD_Notices::push(
				sprintf(
					/* translators: 1: variation label, 2: error message. */
					__( 'متغیر %1$s: %2$s', 'viramoon-product-discounts' ),
					$variation->get_name(),
					$validation->get_error_message()
				)
			);
			return;
		}

		Viramoon_PD_Discount_Manager::save_discount( $variation_id, $discount );

		$result = Viramoon_PD_Discount_Manager::apply(
			$variation,
			$discount,
			array(
				'overwrite' => ! empty( $raw['overwrite'] ) && 'yes' === $raw['overwrite'],
				'source'    => 'variation_admin',
				'save'      => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			Viramoon_PD_Notices::push(
				sprintf(
					/* translators: 1: variation label, 2: error message. */
					__( 'متغیر %1$s: %2$s', 'viramoon-product-discounts' ),
					$variation->get_name(),
					$result->get_error_message()
				)
			);
		}
	}
}
