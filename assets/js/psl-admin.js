/**
 * Product Store Locator — admin store lookup.
 *
 * Uses the NEW Google Places API (AutocompleteSuggestion + Place) to show a
 * live dropdown of matching businesses as you type. Selecting one fills in the
 * store fields and renders a preview map. Requires "Places API (New)".
 *
 * The Maps script is enqueued with `callback=pslAdminMapsReady`.
 */
( function () {
	'use strict';

	var i18n = ( window.PSL_ADMIN && window.PSL_ADMIN.i18n ) || {};

	var placesLib = null;       // Resolved google.maps.places library.
	var sessionToken = null;    // Autocomplete session token.
	var previewMap = null;
	var previewMarker = null;
	var debounceTimer = null;

	var predictions = [];       // Current prediction objects.
	var activeIndex = -1;       // Highlighted option for keyboard nav.

	var MIN_CHARS = 2;
	var DEBOUNCE_MS = 250;

	/**
	 * @param {string} id Element id.
	 * @return {HTMLElement|null}
	 */
	function byId( id ) {
		return document.getElementById( id );
	}

	/**
	 * Set a field value if the element exists.
	 *
	 * @param {string} id    Element id.
	 * @param {*}      value Value.
	 */
	function setField( id, value ) {
		var el = byId( id );
		if ( el ) {
			el.value = value == null ? '' : value;
		}
	}

	/* ------------------------------------------------------------------
	 * Dropdown rendering
	 * --------------------------------------------------------------- */

	/**
	 * Show a single status message row in the dropdown.
	 *
	 * @param {string} message Text.
	 */
	function renderMessage( message ) {
		var list = byId( 'psl-search-results' );
		if ( ! list ) {
			return;
		}
		predictions = [];
		activeIndex = -1;
		list.innerHTML = '';
		var li = document.createElement( 'li' );
		li.className = 'psl-search-results__empty';
		li.textContent = message || '';
		list.appendChild( li );
		openDropdown();
	}

	/**
	 * Empty and hide the dropdown.
	 */
	function clearResults() {
		var list = byId( 'psl-search-results' );
		if ( list ) {
			list.innerHTML = '';
		}
		predictions = [];
		activeIndex = -1;
		closeDropdown();
	}

	/**
	 * Open the dropdown.
	 */
	function openDropdown() {
		var list = byId( 'psl-search-results' );
		var input = byId( 'psl-search-input' );
		if ( list ) {
			list.classList.add( 'is-open' );
		}
		if ( input ) {
			input.setAttribute( 'aria-expanded', 'true' );
		}
	}

	/**
	 * Close the dropdown.
	 */
	function closeDropdown() {
		var list = byId( 'psl-search-results' );
		var input = byId( 'psl-search-input' );
		if ( list ) {
			list.classList.remove( 'is-open' );
		}
		if ( input ) {
			input.setAttribute( 'aria-expanded', 'false' );
		}
	}

	/**
	 * Render prediction rows.
	 *
	 * @param {Array} suggestions AutocompleteSuggestion list.
	 */
	function renderResults( suggestions ) {
		var list = byId( 'psl-search-results' );
		if ( ! list ) {
			return;
		}

		predictions = ( suggestions || [] )
			.map( function ( s ) { return s.placePrediction; } )
			.filter( Boolean );
		activeIndex = -1;
		list.innerHTML = '';

		if ( ! predictions.length ) {
			renderMessage( i18n.noResults || 'No results found.' );
			return;
		}

		predictions.forEach( function ( prediction, index ) {
			var item = document.createElement( 'li' );
			item.className = 'psl-search-results__item';
			item.setAttribute( 'role', 'option' );
			item.id = 'psl-opt-' + index;

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'psl-search-results__button';
			button.tabIndex = -1;

			var main = ( prediction.mainText && prediction.mainText.text ) ||
				( prediction.text && prediction.text.text ) || '';
			var secondary = ( prediction.secondaryText && prediction.secondaryText.text ) || '';

			var strong = document.createElement( 'strong' );
			strong.textContent = main;
			button.appendChild( strong );

			if ( secondary ) {
				var span = document.createElement( 'span' );
				span.textContent = secondary;
				button.appendChild( span );
			}

			button.addEventListener( 'mousedown', function ( e ) {
				// mousedown (not click) so it fires before input blur closes the list.
				e.preventDefault();
				choose( index );
			} );
			button.addEventListener( 'mousemove', function () {
				setActive( index );
			} );

			item.appendChild( button );
			list.appendChild( item );
		} );

		openDropdown();
	}

	/**
	 * Highlight the option at the given index.
	 *
	 * @param {number} index Index.
	 */
	function setActive( index ) {
		var list = byId( 'psl-search-results' );
		var input = byId( 'psl-search-input' );
		if ( ! list ) {
			return;
		}
		var items = list.querySelectorAll( '.psl-search-results__button' );
		items.forEach( function ( el ) { el.classList.remove( 'is-active' ); } );

		activeIndex = index;
		if ( index >= 0 && items[ index ] ) {
			items[ index ].classList.add( 'is-active' );
			items[ index ].scrollIntoView( { block: 'nearest' } );
			if ( input ) {
				input.setAttribute( 'aria-activedescendant', 'psl-opt-' + index );
			}
		} else if ( input ) {
			input.removeAttribute( 'aria-activedescendant' );
		}
	}

	/**
	 * Move the highlight up/down.
	 *
	 * @param {number} delta +1 or -1.
	 */
	function moveActive( delta ) {
		if ( ! predictions.length ) {
			return;
		}
		var next = activeIndex + delta;
		if ( next < 0 ) {
			next = predictions.length - 1;
		} else if ( next >= predictions.length ) {
			next = 0;
		}
		setActive( next );
	}

	/**
	 * Choose the prediction at an index.
	 *
	 * @param {number} index Index.
	 */
	function choose( index ) {
		if ( index < 0 || ! predictions[ index ] ) {
			return;
		}
		var input = byId( 'psl-search-input' );
		var prediction = predictions[ index ];
		var main = ( prediction.mainText && prediction.mainText.text ) ||
			( prediction.text && prediction.text.text ) || '';
		if ( input && main ) {
			input.value = main;
		}
		clearResults();
		selectPrediction( prediction );
	}

	/* ------------------------------------------------------------------
	 * Places calls
	 * --------------------------------------------------------------- */

	/**
	 * @param {*} err Error.
	 * @return {string}
	 */
	function errorText( err ) {
		if ( ! err ) {
			return '';
		}
		return err.message ? err.message : String( err );
	}

	/**
	 * Query the new Places API for suggestions.
	 *
	 * @param {string} value Raw input value.
	 * @return {Promise<void>}
	 */
	async function fetchSuggestions( value ) {
		var query = ( value || '' ).trim();

		if ( ! placesLib || ! placesLib.AutocompleteSuggestion ) {
			renderMessage( i18n.apiMissing || 'Places API is not available.' );
			return;
		}

		if ( query.length < MIN_CHARS ) {
			clearResults();
			return;
		}

		try {
			var response = await placesLib.AutocompleteSuggestion.fetchAutocompleteSuggestions( {
				input: query,
				sessionToken: sessionToken
			} );
			renderResults( response.suggestions );
		} catch ( err ) {
			console.error( 'PSL: autocomplete request failed', err );
			renderMessage( ( i18n.error || 'Search failed:' ) + ' ' + errorText( err ) );
		}
	}

	/**
	 * Debounced live search handler.
	 *
	 * @param {string} value Input value.
	 */
	function liveSearch( value ) {
		if ( debounceTimer ) {
			clearTimeout( debounceTimer );
		}
		debounceTimer = setTimeout( function () {
			fetchSuggestions( value );
		}, DEBOUNCE_MS );
	}

	/**
	 * Fetch full details for a prediction and apply them.
	 *
	 * @param {Object} prediction placePrediction.
	 * @return {Promise<void>}
	 */
	async function selectPrediction( prediction ) {
		try {
			var place = prediction.toPlace();
			await place.fetchFields( {
				fields: [
					'displayName',
					'formattedAddress',
					'location',
					'nationalPhoneNumber',
					'internationalPhoneNumber',
					'regularOpeningHours',
					'utcOffsetMinutes',
					'photos',
					'id'
				]
			} );
			applyPlace( place );
			newToken(); // A selection ends the session; start a new one.
		} catch ( err ) {
			console.error( 'PSL: failed to fetch place details', err );
			renderMessage( ( i18n.error || 'Search failed:' ) + ' ' + errorText( err ) );
		}
	}

	/**
	 * Populate the form from a resolved Place.
	 *
	 * @param {Object} place google.maps.places.Place.
	 */
	function applyPlace( place ) {
		if ( ! place ) {
			return;
		}

		setField( 'psl-store-address', place.formattedAddress || '' );
		setField( 'psl-store-place-id', place.id || '' );
		setField( 'psl-store-phone', place.nationalPhoneNumber || place.internationalPhoneNumber || '' );

		// Store name: fill the visible name field + the hidden WP title, but only
		// when empty so a customized name (e.g. "Store - Downtown") isn't clobbered.
		if ( place.displayName ) {
			var nameEl = byId( 'psl-store-name' );
			var titleEl = byId( 'title' );
			if ( nameEl && ! nameEl.value ) {
				nameEl.value = place.displayName;
			}
			if ( titleEl && ! titleEl.value ) {
				titleEl.value = place.displayName;
			}
		}

		// Note: Google's owner-written business description ("from the business")
		// is not exposed by the Places API, so About is filled in manually.

		// Store photo URL — imported into the media library on save (once).
		var photoField = byId( 'psl-store-google-photo' );
		if ( photoField ) {
			photoField.value = '';
			if ( place.photos && place.photos.length && typeof place.photos[ 0 ].getURI === 'function' ) {
				try {
					// The Maps JS Place API uses maxWidth/maxHeight (pixels).
					photoField.value = place.photos[ 0 ].getURI( { maxWidth: 1200, maxHeight: 900 } );
				} catch ( e ) {}
			}
		}

		if ( place.regularOpeningHours && place.regularOpeningHours.weekdayDescriptions ) {
			setField( 'psl-store-hours', place.regularOpeningHours.weekdayDescriptions.join( '\n' ) );
		}

		// Structured hours (periods) + timezone offset power the live open/closed status.
		if ( place.regularOpeningHours && place.regularOpeningHours.periods ) {
			var periods = place.regularOpeningHours.periods.map( function ( p ) {
				return {
					open: p.open ? { day: p.open.day, hour: p.open.hour, minute: p.open.minute } : null,
					close: p.close ? { day: p.close.day, hour: p.close.hour, minute: p.close.minute } : null
				};
			} );
			setField( 'psl-store-hours-json', JSON.stringify( periods ) );
		} else {
			setField( 'psl-store-hours-json', '' );
		}

		if ( typeof place.utcOffsetMinutes === 'number' ) {
			setField( 'psl-store-utc-offset', place.utcOffsetMinutes );
		}

		if ( place.location ) {
			var lat = typeof place.location.lat === 'function' ? place.location.lat() : place.location.lat;
			var lng = typeof place.location.lng === 'function' ? place.location.lng() : place.location.lng;
			setField( 'psl-store-lat', lat );
			setField( 'psl-store-lng', lng );
			renderPreview( lat, lng );
		}
	}

	/* ------------------------------------------------------------------
	 * Preview map + session token
	 * --------------------------------------------------------------- */

	/**
	 * Start a fresh autocomplete session token.
	 */
	function newToken() {
		if ( placesLib && placesLib.AutocompleteSessionToken ) {
			sessionToken = new placesLib.AutocompleteSessionToken();
		}
	}

	/**
	 * Render or update the preview map.
	 *
	 * @param {number} lat Latitude.
	 * @param {number} lng Longitude.
	 */
	function renderPreview( lat, lng ) {
		var mapEl = byId( 'psl-admin-map' );
		if ( ! mapEl || typeof google === 'undefined' || isNaN( lat ) || isNaN( lng ) ) {
			return;
		}

		var position = { lat: lat, lng: lng };

		if ( ! previewMap ) {
			mapEl.innerHTML = ''; // Remove the "no location yet" placeholder.
			previewMap = new google.maps.Map( mapEl, {
				center: position,
				zoom: 14,
				mapTypeControl: false,
				streetViewControl: false
			} );
			previewMarker = new google.maps.Marker( { map: previewMap, position: position } );
		} else {
			previewMap.setCenter( position );
			previewMarker.setPosition( position );
		}
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * --------------------------------------------------------------- */

	/**
	 * Bind the search UI controls.
	 */
	function bindUi() {
		var button = byId( 'psl-search-button' );
		var input = byId( 'psl-search-input' );

		if ( input ) {
			input.setAttribute( 'role', 'combobox' );
			input.setAttribute( 'aria-autocomplete', 'list' );
			input.setAttribute( 'aria-expanded', 'false' );
			input.setAttribute( 'aria-controls', 'psl-search-results' );

			input.addEventListener( 'input', function () {
				liveSearch( input.value );
			} );

			input.addEventListener( 'keydown', function ( event ) {
				switch ( event.key ) {
					case 'ArrowDown':
						event.preventDefault();
						if ( predictions.length ) {
							moveActive( 1 );
						} else {
							fetchSuggestions( input.value );
						}
						break;
					case 'ArrowUp':
						event.preventDefault();
						moveActive( -1 );
						break;
					case 'Enter':
						event.preventDefault();
						if ( activeIndex >= 0 ) {
							choose( activeIndex );
						} else {
							fetchSuggestions( input.value );
						}
						break;
					case 'Escape':
						clearResults();
						break;
				}
			} );

			input.addEventListener( 'focus', function () {
				if ( predictions.length ) {
					openDropdown();
				}
			} );
		}

		if ( button ) {
			button.addEventListener( 'click', function () {
				fetchSuggestions( input ? input.value : '' );
			} );
		}

		// Close the dropdown when clicking outside the search area.
		document.addEventListener( 'click', function ( event ) {
			var combo = byId( 'psl-combo' );
			if ( combo && ! combo.contains( event.target ) ) {
				closeDropdown();
			}
		} );

		// Keep the hidden WP title in sync with the visible "Store name" field.
		var nameInput = byId( 'psl-store-name' );
		var titleInput = byId( 'title' );
		if ( nameInput && titleInput ) {
			// On load, push the cleaned (entity-decoded) name into the hidden
			// WP title so a re-save persists real characters instead of entities.
			if ( nameInput.value ) {
				titleInput.value = nameInput.value;
			}
			nameInput.addEventListener( 'input', function () {
				titleInput.value = nameInput.value;
			} );
		}

		// Bottom "Save / Publish" button triggers the real submit button.
		var bottomSave = byId( 'psl-bottom-save' );
		if ( bottomSave ) {
			bottomSave.addEventListener( 'click', function () {
				var submit = byId( 'publish' ) || byId( 'save-post' );
				if ( submit ) {
					submit.click();
				}
			} );
		}

		bindLogoPicker();
	}

	/**
	 * Wire the optional store-logo media picker (uses wp.media).
	 */
	function bindLogoPicker() {
		var selectBtn = byId( 'psl-logo-select' );
		var removeBtn = byId( 'psl-logo-remove' );
		var idField = byId( 'psl-store-logo-id' );
		var preview = byId( 'psl-logo-preview' );

		if ( ! selectBtn || ! idField || typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var frame = null;

		selectBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = wp.media( {
				title: ( i18n.selectLogo || 'Select store logo' ),
				button: { text: ( i18n.useLogo || 'Use this logo' ) },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				idField.value = attachment.id;

				var url = attachment.id && attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;

				if ( preview ) {
					preview.innerHTML = '';
					var img = document.createElement( 'img' );
					img.src = url;
					img.alt = '';
					preview.appendChild( img );
					preview.classList.add( 'has-logo' );
				}
				if ( removeBtn ) {
					removeBtn.classList.remove( 'hidden' );
				}
			} );

			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				idField.value = '';
				if ( preview ) {
					preview.innerHTML = '';
					preview.classList.remove( 'has-logo' );
				}
				removeBtn.classList.add( 'hidden' );
			} );
		}
	}

	/**
	 * Google Maps loader callback.
	 *
	 * @return {Promise<void>}
	 */
	async function onMapsReady() {
		try {
			placesLib = await google.maps.importLibrary( 'places' );
			newToken();
		} catch ( err ) {
			console.error( 'PSL: failed to load Places library', err );
			renderMessage( ( i18n.error || 'Search failed:' ) + ' ' + errorText( err ) );
		}

		var mapEl = byId( 'psl-admin-map' );
		if ( mapEl ) {
			var lat = parseFloat( mapEl.getAttribute( 'data-lat' ) );
			var lng = parseFloat( mapEl.getAttribute( 'data-lng' ) );
			if ( ! isNaN( lat ) && ! isNaN( lng ) && ( lat !== 0 || lng !== 0 ) ) {
				renderPreview( lat, lng );
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindUi );
	} else {
		bindUi();
	}

	window.pslAdminMapsReady = onMapsReady;
} )();
