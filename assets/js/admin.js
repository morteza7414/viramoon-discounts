/**
 * Viramoon Product Discounts – admin interactions.
 *
 * The browser only renders previews; every value is validated again in PHP.
 */
( function () {
	'use strict';

	if ( typeof window.viramoonPD === 'undefined' ) {
		return;
	}

	var PD = window.viramoonPD;

	/* ------------------------------------------------------------------ */
	/* Money helpers                                                       */
	/* ------------------------------------------------------------------ */

	function decimals() {
		var d = parseInt( PD.decimals, 10 );
		return isNaN( d ) ? 0 : d;
	}

	function roundingStep() {
		var s = PD.settings || {};
		if ( ! s.roundingEnabled ) {
			return 0;
		}
		switch ( s.roundingMode ) {
			case 'integer':
				return 1;
			case 'nearest_100':
				return 100;
			case 'nearest_1000':
				return 1000;
			case 'custom':
				return parseFloat( s.roundingStep ) || 0;
		}
		return 0;
	}

	function toFixedValue( value ) {
		var factor = Math.pow( 10, decimals() );
		return Math.round( value * factor ) / factor;
	}

	function applyRounding( price ) {
		var step = roundingStep();
		var value = price;
		if ( step > 0 ) {
			value = Math.round( value / step ) * step;
		}
		value = toFixedValue( value );
		return value > 0 ? value : 0;
	}

	function roundPrice( price, regular ) {
		var value = applyRounding( price );
		if ( regular > 0 && value >= regular ) {
			value = toFixedValue( regular - Math.pow( 10, -decimals() ) );
			if ( value < 0 ) {
				value = 0;
			}
		}
		return value;
	}

	function formatMoney( amount ) {
		var text = Number( amount ).toFixed( decimals() );
		var parts = text.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		text = parts.join( '.' );

		var currency = PD.currency || {};
		if ( currency.display === 'none' ) {
			return text;
		}
		if ( currency.display === 'code' ) {
			return text + ' ' + currency.code;
		}
		return text + ' ' + currency.symbol;
	}

	function calculateFinal( regular, type, amount ) {
		if ( ! isFinite( regular ) || regular <= 0 || ! isFinite( amount ) || amount <= 0 ) {
			return null;
		}
		var final = type === 'percent' ? regular * ( 1 - amount / 100 ) : regular - amount;
		return roundPrice( final, regular );
	}

	/* ------------------------------------------------------------------ */
	/* Live previews                                                       */
	/* ------------------------------------------------------------------ */

	function updatePreview( container ) {
		if ( ! container ) {
			return;
		}

		var regular = parseFloat( container.getAttribute( 'data-regular' ) ) || 0;
		var typeEl = container.querySelector( '.viramoon-pd-type' );
		var amountEl = container.querySelector( '.viramoon-pd-amount' );
		var finalEl = container.querySelector( '.viramoon-pd-preview-final' );
		var percentEl = container.querySelector( '.viramoon-pd-preview-percent' );
		var unitEl = container.querySelector( '.viramoon-pd-amount-unit' );

		if ( ! finalEl ) {
			return;
		}

		var type = typeEl ? typeEl.value : 'percent';
		var amount = amountEl ? parseFloat( amountEl.value ) : NaN;

		if ( unitEl ) {
			unitEl.textContent = type === 'percent' ? '%' : ( PD.currency && PD.currency.unit ? PD.currency.unit : '' );
		}

		var final = calculateFinal( regular, type, amount );
		if ( final === null ) {
			finalEl.textContent = '—';
			if ( percentEl ) {
				percentEl.textContent = '';
			}
			return;
		}

		finalEl.textContent = formatMoney( final );
		if ( percentEl ) {
			var pct = regular > 0 ? ( ( regular - final ) / regular ) * 100 : 0;
			percentEl.textContent = ' (' + pct.toFixed( 1 ) + '%)';
		}
	}

	function refreshAllPreviews() {
		document.querySelectorAll( '.viramoon-pd-panel, .viramoon-pd-variation' ).forEach( updatePreview );
	}

	document.addEventListener( 'input', function ( event ) {
		var container = event.target.closest( '.viramoon-pd-panel, .viramoon-pd-variation' );
		if ( ! container ) {
			return;
		}

		// Keep the variation preview in sync with WooCommerce's regular price field.
		if ( event.target.name && event.target.name.indexOf( 'variable_regular_price' ) === 0 ) {
			container.setAttribute( 'data-regular', parseFloat( event.target.value ) || 0 );
		}

		updatePreview( container );
	} );

	document.addEventListener( 'change', function ( event ) {
		var container = event.target.closest( '.viramoon-pd-panel, .viramoon-pd-variation' );
		if ( container ) {
			updatePreview( container );
		}
	} );

	// Prevent Enter inside our bulk widgets from submitting the product form.
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Enter' || event.target.tagName !== 'INPUT' ) {
			return;
		}
		if ( event.target.closest( '.viramoon-pd-bulk-var, .viramoon-pd-bulk-products' ) ) {
			event.preventDefault();
		}
	} );

	/* ------------------------------------------------------------------ */
	/* AJAX helpers                                                        */
	/* ------------------------------------------------------------------ */

	function buildBody( action, params ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', PD.nonce );
		if ( params ) {
			params.forEach( function ( value, key ) {
				body.append( key, value );
			} );
		}
		return body;
	}

	function request( action, params ) {
		return fetch( PD.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: buildBody( action, params )
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					var message = json && json.data && json.data.message ? json.data.message : PD.i18n.error;
					throw new Error( message );
				}
				return json.data;
			} );
	}

	function setBusy( element, busy ) {
		if ( ! element ) {
			return;
		}
		element.disabled = busy;
	}

	function showProgress( wrap, done, total ) {
		if ( ! wrap ) {
			return;
		}
		wrap.hidden = false;
		var bar = wrap.querySelector( '.viramoon-pd-progress-bar span' );
		var text = wrap.querySelector( '.viramoon-pd-progress-text' );
		var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		if ( bar ) {
			bar.style.width = percent + '%';
		}
		if ( text ) {
			text.textContent = PD.i18n.processing + ' ' + done + ' / ' + total + ' (' + percent + '%)';
		}
	}

	function hideProgress( wrap ) {
		if ( wrap ) {
			wrap.hidden = true;
		}
	}

	function renderSummary( target, summary ) {
		if ( ! target ) {
			return;
		}

		var html = '<div class="viramoon-pd-summary">';
		html += '<p><strong>' + PD.i18n.total + ':</strong> ' + summary.total + ' — ';
		html += '<strong>' + PD.i18n.updated + ':</strong> ' + summary.updated + ' — ';
		html += '<strong>' + PD.i18n.skipped + ':</strong> ' + summary.skipped + '</p>';

		if ( summary.errors && summary.errors.length ) {
			html += '<details><summary>' + PD.i18n.skipped + '</summary><ul>';
			summary.errors.forEach( function ( item ) {
				html += '<li>' + escapeHtml( item.name ) + ': ' + escapeHtml( item.reason ) + '</li>';
			} );
			html += '</ul></details>';
		}

		html += '</div>';
		target.innerHTML = html;
	}

	function renderPreview( target, preview ) {
		if ( ! target ) {
			return;
		}

		var html = '<div class="viramoon-pd-summary">';
		html += '<p><strong>' + PD.i18n.total + ':</strong> ' + preview.total + ' — ';
		html += '<strong>' + PD.i18n.updated + ':</strong> ' + preview.ok + ' — ';
		html += '<strong>' + PD.i18n.skipped + ':</strong> ' + preview.skipped + '</p>';

		if ( preview.rows && preview.rows.length ) {
			html += '<table class="widefat striped"><thead><tr><th>محصول</th><th>قیمت قبل</th><th>قیمت بعد</th></tr></thead><tbody>';
			preview.rows.forEach( function ( row ) {
				html += '<tr><td>' + escapeHtml( row.name ) + '</td><td>' + escapeHtml( row.before ) + '</td><td>' + escapeHtml( row.after ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		if ( preview.skip && preview.skip.length ) {
			html += '<details><summary>' + PD.i18n.skipped + '</summary><ul>';
			preview.skip.forEach( function ( item ) {
				html += '<li>' + escapeHtml( item.name ) + ': ' + escapeHtml( item.reason ) + '</li>';
			} );
			html += '</ul></details>';
		}

		html += '</div>';
		target.innerHTML = html;
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	/* ------------------------------------------------------------------ */
	/* Bulk variations metabox                                             */
	/* ------------------------------------------------------------------ */

	function initBulkVariations() {
		var root = document.querySelector( '.viramoon-pd-bulk-var' );
		if ( ! root ) {
			return;
		}

		var scopeEl = root.querySelector( '.viramoon-pd-var-scope' );
		var selectedWrap = root.querySelector( '.viramoon-pd-var-selected-wrap' );
		var selectedEl = root.querySelector( '.viramoon-pd-var-selected' );
		var typeEl = root.querySelector( '.viramoon-pd-type' );
		var amountEl = root.querySelector( '.viramoon-pd-var-amount' );
		var startEl = root.querySelector( '.viramoon-pd-var-start' );
		var endEl = root.querySelector( '.viramoon-pd-var-end' );
		var overwriteEl = root.querySelector( '.viramoon-pd-var-overwrite' );
		var previewBtn = root.querySelector( '.viramoon-pd-var-preview' );
		var applyBtn = root.querySelector( '.viramoon-pd-var-apply' );
		var clearBtn = root.querySelector( '.viramoon-pd-var-clear' );
		var progressEl = root.querySelector( '.viramoon-pd-progress' );
		var resultEl = root.querySelector( '.viramoon-pd-result' );

		function syncScope() {
			if ( selectedWrap ) {
				selectedWrap.hidden = scopeEl.value !== 'selected';
			}
		}

		function collect( withDiscount ) {
			var params = new FormData();
			params.append( 'product_id', root.getAttribute( 'data-product-id' ) );
			params.append( 'scope', scopeEl.value );
			params.append( 'overwrite', overwriteEl && overwriteEl.checked ? 'yes' : 'no' );

			if ( selectedEl ) {
				Array.prototype.forEach.call( selectedEl.selectedOptions, function ( option ) {
					params.append( 'selected[]', option.value );
				} );
			}

			if ( withDiscount ) {
				params.append( 'type', typeEl.value );
				params.append( 'amount', amountEl.value );
				params.append( 'start', startEl.value );
				params.append( 'end', endEl.value );
			}

			return params;
		}

		scopeEl.addEventListener( 'change', syncScope );
		syncScope();

		previewBtn.addEventListener( 'click', function () {
			resultEl.innerHTML = '<p>' + PD.i18n.loading + '</p>';
			applyBtn.disabled = true;

			request( 'viramoon_pd_bulk_var_preview', collect( true ) )
				.then( function ( data ) {
					renderPreview( resultEl, data );
					applyBtn.disabled = data.ok === 0;
				} )
				.catch( function ( error ) {
					resultEl.innerHTML = '<p class="viramoon-pd-error">' + escapeHtml( error.message ) + '</p>';
				} );
		} );

		applyBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( PD.i18n.confirmApply ) ) {
				return;
			}

			var params = collect( true );
			var offset = 0;
			var updated = 0;
			var skipped = 0;
			var total = 0;
			var errors = [];

			setBusy( applyBtn, true );
			setBusy( previewBtn, true );

			function step() {
				params.set( 'offset', offset );
				params.set( 'batch', 25 );

				request( 'viramoon_pd_bulk_var_apply', params )
					.then( function ( data ) {
						total = data.total;
						updated += data.updated;
						skipped += data.skipped;
						errors = errors.concat( data.errors || [] );
						offset = data.offset;
						showProgress( progressEl, offset, total );

						if ( data.done ) {
							hideProgress( progressEl );
							renderSummary( resultEl, { total: total, updated: updated, skipped: skipped, errors: errors } );
							setBusy( applyBtn, false );
							setBusy( previewBtn, false );
							refreshAllPreviews();
							return;
						}
						step();
					} )
					.catch( function ( error ) {
						hideProgress( progressEl );
						resultEl.innerHTML = '<p class="viramoon-pd-error">' + escapeHtml( error.message ) + '</p>';
						setBusy( applyBtn, false );
						setBusy( previewBtn, false );
					} );
			}

			step();
		} );

		clearBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( PD.i18n.confirmClear ) ) {
				return;
			}

			var params = collect( false );
			var offset = 0;
			var updated = 0;
			var skipped = 0;
			var total = 0;

			setBusy( clearBtn, true );

			function step() {
				params.set( 'offset', offset );
				params.set( 'batch', 25 );

				request( 'viramoon_pd_bulk_var_clear', params )
					.then( function ( data ) {
						total = data.total;
						updated += data.updated;
						skipped += data.skipped;
						offset = data.offset;
						showProgress( progressEl, offset, total );

						if ( data.done ) {
							hideProgress( progressEl );
							renderSummary( resultEl, { total: total, updated: updated, skipped: skipped, errors: [] } );
							setBusy( clearBtn, false );
							refreshAllPreviews();
							return;
						}
						step();
					} )
					.catch( function ( error ) {
						hideProgress( progressEl );
						resultEl.innerHTML = '<p class="viramoon-pd-error">' + escapeHtml( error.message ) + '</p>';
						setBusy( clearBtn, false );
					} );
			}

			step();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Bulk products page                                                  */
	/* ------------------------------------------------------------------ */

	function initBulkProducts() {
		var root = document.querySelector( '.viramoon-pd-bulk-products' );
		if ( ! root ) {
			return;
		}

		var selected = new Set();
		var currentPage = 1;
		var maxPages = 1;

		var listEl = root.querySelector( '.viramoon-pd-products-list' );
		var pageInfo = root.querySelector( '.viramoon-pd-page-info' );
		var prevBtn = root.querySelector( '.viramoon-pd-page-prev' );
		var nextBtn = root.querySelector( '.viramoon-pd-page-next' );
		var loadBtn = root.querySelector( '.viramoon-pd-products-load' );
		var selectAllBtn = root.querySelector( '.viramoon-pd-products-select-all' );
		var selectNoneBtn = root.querySelector( '.viramoon-pd-products-select-none' );

		var previewBtn = document.querySelector( '.viramoon-pd-bulk-preview' );
		var applyBtn = document.querySelector( '.viramoon-pd-bulk-apply' );
		var clearBtn = document.querySelector( '.viramoon-pd-bulk-clear' );
		var progressEl = document.querySelector( '.viramoon-pd-progress' );
		var resultEl = document.querySelector( '.viramoon-pd-result' );

		function filterParams() {
			var params = new FormData();
			params.append( 'product_type', root.querySelector( '.viramoon-pd-filter-type' ).value );
			params.append( 'category', root.querySelector( '.viramoon-pd-filter-cat' ).value );
			params.append( 'search', root.querySelector( '.viramoon-pd-filter-search' ).value );
			params.append( 'ids', root.querySelector( '.viramoon-pd-filter-ids' ).value );
			return params;
		}

		function renderList( items ) {
			if ( ! items.length ) {
				listEl.innerHTML = '<p>' + PD.i18n.empty + '</p>';
				return;
			}

			var html = '<table class="widefat striped"><thead><tr><th></th><th>محصول</th><th>نوع</th><th>قیمت اصلی</th><th>متغیرها</th></tr></thead><tbody>';
			items.forEach( function ( item ) {
				var checked = selected.has( String( item.id ) ) ? ' checked' : '';
				html += '<tr>';
				html += '<td><input type="checkbox" class="viramoon-pd-product-check" value="' + item.id + '"' + checked + ' /></td>';
				html += '<td><a href="' + escapeHtml( item.edit ) + '">' + escapeHtml( item.name ) + '</a></td>';
				html += '<td>' + escapeHtml( item.type ) + '</td>';
				html += '<td>' + escapeHtml( item.price ) + '</td>';
				html += '<td>' + ( item.variations ? item.variations : '—' ) + '</td>';
				html += '</tr>';
			} );
			html += '</tbody></table>';
			listEl.innerHTML = html;
		}

		function load( page ) {
			var params = filterParams();
			params.append( 'page', page );
			listEl.innerHTML = '<p>' + PD.i18n.loading + '</p>';

			request( 'viramoon_pd_products_list', params )
				.then( function ( data ) {
					currentPage = data.page;
					maxPages = data.pages;
					renderList( data.items || [] );
					pageInfo.textContent = currentPage + ' / ' + maxPages + ' (' + data.total + ')';
					prevBtn.disabled = currentPage <= 1;
					nextBtn.disabled = currentPage >= maxPages;
				} )
				.catch( function ( error ) {
					listEl.innerHTML = '<p class="viramoon-pd-error">' + escapeHtml( error.message ) + '</p>';
				} );
		}

		loadBtn.addEventListener( 'click', function () {
			load( 1 );
		} );

		prevBtn.addEventListener( 'click', function () {
			if ( currentPage > 1 ) {
				load( currentPage - 1 );
			}
		} );

		nextBtn.addEventListener( 'click', function () {
			if ( currentPage < maxPages ) {
				load( currentPage + 1 );
			}
		} );

		listEl.addEventListener( 'change', function ( event ) {
			if ( ! event.target.classList.contains( 'viramoon-pd-product-check' ) ) {
				return;
			}
			if ( event.target.checked ) {
				selected.add( event.target.value );
			} else {
				selected.delete( event.target.value );
			}
		} );

		selectAllBtn.addEventListener( 'click', function () {
			listEl.querySelectorAll( '.viramoon-pd-product-check' ).forEach( function ( box ) {
				box.checked = true;
				selected.add( box.value );
			} );
		} );

		selectNoneBtn.addEventListener( 'click', function () {
			selected.clear();
			listEl.querySelectorAll( '.viramoon-pd-product-check' ).forEach( function ( box ) {
				box.checked = false;
			} );
		} );

		function discountParams() {
			var params = new FormData();
			params.append( 'type', document.querySelector( '.viramoon-pd-type' ).value );
			params.append( 'amount', document.querySelector( '.viramoon-pd-bulk-amount' ).value );
			params.append( 'start', document.querySelector( '.viramoon-pd-bulk-start' ).value );
			params.append( 'end', document.querySelector( '.viramoon-pd-bulk-end' ).value );
			params.append( 'variable_mode', document.querySelector( '.viramoon-pd-bulk-variable-mode' ).value );
			params.append( 'overwrite', document.querySelector( '.viramoon-pd-bulk-overwrite' ).checked ? 'yes' : 'no' );
			selected.forEach( function ( id ) {
				params.append( 'product_ids[]', id );
			} );
			return params;
		}

		previewBtn.addEventListener( 'click', function () {
			if ( ! selected.size ) {
				window.alert( PD.i18n.noSelection );
				return;
			}

			resultEl.innerHTML = '<p>' + PD.i18n.loading + '</p>';
			applyBtn.disabled = true;

			request( 'viramoon_pd_bulk_products_preview', discountParams() )
				.then( function ( data ) {
					renderPreview( resultEl, data );
					applyBtn.disabled = data.ok === 0;
				} )
				.catch( function ( error ) {
					resultEl.innerHTML = '<p class="viramoon-pd-error">' + escapeHtml( error.message ) + '</p>';
				} );
		} );

		function runBatchLoop( action, params, button, onDone ) {
			var offset = 0;
			var updated = 0;
			var skipped = 0;
			var total = 0;
			var errors = [];

			setBusy( button, true );
			setBusy( previewBtn, true );

			function step() {
				params.set( 'offset', offset );
				params.set( 'batch', 20 );

				request( action, params )
					.then( function ( data ) {
						total = data.total;
						updated += data.updated;
						skipped += data.skipped;
						errors = errors.concat( data.errors || [] );
						offset = data.offset;
						showProgress( progressEl, offset, total );

						if ( data.done ) {
							hideProgress( progressEl );
							renderSummary( resultEl, { total: total, updated: updated, skipped: skipped, errors: errors } );
							setBusy( button, false );
							setBusy( previewBtn, false );
							if ( onDone ) {
								onDone();
							}
							return;
						}
						step();
					} )
					.catch( function ( error ) {
						hideProgress( progressEl );
						resultEl.innerHTML = '<p class="viramoon-pd-error">' + escapeHtml( error.message ) + '</p>';
						setBusy( button, false );
						setBusy( previewBtn, false );
					} );
			}

			step();
		}

		applyBtn.addEventListener( 'click', function () {
			if ( ! selected.size ) {
				window.alert( PD.i18n.noSelection );
				return;
			}
			if ( ! window.confirm( PD.i18n.confirmApply ) ) {
				return;
			}
			runBatchLoop( 'viramoon_pd_bulk_products_apply', discountParams(), applyBtn, null );
		} );

		clearBtn.addEventListener( 'click', function () {
			if ( ! selected.size ) {
				window.alert( PD.i18n.noSelection );
				return;
			}
			if ( ! window.confirm( PD.i18n.confirmClear ) ) {
				return;
			}
			runBatchLoop( 'viramoon_pd_bulk_products_clear', discountParams(), clearBtn, null );
		} );
	}

	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		refreshAllPreviews();
		initBulkVariations();
		initBulkProducts();
	} );

	// WooCommerce re-renders the variations box through AJAX.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'woocommerce_variations_loaded woocommerce_variations_added', function () {
			refreshAllPreviews();
		} );
	}
} )();
