/* globals DAP_CHAT */
( function () {
	'use strict';

	var cfg = typeof DAP_CHAT !== 'undefined' ? DAP_CHAT : {};
	var REST_URL = cfg.rest_url  || '/wp-json/dot-agents-press/v1/chat';
	var NONCE    = cfg.nonce     || '';
	var i18n     = cfg.i18n     || {};

	// ── Initialise every widget on the page ──────────────────────────────────
	document.querySelectorAll( '.dap-chat-widget' ).forEach( initWidget );

	function initWidget( widget ) {
		var agentId   = parseInt( widget.dataset.agentId, 10 );
		var form      = widget.querySelector( '.dap-chat-form' );
		var msgBox    = widget.querySelector( '.dap-chat-messages' );
		var textarea  = widget.querySelector( '.dap-chat-input' );
		var sendBtn   = widget.querySelector( '.dap-chat-send' );
		var history   = []; // { role, content } pairs

		if ( ! form || ! msgBox || ! textarea || ! sendBtn ) return;

		// Auto-resize textarea.
		textarea.addEventListener( 'input', function () {
			this.style.height = 'auto';
			this.style.height = Math.min( this.scrollHeight, 120 ) + 'px';
		} );

		// Submit on Enter (Shift+Enter = newline).
		textarea.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				form.dispatchEvent( new Event( 'submit' ) );
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var text = textarea.value.trim();
			if ( ! text ) return;

			appendMessage( msgBox, 'user', text );
			history.push( { role: 'user', content: text } );

			textarea.value     = '';
			textarea.style.height = '';
			sendBtn.disabled   = true;
			sendBtn.textContent = i18n.sending || 'Sending…';

			var typing = appendTypingIndicator( msgBox );

			fetchReply( agentId, history )
				.then( function ( data ) {
					typing.remove();
					appendMessage( msgBox, 'assistant', data.content );
					history.push( { role: 'assistant', content: data.content } );
				} )
				.catch( function ( err ) {
					typing.remove();
					var msg = ( err && err.message ) ? err.message : ( i18n.error || 'Something went wrong.' );
					appendMessage( msgBox, 'error', msg );
				} )
				.finally( function () {
					sendBtn.disabled    = false;
					sendBtn.textContent = i18n.send || 'Send';
					textarea.focus();
				} );
		} );
	}

	// ── API call ─────────────────────────────────────────────────────────────
	function fetchReply( agentId, messages ) {
		return fetch( REST_URL, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   NONCE,
			},
			body: JSON.stringify( { agent_id: agentId, messages: messages } ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( data.message || 'API error ' + res.status );
				}
				return data;
			} );
		} );
	}

	// ── DOM helpers ──────────────────────────────────────────────────────────
	function appendMessage( msgBox, role, content ) {
		var wrap   = document.createElement( 'div' );
		var bubble = document.createElement( 'div' );

		wrap.className   = 'dap-message dap-message--' + role;
		bubble.className = 'dap-message-bubble';
		bubble.textContent = content;

		wrap.appendChild( bubble );
		msgBox.appendChild( wrap );
		scrollToBottom( msgBox );
		return wrap;
	}

	function appendTypingIndicator( msgBox ) {
		var wrap   = document.createElement( 'div' );
		var bubble = document.createElement( 'div' );
		wrap.className   = 'dap-message dap-message--assistant dap-message--typing';
		bubble.className = 'dap-message-bubble';

		for ( var i = 0; i < 3; i++ ) {
			var dot = document.createElement( 'span' );
			dot.className = 'dap-typing-dot';
			bubble.appendChild( dot );
		}

		wrap.appendChild( bubble );
		msgBox.appendChild( wrap );
		scrollToBottom( msgBox );
		return wrap;
	}

	function scrollToBottom( el ) {
		el.scrollTop = el.scrollHeight;
	}

} )();
