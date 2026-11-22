<?php
/**
 * AJAX endpoints for the bulk operations.
 *
 * @package Viramoon_Product_Discounts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Viramoon_PD_Ajax
 */
class Viramoon_PD_Ajax {

	/**
	 * Maximum number of targets processed per request.
	 */
	const MAX_BATCH = 50;

	/**
	 * Soft time budget per request (seconds).
	 */
	const TIME_BUDGET = 15;

	/**
	 * Maximum number of products handled by one bulk product operation.
	 */
	const MAX_PRODUCTS = 2000;

	/**
	 * Register the AJAX handlers.
	 *
	 * @return void
	 */
	public static function init() {
		$actions = array(
			'viramoon_pd_bulk_var_preview'      => 'bulk_var_preview',
			'viramoon_pd_bulk_var_apply'        => 'bulk_var_apply',
			'viramoon_pd_bulk_var_clear'        => 'bulk_var_clear',
			'viramoon_pd_products_list'         => 'products_list',
			'viramoon_pd_bulk_products_preview' => 'bulk_products_preview',
			'viramoon_pd_bulk_products_apply'   => 'bulk_products_apply',
			'viramoon_pd_bulk_products_clear'   => 'bulk_products_clear',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Verify nonce and capability.
	 *
	 * @return void
	 */
	private static function guard() {
		check_ajax_referer( 'viramoon_pd_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'viramoon-product-discounts' ) ), 403 );
		}
	}

	/**
	 * Read a sanitized discount definition from the request.
	 *
	 * @return array|WP_Error
	 */
	private static function read_discount() {
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'percent';
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$start  = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end    = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

		if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) ) {
			return new WP_Error( 'viramoon_pd_type', __( 'نوع تخفیف نامعتبر است.', 'viramoon-product-discounts' ) );
		}

		if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return new WP_Error( 'viramoon_pd_amount', __( 'مقدار تخفیف باید عددی بزرگ‌تر از صفر باشد.', 'viramoon-product-discounts' ) );
		}

		if ( 'percent' === $type && (float) $amount > 100 ) {
			return new WP_Error( 'viramoon_pd_percent', __( 'درصد تخفیف نباید بیشتر از ۱۰۰ باشد.', 'viramoon-product-discounts' ) );
		}

		$start_ts = Viramoon_PD_Calculator::parse_datetime( $start );
		if ( is_wp_error( $start_ts ) ) {
			return $start_ts;
		}

		$end_ts = Viramoon_PD_Calculator::parse_datetime( $end );
		if ( is_wp_error( $end_ts ) ) {
			return $end_ts;
		}

		if ( $start_ts && $end_ts && $start_ts >= $end_ts ) {
			return new WP_Error( 'viramoon_pd_dates', __( 'تاریخ پایان باید بعد از تاریخ شروع باشد.', 'viramoon-product-discounts' ) );
		}

