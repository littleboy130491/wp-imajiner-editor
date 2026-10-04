/* Dependency-free DOM contract tests; run with node, not a browser or build step. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../../../themes/imajiner/assets/js/navigation.js'), 'utf8');

function fixture(isMobile, includeNav = true, nested = false) {
	const timers = [];
	const doc = { activeElement: null, listeners: {}, querySelectorAll: () => includeNav ? [nav] : [], querySelector: () => toggle };
	class Element {
		constructor(tag, classes = []) {
			this.tag = tag;
			this.children = [];
			this.attributes = {};
			this.listeners = {};
			this.hidden = false;
			this.textContent = '';
			this.classList = { contains: name => classes.includes(name), add: name => classes.push(name) };
		}
		append(child) { this.children.push(child); child.parent = this; return child; }
		insertBefore(child, before) { this.children.splice(this.children.indexOf(before), 0, child); child.parent = this; }
		setAttribute(name, value) { this.attributes[name] = value; }
		getAttribute(name) { return this.attributes[name]; }
		addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
		contains(target) { return this === target || this.children.some(child => child.contains(target)); }
		querySelectorAll(selector) {
			const found = [];
			this.children.forEach(child => {
				if (selector === '.menu-item-has-children' ? child.classList.contains('menu-item-has-children') : selector.split(', ').includes(child.tag)) found.push(child);
				found.push(...child.querySelectorAll(selector));
			});
			return found;
		}
		querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
		focus() { doc.activeElement = this; }
	}
	const toggle = new Element('button');
	toggle.hidden = true;
	toggle.setAttribute('aria-expanded', 'false');
	toggle.setAttribute('aria-controls', 'primary');
	const nav = new Element('nav');
	nav.id = 'primary';
	const list = nav.append(new Element('ul'));
	const li = list.append(new Element('li', ['menu-item-has-children']));
	const link = li.append(new Element('a'));
	link.textContent = 'Parent page';
	const submenu = li.append(new Element('ul', ['sub-menu']));
	const childLink = submenu.append(new Element('a'));
	let nestedLi, nestedLink, nestedSubmenu, grandchildLink;
	if (nested) {
		nestedLi = submenu.append(new Element('li', ['menu-item-has-children']));
		nestedLink = nestedLi.append(new Element('a'));
		nestedLink.textContent = 'Nested page';
		nestedSubmenu = nestedLi.append(new Element('ul', ['sub-menu']));
		grandchildLink = nestedSubmenu.append(new Element('a'));
	}
	const outside = new Element('a');
	doc.createElement = tag => new Element(tag);
	doc.addEventListener = (event, callback) => { (doc.listeners[event] ||= []).push(callback); };
	const media = { matches: isMobile, addEventListener: (name, callback) => { media.change = callback; } };
	let translations = 0;
	const context = { document: doc, window: { matchMedia: () => media, setTimeout: callback => { timers.push(callback); }, wp: { i18n: { __: text => { translations++; return text; } } } } };
	function trigger(target, type, props = {}) {
		const event = { target, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...props };
		(target.listeners[type] || []).forEach(callback => callback(event));
		return event;
	}
	return { doc, toggle, nav, li, link, submenu, childLink, nestedLi, nestedLink, nestedSubmenu, grandchildLink, outside, media, trigger,
		load: () => vm.runInNewContext(source, context),
		flush: () => { while (timers.length) timers.shift()(); },
		translations: () => translations };
}

let checks = 0;
function test(name, callback) { callback(); checks++; console.log('PASS ' + name); }

test('No-JS markup leaves menu/submenus available and inert toggle hidden', () => {
	const f = fixture(true);
	assert.equal(f.nav.hidden, false);
	assert.equal(f.submenu.hidden, false);
	assert.equal(f.toggle.hidden, true);
});
test('Mobile toggle synchronizes visibility/aria and Escape restores focus', () => {
	const f = fixture(true); f.load();
	assert.equal(f.nav.hidden, true); assert.equal(f.toggle.hidden, false);
	f.toggle.focus(); f.trigger(f.toggle, 'click');
	assert.equal(f.nav.hidden, false); assert.equal(f.toggle.getAttribute('aria-expanded'), 'true');
	f.link.focus();
	assert.equal(f.trigger(f.nav, 'keydown', { key: 'Escape', target: f.link }).prevented, true);
	assert.equal(f.nav.hidden, true); assert.equal(f.doc.activeElement, f.toggle);
});
test('Keyboard submenu disclosure preserves parent links and Escape returns focus', () => {
	const f = fixture(true); f.load(); f.trigger(f.toggle, 'click');
	const button = f.li.children[1];
	assert.equal(f.li.children[0], f.link);
	assert.equal(button.getAttribute('aria-controls'), f.submenu.id);
	assert.equal(button.getAttribute('aria-label'), 'Toggle submenu for Parent page');
	assert.equal(f.translations(), 1);
	assert.equal(f.submenu.hidden, true);
	f.trigger(f.li, 'keydown', { key: 'ArrowDown', target: f.link });
	assert.equal(f.submenu.hidden, false); assert.equal(f.doc.activeElement, f.childLink);
	const escape = f.trigger(f.li, 'keydown', { key: 'Escape', target: f.childLink });
	assert.equal(escape.stopped, true); assert.equal(f.submenu.hidden, true);
	assert.equal(f.doc.activeElement, button); assert.equal(f.nav.hidden, false);
	f.trigger(button, 'click'); assert.equal(f.submenu.hidden, false);
	f.trigger(button, 'click'); assert.equal(f.submenu.hidden, true);
});
test('Responsive changes reset disclosures without hiding desktop navigation', () => {
	const f = fixture(true); f.load(); f.trigger(f.toggle, 'click');
	f.trigger(f.li.children[1], 'click');
	f.media.matches = false; f.media.change();
	assert.equal(f.nav.hidden, false); assert.equal(f.toggle.hidden, true); assert.equal(f.submenu.hidden, true);
	f.trigger(f.li, 'mouseenter'); assert.equal(f.submenu.hidden, false);
	f.trigger(f.li, 'mouseleave'); assert.equal(f.submenu.hidden, true);
	f.media.matches = true; f.media.change();
	assert.equal(f.nav.hidden, true); assert.equal(f.toggle.hidden, false);
});
test('Responsive collapse moves focus from hidden navigation to the mobile toggle', () => {
	const f = fixture(false); f.load();
	f.link.focus();
	f.media.matches = true; f.media.change();
	assert.equal(f.nav.hidden, true); assert.equal(f.doc.activeElement, f.toggle);
	f.trigger(f.toggle, 'click'); f.trigger(f.li.children[1], 'click'); f.childLink.focus();
	f.media.change();
	assert.equal(f.nav.hidden, true); assert.equal(f.doc.activeElement, f.toggle);
	f.outside.focus(); f.media.change();
	assert.equal(f.doc.activeElement, f.outside);
});
test('Desktop transition moves focus off the hidden toggle, including an empty menu', () => {
	const f = fixture(true); f.load(); f.toggle.focus();
	f.media.matches = false; f.media.change();
	assert.equal(f.toggle.hidden, true); assert.equal(f.doc.activeElement, f.link);
	const empty = fixture(true); empty.nav.children = []; empty.load(); empty.toggle.focus();
	empty.media.matches = false; empty.media.change();
	assert.equal(empty.doc.activeElement, empty.nav); assert.equal(empty.nav.getAttribute('tabindex'), '-1');
	assert.equal(empty.nav.hidden, false);
});
test('Closing a parent submenu resets nested disclosures and keeps resize focus visible', () => {
	const f = fixture(false, true, true); f.load();
	const parentButton = f.li.children[1], nestedButton = f.nestedLi.children[1];
	f.trigger(parentButton, 'click'); f.trigger(nestedButton, 'click');
	assert.equal(f.nestedSubmenu.hidden, false);
	f.trigger(parentButton, 'click');
	assert.equal(f.submenu.hidden, true); assert.equal(f.nestedSubmenu.hidden, true);
	assert.equal(nestedButton.getAttribute('aria-expanded'), 'false');
	f.trigger(parentButton, 'click'); f.trigger(nestedButton, 'click'); f.grandchildLink.focus();
	f.media.change();
	assert.equal(f.doc.activeElement, parentButton);
	assert.equal(f.submenu.hidden, true); assert.equal(f.nestedSubmenu.hidden, true);
	assert.equal(f.nav.hidden, false);
});
test('Outside click and tab-away close mobile menu without stealing focus', () => {
	const f = fixture(true); f.load(); f.trigger(f.toggle, 'click');
	f.outside.focus(); f.trigger(f.doc, 'click', { target: f.outside });
	assert.equal(f.nav.hidden, true); assert.equal(f.doc.activeElement, f.outside);
	f.trigger(f.toggle, 'click'); f.trigger(f.nav, 'focusout'); f.flush();
	assert.equal(f.nav.hidden, true); assert.equal(f.doc.activeElement, f.outside);
});
test('Custom headers that do not opt in are left alone', () => {
	const f = fixture(true, false); f.load();
	assert.equal(f.nav.hidden, false); assert.equal(f.submenu.hidden, false); assert.equal(f.toggle.hidden, true);
});
console.log(checks + ' navigation contract tests passed. Browser layout/native keyboard behavior not exercised.');
