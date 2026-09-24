/* Chalet Booking — back-office (application autonome, sans dépendance). */
( function () {
	'use strict';

	var C = window.CB_BO;
	var app = document.getElementById( 'bo-app' );

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                        */
	/* ------------------------------------------------------------------ */

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
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
	function diffDays( a, b ) {
		return Math.round( ( parse( b ) - parse( a ) ) / 864e5 );
	}
	function fdate( s, opts ) {
		if ( ! s ) {
			return '';
		}
		return parse( s ).toLocaleDateString( C.locale, Object.assign( { timeZone: 'UTC' }, opts || { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } ) );
	}
	function fshort( s ) {
		return fdate( s, { day: 'numeric', month: 'short' } );
	}
	function money( n ) {
		var v = Number( n || 0 );
		return C.currency + ' ' + v.toFixed( v % 1 ? 2 : 0 ).replace( /\B(?=(\d{3})+(?!\d))/g, '’' );
	}
	function today() {
		var d = new Date();
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}
	function qs( obj ) {
		return Object.keys( obj ).filter( function ( k ) {
			return obj[ k ] !== '' && obj[ k ] != null;
		} ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( obj[ k ] );
		} ).join( '&' );
	}
	function url( path, query ) {
		var u = C.api + path;
		var q = query ? qs( query ) : '';
		return q ? u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + q : u;
	}

	function api( method, path, body, query ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce },
		};
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( url( path, query ), opts ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( j ) {
				if ( r.status === 401 || r.status === 403 ) {
					if ( j && j.code === 'rest_cookie_invalid_nonce' ) {
						location.reload();
					}
				}
				if ( ! r.ok ) {
					throw new Error( ( j && j.message ) || 'Erreur ' + r.status );
				}
				return j;
			} );
		} );
	}

	var toastTimer;
	function toast( msg, error ) {
		var t = document.querySelector( '.bo-toast' );
		if ( ! t ) {
			t = document.createElement( 'div' );
			t.className = 'bo-toast';
			t.setAttribute( 'role', 'status' );
			document.body.appendChild( t );
		}
		t.textContent = msg;
		t.className = 'bo-toast show' + ( error ? ' error' : '' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			t.className = 'bo-toast';
		}, error ? 6000 : 3000 );
	}
	function fail( e ) {
		toast( e.message || String( e ), true );
	}

	var STATUS = {
		pending: 'En attente',
		confirmed: 'Confirmée',
		cancelled: 'Annulée',
		blocked: 'Bloquée',
	};
	var PAYMENT = {
		unpaid: 'Non payé',
		deposit: 'Acompte reçu',
		paid: 'Payé',
	};
	var SOURCE = {
		site: 'Site web',
		admin: 'Ajout manuel',
		ical: 'Calendrier externe',
	};

	function badge( b ) {
		var cls = b.source === 'ical' ? 'ical' : b.status;
		var label = b.source === 'ical' ? ( b.name || 'Externe' ) : STATUS[ b.status ];
		return '<span class="bo-badge bo-' + cls + '">' + esc( label ) + '</span>';
	}
	function payBadge( b ) {
		if ( ! PAYMENT[ b.payment ] || b.status === 'cancelled' ) {
			return '';
		}
		return '<span class="bo-badge bo-pay-' + b.payment + '">' + PAYMENT[ b.payment ] + '</span>';
	}
	function guestName( b ) {
		if ( b.source === 'ical' ) {
			return 'Réservation externe';
		}
		if ( b.status === 'blocked' ) {
			return b.name || 'Dates bloquées';
		}
		return b.name || '(sans nom)';
	}
	function guests( b ) {
		if ( b.status === 'blocked' || b.source === 'ical' ) {
			return '';
		}
		return b.adults + ' ad.' + ( b.children ? ' + ' + b.children + ' enf.' : '' );
	}
	function stayLine( b ) {
		return fshort( b.checkIn ) + ' → ' + fshort( b.checkOut ) + ' · ' + b.nights + ' nuit' + ( b.nights > 1 ? 's' : '' );
	}

	function bookingRow( b, extra ) {
		var clickable = b.source !== 'ical';
		return '<' + ( clickable ? 'a href="#/booking/' + b.id + '"' : 'div' ) + ' class="bo-row">' +
			'<div class="bo-row-main"><strong>' + esc( guestName( b ) ) + '</strong>' +
			'<span class="bo-muted">' + esc( stayLine( b ) ) + ( guests( b ) ? ' · ' + esc( guests( b ) ) : '' ) + '</span>' +
			( extra || '' ) + '</div>' +
			'<div class="bo-row-side">' + badge( b ) +
			( b.total && b.status !== 'blocked' ? '<span class="bo-amount">' + money( b.total ) + '</span>' : '' ) +
			payBadge( b ) + '</div>' +
			'</' + ( clickable ? 'a' : 'div' ) + '>';
	}

	function field( label, html, cls ) {
		return '<label class="bo-field' + ( cls ? ' ' + cls : '' ) + '"><span>' + label + '</span>' + html + '</label>';
	}
	function input( name, value, type, attrs ) {
		return '<input name="' + name + '" type="' + ( type || 'text' ) + '" value="' + esc( value == null ? '' : value ) + '" ' + ( attrs || '' ) + '>';
	}
	function formData( form ) {
		var out = {};
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name ) {
				return;
			}
			if ( el.type === 'checkbox' ) {
				out[ el.name ] = el.checked;
			} else if ( el.type === 'radio' ) {
				if ( el.checked ) {
					out[ el.name ] = el.value;
				}
			} else {
				out[ el.name ] = el.value;
			}
		} );
		return out;
	}

	function confirmBox( message ) {
		return window.confirm( message );
	}

	/* ------------------------------------------------------------------ */
	/* Mise en page                                                       */
	/* ------------------------------------------------------------------ */

	var NAV = [
		{ id: 'dashboard', label: 'Accueil', icon: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10' },
		{ id: 'calendar', label: 'Calendrier', icon: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4' },
		{ id: 'bookings', label: 'Réservations', icon: 'M5 4h14v16H5zM9 8h6M9 12h6M9 16h4' },
		{ id: 'pricing', label: 'Tarifs', icon: 'M20 12l-8 8-9-9V3h8zM7.5 7.5h.01' },
		{ id: 'stats', label: 'Statistiques', icon: 'M5 20V10M12 20V4M19 20v-7' },
		{ id: 'more', label: 'Plus', icon: 'M5 12h.01M12 12h.01M19 12h.01' },
	];

	function icon( d ) {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + d + '"/></svg>';
	}

	function layout() {
		app.innerHTML =
			'<header class="bo-header">' +
				'<div class="bo-brand">' + esc( C.chalet ) + '<small>Gestion</small></div>' +
				'<a class="bo-new" href="#/new">+ <span>Nouvelle réservation</span></a>' +
			'</header>' +
			'<nav class="bo-nav">' + NAV.map( function ( n ) {
				return '<a href="#/' + n.id + '" data-nav="' + n.id + '">' + icon( n.icon ) + '<span>' + n.label + '</span></a>';
			} ).join( '' ) + '</nav>' +
			'<main class="bo-main" id="bo-view" tabindex="-1"></main>';
	}

	function setView( html, nav ) {
		// Nouveau conteneur à chaque vue : les écouteurs de la vue précédente disparaissent avec l'ancien.
		var old = document.getElementById( 'bo-view' );
		var v = old.cloneNode( false );
		v.innerHTML = html;
		old.parentNode.replaceChild( v, old );
		document.querySelectorAll( '[data-nav]' ).forEach( function ( a ) {
			a.classList.toggle( 'active', a.getAttribute( 'data-nav' ) === nav );
		} );
		return v;
	}

	function loading( nav ) {
		return setView( '<div class="bo-loading">Chargement…</div>', nav );
	}

	/* ------------------------------------------------------------------ */
	/* Tableau de bord                                                    */
	/* ------------------------------------------------------------------ */

	function viewDashboard() {
		loading( 'dashboard' );
		api( 'GET', '/dashboard' ).then( function ( d ) {
			var k = d.kpi;
			var html = '<h1>Bonjour ' + esc( C.user ) + '</h1>' +
				'<div class="bo-kpis">' +
					kpi( 'Demandes en attente', k.pending, k.pending ? 'warn' : '', '#/bookings?status=pending' ) +
					kpi( 'Chiffre d’affaires ' + k.year, money( k.revenueYear ), '', '#/stats' ) +
					kpi( 'Soldes à encaisser', money( k.outstanding ), k.outstanding ? 'warn' : '', '' ) +
					kpi( 'Occupation 90 jours', k.occupancy90 + ' %', '', '#/calendar' ) +
				'</div>';

			if ( d.current ) {
				html += '<section class="bo-card bo-current"><h2>Actuellement au chalet</h2>' + bookingRow( d.current, '<span class="bo-muted">Départ ' + esc( fdate( d.current.checkOut ) ) + '</span>' ) + '</section>';
			}

			if ( d.pending.length ) {
				html += '<section class="bo-card bo-attention"><h2>Demandes à traiter (' + d.pending.length + ')</h2>' +
					d.pending.map( function ( b ) {
						return '<div class="bo-pending-item">' + bookingRow( b ) +
							'<div class="bo-actions">' +
								'<button class="bo-btn bo-primary" data-quick="confirmed" data-id="' + b.id + '">Confirmer</button>' +
								'<button class="bo-btn" data-quick="cancelled" data-id="' + b.id + '">Refuser</button>' +
							'</div></div>';
					} ).join( '' ) + '</section>';
			}

			html += '<div class="bo-grid2">' +
				'<section class="bo-card"><h2>Arrivées (30 jours)</h2>' + ( d.arrivals.length ? d.arrivals.map( function ( b ) {
					return bookingRow( b, '<span class="bo-when">' + esc( relDay( b.checkIn, d.today ) ) + '</span>' );
				} ).join( '' ) : '<p class="bo-empty">Aucune arrivée prévue.</p>' ) + '</section>' +
				'<section class="bo-card"><h2>Départs (14 jours) — ménage</h2>' + ( d.departures.length ? d.departures.map( function ( b ) {
					return bookingRow( b, '<span class="bo-when">' + esc( relDay( b.checkOut, d.today ) ) + '</span>' );
				} ).join( '' ) : '<p class="bo-empty">Aucun départ prévu.</p>' ) + '</section>' +
				'</div>';

			if ( d.toCollect.length ) {
				html += '<section class="bo-card"><h2>Paiements à recevoir</h2>' + d.toCollect.map( function ( b ) {
					return bookingRow( b, '<span class="bo-muted">Reste ' + money( b.balance ) + ' sur ' + money( b.total ) + '</span>' );
				} ).join( '' ) + '</section>';
			}

			if ( d.sync.enabled ) {
				html += '<section class="bo-card bo-sync"><h2>Synchronisation Airbnb / Booking</h2><p>' +
					( d.sync.time ? 'Dernière synchronisation : ' + new Date( d.sync.time * 1000 ).toLocaleString( C.locale ) : 'Jamais synchronisé.' ) +
					( d.sync.errors ? ' <span class="bo-badge bo-cancelled">' + d.sync.errors + ' erreur(s)</span>' : '' ) +
					'</p><button class="bo-btn" data-sync>Synchroniser maintenant</button></section>';
			}

			var v = setView( html, 'dashboard' );
			v.addEventListener( 'click', function ( e ) {
				var q = e.target.closest( '[data-quick]' );
				if ( q ) {
					e.preventDefault();
					quickStatus( q.getAttribute( 'data-id' ), q.getAttribute( 'data-quick' ), viewDashboard );
				}
				if ( e.target.closest( '[data-sync]' ) ) {
					doSync( viewDashboard );
				}
			} );
		} ).catch( fail );
	}

	function kpi( label, value, cls, href ) {
		var tag = href ? 'a href="' + href + '"' : 'div';
		return '<' + tag + ' class="bo-kpi ' + ( cls || '' ) + '"><span>' + label + '</span><strong>' + esc( value ) + '</strong></' + ( href ? 'a' : 'div' ) + '>';
	}

	function relDay( date, ref ) {
		var n = diffDays( ref, date );
		if ( n === 0 ) {
			return 'Aujourd’hui';
		}
		if ( n === 1 ) {
			return 'Demain';
		}
		if ( n < 0 ) {
			return fdate( date );
		}
		return 'Dans ' + n + ' jours · ' + fdate( date, { weekday: 'long', day: 'numeric', month: 'long' } );
	}

	function quickStatus( id, status, then ) {
		var confirmMsg = status === 'confirmed' ?
			'Confirmer cette réservation ?\n\nOK = confirmer ET envoyer l’e-mail de confirmation au client.' :
			'Refuser / annuler cette réservation ?\n\nOK = annuler ET prévenir le client par e-mail.';
		if ( ! confirmBox( confirmMsg ) ) {
			return;
		}
		api( 'POST', '/bookings/' + id + '/status', { status: status, notify: true } ).then( function ( b ) {
			toast( ( status === 'confirmed' ? 'Réservation confirmée' : 'Réservation annulée' ) + ( b.sent ? ' — e-mail envoyé' : b.sent === false ? ' — e-mail NON envoyé (voir Plus → E-mails)' : '' ), b.sent === false );
			then();
		} ).catch( fail );
	}

	function doSync( then ) {
		toast( 'Synchronisation…' );
		api( 'POST', '/sync' ).then( function ( r ) {
			var errors = Object.keys( r.report ).filter( function ( u ) {
				return typeof r.report[ u ] === 'string';
			} );
			toast( errors.length ? 'Erreur de synchronisation : ' + r.report[ errors[ 0 ] ] : 'Calendriers synchronisés', errors.length > 0 );
			then();
		} ).catch( fail );
	}

	/* ------------------------------------------------------------------ */
	/* Calendrier                                                         */
	/* ------------------------------------------------------------------ */

	var calMonth = null;
	var calSel = null;

	function viewCalendar() {
		if ( ! calMonth ) {
			calMonth = today().slice( 0, 7 ) + '-01';
		}
		var first = calMonth;
		var last = addDays( ymd( new Date( Date.UTC( +first.slice( 0, 4 ), +first.slice( 5, 7 ), 1 ) ) ), 0 );
		loading( 'calendar' );
		api( 'GET', '/bookings', null, { from: addDays( first, -7 ), to: addDays( last, 7 ), include_ical: 1 } ).then( function ( list ) {
			list = list.filter( function ( b ) {
				return b.status !== 'cancelled';
			} );
			renderCalendar( first, last, list );
		} ).catch( fail );
	}

	function renderCalendar( first, next, list ) {
		var byNight = {};
		list.forEach( function ( b ) {
			for ( var d = b.checkIn; d < b.checkOut; d = addDays( d, 1 ) ) {
				byNight[ d ] = b;
			}
		} );
		var title = parse( first ).toLocaleDateString( C.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' } );
		var html = '<div class="bo-cal-head"><button class="bo-btn" data-cal="-1" aria-label="Mois précédent">‹</button><h1>' + esc( title ) + '</h1><button class="bo-btn" data-cal="1" aria-label="Mois suivant">›</button><button class="bo-btn bo-small" data-cal="0">Aujourd’hui</button></div>' +
			'<p class="bo-muted bo-cal-help">' + ( calSel ? 'Arrivée : <strong>' + esc( fdate( calSel ) ) + '</strong> — touchez maintenant le jour de départ.' : 'Touchez un jour libre (arrivée) puis un second jour (départ) pour bloquer des dates ou créer une réservation.' ) + '</p>' +
			'<div class="bo-cal">';
		for ( var w = 1; w <= 7; w++ ) {
			html += '<div class="bo-dow">' + esc( C.weekdays[ w % 7 ].slice( 0, 3 ) ) + '</div>';
		}
		var lead = ( parse( first ).getUTCDay() + 6 ) % 7;
		for ( var i = 0; i < lead; i++ ) {
			html += '<div class="bo-day bo-out"></div>';
		}
		var t = today();
		for ( var d = first; d < next; d = addDays( d, 1 ) ) {
			var b = byNight[ d ];
			var prev = byNight[ addDays( d, -1 ) ];
			var cls = [ 'bo-day' ];
			var label = '';
			if ( d === t ) {
				cls.push( 'bo-today' );
			}
			if ( b ) {
				cls.push( 'bo-occ', 'bo-' + ( b.source === 'ical' ? 'ical' : b.status ) );
				if ( ! prev || prev.id !== b.id ) {
					cls.push( 'bo-start' );
					label = '<span class="bo-day-label">' + esc( guestName( b ) ) + '</span>';
				}
			}
			if ( prev && ( ! b || b.id !== prev.id ) ) {
				cls.push( 'bo-end', 'bo-end-' + ( prev.source === 'ical' ? 'ical' : prev.status ) );
			}
			if ( calSel && d === calSel ) {
				cls.push( 'bo-sel' );
			}
			html += '<button class="' + cls.join( ' ' ) + '" data-day="' + d + '"' + ( b ? ' data-id="' + b.id + '" data-src="' + b.source + '"' : '' ) + '><span class="bo-num">' + parse( d ).getUTCDate() + '</span>' + label + '</button>';
		}
		html += '</div>' +
			'<div class="bo-legend"><span class="bo-badge bo-confirmed">Confirmée</span><span class="bo-badge bo-pending">En attente</span><span class="bo-badge bo-blocked">Bloquée</span><span class="bo-badge bo-ical">Airbnb / Booking</span></div>';

		var month = list.filter( function ( b ) {
			return b.checkOut > first && b.checkIn < next;
		} );
		html += '<section class="bo-card"><h2>Ce mois-ci</h2>' + ( month.length ? month.map( function ( b ) {
			return bookingRow( b );
		} ).join( '' ) : '<p class="bo-empty">Aucune réservation ce mois-ci.</p>' ) + '</section>';

		var v = setView( html, 'calendar' );
		v.addEventListener( 'click', function ( e ) {
			var nav = e.target.closest( '[data-cal]' );
			if ( nav ) {
				var dir = +nav.getAttribute( 'data-cal' );
				if ( dir === 0 ) {
					calMonth = today().slice( 0, 7 ) + '-01';
				} else {
					var p = parse( calMonth );
					calMonth = ymd( new Date( Date.UTC( p.getUTCFullYear(), p.getUTCMonth() + dir, 1 ) ) );
				}
				viewCalendar();
				return;
			}
			var day = e.target.closest( '[data-day]' );
			if ( ! day ) {
				return;
			}
			var date = day.getAttribute( 'data-day' );
			var id = day.getAttribute( 'data-id' );
			if ( ! calSel && id ) {
				if ( day.getAttribute( 'data-src' ) === 'ical' ) {
					toast( 'Réservation importée d’Airbnb / Booking : à gérer sur la plateforme d’origine.' );
				} else {
					location.hash = '#/booking/' + id;
				}
				return;
			}
			if ( ! calSel || date <= calSel ) {
				if ( id ) {
					return;
				}
				calSel = date;
				renderCalendar( first, next, list );
				return;
			}
			var start = calSel;
			calSel = null;
			location.hash = '#/new?in=' + start + '&out=' + date;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Liste des réservations                                             */
	/* ------------------------------------------------------------------ */

	function viewBookings( params ) {
		var f = {
			status: params.status || '',
			period: params.period || 'upcoming',
			search: params.search || '',
		};
		var html = '<h1>Réservations</h1>' +
			'<form class="bo-filters" id="bo-filter">' +
				'<input type="search" name="search" placeholder="Rechercher un nom, e-mail, téléphone…" value="' + esc( f.search ) + '">' +
				'<select name="status"><option value="">Tous les statuts</option>' + Object.keys( STATUS ).map( function ( s ) {
					return '<option value="' + s + '"' + ( f.status === s ? ' selected' : '' ) + '>' + STATUS[ s ] + '</option>';
				} ).join( '' ) + '</select>' +
				'<select name="period"><option value="upcoming"' + ( f.period === 'upcoming' ? ' selected' : '' ) + '>À venir</option><option value="past"' + ( f.period === 'past' ? ' selected' : '' ) + '>Passées</option><option value="all"' + ( f.period === 'all' ? ' selected' : '' ) + '>Toutes</option></select>' +
			'</form><div id="bo-list"><div class="bo-loading">Chargement…</div></div>';
		var v = setView( html, 'bookings' );
		var form = v.querySelector( '#bo-filter' );
		var timer;
		function load() {
			var q = formData( form );
			api( 'GET', '/bookings', null, { status: q.status, period: q.period === 'all' ? '' : q.period, search: q.search } ).then( function ( list ) {
				v.querySelector( '#bo-list' ).innerHTML = list.length ?
					'<p class="bo-muted">' + list.length + ' résultat' + ( list.length > 1 ? 's' : '' ) + '</p><div class="bo-card bo-flush">' + list.map( function ( b ) {
						return bookingRow( b );
					} ).join( '' ) + '</div>' :
					'<p class="bo-empty">Aucune réservation.</p>';
			} ).catch( fail );
		}
		form.addEventListener( 'change', load );
		form.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( load, 300 );
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			load();
		} );
		load();
	}

	/* ------------------------------------------------------------------ */
	/* Détail d'une réservation                                           */
	/* ------------------------------------------------------------------ */

	function viewBooking( id ) {
		loading( 'bookings' );
		Promise.all( [ api( 'GET', '/bookings/' + id ), api( 'GET', '/emails' ) ] ).then( function ( res ) {
			renderBooking( res[ 0 ] );
		} ).catch( fail );
	}

	function phoneLinks( b ) {
		if ( ! b.phone ) {
			return '';
		}
		var digits = b.phone.replace( /[^\d+]/g, '' ).replace( /^00/, '+' );
		var wa = digits.replace( /^\+/, '' ).replace( /^0(?=[1-9])/, '41' );
		return '<a class="bo-btn" href="tel:' + esc( digits ) + '">Appeler</a>' +
			'<a class="bo-btn" href="https://wa.me/' + esc( wa ) + '" target="_blank" rel="noopener">WhatsApp</a>';
	}

	function renderBooking( b ) {
		var blocked = b.status === 'blocked';
		var actions = '';
		if ( b.status === 'pending' ) {
			actions += '<button class="bo-btn bo-primary" data-status="confirmed">Confirmer</button><button class="bo-btn" data-status="cancelled">Refuser</button>';
		} else if ( b.status === 'confirmed' ) {
			actions += '<button class="bo-btn" data-status="cancelled">Annuler la réservation</button>';
		} else if ( b.status === 'cancelled' ) {
			actions += '<button class="bo-btn" data-status="pending">Réactiver (en attente)</button><button class="bo-btn" data-status="confirmed">Réactiver et confirmer</button>';
		}
		actions += '<button class="bo-btn bo-danger" data-delete>Supprimer</button>';

		var html = '<a class="bo-back" href="#/bookings">← Réservations</a>' +
			'<div class="bo-title"><h1>' + esc( guestName( b ) ) + '</h1>' + badge( b ) + payBadge( b ) + '</div>' +
			'<p class="bo-muted">N° ' + b.id + ' · ' + esc( SOURCE[ b.source ] || b.source ) + ' · reçue le ' + esc( fdate( b.createdAt.slice( 0, 10 ) ) ) + '</p>' +
			'<div class="bo-actions">' + actions + '</div>';

		if ( ! blocked ) {
			html += '<section class="bo-card"><h2>Contact</h2>' +
				'<p>' + ( b.email ? '<a href="mailto:' + esc( b.email ) + '">' + esc( b.email ) + '</a><br>' : '' ) + esc( b.phone ) + '</p>' +
				'<div class="bo-actions">' + phoneLinks( b ) + '</div>' +
				( b.message ? '<h3>Message du client</h3><p class="bo-quote">' + esc( b.message ).replace( /\n/g, '<br>' ) + '</p>' : '' ) +
				'</section>';
		}

		html += '<form class="bo-card bo-form" id="bo-edit"><h2>' + ( blocked ? 'Dates bloquées' : 'Séjour et paiement' ) + '</h2>' +
			'<div class="bo-fields">' +
				field( 'Arrivée', input( 'checkIn', b.checkIn, 'date', 'required' ) ) +
				field( 'Départ', input( 'checkOut', b.checkOut, 'date', 'required' ) ) +
				( blocked ? field( 'Note', input( 'name', b.name ), 'bo-wide' ) :
					field( 'Adultes', input( 'adults', b.adults, 'number', 'min="0" max="' + C.maxGuests + '"' ) ) +
					field( 'Enfants', input( 'children', b.children, 'number', 'min="0" max="' + C.maxGuests + '"' ) ) +
					field( 'Nom', input( 'name', b.name ), 'bo-wide' ) +
					field( 'E-mail', input( 'email', b.email, 'email' ) ) +
					field( 'Téléphone', input( 'phone', b.phone, 'tel' ) ) +
					field( 'Prix total (' + C.currency + ')', input( 'total', b.total, 'number', 'step="0.01" min="0"' ) ) +
					field( 'Montant reçu (' + C.currency + ')', input( 'paid', b.paid, 'number', 'step="0.01" min="0"' ) ) ) +
			'</div>' +
			( blocked ? '' : '<div class="bo-actions bo-pay-quick"><span class="bo-muted">Paiement rapide :</span>' +
				'<button type="button" class="bo-btn bo-small" data-paid="' + ( Math.round( b.total * 30 ) / 100 ) + '">Acompte 30 % (' + money( b.total * 0.3 ) + ')</button>' +
				'<button type="button" class="bo-btn bo-small" data-paid="' + b.total + '">Tout payé</button>' +
				'<span class="bo-muted">Solde : <strong>' + money( b.balance ) + '</strong></span></div>' ) +
			field( 'Notes internes (visibles uniquement ici)', '<textarea name="notes" rows="3">' + esc( b.notes ) + '</textarea>', 'bo-wide' ) +
			'<div class="bo-actions"><button class="bo-btn bo-primary" type="submit">Enregistrer</button>' +
			( blocked ? '' : '<button class="bo-btn" type="button" data-recalc>Recalculer le prix selon les tarifs</button>' ) + '</div>' +
			'</form>';

		if ( ! blocked && b.priceLines.length ) {
			html += '<section class="bo-card"><h2>Détail du prix</h2><table class="bo-table">' + b.priceLines.map( function ( l ) {
				return '<tr><td>' + esc( l.label ) + '</td><td class="bo-num-cell">' + money( l.amount ) + '</td></tr>';
			} ).join( '' ) + '<tr class="bo-total"><td>Total</td><td class="bo-num-cell">' + money( b.total ) + '</td></tr></table></section>';
		}

		if ( ! blocked && b.email ) {
			html += '<form class="bo-card bo-form" id="bo-mail"><h2>Écrire au client</h2>' +
				field( 'Modèle', '<select name="template"><option value="">Message libre</option><option value="guest_confirmed">Renvoyer la confirmation</option><option value="guest_request">Renvoyer l’accusé de réception</option><option value="guest_cancelled">Envoyer le refus</option></select>', 'bo-wide' ) +
				'<div id="bo-free">' +
					field( 'Objet', input( 'subject', 'Votre séjour au ' + C.chalet ), 'bo-wide' ) +
					field( 'Message', '<textarea name="body" rows="6">Bonjour {name},\n\n\n\nMeilleures salutations,\n{chalet}</textarea>', 'bo-wide' ) +
					'<p class="bo-muted">Variables : {name} {check_in} {check_out} {nights} {total} {summary} {payment}</p>' +
				'</div>' +
				'<div class="bo-actions"><button class="bo-btn bo-primary" type="submit">Envoyer</button></div></form>';
		}

		var v = setView( html, 'bookings' );

		v.querySelectorAll( '[data-status]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var status = btn.getAttribute( 'data-status' );
				var notify = false;
				if ( b.email && ( status === 'confirmed' || ( status === 'cancelled' && b.status === 'pending' ) ) ) {
					notify = confirmBox( status === 'confirmed' ? 'Envoyer l’e-mail de confirmation au client ?' : 'Prévenir le client par e-mail ?' );
				} else if ( ! confirmBox( 'Passer la réservation en « ' + STATUS[ status ] + ' » ?' ) ) {
					return;
				}
				api( 'POST', '/bookings/' + b.id + '/status', { status: status, notify: notify } ).then( function ( r ) {
					toast( STATUS[ status ] + ( r.sent ? ' — e-mail envoyé' : r.sent === false ? ' — e-mail NON envoyé' : '' ), r.sent === false );
					renderBooking( r );
				} ).catch( fail );
			} );
		} );

		v.querySelector( '[data-delete]' ).addEventListener( 'click', function () {
			if ( confirmBox( 'Supprimer définitivement cette entrée ? Cette action est irréversible.' ) ) {
				api( 'DELETE', '/bookings/' + b.id ).then( function () {
					toast( 'Supprimée' );
					location.hash = '#/bookings';
				} ).catch( fail );
			}
		} );

		var edit = v.querySelector( '#bo-edit' );
		edit.addEventListener( 'click', function ( e ) {
			var p = e.target.closest( '[data-paid]' );
			if ( p ) {
				edit.elements.paid.value = p.getAttribute( 'data-paid' );
			}
			if ( e.target.closest( '[data-recalc]' ) ) {
				var data = formData( edit );
				data.recalculate = true;
				delete data.total;
				save( data );
			}
		} );
		edit.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save( formData( edit ) );
		} );
		function save( data ) {
			api( 'POST', '/bookings/' + b.id, data ).then( function ( r ) {
				toast( 'Enregistré' );
				renderBooking( r );
			} ).catch( fail );
		}

		var mail = v.querySelector( '#bo-mail' );
		if ( mail ) {
			mail.elements.template.addEventListener( 'change', function () {
				mail.querySelector( '#bo-free' ).hidden = !! this.value;
			} );
			mail.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var data = formData( mail );
				if ( ! confirmBox( 'Envoyer cet e-mail à ' + b.email + ' ?' ) ) {
					return;
				}
				api( 'POST', '/bookings/' + b.id + '/email', data ).then( function () {
					toast( 'E-mail envoyé à ' + b.email );
				} ).catch( fail );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Nouvelle réservation / blocage                                     */
	/* ------------------------------------------------------------------ */

	function viewNew( params ) {
		var html = '<a class="bo-back" href="#/calendar">← Calendrier</a><h1>Nouvelle entrée</h1>' +
			'<form class="bo-card bo-form" id="bo-new">' +
				'<div class="bo-seg">' +
					'<label><input type="radio" name="status" value="confirmed" checked><span>Réservation confirmée</span></label>' +
					'<label><input type="radio" name="status" value="pending"><span>Option / en attente</span></label>' +
					'<label><input type="radio" name="status" value="blocked"><span>Bloquer des dates</span></label>' +
				'</div>' +
				'<div class="bo-fields">' +
					field( 'Arrivée', input( 'checkIn', params.in || '', 'date', 'required' ) ) +
					field( 'Départ', input( 'checkOut', params.out || '', 'date', 'required' ) ) +
					'<div class="bo-guest bo-fields bo-wide">' +
						field( 'Adultes', input( 'adults', 2, 'number', 'min="1" max="' + C.maxGuests + '"' ) ) +
						field( 'Enfants', input( 'children', 0, 'number', 'min="0" max="' + C.maxGuests + '"' ) ) +
					'</div>' +
					field( '<span class="bo-guest-label">Nom du client</span><span class="bo-block-label" hidden>Motif (usage personnel, travaux…)</span>', input( 'name', '' ), 'bo-wide' ) +
					'<div class="bo-guest bo-fields bo-wide">' +
						field( 'E-mail', input( 'email', '', 'email' ) ) +
						field( 'Téléphone', input( 'phone', '', 'tel' ) ) +
						field( 'Prix total (' + C.currency + ')', input( 'total', '', 'number', 'step="0.01" min="0" placeholder="Calcul automatique"' ) ) +
						field( 'Montant déjà reçu (' + C.currency + ')', input( 'paid', 0, 'number', 'step="0.01" min="0"' ) ) +
					'</div>' +
				'</div>' +
				'<div id="bo-quote" class="bo-guest"></div>' +
				field( 'Notes internes', '<textarea name="notes" rows="3"></textarea>', 'bo-wide' ) +
				'<label class="bo-check bo-guest"><input type="checkbox" name="notify"> Envoyer l’e-mail de confirmation au client</label>' +
				'<div class="bo-actions"><button class="bo-btn bo-primary" type="submit">Enregistrer</button></div>' +
			'</form>';
		var v = setView( html, 'calendar' );
		var form = v.querySelector( '#bo-new' );
		var quoteEl = v.querySelector( '#bo-quote' );

		function mode() {
			var blocked = formData( form ).status === 'blocked';
			form.querySelectorAll( '.bo-guest' ).forEach( function ( el ) {
				el.hidden = blocked;
			} );
			form.querySelector( '.bo-guest-label' ).hidden = blocked;
			form.querySelector( '.bo-block-label' ).hidden = ! blocked;
		}
		var qTimer;
		function quote() {
			clearTimeout( qTimer );
			qTimer = setTimeout( function () {
				var d = formData( form );
				if ( ! d.checkIn || ! d.checkOut || d.checkOut <= d.checkIn ) {
					quoteEl.innerHTML = '';
					return;
				}
				api( 'GET', '/quote', null, { checkIn: d.checkIn, checkOut: d.checkOut, adults: d.adults, children: d.children } ).then( function ( q ) {
					quoteEl.innerHTML =
						( q.conflict ? '<p class="bo-alert">⚠ ' + esc( q.conflict ) + '</p>' : '' ) +
						( q.warning ? '<p class="bo-note">ℹ Règle du site non respectée (autorisé pour vous) : ' + esc( q.warning ) + '</p>' : '' ) +
						'<table class="bo-table">' + q.lines.map( function ( l ) {
							return '<tr><td>' + esc( l.label ) + '</td><td class="bo-num-cell">' + money( l.amount ) + '</td></tr>';
						} ).join( '' ) + '<tr class="bo-total"><td>Tarif calculé (' + q.nights + ' nuits)</td><td class="bo-num-cell">' + money( q.total ) + '</td></tr></table>';
				} ).catch( function ( e ) {
					quoteEl.innerHTML = '<p class="bo-alert">' + esc( e.message ) + '</p>';
				} );
			}, 250 );
		}
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.name === 'status' ) {
				mode();
			}
			quote();
		} );
		form.addEventListener( 'input', quote );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var d = formData( form );
			api( 'POST', '/bookings', d ).then( function ( b ) {
				toast( d.status === 'blocked' ? 'Dates bloquées' : 'Réservation enregistrée' );
				location.hash = d.status === 'blocked' ? '#/calendar' : '#/booking/' + b.id;
			} ).catch( fail );
		} );
		if ( params.in ) {
			calMonth = params.in.slice( 0, 7 ) + '-01';
		}
		mode();
		quote();
	}

	/* ------------------------------------------------------------------ */
	/* Tarifs                                                             */
	/* ------------------------------------------------------------------ */

	function viewPricing() {
		loading( 'pricing' );
		api( 'GET', '/pricing' ).then( renderPricing ).catch( fail );
	}

	function seasonCard( s, i ) {
		var days = [ '<option value="-1">Libre</option>' ].concat( C.weekdays.map( function ( n, k ) {
			return '<option value="' + k + '"' + ( +s.arrival_day === k ? ' selected' : '' ) + '>' + esc( n ) + '</option>';
		} ) ).join( '' );
		return '<div class="bo-season" data-i="' + i + '">' +
			'<div class="bo-fields">' +
				field( 'Nom', input( 'name', s.name, 'text', 'placeholder="ex. Noël / Nouvel An"' ), 'bo-wide' ) +
				field( 'Du', input( 'start', s.start, 'date', 'required' ) ) +
				field( 'Au (inclus)', input( 'end', s.end, 'date', 'required' ) ) +
				field( 'Prix / nuit', input( 'price', s.price, 'number', 'min="0" step="0.01"' ) ) +
				field( 'Nuits min.', input( 'min_nights', s.min_nights, 'number', 'min="1"' ) ) +
				field( 'Jour d’arrivée / départ', '<select name="arrival_day">' + days + '</select>' ) +
			'</div>' +
			'<div class="bo-actions"><button type="button" class="bo-btn bo-small" data-dup>Copier sur l’année suivante</button><button type="button" class="bo-btn bo-small bo-danger" data-del>Supprimer</button></div>' +
		'</div>';
	}

	function renderPricing( p ) {
		var seasons = p.seasons.slice();
		var html = '<h1>Tarifs et règles</h1>' +
			'<form class="bo-form" id="bo-pricing">' +
			'<section class="bo-card"><h2>Général</h2><div class="bo-fields">' +
				field( 'Prix / nuit hors saison', input( 'base_price', p.base_price, 'number', 'min="0" step="0.01"' ) ) +
				field( 'Nuits min. hors saison', input( 'base_min_nights', p.base_min_nights, 'number', 'min="1"' ) ) +
				field( 'Frais de nettoyage', input( 'cleaning_fee', p.cleaning_fee, 'number', 'min="0" step="0.01"' ) ) +
				field( 'Taxe de séjour / adulte / nuit', input( 'tourist_tax', p.tourist_tax, 'number', 'min="0" step="0.01"' ) ) +
				field( 'Rabais dès 7 nuits (%)', input( 'weekly_discount', p.weekly_discount, 'number', 'min="0" max="100" step="0.1"' ) ) +
				field( 'Capacité (personnes)', input( 'max_guests', p.max_guests, 'number', 'min="1"' ) ) +
				field( 'Délai min. avant arrivée (jours)', input( 'min_advance_days', p.min_advance_days, 'number', 'min="0"' ) ) +
				field( 'Modalités de paiement (e-mail de confirmation)', '<textarea name="payment_instructions" rows="4">' + esc( p.payment_instructions ) + '</textarea>', 'bo-wide' ) +
			'</div></section>' +
			'<section class="bo-card"><h2>Saisons</h2><p class="bo-muted">Les dates hors saison utilisent le tarif général. Un « jour d’arrivée » impose des séjours du samedi au samedi par exemple.</p>' +
				'<div id="bo-seasons"></div>' +
				'<button type="button" class="bo-btn" data-add>+ Ajouter une saison</button>' +
			'</section>' +
			'<div class="bo-sticky"><button class="bo-btn bo-primary" type="submit">Enregistrer les tarifs</button></div>' +
			'</form>';
		var v = setView( html, 'pricing' );
		var form = v.querySelector( '#bo-pricing' );
		var list = v.querySelector( '#bo-seasons' );

		function readSeasons() {
			return Array.prototype.map.call( list.querySelectorAll( '.bo-season' ), function ( el ) {
				var o = {};
				el.querySelectorAll( '[name]' ).forEach( function ( f ) {
					o[ f.name ] = f.value;
				} );
				return o;
			} );
		}
		function draw() {
			list.innerHTML = seasons.length ? seasons.map( seasonCard ).join( '' ) : '<p class="bo-empty">Aucune saison : le tarif général s’applique toute l’année.</p>';
		}
		draw();

		v.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-add]' ) ) {
				seasons = readSeasons();
				seasons.push( { name: '', start: '', end: '', price: p.base_price, min_nights: 7, arrival_day: 6 } );
				draw();
				list.lastElementChild.querySelector( 'input' ).focus();
			}
			var card = e.target.closest( '.bo-season' );
			if ( ! card ) {
				return;
			}
			var i = +card.getAttribute( 'data-i' );
			if ( e.target.closest( '[data-del]' ) ) {
				seasons = readSeasons();
				seasons.splice( i, 1 );
				draw();
			}
			if ( e.target.closest( '[data-dup]' ) ) {
				seasons = readSeasons();
				var s = Object.assign( {}, seasons[ i ] );
				if ( ! s.start || ! s.end ) {
					return;
				}
				// Même jour de semaine un an plus tard (+364 jours) si un jour d'arrivée est imposé.
				var shift = +s.arrival_day >= 0 ? 364 : 365;
				s.start = addDays( s.start, shift );
				s.end = addDays( s.end, shift );
				seasons.splice( i + 1, 0, s );
				draw();
				toast( 'Saison copiée : vérifiez les dates' );
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var d = {};
			[ 'base_price', 'base_min_nights', 'cleaning_fee', 'tourist_tax', 'weekly_discount', 'max_guests', 'min_advance_days', 'payment_instructions' ].forEach( function ( k ) {
				d[ k ] = form.elements[ k ].value;
			} );
			d.seasons = readSeasons();
			var bad = d.seasons.filter( function ( s ) {
				return ! s.start || ! s.end || s.end < s.start;
			} );
			if ( bad.length ) {
				toast( 'Une saison a des dates manquantes ou inversées.', true );
				return;
			}
			api( 'POST', '/pricing', d ).then( function ( r ) {
				toast( 'Tarifs enregistrés' );
				C.maxGuests = +r.max_guests;
				renderPricing( r );
			} ).catch( fail );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Statistiques                                                       */
	/* ------------------------------------------------------------------ */

	var statsYear = null;
	var MONTHS = [ 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' ];
	var MONTHS_AXIS = [ 'jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc' ];

	function viewStats() {
		statsYear = statsYear || +today().slice( 0, 4 );
		loading( 'stats' );
		api( 'GET', '/stats', null, { year: statsYear } ).then( renderStats ).catch( fail );
	}

	function niceMax( v ) {
		if ( v <= 0 ) {
			return 1;
		}
		var p = Math.pow( 10, Math.floor( Math.log10( v ) ) );
		var n = v / p;
		return ( n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10 ) * p;
	}

	function barChart( id, title, values, max, fmt ) {
		var ticks = [ 0, 0.5, 1 ].map( function ( f ) {
			return '<div class="bo-tick" style="bottom:' + ( f * 100 ) + '%"><span>' + esc( fmt( max * f ) ) + '</span></div>';
		} ).join( '' );
		var bars = values.map( function ( v, i ) {
			var h = max ? Math.max( 0, v / max * 100 ) : 0;
			return '<div class="bo-bar-slot" tabindex="0" data-tip="' + esc( MONTHS[ i ] + ' ' + statsYear + ' : ' + fmt( v ) ) + '">' +
				'<div class="bo-bar" style="height:' + h + '%"></div>' +
				'<span class="bo-bar-label">' + MONTHS_AXIS[ i ] + '</span></div>';
		} ).join( '' );
		return '<figure class="bo-chart" id="' + id + '"><figcaption>' + esc( title ) + '</figcaption>' +
			'<div class="bo-plot"><div class="bo-ticks">' + ticks + '</div><div class="bo-bars">' + bars + '</div><div class="bo-tip" hidden></div></div></figure>';
	}

	function renderStats( s ) {
		var revenue = s.months.map( function ( m ) {
			return m.revenue;
		} );
		var occ = s.months.map( function ( m ) {
			return m.occupancy;
		} );
		var html = '<div class="bo-cal-head"><button class="bo-btn" data-year="-1" aria-label="Année précédente">‹</button><h1>Statistiques ' + s.year + '</h1><button class="bo-btn" data-year="1" aria-label="Année suivante">›</button></div>' +
			'<div class="bo-kpis">' +
				kpi( 'Chiffre d’affaires', money( s.revenue ) ) +
				kpi( 'Nuits louées', s.nights ) +
				kpi( 'Taux d’occupation', s.occupancy + ' %' ) +
				kpi( 'Prix moyen / nuit', money( s.avgNight ) ) +
			'</div>' +
			'<section class="bo-card">' + barChart( 'bo-rev', 'Chiffre d’affaires par mois (' + C.currency + ')', revenue, niceMax( Math.max.apply( null, revenue ) ), money ) + '</section>' +
			'<section class="bo-card">' + barChart( 'bo-occ', 'Taux d’occupation par mois (%)', occ, 100, function ( v ) {
				return Math.round( v ) + ' %';
			} ) + '</section>' +
			'<section class="bo-card"><details><summary>Voir le tableau des données</summary><table class="bo-table"><thead><tr><th>Mois</th><th class="bo-num-cell">Nuits</th><th class="bo-num-cell">Occupation</th><th class="bo-num-cell">Chiffre d’affaires</th><th class="bo-num-cell">Arrivées</th></tr></thead><tbody>' +
				s.months.map( function ( m, i ) {
					return '<tr><td>' + MONTHS[ i ] + '</td><td class="bo-num-cell">' + m.nights + '</td><td class="bo-num-cell">' + m.occupancy + ' %</td><td class="bo-num-cell">' + money( m.revenue ) + '</td><td class="bo-num-cell">' + m.bookings + '</td></tr>';
				} ).join( '' ) +
			'</tbody></table></details>' +
			'<p class="bo-muted">Réservations par origine : site web ' + ( s.sources.site || 0 ) + ' · ajout manuel ' + ( s.sources.admin || 0 ) + ' · Airbnb/Booking ' + ( s.sources.ical || 0 ) + '. Le chiffre d’affaires est réparti par nuit ; les réservations importées d’Airbnb/Booking comptent dans l’occupation mais pas dans le chiffre d’affaires.</p></section>';

		var v = setView( html, 'stats' );
		v.addEventListener( 'click', function ( e ) {
			var y = e.target.closest( '[data-year]' );
			if ( y ) {
				statsYear += +y.getAttribute( 'data-year' );
				viewStats();
			}
		} );
		v.querySelectorAll( '.bo-plot' ).forEach( function ( plot ) {
			var tip = plot.querySelector( '.bo-tip' );
			function show( slot ) {
				tip.textContent = slot.getAttribute( 'data-tip' );
				tip.hidden = false;
				var r = slot.getBoundingClientRect();
				var pr = plot.getBoundingClientRect();
				var left = r.left - pr.left + r.width / 2;
				tip.style.left = Math.min( Math.max( left, 70 ), pr.width - 70 ) + 'px';
				plot.querySelectorAll( '.bo-bar-slot' ).forEach( function ( s ) {
					s.classList.toggle( 'dim', s !== slot );
				} );
			}
			function hide() {
				tip.hidden = true;
				plot.querySelectorAll( '.bo-bar-slot' ).forEach( function ( s ) {
					s.classList.remove( 'dim' );
				} );
			}
			plot.querySelectorAll( '.bo-bar-slot' ).forEach( function ( slot ) {
				slot.addEventListener( 'mouseenter', function () {
					show( slot );
				} );
				slot.addEventListener( 'focus', function () {
					show( slot );
				} );
				slot.addEventListener( 'click', function () {
					show( slot );
				} );
				slot.addEventListener( 'blur', hide );
			} );
			plot.addEventListener( 'mouseleave', hide );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Plus                                                               */
	/* ------------------------------------------------------------------ */

	function viewMore() {
		var html = '<h1>Plus</h1>' +
			'<section class="bo-card bo-menu">' +
				'<a class="bo-row" href="' + esc( url( '/export', { _wpnonce: C.nonce } ) ) + '"><div class="bo-row-main"><strong>Exporter les réservations</strong><span class="bo-muted">Fichier CSV (Excel, Numbers, Google Sheets)</span></div></a>' +
				'<button class="bo-row" data-sync><div class="bo-row-main"><strong>Synchroniser Airbnb / Booking</strong><span class="bo-muted">Importer maintenant les calendriers externes</span></div></button>' +
				'<a class="bo-row" href="' + esc( C.site ) + '" target="_blank" rel="noopener"><div class="bo-row-main"><strong>Voir le site</strong><span class="bo-muted">' + esc( C.site ) + '</span></div></a>' +
				( C.wpAdmin ? '<a class="bo-row" href="' + esc( C.wpAdmin ) + '"><div class="bo-row-main"><strong>Administration WordPress</strong><span class="bo-muted">Réglages avancés, e-mails, photos, conditions</span></div></a>' : '' ) +
				'<a class="bo-row" href="' + esc( C.logout ) + '"><div class="bo-row-main"><strong>Se déconnecter</strong><span class="bo-muted">Connecté en tant que ' + esc( C.user ) + '</span></div></a>' +
			'</section>' +
			'<section class="bo-card"><h2>Derniers e-mails envoyés</h2><div id="bo-maillog"><div class="bo-loading">Chargement…</div></div></section>';
		var v = setView( html, 'more' );
		v.querySelector( '[data-sync]' ).addEventListener( 'click', function () {
			doSync( function () {} );
		} );
		api( 'GET', '/emails' ).then( function ( log ) {
			v.querySelector( '#bo-maillog' ).innerHTML = log.length ? '<table class="bo-table">' + log.map( function ( m ) {
				return '<tr><td>' + esc( new Date( m.time * 1000 ).toLocaleString( C.locale, { dateStyle: 'short', timeStyle: 'short' } ) ) + '</td><td>' + esc( m.to ) + '<br><span class="bo-muted">' + esc( m.subject ) + '</span></td><td>' + ( m.ok ? '<span class="bo-badge bo-confirmed">Envoyé</span>' : '<span class="bo-badge bo-cancelled" title="' + esc( m.error ) + '">Échec</span><br><span class="bo-muted">' + esc( m.error ) + '</span>' ) + '</td></tr>';
			} ).join( '' ) + '</table>' : '<p class="bo-empty">Aucun e-mail envoyé pour le moment.</p>';
		} ).catch( fail );
	}

	/* ------------------------------------------------------------------ */
	/* Routeur                                                            */
	/* ------------------------------------------------------------------ */

	function route() {
		var hash = location.hash.replace( /^#\/?/, '' );
		var parts = hash.split( '?' );
		var path = parts[ 0 ].split( '/' );
		var params = {};
		( parts[ 1 ] || '' ).split( '&' ).forEach( function ( kv ) {
			if ( kv ) {
				var p = kv.split( '=' );
				params[ decodeURIComponent( p[ 0 ] ) ] = decodeURIComponent( p[ 1 ] || '' );
			}
		} );
		if ( path[ 0 ] !== 'calendar' && path[ 0 ] !== 'new' ) {
			calSel = null;
		}
		window.scrollTo( 0, 0 );
		switch ( path[ 0 ] ) {
			case 'calendar':
				return viewCalendar();
			case 'bookings':
				return viewBookings( params );
			case 'booking':
				return viewBooking( path[ 1 ] );
			case 'new':
				return viewNew( params );
			case 'pricing':
				return viewPricing();
			case 'stats':
				return viewStats();
			case 'more':
				return viewMore();
			default:
				return viewDashboard();
		}
	}

	layout();
	window.addEventListener( 'hashchange', route );
	route();
} )();
