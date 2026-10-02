'use strict';
const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { runInNewContext } = require('node:vm');
const source = readFileSync(resolve(__dirname, '../../../themes/imajiner/assets/js/navigation.js'), 'utf8');

class Element {
	constructor(tag, owner, classes = '') {
		this.tag = tag;
		this.owner = owner;
		this.children = [];
		this.attributes = {};
		this.events = {};
		this.hidden = false;
		this.textContent = '';
		this.className = classes;
		this.classList = { add: (value) => { this.className += ' ' + value; } };
	}
	get id() { return this.attributes.id; }
	set id(value) { this.attributes.id = value; }
	setAttribute(key, value) { this.attributes[key] = value; }
	getAttribute(key) { return this.attributes[key] ?? null; }
	append(child) { child.parentElement = this; this.children.push(child); return child; }
	contains(node) { return this === node || this.children.some((child) => child.contains(node)); }
	querySelectorAll(selector) {
		return this.children.flatMap((child) => {
			const matches = selector.startsWith('.') ? child.className.split(' ').includes(selector.slice(1)) : child.tag === selector;
			return [...(matches ? [child] : []), ...child.querySelectorAll(selector)];
		});
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	insertAdjacentElement(position, element) {
		assert.equal(position, 'afterend');
		element.parentElement = this.parentElement;
		this.parentElement.children.splice(this.parentElement.children.indexOf(this) + 1, 0, element);
	}
	addEventListener(type, callback) { (this.events[type] ||= []).push(callback); }
	fire(type, key, target = this) {
		const event = { key, target, preventDefault() { this.prevented = true; }, stopPropagation() {} };
		(this.events[type] || []).forEach((callback) => callback(event));
		return event;
	}
	focus() { this.owner.activeElement = this; }
}

function fixture(isMobile = true, optIn = true) {
	const document = { activeElement: null, events: {}, addEventListener: Element.prototype.addEventListener, fire: Element.prototype.fire };
	const node = (tag, classes = '') => new Element(tag, document, classes);
	const nav = node('nav', 'site-nav');
	const toggle = nav.append(node('button', 'site-nav__toggle'));
	toggle.hidden = true;
	toggle.setAttribute('aria-controls', 'primary-menu');
	toggle.setAttribute('aria-expanded', 'false');
	const menu = nav.append(node('ul', 'site-nav__menu'));
	menu.id = 'primary-menu';
	const parent = menu.append(node('li'));
	const link = parent.append(node('a'));
	link.textContent = 'Parent';
	const submenu = parent.append(node('ul', 'sub-menu'));
	const first = submenu.append(node('li')).append(node('a'));
	const last = submenu.append(node('li')).append(node('a'));
	document.querySelectorAll = () => optIn ? [nav] : [];
	document.getElementById = (id) => id === menu.id ? menu : null;
	document.createElement = (tag) => node(tag);
	let resize;
	const media = { matches: isMobile, addEventListener(type, callback) { assert.equal(type, 'change'); resize = callback; } };
	const window = { wp: { i18n: { __: (value, domain) => { assert.equal(domain, 'imajiner-editor'); return value; } } }, matchMedia: () => media, requestAnimationFrame: (callback) => callback() };
	runInNewContext(source, { window, document });
	return { nav, toggle, menu, link, submenu, first, last, document, setMobile(value) { media.matches = value; resize(); }, submenuToggle: parent.querySelector('button') };
}

test('mobile menu has synchronized hidden and aria states and Escape returns focus', () => {
	const f = fixture();
	assert.equal(f.toggle.hidden, false);
	assert.equal(f.menu.hidden, true);
	f.toggle.fire('click');
	assert.equal(f.toggle.getAttribute('aria-expanded'), 'true');
	assert.equal(f.menu.hidden, false);
	f.link.focus();
	assert.equal(f.nav.fire('keydown', 'Escape', f.link).prevented, true);
	assert.equal(f.menu.hidden, true);
	assert.equal(f.toggle.getAttribute('aria-expanded'), 'false');
	assert.equal(f.document.activeElement, f.toggle);
});

test('submenu buttons support click, ArrowDown, ArrowUp and Escape focus restoration', () => {
	const f = fixture(false);
	assert.equal(f.submenu.hidden, true);
	assert.equal(f.submenuToggle.getAttribute('aria-controls'), f.submenu.id);
	f.submenuToggle.fire('click');
	assert.equal(f.submenu.hidden, false);
	f.submenuToggle.fire('click');
	assert.equal(f.submenu.hidden, true);
	f.submenuToggle.fire('keydown', 'ArrowDown');
	assert.equal(f.document.activeElement, f.first);
	assert.equal(f.submenuToggle.getAttribute('aria-expanded'), 'true');
	f.submenuToggle.fire('keydown', 'ArrowUp');
	assert.equal(f.document.activeElement, f.last);
	f.nav.fire('keydown', 'Escape', f.last);
	assert.equal(f.submenu.hidden, true);
	assert.equal(f.document.activeElement, f.submenuToggle);
});

test('outside click and focus leaving navigation collapse the mobile menu', () => {
	const f = fixture();
	f.toggle.fire('click');
	f.document.fire('click', undefined, {});
	assert.equal(f.menu.hidden, true);
	f.toggle.fire('click');
	f.document.activeElement = {};
	f.nav.fire('focusout');
	assert.equal(f.menu.hidden, true);
});

test('breakpoint transitions preserve access and move focus away from hidden controls', () => {
	const f = fixture(false);
	assert.equal(f.toggle.hidden, true);
	assert.equal(f.menu.hidden, false);
	f.link.focus();
	f.setMobile(true);
	assert.equal(f.menu.hidden, true);
	assert.equal(f.document.activeElement, f.toggle);
	f.setMobile(false);
	assert.equal(f.menu.hidden, false);
	assert.equal(f.toggle.hidden, true);
	assert.equal(f.document.activeElement, f.link);
});

test('custom headers without opt-in retain visible menus and receive no controls', () => {
	const f = fixture(true, false);
	assert.equal(f.menu.hidden, false);
	assert.equal(f.submenu.hidden, false);
	assert.equal(f.toggle.hidden, true);
	assert.equal(f.submenuToggle, null);
});
