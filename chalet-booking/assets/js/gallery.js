/* Chalet Booking — visionneuse plein écran pour [chalet_gallery]. */
( function () {
	'use strict';

	var box, img, caption, counter, items = [], index = 0, touchX = null;

	function build() {
		box = document.createElement( 'div' );
		box.className = 'cb-lightbox';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.hidden = true;
		box.innerHTML =
			'<button type="button" class="cb-lb-close" aria-label="Fermer">&times;</button>' +
			'<button type="button" class="cb-lb-prev" aria-label="Précédente">&lsaquo;</button>' +
			'<figure><img alt=""><figcaption></figcaption></figure>' +
			'<button type="button" class="cb-lb-next" aria-label="Suivante">&rsaquo;</button>' +
			'<div class="cb-lb-counter"></div>';
		document.body.appendChild( box );
		img = box.querySelector( 'img' );
		caption = box.querySelector( 'figcaption' );
		counter = box.querySelector( '.cb-lb-counter' );

		box.querySelector( '.cb-lb-close' ).addEventListener( 'click', close );
		box.querySelector( '.cb-lb-prev' ).addEventListener( 'click', function () { show( index - 1 ); } );
		box.querySelector( '.cb-lb-next' ).addEventListener( 'click', function () { show( index + 1 ); } );
		box.addEventListener( 'click', function ( e ) {
			if ( e.target === box || e.target.tagName === 'FIGURE' ) {
				close();
			}
		} );
		box.addEventListener( 'touchstart', function ( e ) { touchX = e.touches[ 0 ].clientX; }, { passive: true } );
		box.addEventListener( 'touchend', function ( e ) {
			if ( touchX === null ) {
				return;
			}
			var dx = e.changedTouches[ 0 ].clientX - touchX;
			if ( Math.abs( dx ) > 50 ) {
				show( index + ( dx < 0 ? 1 : -1 ) );
			}
			touchX = null;
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( box.hidden ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				close();
			} else if ( e.key === 'ArrowLeft' ) {
				show( index - 1 );
			} else if ( e.key === 'ArrowRight' ) {
				show( index + 1 );
			}
		} );
	}

	function show( i ) {
		index = ( i + items.length ) % items.length;
		var a = items[ index ];
		img.src = a.href;
		img.alt = ( a.querySelector( 'img' ) || {} ).alt || '';
		caption.textContent = a.getAttribute( 'data-caption' ) || '';
		counter.textContent = ( index + 1 ) + ' / ' + items.length;
		// Précharge la suivante.
		new Image().src = items[ ( index + 1 ) % items.length ].href;
	}

	function close() {
		box.hidden = true;
		document.documentElement.classList.remove( 'cb-lb-open' );
	}

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( '[data-cb-gallery] a' );
		if ( ! a ) {
			return;
		}
		e.preventDefault();
		if ( ! box ) {
			build();
		}
		items = Array.prototype.slice.call( a.closest( '[data-cb-gallery]' ).querySelectorAll( 'a' ) );
		show( items.indexOf( a ) );
		box.hidden = false;
		document.documentElement.classList.add( 'cb-lb-open' );
		box.querySelector( '.cb-lb-close' ).focus();
	} );
} )();
