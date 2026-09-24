/* Chalet Booking — calendrier et formulaire de demande de réservation. */
( function () {
	'use strict';

	var cfg = window.ChaletBooking;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	// --- Utilitaires de dates (UTC pour éviter les décalages d'heure d'été) ---
	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}
	function ymd( d ) {
		return d.getUTCFullYear() + '-' + pad( d.getUTCMonth() + 1 ) + '-' + pad( d.getUTCDate() );
	}
	function parse( s ) {
		var p = s.split( '-' );
		return new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] ) );
	}
	function addDays( s, n ) {
		var d = parse( s );
		d.setUTCDate( d.getUTCDate() + n );
		return ymd( d );
	}
	function fmt( s, opts ) {
		return parse( s ).toLocaleDateString( cfg.locale, Object.assign( { timeZone: 'UTC' }, opts || { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' } ) );
	}
	// Compatible avec les permaliens simples (…/index.php?rest_route=/chalet-booking/v1).
	function api( path, query ) {
		var url = cfg.api + path;
		return query ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query : url;
	}
	function sprintf( str, v ) {
		return str.replace( /%[ds]/, v );
	}

	function Booking( root ) {
		this.root = root;
		this.cal = root.querySelector( '.cb-calendar' );
		this.hint = root.querySelector( '.cb-hint' );
		this.dates = root.querySelector( '.cb-dates' );
		this.quoteEl = root.querySelector( '.cb-quote' );
		this.errorEl = root.querySelector( '.cb-error' );
		this.form = root.querySelector( '.cb-form' );
		this.success = root.querySelector( '.cb-success' );
		this.offset = 0;
		this.checkIn = null;
		this.checkOut = null;
		this.data = null;
		this.booked = {};
		this.bind();
		this.load();
	}

	Booking.prototype.bind = function () {
		var self = this;
		this.root.querySelector( '.cb-prev' ).addEventListener( 'click', function () {
			if ( self.offset > 0 ) {
				self.offset--;
				self.render();
			}
		} );
		this.root.querySelector( '.cb-next' ).addEventListener( 'click', function () {
			if ( self.offset < self.maxOffset() ) {
				self.offset++;
				self.render();
			}
		} );
		this.cal.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button[data-date]' );
			if ( btn && ! btn.disabled ) {
				self.pick( btn.getAttribute( 'data-date' ) );
			}
		} );
		this.form.addEventListener( 'change', function ( e ) {
			if ( e.target.name === 'adults' || e.target.name === 'children' ) {
				self.quote();
			}
		} );
		this.form.addEventListener( 'click', function ( e ) {
			var toggle = e.target.closest( '.cb-terms-toggle' );
			if ( toggle ) {
				var box = self.form.querySelector( '.cb-terms-inline' );
				box.hidden = ! box.hidden;
				toggle.setAttribute( 'aria-expanded', String( ! box.hidden ) );
			}
		} );
		this.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self.submit();
		} );
	};

	Booking.prototype.load = function () {
		var self = this;
		fetch( api( '/availability' ), { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				self.data = data;
				self.booked = {};
				data.booked.forEach( function ( d ) {
					self.booked[ d ] = true;
				} );
				self.render();
				self.updateHint();
			} )
			.catch( function () {
				self.showError( t.error );
			} );
	};

	Booking.prototype.maxOffset = function () {
		if ( ! this.data ) {
			return 0;
		}
		var now = new Date();
		var last = parse( this.data.latest );
		var months = ( last.getUTCFullYear() - now.getFullYear() ) * 12 + last.getUTCMonth() - now.getMonth();
		return Math.max( 0, months - cfg.months + 1 );
	};

	Booking.prototype.season = function ( date ) {
		var s = this.data.seasons;
		for ( var i = 0; i < s.length; i++ ) {
			if ( date >= s[ i ].start && date <= s[ i ].end ) {
				return s[ i ];
			}
		}
		return null;
	};

	Booking.prototype.rule = function ( date ) {
		var s = this.season( date );
		return {
			minNights: s ? s.minNights : this.data.baseMinNights,
			arrivalDay: s ? s.arrivalDay : -1,
			price: s ? s.price : this.data.basePrice,
		};
	};

	Booking.prototype.canArrive = function ( date ) {
		if ( date < this.data.earliest || date >= this.data.latest || this.booked[ date ] ) {
			return false;
		}
		var r = this.rule( date );
		return r.arrivalDay < 0 || parse( date ).getUTCDay() === r.arrivalDay;
	};

	Booking.prototype.canDepart = function ( date ) {
		if ( ! this.checkIn || date <= this.checkIn || date > this.data.latest ) {
			return false;
		}
		for ( var d = this.checkIn; d < date; d = addDays( d, 1 ) ) {
			if ( this.booked[ d ] ) {
				return false;
			}
		}
		var nights = Math.round( ( parse( date ) - parse( this.checkIn ) ) / 864e5 );
		if ( nights < this.rule( this.checkIn ).minNights ) {
			return false;
		}
		var r = this.rule( addDays( date, -1 ) );
		return r.arrivalDay < 0 || parse( date ).getUTCDay() === r.arrivalDay;
	};

	Booking.prototype.pick = function ( date ) {
		if ( this.checkIn && ! this.checkOut && this.canDepart( date ) ) {
			this.checkOut = date;
			this.render();
			this.quote();
		} else if ( this.canArrive( date ) ) {
			this.checkIn = date;
			this.checkOut = null;
			this.form.hidden = true;
			this.quoteEl.hidden = true;
			this.render();
		}
		this.updateHint();
	};

	Booking.prototype.updateHint = function () {
		this.hideError();
		if ( ! this.checkIn ) {
			this.hint.textContent = t.selectArrival;
			this.dates.hidden = true;
			return;
		}
		var r = this.rule( this.checkIn );
		var parts = [ fmt( this.checkIn ) + ' → ' + ( this.checkOut ? fmt( this.checkOut ) : '…' ) ];
		this.dates.textContent = parts[ 0 ];
		this.dates.hidden = false;
		if ( ! this.checkOut ) {
			var info = [ t.selectDeparture, sprintf( t.minNights, r.minNights ) ];
			if ( r.arrivalDay >= 0 ) {
				info.push( sprintf( t.arrivalOnly, t.weekdays[ r.arrivalDay ].toLowerCase() ) );
			}
			this.hint.textContent = info.join( ' · ' );
		} else {
			this.hint.textContent = '';
		}
	};

	Booking.prototype.render = function () {
		if ( ! this.data ) {
			return;
		}
		var now = new Date();
		var html = '';
		for ( var m = 0; m < cfg.months; m++ ) {
			var first = new Date( Date.UTC( now.getFullYear(), now.getMonth() + this.offset + m, 1 ) );
			html += this.month( first );
		}
		this.cal.innerHTML = html;
		this.root.querySelector( '.cb-prev' ).disabled = this.offset === 0;
		this.root.querySelector( '.cb-next' ).disabled = this.offset >= this.maxOffset();
	};

	Booking.prototype.month = function ( first ) {
		var title = first.toLocaleDateString( cfg.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' } );
		var html = '<div class="cb-month"><div class="cb-month-title">' + title + '</div><div class="cb-grid">';
		// En-têtes lundi → dimanche.
		for ( var w = 1; w <= 7; w++ ) {
			html += '<div class="cb-dow">' + t.weekdays[ w % 7 ].slice( 0, 2 ) + '</div>';
		}
		var lead = ( first.getUTCDay() + 6 ) % 7;
		for ( var i = 0; i < lead; i++ ) {
			html += '<div></div>';
		}
		var d = ymd( first );
		var month = first.getUTCMonth();
		while ( parse( d ).getUTCMonth() === month ) {
			html += this.day( d );
			d = addDays( d, 1 );
		}
		return html + '</div></div>';
	};

	Booking.prototype.day = function ( d ) {
		var cls = [ 'cb-day' ];
		var selectable;
		var prevBooked = this.booked[ addDays( d, -1 ) ];

		if ( this.booked[ d ] ) {
			cls.push( prevBooked ? 'cb-booked' : 'cb-booked-start' );
		} else if ( prevBooked ) {
			cls.push( 'cb-booked-end' );
		}
		if ( d < this.data.earliest || d > this.data.latest ) {
			cls.push( 'cb-past' );
		}
		if ( this.checkIn && d === this.checkIn ) {
			cls.push( 'cb-sel-start' );
		}
		if ( this.checkOut && d === this.checkOut ) {
			cls.push( 'cb-sel-end' );
		}
		if ( this.checkIn && this.checkOut && d > this.checkIn && d < this.checkOut ) {
			cls.push( 'cb-sel' );
		}

		if ( this.checkIn && ! this.checkOut ) {
			selectable = this.canDepart( d ) || this.canArrive( d );
			if ( this.canDepart( d ) ) {
				cls.push( 'cb-can-depart' );
			}
		} else {
			selectable = this.canArrive( d );
		}
		if ( selectable ) {
			cls.push( 'cb-available' );
		}

		var price = this.rule( d ).price;
		return '<button type="button" class="' + cls.join( ' ' ) + '" data-date="' + d + '"' +
			( selectable ? '' : ' disabled' ) +
			' title="' + fmt( d ) + ( selectable && ! this.booked[ d ] ? ' — ' + this.data.currency + ' ' + price + ' ' + t.perNight : '' ) + '">' +
			parse( d ).getUTCDate() + '</button>';
	};

	Booking.prototype.values = function () {
		var f = this.form.elements;
		return {
			check_in: this.checkIn,
			check_out: this.checkOut,
			adults: f.adults.value,
			children: f.children.value,
		};
	};

	Booking.prototype.quote = function () {
		var self = this;
		if ( ! this.checkIn || ! this.checkOut ) {
			return;
		}
		var q = new URLSearchParams( this.values() ).toString();
		this.quoteEl.hidden = false;
		this.quoteEl.innerHTML = '<p>' + t.loading + '</p>';
		this.form.hidden = false;
		fetch( api( '/quote', q ), { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					return { ok: r.ok, body: j };
				} );
			} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					self.quoteEl.hidden = true;
					self.form.hidden = true;
					self.showError( res.body.message || t.error );
					return;
				}
				self.hideError();
				var html = '<table class="cb-quote-table"><tbody>';
				res.body.lines.forEach( function ( l ) {
					html += '<tr><td>' + escapeHtml( l.label ) + '</td><td>' + escapeHtml( l.formatted ) + '</td></tr>';
				} );
				html += '</tbody><tfoot><tr><th>' + t.total + ' (' + sprintf( t.nights, res.body.nights ) + ')</th><th>' + escapeHtml( res.body.totalFormatted ) + '</th></tr></tfoot></table>';
				self.quoteEl.innerHTML = html;
			} )
			.catch( function () {
				self.showError( t.error );
			} );
	};

	Booking.prototype.submit = function () {
		var self = this;
		if ( ! this.form.reportValidity() ) {
			return;
		}
		var btn = this.form.querySelector( '.cb-submit' );
		var payload = this.values();
		var f = this.form.elements;
		[ 'name', 'email', 'phone', 'message', 'website' ].forEach( function ( k ) {
			payload[ k ] = f[ k ].value;
		} );
		payload.terms = f.terms ? f.terms.checked : false;

		btn.disabled = true;
		fetch( api( '/book' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					return { ok: r.ok, body: j };
				} );
			} )
			.then( function ( res ) {
				btn.disabled = false;
				if ( ! res.ok ) {
					self.showError( res.body.message || t.error );
					if ( res.body.code === 'cb_unavailable' ) {
						self.checkIn = self.checkOut = null;
						self.form.hidden = true;
						self.quoteEl.hidden = true;
						self.load();
					}
					return;
				}
				self.form.hidden = true;
				self.root.querySelector( '.cb-calendar-wrap' ).hidden = true;
				self.hint.hidden = true;
				self.success.textContent = res.body.message;
				self.success.hidden = false;
				self.success.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} )
			.catch( function () {
				btn.disabled = false;
				self.showError( t.error );
			} );
	};

	Booking.prototype.showError = function ( msg ) {
		this.errorEl.textContent = msg;
		this.errorEl.hidden = false;
	};
	Booking.prototype.hideError = function () {
		this.errorEl.hidden = true;
	};

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-cb-booking]' ).forEach( function ( el ) {
			new Booking( el );
		} );
	} );
} )();
