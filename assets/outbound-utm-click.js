/*
 * Outbound UTM Tagger, JavaScript mode.
 *
 * Links in the page stay exactly as written. The parameters are added to a link
 * at the moment someone activates it, so anything that only reads the page,
 * crawlers included, sees the clean link.
 *
 * Right click is deliberately left alone, so "copy link address" copies the
 * clean link. If anything here fails, links simply go out without UTMs.
 */
(function () {
	'use strict';

	var config = window.outboundUtmConfig;
	if (!config || !config.params) {
		return;
	}

	var internal = [];
	var exclude = [];
	var skipSelector = '.noutm';
	var tidy = false;

	function stripWww(host) {
		return host.indexOf('www.') === 0 ? host.slice(4) : host;
	}

	// Lower case, punycode, and no "www.", so every comparison is like for like.
	function normalizeHost(value) {
		var host = String(value || '').trim().toLowerCase().replace(/\.+$/, '');
		if (!host) {
			return '';
		}
		try {
			host = new URL('http://' + host).hostname;
		} catch (e) {
			return '';
		}
		return stripWww(host);
	}

	function matchesDomain(host, domains) {
		for (var i = 0; i < domains.length; i++) {
			var domain = domains[i];
			if (host === domain || host.slice(-(domain.length + 1)) === '.' + domain) {
				return true;
			}
		}
		return false;
	}

	function hasUtm(url) {
		var found = false;
		url.searchParams.forEach(function (value, key) {
			if (key.toLowerCase().indexOf('utm_') === 0) {
				found = true;
			}
		});
		return found;
	}

	// Matches what the PHP side does, so both modes send the same values.
	function tidyValue(value) {
		return String(value)
			.toLowerCase()
			.replace(/\s+/g, '-')
			.replace(/-{2,}/g, '-')
			.replace(/^-+|-+$/g, '');
	}

	// Counted in characters (code points), not UTF-16 units, so an emoji counts once
	// and the cut lands in the same place as the PHP side's mb_ functions.
	function linkText(link) {
		var text = String(link.textContent || '').replace(/\s+/g, ' ').trim();
		var chars = Array.from(text);
		if (chars.length > 60) {
			var cut = chars.slice(0, 60);
			var gap = cut.lastIndexOf(' ');
			text = (gap > 20 ? cut.slice(0, gap) : cut).join('');
		}
		return tidy ? tidyValue(text) : text;
	}

	// Matches PHP's rawurlencode(): encodeURIComponent() leaves ! ' ( ) * as they are,
	// so those are encoded too. A slash stays readable, as on the PHP side.
	function encodeValue(value) {
		return encodeURIComponent(value)
			.replace(/[!'()*]/g, function (c) {
				return '%' + c.charCodeAt(0).toString(16).toUpperCase();
			})
			.replace(/%2F/g, '/');
	}

	function buildQuery(link) {
		var text = null;
		var parts = [];

		Object.keys(config.params).forEach(function (key) {
			var value = String(config.params[key] || '');
			if (!value) {
				return;
			}
			if (value.indexOf('{link_text}') !== -1) {
				if (null === text) {
					text = linkText(link);
				}
				value = value.split('{link_text}').join(text);
				if (tidy) {
					value = tidyValue(value);
				}
			}
			if (value) {
				parts.push(encodeValue(key) + '=' + encodeValue(value));
			}
		});

		return parts.join('&');
	}

	function tagLink(link) {
		var raw = link.getAttribute('href');
		if (!raw) {
			return;
		}
		raw = raw.trim();
		if (!raw || raw.charAt(0) === '#' || link.closest(skipSelector)) {
			return;
		}

		var url;
		try {
			url = new URL(raw, document.baseURI);
		} catch (e) {
			return;
		}
		if (url.protocol !== 'http:' && url.protocol !== 'https:') {
			return;
		}

		var host = normalizeHost(url.hostname);
		if (!host || matchesDomain(host, internal) || matchesDomain(host, exclude) || hasUtm(url)) {
			return;
		}

		var query = buildQuery(link);
		if (!query) {
			return;
		}

		// The UTMs go before any #fragment and the rest of the link is left as written.
		var hashAt = raw.indexOf('#');
		var base = hashAt === -1 ? raw : raw.slice(0, hashAt);
		var hash = hashAt === -1 ? '' : raw.slice(hashAt);
		var separator = base.indexOf('?') === -1 ? '?' : (/[?&]$/.test(base) ? '' : '&');

		link.setAttribute('href', base + separator + query + hash);
	}

	function onActivate(event) {
		var target = event.target;
		if (target && target.closest) {
			var link = target.closest('a[href]');
			if (link) {
				tagLink(link);
			}
		}
	}

	function init() {
		internal = (config.internal || []).concat([window.location.hostname]).map(normalizeHost).filter(Boolean);
		exclude = (config.exclude || []).map(normalizeHost).filter(Boolean);
		if (config.skipClass) {
			skipSelector = '.' + config.skipClass;
		}
		tidy = !!config.tidy;

		// Left click, keyboard, and touch all raise click; middle click raises auxclick.
		// Right click raises neither, which keeps copied links clean.
		document.addEventListener('click', onActivate, true);
		document.addEventListener('auxclick', onActivate, true);
	}

	init();
})();
