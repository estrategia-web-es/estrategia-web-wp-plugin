/*
 * Matomo, hybrid mode: follows the cookie notice.
 * Printed INLINE by PHP after replacing the __TOKENS__ with JSON (strtr), so
 * this is a template, not a script WordPress enqueues. It lives here and not in
 * a PHP heredoc because WordPress.org's Plugin Check does not allow heredocs.
 */
(function () {
	var given = null;
	function sync(accepted) {
		var yes = Array.isArray(accepted) && accepted.indexOf(__CATEGORY__) !== -1;
		if (yes === given) { return; }
		given = yes;
		window._paq.push([yes ? 'rememberCookieConsentGiven' : 'forgetCookieConsentGiven']);
	}
	document.addEventListener('estrategia-web:consent', function (e) { sync(e.detail && e.detail.accepted); });
	function current() {
		if (given === null && window.estrategiaWebConsent && typeof window.estrategiaWebConsent.get === 'function') {
			var choice = window.estrategiaWebConsent.get();
			sync(choice || []);
		}
	}
	current();
	window.addEventListener('load', current);
	document.addEventListener('estrategia-web:consent-ready', current);
})();
