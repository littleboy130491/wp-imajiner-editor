(function () {
	'use strict';
	var __ = window.wp.i18n.__;
	var mobile = window.matchMedia('(max-width: 767px)');

	document.querySelectorAll('[data-imajiner-navigation]').forEach(function (nav, navIndex) {
		var toggle = document.querySelector('[aria-controls="' + nav.id + '"]');
		if (!toggle) return;
		var disclosures = [];
		function closeSubmenus() {
			disclosures.forEach(function (item) { item.set(false); });
		}
		function setMenu(open, focus) {
			toggle.setAttribute('aria-expanded', String(open));
			nav.hidden = mobile.matches && !open;
			if (!open) closeSubmenus();
			if (focus) toggle.focus();
		}

		nav.querySelectorAll('.menu-item-has-children').forEach(function (li, index) {
			var link = li.querySelector('a');
			var submenu = Array.prototype.find.call(li.children, function (child) { return child.classList.contains('sub-menu'); });
			if (!link || !submenu) return;
			submenu.id = submenu.id || 'imajiner-submenu-' + navIndex + '-' + index;
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'site-nav__submenu-toggle';
			button.setAttribute('aria-controls', submenu.id);
			/* translators: %s is the parent navigation link's text. */
			button.setAttribute('aria-label', __('Toggle submenu for %s', 'imajiner-editor').replace('%s', link.textContent.trim()));
			button.textContent = '▾';
			li.insertBefore(button, submenu);
			function set(open) {
				button.setAttribute('aria-expanded', String(open));
				submenu.hidden = !open;
				if (!open) disclosures.forEach(function (item) { if (submenu.contains(item.button)) item.set(false); });
			}
			set(false);
			disclosures.push({ set: set, button: button, submenu: submenu });
			button.addEventListener('click', function () { set(button.getAttribute('aria-expanded') !== 'true'); });
			li.addEventListener('mouseenter', function () { if (!mobile.matches) set(true); });
			li.addEventListener('mouseleave', function () { if (!li.contains(document.activeElement)) set(false); });
			li.addEventListener('focusout', function () {
				window.setTimeout(function () { if (!li.contains(document.activeElement)) set(false); }, 0);
			});
			li.addEventListener('keydown', function (event) {
				if (event.key === 'ArrowDown' && (event.target === button || event.target === link)) {
					event.preventDefault();
					set(true);
					var first = submenu.querySelector('a, button');
					if (first) first.focus();
				} else if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
					event.preventDefault();
					event.stopPropagation();
					set(false);
					button.focus();
				}
			});
		});

		toggle.addEventListener('click', function () { setMenu(toggle.getAttribute('aria-expanded') !== 'true'); });
		nav.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && mobile.matches) { event.preventDefault(); setMenu(false, true); }
		});
		toggle.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') setMenu(false, true);
		});
		document.addEventListener('click', function (event) {
			if (!nav.contains(event.target) && !toggle.contains(event.target)) setMenu(false);
		});
		nav.addEventListener('focusout', function () {
			window.setTimeout(function () {
				if (!nav.contains(document.activeElement) && document.activeElement !== toggle) setMenu(false);
			}, 0);
		});
		function resize() {
			var active = document.activeElement;
			var focus = null;
			if (mobile.matches && nav.contains(active)) focus = toggle;
			else if (!mobile.matches && active === toggle) focus = nav.querySelector('a, button') || nav;
			else if (!mobile.matches) {
				var parent = disclosures.find(function (item) { return item.submenu.contains(active); });
				if (parent) focus = parent.button;
			}
			toggle.hidden = !mobile.matches;
			setMenu(false);
			if (focus) {
				if (focus === nav) nav.setAttribute('tabindex', '-1');
				focus.focus();
			}
		}
		if (mobile.addEventListener) mobile.addEventListener('change', resize);
		else mobile.addListener(resize);
		nav.classList.add('site-nav--enhanced');
		resize();
	});
}());
