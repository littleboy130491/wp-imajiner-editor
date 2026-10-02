/* global imajinerDesignSystem */
(function () {
	'use strict';
	const __ = window.wp.i18n.__;
	const config = imajinerDesignSystem;
	const root = document.getElementById('imj-design-system');
	if (!root) return;
	const el = id => document.getElementById('imj-design-' + id);
	let attachment = 0;
	let proposal = null;
	let busy = false;
	let sequence = 0;
	let media;

	function status(message, error) {
		el('status').textContent = message;
		el('status').className = error ? 'notice notice-error' : 'notice notice-info';
	}

	function setBusy(value) {
		busy = value;
		['extract', 'media', 'remove', 'discard'].forEach(id => { el(id).disabled = value; });
		el('save').disabled = value || !proposal || !el('confirm').checked;
		el('revisions').querySelectorAll('button').forEach(button => { button.disabled = value; });
		el('form').setAttribute('aria-busy', String(value));
	}

	async function request(action, body, endpoint) {
		const response = await fetch(endpoint || config.rest + action, {
			method: body ? 'POST' : 'GET', credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		});
		const data = await response.json();
		if (!response.ok) {
			const warnings = data.data && data.data.warnings;
			throw new Error((data.message || __('Request failed.', 'imajiner-editor')) + (warnings && warnings.length ? '\n' + warnings.join('\n') : ''));
		}
		return data;
	}

	function discard() {
		sequence++;
		proposal = null;
		el('review').hidden = true;
		el('confirm').checked = false;
		el('save').disabled = true;
	}

	function element(tag, text, className) {
		const node = document.createElement(tag);
		if (text !== undefined) node.textContent = text;
		if (className) node.className = className;
		return node;
	}

	function resolve(tokens, name, seen) {
		seen = seen || [];
		if (seen.includes(name)) return '';
		const value = tokens[name] || '';
		const alias = /^var\((--imj-[a-z0-9-]+)\)$/.exec(value);
		return alias ? resolve(tokens, alias[1], seen.concat(name)) : value;
	}

	function preview(target, tokens) {
		target.replaceChildren();
		const colors = element('div', undefined, 'imj-design-swatches');
		Object.keys(tokens).filter(name => name.startsWith('--imj-color-')).forEach(name => {
			const card = element('div', undefined, 'imj-design-swatch');
			const chip = element('span', undefined, 'imj-design-chip');
			chip.style.backgroundColor = resolve(tokens, name);
			card.append(chip, element('code', name), element('span', tokens[name]));
			colors.append(card);
		});
		const type = element('div', undefined, 'imj-design-type');
		const heading = element('h4', __('Heading sample', 'imajiner-editor'));
		const body = element('p', __('The quick brown fox jumps over the lazy dog.', 'imajiner-editor'));
		heading.style.fontFamily = resolve(tokens, '--imj-font-heading');
		body.style.fontFamily = resolve(tokens, '--imj-font-body');
		const fontInfo = element('code', (tokens['--imj-font-heading'] || '') + '\n' + (tokens['--imj-font-body'] || ''));
		type.append(heading, body, fontInfo);
		target.append(colors, type);
	}

	function review(data) {
		proposal = data.proposal;
		el('summary').textContent = data.summary;
		el('warnings').replaceChildren();
		(data.warnings || []).forEach(warning => el('warnings').append(element('li', warning)));
		preview(el('before'), data.before.tokens);
		preview(el('after'), data.after.tokens);
		const names = [...new Set(Object.keys(data.before.tokens).concat(Object.keys(data.after.tokens)))].sort();
		const diff = [];
		names.forEach(name => {
			if (data.before.tokens[name] === data.after.tokens[name]) return;
			if (name in data.before.tokens) diff.push('- ' + name + ': ' + data.before.tokens[name] + ';');
			if (name in data.after.tokens) diff.push('+ ' + name + ': ' + data.after.tokens[name] + ';');
		});
		el('diff').textContent = diff.join('\n') || __('No token value changes.', 'imajiner-editor');
		el('css').textContent = data.after.css;
		el('confirm').checked = false;
		el('save').disabled = true;
		el('review').hidden = false;
		status(__('Review the proposal. No tokens have been saved.', 'imajiner-editor'));
	}

	async function refresh() {
		const data = await request('current');
		preview(el('current'), data.tokens);
		const revisions = await request('revisions');
		el('revisions').replaceChildren();
		if (!revisions.length) el('revisions').append(element('p', __('No saved revisions yet.', 'imajiner-editor')));
		revisions.forEach(revision => {
			const row = element('p');
			const button = element('button', __('Review restore', 'imajiner-editor'), 'button');
			button.type = 'button';
			button.disabled = busy;
			button.addEventListener('click', async () => {
				if (busy) return;
				discard();
				setBusy(true);
				try { review(await request('restore', { revision: revision.id })); }
				catch (error) { status(error.message, true); }
				finally { setBusy(false); }
			});
			row.append(element('span', revision.date + ' '), button);
			el('revisions').append(row);
		});
	}

	async function awaitJob(data, run) {
		if (!data.job || !data.statusUrl) return data;
		const endpoint = new URL(data.statusUrl, config.rest);
		const allowed = new URL(config.rest);
		if (endpoint.origin !== allowed.origin || !endpoint.pathname.startsWith(allowed.pathname.replace(/design-system\/$/, ''))) {
			throw new Error(__('Invalid job status URL.', 'imajiner-editor'));
		}
		for (let attempt = 0; attempt < 180; attempt++) {
			if (run !== sequence) throw new Error(__('Extraction was discarded.', 'imajiner-editor'));
			const job = await request('', null, endpoint.href);
			if (job.status === 'complete') return job.result;
			if (job.status === 'failed' || job.status === 'cancelled') throw new Error(job.message || __('Extraction failed.', 'imajiner-editor'));
			status(job.message || __('Extracting design tokens…', 'imajiner-editor'));
			await new Promise(resolveWait => setTimeout(resolveWait, 2000));
		}
		throw new Error(__('Extraction is still running. Reopen the saved job status before trying again.', 'imajiner-editor'));
	}

	el('media').addEventListener('click', () => {
		if (!media) {
			media = window.wp.media({ title: __('Choose a design screenshot', 'imajiner-editor'), button: { text: __('Use screenshot', 'imajiner-editor') }, library: { type: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] }, multiple: false });
			media.on('select', () => {
				const image = media.state().get('selection').first().toJSON();
				if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(image.mime) || image.filesizeInBytes > config.maxImageBytes) {
					status(__('Choose a JPEG, PNG, WebP or GIF no larger than 5 MB.', 'imajiner-editor'), true);
					return;
				}
				discard();
				attachment = image.id;
				el('image').src = image.url;
				el('image').hidden = false;
				el('image-name').textContent = image.filename || image.title;
				el('remove').hidden = false;
			});
		}
		media.open();
	});
	el('remove').addEventListener('click', () => {
		discard(); attachment = 0;
		el('image').hidden = true; el('image').removeAttribute('src');
		el('image-name').textContent = ''; el('remove').hidden = true;
	});
	el('form').addEventListener('submit', async event => {
		event.preventDefault();
		if (busy) return;
		discard();
		const run = sequence;
		setBusy(true);
		status(__('Extracting design tokens…', 'imajiner-editor'));
		try {
			const data = await request('extract', { prompt: el('prompt').value, url: el('url').value, attachment });
			const result = await awaitJob(data, run);
			if (run === sequence) review(result);
		} catch (error) { status(error.message, true); }
		finally { setBusy(false); }
	});
	el('confirm').addEventListener('change', () => { el('save').disabled = busy || !proposal || !el('confirm').checked; });
	el('discard').addEventListener('click', () => { discard(); status(__('Proposal discarded. No tokens were saved.', 'imajiner-editor')); });
	el('save').addEventListener('click', async () => {
		if (busy || !proposal || !el('confirm').checked) return;
		setBusy(true);
		try {
			await request('accept', { proposal, confirmed: true });
			discard();
			status(__('Design tokens saved to the child theme.', 'imajiner-editor'));
			await refresh();
		} catch (error) { status(error.message, true); }
		finally { setBusy(false); }
	});
	refresh().catch(error => status(error.message, true));
})();
