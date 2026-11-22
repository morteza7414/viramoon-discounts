<?php
/**
 * Standalone bulk discount page for the product list.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Admin_Bulk_Products
 */
class Viramoon_PD_Admin_Bulk_Products {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'viramoon-pd-bulk';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'تخفیف گروهی ویرامون', 'viramoon-product-discounts' ),
			__( 'تخفیف گروهی ویرامون', 'viramoon-product-discounts' ),
			'edit_products',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'viramoon-product-discounts' ) );
		}

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		?>
		<div class="wrap viramoon-pd-wrap" dir="rtl">
			<h1><?php esc_html_e( 'تخفیف گروهی ویرامون', 'viramoon-product-discounts' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'محصولات را فیلتر کنید، انتخاب کنید و پس از پیش‌نمایش، تخفیف را به‌صورت دسته‌ای (batch) اعمال کنید. هیچ تغییری بدون پیش‌نمایش و تأیید شما ذخیره نمی‌شود.', 'viramoon-product-discounts' ); ?>
			</p>

			<div class="viramoon-pd-card">
				<h2><?php esc_html_e( '۱) انتخاب محصولات', 'viramoon-product-discounts' ); ?></h2>
				<div class="viramoon-pd-bulk-products" data-page="1">
					<div class="viramoon-pd-grid">
						<p>
							<label for="viramoon-pd-filter-type"><?php esc_html_e( 'نوع محصول', 'viramoon-product-discounts' ); ?></label>
							<select id="viramoon-pd-filter-type" class="viramoon-pd-filter-type">
								<option value=""><?php esc_html_e( 'همه', 'viramoon-product-discounts' ); ?></option>
								<option value="simple"><?php esc_html_e( 'ساده', 'viramoon-product-discounts' ); ?></option>
								<option value="variable"><?php esc_html_e( 'متغیر', 'viramoon-product-discounts' ); ?></option>
							</select>
						</p>
						<p>
							<label for="viramoon-pd-filter-cat"><?php esc_html_e( 'دسته‌بندی', 'viramoon-product-discounts' ); ?></label>
							<select id="viramoon-pd-filter-cat" class="viramoon-pd-filter-cat">
								<option value=""><?php esc_html_e( 'همه دسته‌ها', 'viramoon-product-discounts' ); ?></option>
								<?php if ( ! is_wp_error( $categories ) ) : ?>
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
						</p>
						<p>
							<label for="viramoon-pd-filter-search"><?php esc_html_e( 'جست‌وجو', 'viramoon-product-discounts' ); ?></label>
							<input type="search" id="viramoon-pd-filter-search" class="viramoon-pd-filter-search regular-text" placeholder="<?php esc_attr_e( 'نام محصول…', 'viramoon-product-discounts' ); ?>" />
						</p>
						<p>
							<label for="viramoon-pd-filter-ids"><?php esc_html_e( 'شناسه دستی', 'viramoon-product-discounts' ); ?></label>
							<input type="text" id="viramoon-pd-filter-ids" class="viramoon-pd-filter-ids regular-text" placeholder="<?php esc_attr_e( 'مثلاً 12, 45, 90', 'viramoon-product-discounts' ); ?>" />
						</p>
					</div>

					<p class="viramoon-pd-actions">
						<button type="button" class="button viramoon-pd-products-load"><?php esc_html_e( 'بارگذاری محصولات', 'viramoon-product-discounts' ); ?></button>
						<button type="button" class="button viramoon-pd-products-select-all"><?php esc_html_e( 'انتخاب همه در این صفحه', 'viramoon-product-discounts' ); ?></button>
						<button type="button" class="button viramoon-pd-products-select-none"><?php esc_html_e( 'حذف انتخاب‌ها', 'viramoon-product-discounts' ); ?></button>
					</p>

					<div class="viramoon-pd-products-list" aria-live="polite"></div>

					<p class="viramoon-pd-pagination">
						<button type="button" class="button viramoon-pd-page-prev" disabled><?php esc_html_e( 'صفحه قبل', 'viramoon-product-discounts' ); ?></button>
						<span class="viramoon-pd-page-info"></span>
						<button type="button" class="button viramoon-pd-page-next" disabled><?php esc_html_e( 'صفحه بعد', 'viramoon-product-discounts' ); ?></button>
					</p>
				</div>
			</div>

			<div class="viramoon-pd-card">
				<h2><?php esc_html_e( '۲) تنظیم تخفیف', 'viramoon-product-discounts' ); ?></h2>
				<div class="viramoon-pd-grid">
					<p>
						<label for="viramoon-pd-bulk-type"><?php esc_html_e( 'نوع تخفیف', 'viramoon-product-discounts' ); ?></label>
						<select id="viramoon-pd-bulk-type" class="viramoon-pd-type">
							<option value="percent"><?php esc_html_e( 'درصدی', 'viramoon-product-discounts' ); ?></option>
							<option value="fixed"><?php esc_html_e( 'مبلغ ثابت', 'viramoon-product-discounts' ); ?></option>
						</select>
					</p>
					<p>
						<label for="viramoon-pd-bulk-amount"><?php esc_html_e( 'مقدار تخفیف', 'viramoon-product-discounts' ); ?></label>
						<input type="number" step="0.01" min="0" id="viramoon-pd-bulk-amount" class="viramoon-pd-bulk-amount short" value="" />
					</p>
					<p>
						<label for="viramoon-pd-bulk-start"><?php esc_html_e( 'شروع (اختیاری)', 'viramoon-product-discounts' ); ?></label>
						<input type="datetime-local" id="viramoon-pd-bulk-start" class="viramoon-pd-bulk-start" value="" />
					</p>
					<p>
						<label for="viramoon-pd-bulk-end"><?php esc_html_e( 'پایان (اختیاری)', 'viramoon-product-discounts' ); ?></label>
						<input type="datetime-local" id="viramoon-pd-bulk-end" class="viramoon-pd-bulk-end" value="" />
					</p>
					<p>
						<label for="viramoon-pd-bulk-variable-mode"><?php esc_html_e( 'رفتار محصولات متغیر', 'viramoon-product-discounts' ); ?></label>
						<select id="viramoon-pd-bulk-variable-mode" class="viramoon-pd-bulk-variable-mode">
							<option value="all_variations"><?php esc_html_e( 'اعمال روی همه متغیرها', 'viramoon-product-discounts' ); ?></option>
							<option value="eligible_variations"><?php esc_html_e( 'فقط متغیرهای واجد شرایط (قیمت‌دار و منتشرشده)', 'viramoon-product-discounts' ); ?></option>
						</select>
					</p>
				</div>

				<p>
					<label>
						<input type="checkbox" class="viramoon-pd-bulk-overwrite" value="yes" />
						<?php esc_html_e( 'حراج‌های خارجی موجود بازنویسی شوند (در غیر این صورت آن محصولات/متغیرها رد می‌شوند).', 'viramoon-product-discounts' ); ?>
					</label>
				</p>

				<p class="viramoon-pd-actions">
					<button type="button" class="button viramoon-pd-bulk-preview"><?php esc_html_e( 'پیش‌نمایش', 'viramoon-product-discounts' ); ?></button>
					<button type="button" class="button button-primary viramoon-pd-bulk-apply" disabled><?php esc_html_e( 'اعمال تخفیف', 'viramoon-product-discounts' ); ?></button>
					<button type="button" class="button viramoon-pd-bulk-clear"><?php esc_html_e( 'لغو تخفیف محصولات انتخاب‌شده', 'viramoon-product-discounts' ); ?></button>
				</p>

				<div class="viramoon-pd-progress" hidden>
					<div class="viramoon-pd-progress-bar"><span></span></div>
					<p class="viramoon-pd-progress-text"></p>
				</div>

				<div class="viramoon-pd-result" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}
}