		return array(
			'enabled' => 'yes',
			'type'    => $type,
			'amount'  => $amount,
			'start'   => $start,
			'end'     => $end,
		);
	}

	/**
	 * Resolve the variation IDs targeted by a bulk variation request.
	 *
	 * @param int    $product_id Variable product ID.
	 * @param string $scope      all|selected|without_active.
	 * @param array  $selected   Selected variation IDs.
	 * @return array
	 */
	private static function get_variation_targets( $product_id, $scope, array $selected ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$children = array_map( 'intval', (array) $product->get_children() );

		if ( 'selected' === $scope ) {
			$children = array_values( array_intersect( $children, $selected ) );
		} elseif ( 'without_active' === $scope ) {
			$children = array_values(
				array_filter(
					$children,
					static function ( $child_id ) {
						$child = wc_get_product( $child_id );
						if ( ! $child ) {
							return false;
						}
						$discount = Viramoon_PD_Discount_Manager::get_discount( $child );
						$state    = Viramoon_PD_Discount_Manager::get_state( $child );
						return ! ( 'yes' === $discount['enabled'] && Viramoon_PD_Discount_Manager::is_owned( $child, $state ) );
					}
				)
			);
		}

		return $children;
	}

	/**
	 * Why a target cannot receive a discount, or an empty string when it can.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	private static function skip_reason( $product ) {
		if ( ! $product ) {
			return __( 'محصول یافت نشد.', 'viramoon-product-discounts' );
		}

		if ( 'publish' !== $product->get_status() ) {
			return __( 'وضعیت منتشرشده ندارد.', 'viramoon-product-discounts' );
		}

		if ( '' === (string) $product->get_regular_price( 'edit' ) ) {
			return __( 'قیمت اصلی ندارد.', 'viramoon-product-discounts' );
		}

		return '';
	}

	/**
	 * Apply or preview one target.
	 *
	 * @param WC_Product $product    Product or variation.
	 * @param array      $discount   Discount definition.
	 * @param bool       $overwrite  Overwrite foreign sale prices.
	 * @param bool       $preview    Preview only.
	 * @return array
	 */
	private static function handle_target( $product, array $discount, $overwrite, $preview ) {
		$reason = self::skip_reason( $product );
		if ( '' !== $reason ) {
			return array(
				'status' => 'skipped',
				'reason' => $reason,
			);
		}

		$regular = (float) $product->get_regular_price( 'edit' );
		$before  = (float) $product->get_price( 'edit' );

		$validation = Viramoon_PD_Calculator::validate( $discount, $regular );
		if ( is_wp_error( $validation ) ) {
			return array(
				'status' => 'skipped',
				'reason' => $validation->get_error_message(),
			);
		}

		$after = Viramoon_PD_Calculator::calculate( $regular, $discount['type'], $discount['amount'] );

		if ( $preview ) {
			return array(
				'status' => 'ok',
				'before' => $before,
				'after'  => $after,
			);
		}

		$result = Viramoon_PD_Discount_Manager::apply(
			$product,
			$discount,
			array(
				'overwrite' => (bool) $overwrite,
				'source'    => 'bulk',
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'status' => 'skipped',
				'reason' => $result->get_error_message(),
			);
		}

		Viramoon_PD_Discount_Manager::save_discount( $product->get_id(), $discount );

		return array(
			'status' => 'updated',
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Preview a bulk variation operation.
	 *
	 * @return void
	 */
	public static function bulk_var_preview() {
		self::guard();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$scope      = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		$scope      = in_array( $scope, array( 'all', 'selected', 'without_active' ), true ) ? $scope : 'all';
		$selected   = isset( $_POST['selected'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['selected'] ) ) ) : array();

		$discount = self::read_discount();
		if ( is_wp_error( $discount ) ) {
			wp_send_json_error( array( 'message' => $discount->get_error_message() ) );
		}

		$targets = self::get_variation_targets( $product_id, $scope, $selected );
		$summary = self::summarize( $targets, $discount );

		wp_send_json_success( $summary );
	}

	/**
	 * Apply a bulk variation operation in batches.
	 *
	 * @return void
	 */
	public static function bulk_var_apply() {
		self::guard();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$scope      = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		$scope      = in_array( $scope, array( 'all', 'selected', 'without_active' ), true ) ? $scope : 'all';
		$selected   = isset( $_POST['selected'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['selected'] ) ) ) : array();
		$overwrite  = ! empty( $_POST['overwrite'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['overwrite'] ) );

		$discount = self::read_discount();
		if ( is_wp_error( $discount ) ) {
			wp_send_json_error( array( 'message' => $discount->get_error_message() ) );
		}

		$targets = self::get_variation_targets( $product_id, $scope, $selected );
		wp_send_json_success( self::run_batch( $targets, $discount, $overwrite, false ) );
	}

	/**
	 * Remove the discount from the targeted variations.
	 *
	 * @return void
	 */
	public static function bulk_var_clear() {
		self::guard();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$scope      = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		$scope      = in_array( $scope, array( 'all', 'selected', 'without_active' ), true ) ? $scope : 'all';
		$selected   = isset( $_POST['selected'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['selected'] ) ) ) : array();

		$targets = self::get_variation_targets( $product_id, $scope, $selected );
		wp_send_json_success( self::run_batch( $targets, array(), false, true ) );
	}

	/**
	 * List products for the bulk page.
	 *
	 * @return void
	 */
	public static function products_list() {
		self::guard();

		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$type     = isset( $_POST['product_type'] ) ? sanitize_key( wp_unslash( $_POST['product_type'] ) ) : '';
		$category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$raw_ids  = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';

		$args = array(
			'status'   => array( 'publish', 'draft', 'private' ),
			'limit'    => 50,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( in_array( $type, array( 'simple', 'variable' ), true ) ) {
			$args['type'] = $type;
		}

		if ( $category ) {
			$term = get_term( $category, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$args['category'] = array( $term->slug );
			}
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $raw_ids ) {
			$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw_ids ) ) );
			if ( empty( $ids ) ) {
				wp_send_json_error( array( 'message' => __( 'شناسه‌های واردشده معتبر نیستند.', 'viramoon-product-discounts' ) ) );
			}
			$args['include'] = array_slice( $ids, 0, self::MAX_PRODUCTS );
			$args['paginate'] = false;
			$args['limit']    = -1;
		}

		$result   = wc_get_products( $args );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : ( is_array( $result ) ? $result : array() );
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );
		$pages    = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

		$items = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items[] = array(
				'id'         => $product->get_id(),
				'name'       => $product->get_name(),
				'type'       => $product->get_type(),
				'price'      => Viramoon_PD_Settings::format_amount( (float) $product->get_regular_price( 'edit' ) ),
				'variations' => $product->is_type( 'variable' ) ? count( (array) $product->get_children() ) : 0,
				'edit'       => get_edit_post_link( $product->get_id(), 'raw' ),
			);
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
				'pages' => max( 1, $pages ),
				'page'  => $page,
			)
		);
	}

	/**
	 * Build the flat target list for a product selection.
	 *
	 * @param array $product_ids Product IDs.
	 * @return array
	 */
	private static function build_targets( array $product_ids ) {
		$targets = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( (array) $product->get_children() as $child_id ) {
					$targets[] = (int) $child_id;
				}
			} else {
				$targets[] = (int) $product->get_id();
			}
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Read the selected product IDs from the request.
	 *
	 * @return array
	 */
	private static function read_product_ids() {
		$ids = isset( $_POST['product_ids'] ) ? (array) wp_unslash( $_POST['product_ids'] ) : array();
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		return array_slice( $ids, 0, self::MAX_PRODUCTS );
	}

	/**
	 * Preview a bulk product operation.
	 *
	 * @return void
	 */
	public static function bulk_products_preview() {
		self::guard();

		$discount = self::read_discount();
		if ( is_wp_error( $discount ) ) {
			wp_send_json_error( array( 'message' => $discount->get_error_message() ) );
		}

		$targets = self::build_targets( self::read_product_ids() );
		if ( empty( $targets ) ) {
			wp_send_json_error( array( 'message' => __( 'هیچ محصولی انتخاب نشده است.', 'viramoon-product-discounts' ) ) );
		}

		wp_send_json_success( self::summarize( $targets, $discount ) );
	}

	/**
	 * Apply a bulk product operation in batches.
	 *
	 * @return void
	 */
	public static function bulk_products_apply() {
		self::guard();

		$discount = self::read_discount();
		if ( is_wp_error( $discount ) ) {
			wp_send_json_error( array( 'message' => $discount->get_error_message() ) );
		}

		$targets   = self::build_targets( self::read_product_ids() );
		$overwrite = ! empty( $_POST['overwrite'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['overwrite'] ) );

		wp_send_json_success( self::run_batch( $targets, $discount, $overwrite, false ) );
	}

	/**
	 * Clear discounts on the selected products.
	 *
	 * @return void
	 */
	public static function bulk_products_clear() {
		self::guard();

		$targets = self::build_targets( self::read_product_ids() );
		wp_send_json_success( self::run_batch( $targets, array(), false, true ) );
	}

	/**
	 * Summarize a preview over the given targets.
	 *
	 * @param array $targets  Target IDs.
	 * @param array $discount Discount definition.
	 * @return array
	 */
	private static function summarize( array $targets, array $discount ) {
		$total   = count( $targets );
		$ok      = 0;
		$skipped = 0;
		$rows    = array();
		$skip    = array();

		foreach ( $targets as $target_id ) {
			$product = wc_get_product( $target_id );
			$result  = self::handle_target( $product, $discount, false, true );

			if ( 'ok' === $result['status'] ) {
				$ok++;
				if ( count( $rows ) < 50 ) {
					$rows[] = array(
						'name'   => $product->get_name(),
						'before' => Viramoon_PD_Settings::format_amount( $result['before'] ),
						'after'  => Viramoon_PD_Settings::format_amount( $result['after'] ),
					);
				}
			} else {
				$skipped++;
				if ( count( $skip ) < 50 ) {
					$skip[] = array(
						'name'   => $product ? $product->get_name() : '#' . $target_id,
						'reason' => $result['reason'],
					);
				}
			}
		}

		return array(
			'total'   => $total,
			'ok'      => $ok,
			'skipped' => $skipped,
			'rows'    => $rows,
			'skip'    => $skip,
			'done'    => true,
		);
	}

	/**
	 * Process one batch of targets.
	 *
	 * @param array $targets   Target IDs.
	 * @param array $discount  Discount definition.
	 * @param bool  $overwrite Overwrite foreign sale prices.
	 * @param bool  $clear     Clear instead of apply.
	 * @return array
	 */
	private static function run_batch( array $targets, array $discount, $overwrite, $clear ) {
		$total   = count( $targets );
		$offset  = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
		$batch   = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : self::MAX_BATCH;
		$batch   = max( 1, min( self::MAX_BATCH, $batch ) );
		$start   = microtime( true );
		$index   = $offset;
		$updated = 0;
		$skipped = 0;
		$errors  = array();

		while ( $index < $total && ( $index - $offset ) < $batch ) {
			if ( ( microtime( true ) - $start ) > self::TIME_BUDGET ) {
				break;
			}

			$target_id = (int) $targets[ $index ];
			$index++;

			$product = wc_get_product( $target_id );
			if ( ! $product ) {
				$skipped++;
				if ( count( $errors ) < 50 ) {
					$errors[] = array( 'name' => '#' . $target_id, 'reason' => __( 'محصول یافت نشد.', 'viramoon-product-discounts' ) );
				}
				continue;
			}

			if ( $clear ) {
				Viramoon_PD_Discount_Manager::clear( $product, true );
				$definition          = Viramoon_PD_Discount_Manager::get_discount( $product );
				$definition['enabled'] = 'no';
				Viramoon_PD_Discount_Manager::save_discount( $target_id, $definition );
				$updated++;
				continue;
			}

			$result = self::handle_target( $product, $discount, $overwrite, false );
			if ( 'updated' === $result['status'] ) {
				$updated++;
			} else {
				$skipped++;
				if ( count( $errors ) < 50 ) {
					$errors[] = array(
						'name'   => $product->get_name(),
						'reason' => $result['reason'],
					);
				}
			}
		}

		return array(
			'total'   => $total,
			'offset'  => $index,
			'done'    => ( $index >= $total ),
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}
}
