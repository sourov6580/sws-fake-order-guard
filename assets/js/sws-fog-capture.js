/* SWS Fake Order Guard - Incomplete Order Capture (checkout). */
(function () {
	'use strict';
	var C = window.swsFogIC;
	if (!C || (!window.fetch && !navigator.sendBeacon) || !window.FormData) {
		return;
	}

	function rnd() {
		var c = window.crypto || window.msCrypto, s = '', i, a;
		if (c && c.getRandomValues) {
			a = new Uint8Array(16);
			c.getRandomValues(a);
			for (i = 0; i < a.length; i++) { s += ('0' + a[i].toString(16)).slice(-2); }
			return s;
		}
		for (i = 0; i < 32; i++) { s += Math.floor(Math.random() * 16).toString(16); }
		return s;
	}

	var m = document.cookie.match(/(?:^|;\s*)sws_fog_ic=([a-f0-9]{16,64})/);
	var KEY = m ? m[1] : rnd();
	document.cookie = 'sws_fog_ic=' + KEY + ';path=/;max-age=2592000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');

	function normPhone(v) {
		var d = String(v || '').replace(/[০-৯]/g, function (ch) {
			return String(ch.charCodeAt(0) - 0x09E6);
		}).replace(/\D/g, '');
		if (d.length === 13 && d.indexOf('880') === 0) { d = d.slice(2); }
		if (d.length === 10 && d.charAt(0) === '1') { d = '0' + d; }
		return d;
	}

	function collect() {
		var o = { phone: '', first: '', last: '', full: '', addr: [], email: '' };
		var els = document.querySelectorAll('input, textarea, select'), i, el, id, v, d;
		for (i = 0; i < els.length; i++) {
			el = els[i];
			if (/^(hidden|password|checkbox|radio|submit|button|file)$/i.test(el.type || '')) { continue; }
			id = ' ' + (el.name || '') + ' ' + (el.id || '') + ' ';
			if (el.tagName === 'SELECT') {
				v = el.selectedIndex > 0 ? (el.options[el.selectedIndex].text || '').trim() : '';
			} else {
				v = (el.value || '').trim();
			}
			if (!v) { continue; }
			if (el.type === 'email' || /email/i.test(id)) {
				if (!o.email) { o.email = v; }
				continue;
			}
			if (el.type === 'tel' || /phone|mobile/i.test(id)) {
				d = normPhone(v);
				if (!o.phone && /^01[3-9]\d{8}$/.test(d)) { o.phone = d; }
				continue;
			}
			if (/company|coupon|user|search|captcha|card|country|postcode|zip|note/i.test(id)) { continue; }
			if (/first[_-]?name/i.test(id)) { o.first = o.first || v; continue; }
			if (/last[_-]?name/i.test(id)) { o.last = o.last || v; continue; }
			if (/address|city|state|upazila|thana|district|area/i.test(id)) {
				if (o.addr.indexOf(v) < 0 && o.addr.length < 5) { o.addr.push(v); }
				continue;
			}
			if (/[\s_\-\[](full[_-]?)?name\]?\s/i.test(id)) { o.full = o.full || v; }
		}
		return {
			phone: o.phone,
			name: (o.full || (o.first + ' ' + o.last)).trim(),
			email: o.email,
			address: o.addr.join(', ')
		};
	}

	var last = '', timer = null, pausedUntil = 0;

	function send(beacon) {
		if (Date.now() < pausedUntil) { return; }
		var d = collect();
		if (!d.phone) { return; }
		var sig = JSON.stringify(d);
		if (sig === last) { return; }
		last = sig;
		var f = new FormData();
		f.append('action', 'sws_fog_capture');
		f.append('nonce', C.nonce);
		f.append('key', KEY);
		f.append('phone', d.phone);
		f.append('name', d.name);
		f.append('email', d.email);
		f.append('address', d.address);
		f.append('src', document.referrer || location.href);
		if (beacon && navigator.sendBeacon) {
			navigator.sendBeacon(C.ajax, f);
		} else if (window.fetch) {
			fetch(C.ajax, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true }).catch(function () {});
		}
	}

	function notice() {
		if (!C.notice || document.getElementById('sws-fog-ic-note')) { return; }
		var el = document.querySelector('input[type="tel"], input[name*="phone"], input[id*="phone"]');
		if (!el) { return; }
		var p = document.createElement('small');
		p.id = 'sws-fog-ic-note';
		p.textContent = C.notice;
		p.style.cssText = 'display:block;margin-top:4px;font-size:12px;opacity:.7;line-height:1.4';
		var wrap = el.closest ? el.closest('.form-row, .wc-block-components-text-input') : null;
		if (wrap) { wrap.appendChild(p); } else { el.insertAdjacentElement('afterend', p); }
	}

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(function () { send(false); }, 1500);
		notice();
	}

	// Stop sending while the order is being placed, so a finished order doesn't leave a new row behind.
	function placing() {
		clearTimeout(timer);
		pausedUntil = Date.now() + 15000;
	}

	document.addEventListener('input', schedule, true);
	document.addEventListener('change', schedule, true);
	document.addEventListener('submit', function (e) {
		if (e.target && e.target.matches && e.target.matches('form.checkout, form.woocommerce-checkout')) { placing(); }
	}, true);
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('#place_order, .wc-block-components-checkout-place-order-button') : null;
		if (b) { placing(); }
	}, true);
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') { clearTimeout(timer); send(true); }
	});
	window.addEventListener('pagehide', function () { clearTimeout(timer); send(true); });

	notice();
	setTimeout(notice, 1500);
	setTimeout(notice, 4000);
})();
