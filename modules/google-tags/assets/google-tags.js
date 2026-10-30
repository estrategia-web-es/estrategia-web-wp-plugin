/*
 * Google tags: Consent Mode defaults, loading after consent and conversions.
 * Printed INLINE by PHP after replacing the __TOKENS__ with JSON (strtr), so
 * this is a template, not a script WordPress enqueues. It lives here and not in
 * a PHP heredoc because WordPress.org's Plugin Check does not allow heredocs.
 */
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
	ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
	analytics_storage: 'denied', functionality_storage: 'denied',
	personalization_storage: 'denied', security_storage: 'granted',
	wait_for_update: 500
});
gtag('set', 'ads_data_redaction', true);
(function () {
	var c = __CONFIG__;
	var loaded = false, now = { stats: false, ads: false, prefs: false }, known = false, pending = false;
	function add(src) { var s = document.createElement('script'); s.async = true; s.src = src; document.head.appendChild(s); }
	function load() {
		if (loaded) { return; }
		loaded = true;
		var first = c.ga4 || c.ads;
		if (first) {
			add('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(first));
			gtag('js', new Date());
			if (c.ga4) { gtag('config', c.ga4); }
			if (c.ads) { gtag('config', c.ads); }
		}
		if (c.gtm) {
			window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
			add('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(c.gtm));
		}
	}
	function forget(re) {
		document.cookie.split(';').forEach(function (pair) {
			var name = pair.split('=')[0].trim();
			if (!re.test(name)) { return; }
			var parts = location.hostname.split('.');
			for (var i = 0; i < parts.length - 1; i++) {
				document.cookie = name + '=; Max-Age=0; Path=/; Domain=.' + parts.slice(i).join('.');
			}
			document.cookie = name + '=; Max-Age=0; Path=/';
		});
	}
	function g(yes) { return yes ? 'granted' : 'denied'; }
	function sync(accepted) {
		var list = Array.isArray(accepted) ? accepted : [];
		var next = { stats: list.indexOf('statistics') !== -1, ads: list.indexOf('marketing') !== -1, prefs: list.indexOf('preferences') !== -1 };
		if (known && next.stats === now.stats && next.ads === now.ads && next.prefs === now.prefs) { return; }
		var before = now;
		now = next;
		known = true;
		gtag('consent', 'update', {
			analytics_storage: g(next.stats),
			ad_storage: g(next.ads), ad_user_data: g(next.ads), ad_personalization: g(next.ads),
			functionality_storage: g(next.prefs), personalization_storage: g(next.prefs)
		});
		if (before.stats && !next.stats) { forget(/^(_ga|_ga_.*|_gid|_gat.*)$/); }
		if (before.ads && !next.ads) { forget(/^(_gcl_.*|_gac_.*)$/); }
		if (next.stats || next.ads) { load(); }
		if (pending && next.ads && loaded) { pending = false; convert(); }
	}
	function convert() { gtag('event', 'conversion', { send_to: c.form }); }
	if (c.advanced) { load(); }
	document.addEventListener('estrategia-web:consent', function (e) { sync(e.detail && e.detail.accepted); });
	document.addEventListener('estrategia-web:consent-ready', function (e) { if (e.detail && e.detail.accepted) { sync(e.detail.accepted); } });
	if (window.estrategiaWebConsent && typeof window.estrategiaWebConsent.get === 'function' && window.estrategiaWebConsent.get()) {
		sync(window.estrategiaWebConsent.get());
	}
	document.addEventListener('estrategia-web:form-sent', function (e) {
		if (!c.form) { return; }
		if (now.ads && loaded) { convert(); } else if (e.detail && e.detail.returned) { pending = true; }
	});
})();
