/*
 * Matomo tracking code.
 * Printed INLINE by PHP after replacing the __TOKENS__ with JSON (strtr), so
 * this is a template, not a script WordPress enqueues. It lives here and not in
 * a PHP heredoc because WordPress.org's Plugin Check does not allow heredocs.
 */
var _paq = window._paq = window._paq || [];
__FIRST__
_paq.push(['trackPageView']);
_paq.push(['enableLinkTracking']);
(function () {
	var u = __URL__;
	_paq.push(['setTrackerUrl', u + 'matomo.php']);
	_paq.push(['setSiteId', __SITE_ID__]);
	var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
	g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
})();
