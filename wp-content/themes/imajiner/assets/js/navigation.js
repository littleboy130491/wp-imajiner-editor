(function () {
	'use strict';
	var __ = window.wp.i18n.__;
	document.querySelectorAll('[data-imajiner-navigation]').forEach(function (nav) {
		var toggle = nav.querySelector('.site-nav__toggle');
		var menu = toggle && document.getElementById(toggle.getAttribute('aria-controls'));
		if (!menu || !nav.contains(menu)) return;
		var mobile = window.matchMedia('(max-width: 767px)');
		var submenus = [];
		nav.classList.add('is-enhanced');

		function expandSubmenu(item, expanded) {
			item.button.setAttribute('aria-expanded', String(expanded));
			item.menu.hidden = !expanded;
		}
		function closeSubmenus() {
			submenus.forEach(function (item) { expandSubmenu(item, false); });
		}
		function expandMenu(expanded, restoreFocus) {
			toggle.setAttribute('aria-expanded', String(expanded));
			menu.hidden = mobile.matches && !expanded;
			if (!expanded) closeSubmenus();
			if (restoreFocus) toggle.focus();
		}
		menu.querySelectorAll('.sub-menu').forEach(function (submenu, index) {
			var link = submenu.parentElement.querySelector('a');
			if (!link) return;
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'site-nav__submenu-toggle';
			button.textContent = '▾';
			button.setAttribute('aria-label', __('Toggle submenu', 'imajiner-editor') + ': ' + link.textContent.trim());
			submenu.id = menu.id + '-submenu-' + index;
			button.setAttribute('aria-controls', submenu.id);
			link.insertAdjacentElement('afterend', button);
			var item = { button: button, menu: submenu };
			submenus.push(item);
			expandSubmenu(item, false);
			button.addEventListener('click', function () {
				expandSubmenu(item, button.getAttribute('aria-expanded') !== 'true');
			});
			button.addEventListener('keydown', function (event) {
				if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
				event.preventDefault();
				expandSubmenu(item, true);
				var links = submenu.querySelectorAll('a');
				if (links.length) links[event.key === 'ArrowUp' ? links.length - 1 : 0].focus();
			});
		});
		toggle.addEventListener('click', function () {
			expandMenu(toggle.getAttribute('aria-expanded') !== 'true', false);
		});
		nav.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') return;
			var item = submenus.find(function (entry) {
				return entry.button.getAttribute('aria-expanded') === 'true' &&
					(entry.menu.contains(event.target) || entry.button === event.target);
			});
			if (item) {
				expandSubmenu(item, false);
				item.button.focus();
			} else if (mobile.matches && toggle.getAttribute('aria-expanded') === 'true') {
				expandMenu(false, true);
			} else return;
			event.preventDefault();
			event.stopPropagation();
		});
		nav.addEventListener('focusout', function () {
			window.requestAnimationFrame(function () {
				if (!nav.contains(document.activeElement)) expandMenu(false, false);
			});
		});
		document.addEventListener('click', function (event) {
			if (!nav.contains(event.target)) expandMenu(false, false);
		});
		function resize() {
			var focused = menu.contains(document.activeElement);
			var toggleFocused = document.activeElement === toggle;
			toggle.hidden = !mobile.matches;
			expandMenu(false, mobile.matches && focused);
			if (!mobile.matches && toggleFocused) {
				var firstLink = menu.querySelector('a');
				if (firstLink) firstLink.focus();
			}
		}
		if (mobile.addEventListener) mobile.addEventListener('change', resize);
		else mobile.addListener(resize);
		resize();
	});
}());
