<?php
/**
 * Bulk discount metabox for the variations of a variable product.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Admin_Bulk_Variations
 */
class Viramoon_PD_Admin_Bulk_Variations {

	/**
	 * Maximum number of variations rendered inside the "selected" picker.
	 */
	const MAX_PICKER_ITEMS = 1000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * Register the metabox.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'viramoon-pd-bulk-variations',
			__( 'اعمال گروهی تخفیف به متغیرها', 'viramoon-product-discounts' ),
			array( __CLASS__, 'render' ),
			'product',
			'normal',
			'low'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public static function render( $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			echo '<p>' . esc_html__( 'این بخش فقط برای محصولات متغیر نمایش داده می‌شود.', 'viramoon-product-discounts' ) . '</p>';
			return;
		}

		$children = $product->get_children();
		$children = is_array( $children ) ? $children : array();
		?>
		<div class="viramoon-pd-bulk-var" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<p class="viramoon-pd-bulk-hint">
				<?php
				printf(
					/* translators: %d: number of variations. */
					esc_html__( 'این محصول %d متغیر دارد. تغییرات پس از پیش‌نمایش و با تأیید شما اعمال می‌شود.', 'viramoon-product-discounts' ),
					count( $children )
				);
				?>
			</p>

			<div class="viramoon-pd-grid">
				<p>
					<label for="viramoon-pd-var-scope"><?php esc_html_e( 'دامنه اعمال', 'viramoon-product-discounts' ); ?></label>
					<select id="viramoon-pd-var-scope" class="viramoon-pd-var-scope">
						<option value="all"><?php esc_html_e( 'همه متغیرها', 'viramoon-product-discounts' ); ?></option>
						<option value="selected"><?php esc_html_e( 'فقط متغیرهای انتخاب‌شده', 'viramoon-product-discounts' ); ?></option>
						<option value="without_active"><?php esc_html_e( 'فقط متغیرهای بدون تخفیف فعال', 'viramoon-product-discounts' ); ?></option>
					</select>
				</p>

				<p>
					<label for="viramoon-pd-var-type"><?php esc_html_e( 'نوع تخفیف', 'viramoon-product-discounts' ); ?></label>
					<select id="viramoon-pd-var-type" class="viramoon-pd-type">
						<option value="percent"><?php esc_html_e( 'درصدی', 'viramoon-product-discounts' ); ?></option>
						<option value="fixed"><?php esc_html_e( 'مبلغ ثابت', 'viramoon-product-discounts' ); ?></option>
					</select>
				</p>

				<p>
					<label for="viramoon-pd-var-amount"><?php esc_html_e( 'مقدار تخفیف', 'viramoon-product-discounts' ); ?></label>
					<input type="number" step="0.01" min="0" id="viramoon-pd-var-amount" class="viramoon-pd-var-amount short" value="" />
				</p>

				<p>
					<label for="viramoon-pd-var-start"><?php esc_html_e( 'شروع (اختیاری)', 'viramoon-product-discounts' ); ?></label>
					<input type="datetime-local" id="viramoon-pd-var-start" class="viramoon-pd-var-start" value="" />
				</p>

				<p>
					<label for="viramoon-pd-var-end"><?php esc_html_e( 'پایان (اختیاری)', 'viramoon-product-discounts' ); ?></label>
					<input type="datetime-local" id="viramoon-pd-var-end" class="viramoon-pd-var-end" value="" />
				</p>
			</div>

			<p class="viramoon-pd-var-selected-wrap" hidden>
				<label for="viramoon-pd-var-selected"><?php esc_html_e( 'متغیرهای انتخاب‌شده', 'viramoon-product-discounts' ); ?></label>
				<select id="viramoon-pd-var-selected" class="viramoon-pd-var-selected" multiple size="6">
					<?php foreach ( array_slice( $children, 0, self::MAX_PICKER_ITEMS ) as $child_id ) : ?>
						<?php $child = wc_get_product( $child_id ); ?>
						<option value="<?php echo esc_attr( $child_id ); ?>">
							<?php echo esc_html( $child ? $child->get_name() : '#' . $child_id ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( count( $children ) > self::MAX_PICKER_ITEMS ) : ?>
					<span class="description"><?php esc_html_e( 'برای عملکرد بهتر فقط ۱۰۰۰ متغیر اول در این فهرست نمایش داده می‌شود؛ برای بقیه از دامنه «همه» استفاده کنید.', 'viramoon-product-discounts' ); ?></span>
				<?php endif; ?>
			</p>

			<p class="viramoon-pd-var-overwrite-wrap">
				<label>
					<input type="checkbox" class="viramoon-pd-var-overwrite" value="yes" />
					<?php esc_html_e( 'حراج‌های خارجی موجود بازنویسی شوند (در غیر این صورت آن متغیرها رد می‌شوند).', 'viramoon-product-discounts' ); ?>
				</label>
			</p>

			<p class="viramoon-pd-actions">
				<button type="button" class="button viramoon-pd-var-preview"><?php esc_html_e( 'پیش‌نمایش', 'viramoon-product-discounts' ); ?></button>
				<button type="button" class="button button-primary viramoon-pd-var-apply" disabled><?php esc_html_e( 'اعمال تخفیف', 'viramoon-product-discounts' ); ?></button>
				<button type="button" class="button viramoon-pd-var-clear"><?php esc_html_e( 'لغو تخفیف گروهی', 'viramoon-product-discounts' ); ?></button>
			</p>

			<div class="viramoon-pd-progress" hidden>
				<div class="viramoon-pd-progress-bar"><span></span></div>
				<p class="viramoon-pd-progress-text"></p>
			</div>

			<div class="viramoon-pd-result" aria-live="polite"></div>
		</div>
		<?php
	}
}
