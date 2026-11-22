/**
 * Viramoon Product Discounts – Jalali (Persian) date & time picker.
 *
 * Every native `datetime-local` field used by the plugin is replaced with a
 * Persian calendar widget. The original input stays in the form as a hidden
 * field, so the submitted value is still the Gregorian `Y-m-dTH:i` string that
 * WooCommerce and the PHP side already understand.
 *
 * The conversion routines implement the well known jalaali-js algorithm.
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Jalali <-> Gregorian conversion                                     */
	/* ------------------------------------------------------------------ */

	var BREAKS = [ -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 ];

	function div( a, b ) {
		return ~~( a / b );
	}

	function mod( a, b ) {
		return a - div( a, b ) * b;
	}

	function jalCal( jy ) {
		var bl = BREAKS.length;
		var gy = jy + 621;
		var leapJ = -14;
		var jp = BREAKS[ 0 ];
		var jm;
		var jump = 0;
		var leap;
		var n;
		var i;

		for ( i = 1; i < bl; i += 1 ) {
			jm = BREAKS[ i ];
			jump = jm - jp;
			if ( jy < jm ) {
				break;
			}
			leapJ = leapJ + div( jump, 33 ) * 8 + div( mod( jump, 33 ), 4 );
			jp = jm;
		}

		n = jy - jp;
		leapJ = leapJ + div( n, 33 ) * 8 + div( mod( n, 33 ) + 3, 4 );
		if ( mod( jump, 33 ) === 4 && jump - n === 4 ) {
			leapJ += 1;
		}

		var leapG = div( gy, 4 ) - div( ( div( gy, 100 ) + 1 ) * 3, 4 ) - 150;
		var march = 20 + leapJ - leapG;

		if ( jump - n < 6 ) {
			n = n - jump + div( jump + 4, 33 ) * 33;
		}
		leap = mod( mod( n + 1, 33 ) - 1, 4 );
		if ( leap === -1 ) {
			leap = 4;
		}

		return { leap: leap, gy: gy, march: march };
	}

	function g2d( gy, gm, gd ) {
		var d = div( ( gy + div( gm - 8, 6 ) + 100100 ) * 1461, 4 ) + div( 153 * mod( gm + 9, 12 ) + 2, 5 ) + gd - 34840408;
		d = d - div( div( gy + 100100 + div( gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
		return d;
	}

	function d2g( jdn ) {
		var j = 4 * jdn + 139361631;
		j = j + div( div( 4 * jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;

		var i = div( mod( j, 1461 ), 4 ) * 5 + 308;
		var gd = div( mod( i, 153 ), 5 ) + 1;
		var gm = mod( div( i, 153 ), 12 ) + 1;
		var gy = div( j, 1461 ) - 100100 + div( 8 - gm, 6 );

		return { gy: gy, gm: gm, gd: gd };
	}

	function j2d( jy, jm, jd ) {
		var r = jalCal( jy );
		return g2d( r.gy, 3, r.march ) + ( jm - 1 ) * 31 - div( jm, 7 ) * ( jm - 7 ) + jd - 1;
	}

	function d2j( jdn ) {
		var gy = d2g( jdn ).gy;
		var jy = gy - 621;
		var r = jalCal( jy );
		var jdn1f = g2d( gy, 3, r.march );
		var k = jdn - jdn1f;

		if ( k >= 0 ) {
			if ( k <= 185 ) {
				return { jy: jy, jm: 1 + div( k, 31 ), jd: mod( k, 31 ) + 1 };
			}
			k -= 186;
		} else {
			jy -= 1;
			k += 179;
			if ( r.leap === 1 ) {
				k += 1;
			}
		}

		return { jy: jy, jm: 7 + div( k, 30 ), jd: mod( k, 30 ) + 1 };
	}

	function gregorianToJalali( gy, gm, gd ) {
		return d2j( g2d( gy, gm, gd ) );
	}

	function jalaliToGregorian( jy, jm, jd ) {
		return d2g( j2d( jy, jm, jd ) );
	}

	function isLeapJalali( jy ) {
		return jalCal( jy ).leap === 0;
	}

	function jalaliMonthLength( jy, jm ) {
		if ( jm <= 6 ) {
			return 31;
		}
		if ( jm <= 11 ) {
			return 30;
		}
		return isLeapJalali( jy ) ? 30 : 29;
	}

	/* ------------------------------------------------------------------ */
	/* Formatting helpers                                                  */
	/* ------------------------------------------------------------------ */

	var MONTHS = [ 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ];
	var WEEKDAYS = [ 'ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج' ];
	var FA_DIGITS = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];

	function fa( value ) {
		return String( value ).replace( /[0-9]/g, function ( digit ) {
			return FA_DIGITS[ +digit ];
		} );
	}

	function pad( value, length ) {
		var text = String( Math.abs( value ) );
		while ( text.length < length ) {
			text = '0' + text;
		}
		return text;
	}

	function parseNative( raw ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec( String( raw || '' ) );
		if ( ! match ) {
			return null;
		}

		var jalali = gregorianToJalali( parseInt( match[ 1 ], 10 ), parseInt( match[ 2 ], 10 ), parseInt( match[ 3 ], 10 ) );
		return {
			jy: jalali.jy,
			jm: jalali.jm,
			jd: jalali.jd,
			hour: match[ 4 ] ? parseInt( match[ 4 ], 10 ) : 0,
			minute: match[ 5 ] ? parseInt( match[ 5 ], 10 ) : 0,
		};
	}

	function toNative( state ) {
		var gregorian = jalaliToGregorian( state.jy, state.jm, state.jd );
		return pad( gregorian.gy, 4 ) + '-' + pad( gregorian.gm, 2 ) + '-' + pad( gregorian.gd, 2 ) + 'T' + pad( state.hour, 2 ) + ':' + pad( state.minute, 2 );
	}

	function toDisplay( state ) {
		return fa( pad( state.jy, 4 ) + '/' + pad( state.jm, 2 ) + '/' + pad( state.jd, 2 ) + ' ' + pad( state.hour, 2 ) + ':' + pad( state.minute, 2 ) );
	}

	function todayState() {
		var now = new Date();
		var jalali = gregorianToJalali( now.getFullYear(), now.getMonth() + 1, now.getDate() );
		return { jy: jalali.jy, jm: jalali.jm, jd: jalali.jd, hour: 0, minute: 0 };
	}

	function weekdayIndex( jy, jm, jd ) {
		var gregorian = jalaliToGregorian( jy, jm, jd );
		var day = new Date( gregorian.gy, gregorian.gm - 1, gregorian.gd ).getDay();

		// JavaScript weeks start on Sunday; the Persian week starts on Saturday.
		return ( day + 1 ) % 7;
	}

	/* ------------------------------------------------------------------ */
	/* Field widget                                                        */
	/* ------------------------------------------------------------------ */

	var FIELD_SELECTOR = [
		'.viramoon-pd-panel input[type="datetime-local"]',
		'.viramoon-pd-variation input[type="datetime-local"]',
		'.viramoon-pd-bulk-var input[type="datetime-local"]',
		'.viramoon-pd-bulk-products input[type="datetime-local"]',
	].join( ',' );

	var popup = null;
	var nodes = {};
	var selection = null;
	var view = null;
	var dirty = false;

	function makeField( native ) {
		if ( ! native || native.dataset.vpJalali === '1' ) {
			return;
		}

		native.dataset.vpJalali = '1';
		native.classList.add( 'viramoon-pd-jalali-native' );

		var nativeId   = native.getAttribute( 'id' ) || '';
		var nativeName = native.getAttribute( 'name' ) || '';
		var isEnd      = /\[end\]/.test( nativeName ) || /_end$/.test( nativeId );

		var wrapper = document.createElement( 'span' );
		wrapper.className = 'viramoon-pd-jalali';

		var display = document.createElement( 'input' );
		display.type = 'text';
		display.readOnly = true;
		display.className = 'viramoon-pd-jalali-display';
		display.placeholder = 'انتخاب تاریخ و ساعت';
		display.autocomplete = 'off';

		if ( native.id ) {
			display.id = native.id;
			native.removeAttribute( 'id' );
		}

		var open = document.createElement( 'button' );
		open.type = 'button';
		open.className = 'button viramoon-pd-jalali-open';
		open.textContent = 'تقویم';

		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'button-link viramoon-pd-jalali-clear';
		clear.textContent = 'پاک کردن';

		wrapper.appendChild( display );
		wrapper.appendChild( open );
		wrapper.appendChild( clear );

		// Variation rows wrap the input inside its own <label>; keep the widget
		// outside of it so label clicks cannot fight the picker buttons.
		var host = native.parentNode;
		var anchor = ( host && host.tagName === 'LABEL' ) ? host : native;
		anchor.parentNode.insertBefore( wrapper, anchor.nextSibling );

		var field = {
			native: native,
			display: display,
			isEnd: isEnd,
		};

		function render() {
			display.value = native.value ? toDisplay( parseNative( native.value ) || todayState() ) : '';
		}

		field.render = render;
		field.state = function () {
			return parseNative( native.value );
		};

		field.commit = function ( state ) {
			native.value = toNative( state );
			render();
			native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		};

		display.addEventListener( 'focus', function () {
			openFor( field );
		} );
		display.addEventListener( 'click', function () {
			openFor( field );
		} );
		open.addEventListener( 'click', function () {
			openFor( field );
		} );
		clear.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			native.value = '';
			render();
			native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			close();
		} );

		render();
	}

	/* ------------------------------------------------------------------ */
	/* Calendar popup                                                      */
	/* ------------------------------------------------------------------ */

	function buildPopup() {
		var el = document.createElement( 'div' );
		el.className = 'viramoon-pd-jp';
		el.setAttribute( 'dir', 'rtl' );
		el.hidden = true;
		el.innerHTML =
			'<div class="viramoon-pd-jp-head">' +
				'<button type="button" class="viramoon-pd-jp-prev" aria-label="ماه قبل">‹</button>' +
				'<span class="viramoon-pd-jp-title"></span>' +
				'<button type="button" class="viramoon-pd-jp-next" aria-label="ماه بعد">›</button>' +
			'</div>' +
			'<div class="viramoon-pd-jp-week"></div>' +
			'<div class="viramoon-pd-jp-grid"></div>' +
			'<div class="viramoon-pd-jp-time">' +
				'<span>ساعت</span>' +
				'<select class="viramoon-pd-jp-hour"></select>' +
				'<select class="viramoon-pd-jp-minute"></select>' +
			'</div>' +
			'<div class="viramoon-pd-jp-foot">' +
				'<button type="button" class="button viramoon-pd-jp-today">امروز</button>' +
				'<button type="button" class="button button-primary viramoon-pd-jp-apply">تأیید</button>' +
				'<button type="button" class="button-link viramoon-pd-jp-clear">پاک کردن</button>' +
			'</div>';

		document.body.appendChild( el );

		nodes = {
			prev: el.querySelector( '.viramoon-pd-jp-prev' ),
			next: el.querySelector( '.viramoon-pd-jp-next' ),
			title: el.querySelector( '.viramoon-pd-jp-title' ),
			week: el.querySelector( '.viramoon-pd-jp-week' ),
			grid: el.querySelector( '.viramoon-pd-jp-grid' ),
			hour: el.querySelector( '.viramoon-pd-jp-hour' ),
			minute: el.querySelector( '.viramoon-pd-jp-minute' ),
			today: el.querySelector( '.viramoon-pd-jp-today' ),
			apply: el.querySelector( '.viramoon-pd-jp-apply' ),
			clear: el.querySelector( '.viramoon-pd-jp-clear' ),
		};

		nodes.week.innerHTML = WEEKDAYS.map( function ( name ) {
			return '<span>' + name + '</span>';
		} ).join( '' );

		var hours = '';
		var minutes = '';
		var i;
		for ( i = 0; i < 24; i += 1 ) {
			hours += '<option value="' + i + '">' + fa( pad( i, 2 ) ) + '</option>';
		}
		for ( i = 0; i < 60; i += 1 ) {
			minutes += '<option value="' + i + '">' + fa( pad( i, 2 ) ) + '</option>';
		}
		nodes.hour.innerHTML = hours;
		nodes.minute.innerHTML = minutes;

		nodes.prev.addEventListener( 'click', function () {
			view.jm -= 1;
			if ( view.jm < 1 ) {
				view.jm = 12;
				view.jy -= 1;
			}
			renderGrid();
		} );

		nodes.next.addEventListener( 'click', function () {
			view.jm += 1;
			if ( view.jm > 12 ) {
				view.jm = 1;
				view.jy += 1;
			}
			renderGrid();
		} );

		nodes.hour.addEventListener( 'change', function () {
			selection.hour = parseInt( nodes.hour.value, 10 ) || 0;
			dirty = true;
			commit();
		} );

		nodes.minute.addEventListener( 'change', function () {
			selection.minute = parseInt( nodes.minute.value, 10 ) || 0;
			dirty = true;
			commit();
		} );

		nodes.today.addEventListener( 'click', function () {
			var today = todayState();
			selection.jy = today.jy;
			selection.jm = today.jm;
			selection.jd = today.jd;
			view.jy = today.jy;
			view.jm = today.jm;
			dirty = true;
			renderGrid();
			commit();
		} );

		nodes.apply.addEventListener( 'click', function () {
			// Confirming an untouched, empty field must not invent a date.
			if ( dirty || ( selection.field && selection.field.native.value ) ) {
				commit();
			}
			close();
		} );

		nodes.clear.addEventListener( 'click', function () {
			selection.field.native.value = '';
			selection.field.render();
			selection.field.native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			close();
		} );

		document.addEventListener( 'mousedown', function ( event ) {
			if ( ! popup || popup.hidden ) {
				return;
			}
			if ( popup.contains( event.target ) || ( event.target.closest && event.target.closest( '.viramoon-pd-jalali' ) ) ) {
				return;
			}
			close();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && popup && ! popup.hidden ) {
				close();
			}
		} );

		return el;
	}

	function commit() {
		if ( selection && selection.field ) {
			selection.field.commit( selection );
		}
	}

	function populateTime() {
		nodes.hour.value = String( selection.hour );
		nodes.minute.value = String( selection.minute );
	}

	function renderGrid() {
		nodes.title.textContent = MONTHS[ view.jm - 1 ] + ' ' + fa( view.jy );
		nodes.grid.innerHTML = '';

		var today = todayState();
		var lead = weekdayIndex( view.jy, view.jm, 1 );
		var length = jalaliMonthLength( view.jy, view.jm );
		var i;

		for ( i = 0; i < lead; i += 1 ) {
			var spacer = document.createElement( 'span' );
			spacer.className = 'viramoon-pd-jp-empty';
			nodes.grid.appendChild( spacer );
		}

		for ( i = 1; i <= length; i += 1 ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'viramoon-pd-jp-day';
			button.textContent = fa( i );
			button.dataset.day = String( i );

			if ( view.jy === today.jy && view.jm === today.jm && i === today.jd ) {
				button.classList.add( 'is-today' );
			}

			if ( view.jy === selection.jy && view.jm === selection.jm && i === selection.jd ) {
				button.classList.add( 'is-selected' );
			}

			button.addEventListener( 'click', function () {
				selection.jy = view.jy;
				selection.jm = view.jm;
				selection.jd = parseInt( this.dataset.day, 10 );
				dirty = true;
				commit();
				renderGrid();
			} );

			nodes.grid.appendChild( button );
		}
	}

	function position( anchor ) {
		var rect = anchor.getBoundingClientRect();
		var offsetX = window.pageXOffset || document.documentElement.scrollLeft || 0;
		var offsetY = window.pageYOffset || document.documentElement.scrollTop || 0;
		var width = popup.offsetWidth || 292;
		var viewport = document.documentElement.clientWidth;

		popup.style.top = ( rect.bottom + offsetY + 6 ) + 'px';
		popup.style.left = Math.max( offsetX + 8, Math.min( rect.left + offsetX, offsetX + viewport - width - 8 ) ) + 'px';
	}

	function openFor( field ) {
		if ( ! popup ) {
			popup = buildPopup();
		}

		selection = field.state();
		if ( ! selection ) {
			selection = todayState();
			// An end date defaults to the end of the day, otherwise picking
			// "today" would create a window that is already over.
			if ( field.isEnd ) {
				selection.hour = 23;
				selection.minute = 59;
			}
		}
		selection.field = field;
		view = { jy: selection.jy, jm: selection.jm };
		dirty = false;

		nodes.hour.value = String( selection.hour );
		nodes.minute.value = String( selection.minute );

		renderGrid();
		popup.hidden = false;
		position( field.display );
	}

	function close() {
		if ( popup ) {
			popup.hidden = true;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Bootstrap                                                           */
	/* ------------------------------------------------------------------ */

	function refresh() {
		Array.prototype.forEach.call( document.querySelectorAll( FIELD_SELECTOR ), makeField );
		localizeTimestamps();
	}

	/**
	 * Render a Unix timestamp as a Jalali date/time using the site's UTC offset.
	 */
	function formatTimestamp( timestamp, offset ) {
		var date = new Date( ( timestamp + offset ) * 1000 );
		var jalali = gregorianToJalali( date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate() );

		return toDisplay( {
			jy: jalali.jy,
			jm: jalali.jm,
			jd: jalali.jd,
			hour: date.getUTCHours(),
			minute: date.getUTCMinutes(),
		} );
	}

	/**
	 * Replace server rendered dates (scheduling notices) with Jalali text.
	 */
	function localizeTimestamps() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-vp-ts]' ), function ( el ) {
			var timestamp = parseInt( el.getAttribute( 'data-vp-ts' ), 10 );
			if ( ! timestamp || timestamp <= 0 ) {
				return;
			}

			var offset = parseInt( el.getAttribute( 'data-vp-offset' ) || '0', 10 ) || 0;
			el.textContent = formatTimestamp( timestamp, offset );
		} );
	}

	function init() {
		refresh();

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'woocommerce_variations_loaded woocommerce_variations_added', function () {
				refresh();
			} );
		}

		if ( window.MutationObserver ) {
			var scheduled = false;
			var observer = new window.MutationObserver( function ( mutations ) {
				var relevant = mutations.some( function ( mutation ) {
					return ! popup || ! popup.contains( mutation.target );
				} );
				if ( ! relevant ) {
					return;
				}
				if ( scheduled ) {
					return;
				}
				scheduled = true;
				window.setTimeout( function () {
					scheduled = false;
					refresh();
				}, 150 );
			} );

			observer.observe( document.body, { childList: true, subtree: true } );
		}

		window.addEventListener( 'resize', function () {
			if ( popup && ! popup.hidden && selection && selection.field ) {
				position( selection.field.display );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.ViramoonPDJalali = {
		refresh: refresh,
		toJalali: gregorianToJalali,
		toGregorian: jalaliToGregorian,
		format: toDisplay,
	};
} )();
