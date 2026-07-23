/**
 * Product Store Locator — frontend map.
 *
 * Loads store markers onto a Google Map, shows details in info windows,
 * and recenters the map on ZIP/postcode search. No side list is rendered.
 *
 * The Google Maps script is enqueued with `callback=pslInitMap`, so the
 * global `pslInitMap` defined here is invoked once the API is ready.
 */
( function () {
	'use strict';

	var data = window.PSL_DATA || {};
	var map = null;
	var geocoder = null;
	var infoWindow = null;
	var markers = [];
	var searchBusy = false;

	/**
	 * Preset map styles (subset of Google's sample styles).
	 */
	var PRESET_STYLES = {
		silver: [
			{ elementType: 'geometry', stylers: [ { color: '#f5f5f5' } ] },
			{ elementType: 'labels.icon', stylers: [ { visibility: 'off' } ] },
			{ elementType: 'labels.text.fill', stylers: [ { color: '#616161' } ] },
			{ elementType: 'labels.text.stroke', stylers: [ { color: '#f5f5f5' } ] },
			{ featureType: 'road', elementType: 'geometry', stylers: [ { color: '#ffffff' } ] },
			{ featureType: 'water', elementType: 'geometry', stylers: [ { color: '#c9c9c9' } ] }
		],
		retro: [
			{ elementType: 'geometry', stylers: [ { color: '#ebe3cd' } ] },
			{ elementType: 'labels.text.fill', stylers: [ { color: '#523735' } ] },
			{ elementType: 'labels.text.stroke', stylers: [ { color: '#f5f1e6' } ] },
			{ featureType: 'road', elementType: 'geometry', stylers: [ { color: '#f5f1e6' } ] },
			{ featureType: 'water', elementType: 'geometry.fill', stylers: [ { color: '#b9d3c2' } ] }
		],
		night: [
			{ elementType: 'geometry', stylers: [ { color: '#242f3e' } ] },
			{ elementType: 'labels.text.fill', stylers: [ { color: '#746855' } ] },
			{ elementType: 'labels.text.stroke', stylers: [ { color: '#242f3e' } ] },
			{ featureType: 'road', elementType: 'geometry', stylers: [ { color: '#38414e' } ] },
			{ featureType: 'road', elementType: 'geometry.stroke', stylers: [ { color: '#212a37' } ] },
			{ featureType: 'water', elementType: 'geometry', stylers: [ { color: '#17263c' } ] }
		]
	};

	/**
	 * Resolve the configured map styles array.
	 *
	 * @return {Array} Styles array (possibly empty).
	 */
	function resolveStyles() {
		var choice = data.mapStyle || 'default';

		if ( choice === 'custom_json' && data.mapStyleJson ) {
			try {
				var parsed = JSON.parse( data.mapStyleJson );
				if ( Array.isArray( parsed ) ) {
					return parsed;
				}
			} catch ( e ) {
				// Fall through to default on malformed JSON.
			}
			return [];
		}

		return PRESET_STYLES[ choice ] || [];
	}

	/**
	 * Build a colored SVG pin as a data URI for marker icons.
	 *
	 * @param {string} color Hex color.
	 * @return {Object} Google Maps icon definition.
	 */
	function markerIcon( color ) {
		var safe = /^#[0-9a-fA-F]{3,8}$/.test( color ) ? color : '#d9433f';
		var svg =
			'<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 32 42">' +
			'<path d="M16 0C7.2 0 0 7.1 0 15.9 0 27 16 42 16 42s16-15 16-26.1C32 7.1 24.8 0 16 0z" fill="' + safe + '"/>' +
			'<circle cx="16" cy="16" r="6" fill="#ffffff"/>' +
			'</svg>';

		return {
			url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent( svg ),
			scaledSize: new google.maps.Size( 32, 42 ),
			anchor: new google.maps.Point( 16, 42 )
		};
	}

	/**
	 * Create an element with optional class and text.
	 *
	 * @param {string} tag       Tag name.
	 * @param {string} className Class name.
	 * @param {string} text      Text content.
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Return a monochrome inline-SVG icon that inherits the current text color.
	 *
	 * @param {string} name Icon name.
	 * @return {HTMLElement}
	 */
	function svgIcon( name ) {
		var paths = {
			phone: '<path d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.1.37 2.3.57 3.6.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.3.2 2.5.57 3.6a1 1 0 0 1-.25 1z"/>',
			directions: '<path d="M21.7 11.29 12.71 2.3a1 1 0 0 0-1.42 0l-9 9a1 1 0 0 0 0 1.42l9 9a1 1 0 0 0 1.42 0l9-9a1 1 0 0 0 0-1.43zM14 14.5V12h-4v3H8v-4a1 1 0 0 1 1-1h5V7.5l3.5 3.5z"/>',
			pin: '<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/>',
			clock: '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm3.3 13.7L11 13V7h1.5v5.2l3.5 2.1z"/>'
		};
		var span = el( 'span', 'psl-iw__svg' );
		span.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false">' +
			( paths[ name ] || '' ) + '</svg>';
		return span;
	}

	/**
	 * Format minutes-of-day (0–1439) as a 12-hour time string.
	 *
	 * @param {number} min Minutes.
	 * @return {string}
	 */
	function formatMinutes( min ) {
		min = ( ( min % 1440 ) + 1440 ) % 1440;
		var h = Math.floor( min / 60 );
		var m = min % 60;
		var ap = h >= 12 ? 'PM' : 'AM';
		var hh = h % 12;
		if ( hh === 0 ) {
			hh = 12;
		}
		return hh + ':' + ( m < 10 ? '0' + m : m ) + ' ' + ap;
	}

	/**
	 * Current time at the store, as a Date whose UTC parts are the store-local time.
	 *
	 * @param {number} utcOffset Store UTC offset in minutes.
	 * @return {Date}
	 */
	function nowInStore( utcOffset ) {
		var now = new Date();
		var utcMs = now.getTime() + now.getTimezoneOffset() * 60000;
		return new Date( utcMs + ( utcOffset || 0 ) * 60000 );
	}

	/**
	 * Compute open/closed status from structured periods.
	 *
	 * @param {Array}  periods   Period objects {open:{day,hour,minute}, close:{...}|null}.
	 * @param {number} utcOffset Store UTC offset in minutes.
	 * @return {Object|null} { open, until, opensAt, twentyFour } or null.
	 */
	function computeStatus( periods, utcOffset ) {
		if ( ! Array.isArray( periods ) || ! periods.length ) {
			return null;
		}

		var WEEK = 7 * 1440;
		var d = nowInStore( utcOffset );
		var nowMin = d.getUTCDay() * 1440 + d.getUTCHours() * 60 + d.getUTCMinutes();

		var intervals = [];
		var twentyFour = false;

		periods.forEach( function ( p ) {
			if ( ! p.open ) {
				return;
			}
			var start = p.open.day * 1440 + p.open.hour * 60 + p.open.minute;
			var end;
			if ( ! p.close ) {
				// No close = open continuously (24/7).
				twentyFour = true;
				end = start + WEEK;
			} else {
				end = p.close.day * 1440 + p.close.hour * 60 + p.close.minute;
				if ( end <= start ) {
					end += WEEK; // Wraps past the end of the week.
				}
			}
			intervals.push( { start: start, end: end, close: p.close } );
		} );

		for ( var i = 0; i < intervals.length; i++ ) {
			var iv = intervals[ i ];
			if ( ( nowMin >= iv.start && nowMin < iv.end ) ||
				( nowMin + WEEK >= iv.start && nowMin + WEEK < iv.end ) ) {
				if ( twentyFour && ! iv.close ) {
					return { open: true, twentyFour: true };
				}
				return { open: true, until: formatMinutes( iv.close.hour * 60 + iv.close.minute ) };
			}
		}

		// Closed — find the next opening.
		var next = null;
		intervals.forEach( function ( iv2 ) {
			[ iv2.start, iv2.start + WEEK ].forEach( function ( s ) {
				if ( s > nowMin && ( next === null || s < next ) ) {
					next = s;
				}
			} );
		} );

		return next !== null ? { open: false, opensAt: formatMinutes( next % 1440 ) } : { open: false };
	}

	/**
	 * Human-readable hours for a given weekday from structured periods.
	 *
	 * @param {Array}  periods Period objects.
	 * @param {number} day     0=Sunday.
	 * @return {string}
	 */
	function dayHoursText( periods, day ) {
		var i18n = data.i18n || {};
		var segments = [];
		periods.forEach( function ( p ) {
			if ( p.open && p.open.day === day ) {
				var openStr = formatMinutes( p.open.hour * 60 + p.open.minute );
				if ( ! p.close ) {
					segments.push( i18n.open24 || 'Open 24 hours' );
				} else {
					segments.push( openStr + ' – ' + formatMinutes( p.close.hour * 60 + p.close.minute ) );
				}
			}
		} );
		return segments.length ? segments.join( ', ' ) : ( i18n.closedDay || 'Closed' );
	}

	/**
	 * Build the collapsible hours block with live status.
	 *
	 * @param {Object} store Store record.
	 * @return {HTMLElement|null}
	 */
	function buildHours( store ) {
		var i18n = data.i18n || {};
		var periods = Array.isArray( store.hoursPeriods ) ? store.hoursPeriods : [];

		// Fallback: no structured data, just show the text hours if present.
		if ( ! periods.length ) {
			if ( ! store.hours ) {
				return null;
			}
			var wrap = el( 'div', 'psl-iw__hours' );
			var row = el( 'div', 'psl-iw__row' );
			row.appendChild( svgIcon( 'clock' ) );
			var textBox = el( 'div', 'psl-iw__hours-text' );
			store.hours.split( '\n' ).forEach( function ( line ) {
				textBox.appendChild( el( 'div', null, line ) );
			} );
			row.appendChild( textBox );
			wrap.appendChild( row );
			return wrap;
		}

		var status = computeStatus( periods, store.utcOffset );
		var wrapper = el( 'div', 'psl-iw__hours' );

		var toggle = el( 'button', 'psl-iw__hours-toggle' );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-expanded', 'false' );

		toggle.appendChild( svgIcon( 'clock' ) );

		var statusText = el( 'span', 'psl-iw__status' );
		if ( status && status.open ) {
			statusText.classList.add( 'is-open' );
			if ( status.twentyFour ) {
				statusText.textContent = i18n.open24 || 'Open 24 hours';
			} else {
				statusText.textContent = ( i18n.openUntil || 'Open until %s' ).replace( '%s', status.until );
			}
		} else {
			statusText.classList.add( 'is-closed' );
			if ( status && status.opensAt ) {
				statusText.textContent = ( i18n.opensAt || 'Closed · opens %s' ).replace( '%s', status.opensAt );
			} else {
				statusText.textContent = i18n.closedNow || 'Closed';
			}
		}
		toggle.appendChild( statusText );
		toggle.appendChild( el( 'span', 'psl-iw__caret', '▾' ) );

		// The full week table.
		var table = el( 'div', 'psl-iw__hours-table' );
		var weekdays = ( data.weekdays && data.weekdays.length === 7 ) ? data.weekdays :
			[ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
		var todayIdx = nowInStore( store.utcOffset ).getUTCDay();

		// Order Monday..Sunday for a familiar layout.
		var order = [ 1, 2, 3, 4, 5, 6, 0 ];
		order.forEach( function ( dayIdx ) {
			var line = el( 'div', 'psl-iw__hours-line' );
			if ( dayIdx === todayIdx ) {
				line.classList.add( 'is-today' );
			}
			var name = ( dayIdx === todayIdx ) ? ( i18n.today || 'Today' ) : weekdays[ dayIdx ];
			line.appendChild( el( 'span', 'psl-iw__day', name ) );
			line.appendChild( el( 'span', 'psl-iw__day-hours', dayHoursText( periods, dayIdx ) ) );
			table.appendChild( line );
		} );

		toggle.addEventListener( 'click', function () {
			var open = wrapper.classList.toggle( 'is-expanded' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		wrapper.appendChild( toggle );
		wrapper.appendChild( table );
		return wrapper;
	}

	/**
	 * Build the full info-window card as a DOM element.
	 *
	 * @param {Object} store Store record.
	 * @return {HTMLElement}
	 */
	function buildInfoWindow( store ) {
		var i18n = data.i18n || {};
		var card = el( 'div', 'psl-iw' );

		// Use the site's body font instead of the map's default Roboto.
		try {
			card.style.fontFamily = getComputedStyle( document.body ).fontFamily;
		} catch ( e ) {}

		// Media: photo banner (featured image) + optional logo badge.
		if ( store.photo || store.logo ) {
			var media = el( 'div', 'psl-iw__media' + ( store.photo ? '' : ' psl-iw__media--nologo-bg' ) );

			if ( store.photo ) {
				var banner = el( 'div', 'psl-iw__photo' );
				var img = document.createElement( 'img' );
				img.src = store.photo;
				img.alt = store.name || '';
				img.loading = 'lazy';
				banner.appendChild( img );
				media.appendChild( banner );
			}

			if ( store.logo ) {
				var logo = el( 'div', 'psl-iw__logo' + ( store.photo ? '' : ' psl-iw__logo--nophoto' ) );
				var logoImg = document.createElement( 'img' );
				logoImg.src = store.logo;
				logoImg.alt = store.name || '';
				logoImg.loading = 'lazy';
				logo.appendChild( logoImg );
				media.appendChild( logo );
			}

			card.appendChild( media );
		}

		var body = el( 'div', 'psl-iw__body' + ( store.logo ? ' psl-iw__body--logo' : '' ) );
		body.appendChild( el( 'h3', 'psl-iw__title', store.name ) );

		// About with read more/less.
		if ( store.show_about && store.about ) {
			var about = el( 'div', 'psl-iw__about' );
			var LIMIT = 120;
			if ( store.about.length > LIMIT ) {
				var shortText = store.about.slice( 0, LIMIT ).replace( /\s+\S*$/, '' ) + '… ';
				var textSpan = el( 'span', null, shortText );
				var moreBtn = el( 'button', 'psl-iw__readmore', i18n.readMore || 'Read More' );
				moreBtn.type = 'button';
				var expanded = false;
				moreBtn.addEventListener( 'click', function () {
					expanded = ! expanded;
					textSpan.textContent = expanded ? ( store.about + ' ' ) : shortText;
					moreBtn.textContent = expanded ? ( i18n.readLess || 'Read Less' ) : ( i18n.readMore || 'Read More' );
				} );
				about.appendChild( textSpan );
				about.appendChild( moreBtn );
			} else {
				about.textContent = store.about;
			}
			body.appendChild( about );
		}

		// Action buttons (identical style, white icon + label).
		var actions = el( 'div', 'psl-iw__actions' );
		if ( store.show_phone && store.phone ) {
			var callLink = document.createElement( 'a' );
			callLink.className = 'psl-iw__btn';
			callLink.href = 'tel:' + store.phone.replace( /[^0-9+]/g, '' );
			callLink.appendChild( svgIcon( 'phone' ) );
			callLink.appendChild( el( 'span', null, i18n.callUs || 'Call Us' ) );
			actions.appendChild( callLink );
		}
		if ( data.showDirections ) {
			var dir = document.createElement( 'a' );
			dir.className = 'psl-iw__btn';
			dir.href = 'https://www.google.com/maps/dir/?api=1&destination=' +
				encodeURIComponent( store.lat + ',' + store.lng );
			dir.target = '_blank';
			dir.rel = 'noopener noreferrer';
			dir.appendChild( svgIcon( 'directions' ) );
			dir.appendChild( el( 'span', null, i18n.directions || 'Get Directions' ) );
			actions.appendChild( dir );
		}
		if ( actions.childNodes.length ) {
			body.appendChild( actions );
		}

		// Phone row.
		if ( store.show_phone && store.phone ) {
			var phoneRow = el( 'div', 'psl-iw__row' );
			phoneRow.appendChild( svgIcon( 'phone' ) );
			var phoneLink = document.createElement( 'a' );
			phoneLink.href = 'tel:' + store.phone.replace( /[^0-9+]/g, '' );
			phoneLink.textContent = store.phone;
			phoneRow.appendChild( phoneLink );
			body.appendChild( phoneRow );
		}

		// Address row.
		if ( store.address ) {
			var addrRow = el( 'div', 'psl-iw__row' );
			addrRow.appendChild( svgIcon( 'pin' ) );
			addrRow.appendChild( el( 'span', null, store.address ) );
			body.appendChild( addrRow );
		}

		// Hours.
		if ( store.show_hours ) {
			var hours = buildHours( store );
			if ( hours ) {
				body.appendChild( hours );
			}
		}

		card.appendChild( body );
		return card;
	}

	/**
	 * Create markers for all stores and fit the map to their bounds.
	 *
	 * @return {void}
	 */
	function addMarkers() {
		var stores = Array.isArray( data.stores ) ? data.stores : [];
		var bounds = new google.maps.LatLngBounds();
		var icon = markerIcon( data.markerColor );

		stores.forEach( function ( store ) {
			var position = { lat: parseFloat( store.lat ), lng: parseFloat( store.lng ) };
			if ( isNaN( position.lat ) || isNaN( position.lng ) ) {
				return;
			}

			var marker = new google.maps.Marker( {
				position: position,
				map: map,
				title: store.name,
				icon: icon
			} );

			marker.addListener( 'click', function () {
				infoWindow.setContent( buildInfoWindow( store ) );
				infoWindow.open( { anchor: marker, map: map } );
			} );

			markers.push( marker );
			bounds.extend( position );
		} );

		if ( markers.length > 1 ) {
			// Fit so the outermost stores sit comfortably inside the viewport.
			map.fitBounds( bounds, 60 );
			// Never zoom in so far that a tight cluster fills the whole map.
			google.maps.event.addListenerOnce( map, 'idle', function () {
				if ( map.getZoom() > 15 ) {
					map.setZoom( 15 );
				}
			} );
		} else if ( markers.length === 1 ) {
			map.setCenter( bounds.getCenter() );
			map.setZoom( 14 );
		}
	}

	/**
	 * Resolve a friendly message for a server error code.
	 *
	 * @param {string} code Error code from the REST endpoint.
	 * @return {string} Message.
	 */
	function messageForError( code ) {
		var i18n = data.i18n || {};
		switch ( code ) {
			case 'rate_limited':
				return i18n.rateLimited || 'Too many searches. Please wait a moment.';
			case 'cap_reached':
				return i18n.capReached || 'Search is temporarily unavailable.';
			case 'zero_results':
			case 'invalid':
				return i18n.geoError || 'Location not found.';
			default:
				return i18n.searchError || 'Something went wrong. Please try again.';
		}
	}

	/**
	 * Handle a ZIP/postcode search via the server-side geocode proxy.
	 *
	 * @return {void}
	 */
	/**
	 * Recenter and zoom the map to a coordinate.
	 *
	 * @param {number} lat Latitude.
	 * @param {number} lng Longitude.
	 * @return {void}
	 */
	function recenterTo( lat, lng ) {
		map.setCenter( { lat: parseFloat( lat ), lng: parseFloat( lng ) } );
		map.setZoom( parseInt( data.searchZoom, 10 ) || 12 );
	}

	/**
	 * Fallback geocoding using the client-side Geocoder (uses the referrer-
	 * restricted Maps key, so it works even if the server proxy is unconfigured).
	 *
	 * @param {string}   query  Search text.
	 * @param {Element}  status Status element.
	 * @param {Function} done   Called when finished.
	 * @return {void}
	 */
	function clientGeocode( query, status, done ) {
		if ( ! geocoder ) {
			if ( status ) {
				status.textContent = ( data.i18n && data.i18n.geoError ) || 'Location not found.';
			}
			done();
			return;
		}
		geocoder.geocode( { address: query }, function ( results, gStatus ) {
			if ( gStatus === 'OK' && results && results[ 0 ] ) {
				var loc = results[ 0 ].geometry.location;
				recenterTo( loc.lat(), loc.lng() );
				if ( status ) {
					status.textContent = '';
				}
			} else if ( status ) {
				status.textContent = ( data.i18n && data.i18n.geoError ) || 'Location not found.';
			}
			done();
		} );
	}

	function handleSearch() {
		var input = document.getElementById( 'psl-zip' );
		var status = document.getElementById( 'psl-search-status' );
		var button = document.getElementById( 'psl-zip-search' );

		if ( ! input || ! input.value.trim() || searchBusy ) {
			return;
		}

		var query = input.value.trim();
		var minLen = parseInt( data.searchMinLen, 10 ) || 3;
		if ( query.length < minLen ) {
			return;
		}

		searchBusy = true;
		if ( button ) {
			button.disabled = true;
		}
		if ( status ) {
			status.textContent = ( data.i18n && data.i18n.searching ) || 'Searching…';
		}

		var finish = function () {
			searchBusy = false;
			if ( button ) {
				button.disabled = false;
			}
		};

		var url = data.geocodeUrl + ( data.geocodeUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'query=' + encodeURIComponent( query );

		// Public/anonymous endpoint (cached, rate-limited, capped) — no auth header.
		fetch( url, { method: 'GET' } )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body || {} };
				} );
			} )
			.then( function ( result ) {
				var body = result.body;

				if ( result.ok && typeof body.lat !== 'undefined' ) {
					recenterTo( body.lat, body.lng );
					if ( status ) {
						status.textContent = '';
					}
					finish();
					return;
				}

				var err = body.error;
				// Respect rate limit / cap; and show genuine "not found".
				if ( err === 'rate_limited' || err === 'cap_reached' ||
					err === 'zero_results' || err === 'invalid' ) {
					if ( status ) {
						status.textContent = messageForError( err );
					}
					finish();
					return;
				}

				// Config/denied/other server issue → try client-side geocoding.
				clientGeocode( query, status, finish );
			} )
			.catch( function () {
				// Server unreachable → client-side fallback.
				clientGeocode( query, status, finish );
			} );
	}

	/**
	 * Wire up the search form controls.
	 *
	 * @return {void}
	 */
	function bindSearch() {
		var button = document.getElementById( 'psl-zip-search' );
		var input = document.getElementById( 'psl-zip' );

		if ( button ) {
			button.addEventListener( 'click', handleSearch );
		}
		if ( input ) {
			input.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					handleSearch();
				}
			} );
		}
	}

	/**
	 * Initialise the map. Invoked by the Google Maps loader callback.
	 *
	 * @return {void}
	 */
	function init() {
		var mapEl = document.getElementById( 'psl-map' );
		if ( ! mapEl || typeof google === 'undefined' ) {
			return;
		}

		var center = ( data.defaultCenter && typeof data.defaultCenter.lat !== 'undefined' )
			? { lat: parseFloat( data.defaultCenter.lat ), lng: parseFloat( data.defaultCenter.lng ) }
			: { lat: 39.8283, lng: -98.5795 };

		map = new google.maps.Map( mapEl, {
			center: center,
			zoom: parseInt( data.defaultZoom, 10 ) || 4,
			mapTypeId: data.mapType || 'roadmap',
			styles: resolveStyles(),
			mapTypeControl: false,
			streetViewControl: false,
			fullscreenControl: true,
			// Plain mouse-wheel zoom (no Ctrl needed), like Elfsight.
			gestureHandling: 'greedy'
		} );

		geocoder = new google.maps.Geocoder();
		infoWindow = new google.maps.InfoWindow();

		addMarkers();
		bindSearch();
	}

	// Expose the loader callback globally for the Google Maps script.
	window.pslInitMap = init;
} )();
