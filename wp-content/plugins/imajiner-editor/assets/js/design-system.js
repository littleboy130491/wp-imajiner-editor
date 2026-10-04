/* global wp, imajinerDesignSystem */
(function () {
	'use strict';
	const { __ } = wp.i18n;
	const root = document.getElementById('imj-design-system');
	if (!root) return;
	const el = name => document.getElementById('imj-design-' + name);
	let state = null;
	let proposal = null;
	let attachment = 0;
	let restore = null;
	let busy = false;
	let job = 0;

	function status(message, error) {
		el('status').textContent = message;
		el('status').className = error ? 'notice notice-error' : 'notice notice-info';
	}

	function setBusy(value) {
		busy = value;
		el('extract').disabled = busy || !state;
		el('save').disabled = busy || !proposal || !el('confirm').checked;
		el('restore').disabled = busy || !restore || !el('restore-confirm').checked;
		el('history').disabled = busy;
		el('media').disabled = busy;
		el('remove').disabled = busy;
		el('discard').disabled = busy;
		el('stop').hidden = !busy || !job;
	}

	async function api(action, data, jobs = false) {
		const base = jobs ? imajinerDesignSystem.api.replace(/design-system\/$/, 'ai/') : imajinerDesignSystem.api;
		const response = await fetch(base + action, {
			method: data ? 'POST' : 'GET', credentials: 'same-origin',
			headers: { 'X-WP-Nonce': imajinerDesignSystem.nonce, 'Content-Type': 'application/json' },
			body: data ? JSON.stringify(data) : undefined
		});
		const result = await response.json();
		if (!response.ok) {
			const warnings = result.data && result.data.warnings;
			throw new Error((result.message || __('Request failed.', 'imajiner-editor')) + (warnings ? '\n' + warnings.join('\n') : ''));
		}
		return result;
	}

	async function load() {
		state = await api('state');
		// Other editor modules can refresh accepted token suggestions without rewriting PHP.
		window.dispatchEvent(new CustomEvent('imajiner:design-tokens', { detail: state }));
	}

	function clearProposal() {
		proposal = null;
		el('review').hidden = true;
		el('confirm').checked = false;
		setBusy(busy);
	}

	function sample(name, value) {
		const box = document.createElement('span');
		box.className = 'imj-token-sample';
		box.textContent = __('Aa — Sample', 'imajiner-editor');
		if (!value) return box;
		if (name.startsWith('--imj-color-')) {
			box.style.backgroundColor = value;
			box.textContent = '';
			box.setAttribute('aria-label', value);
		} else if (name.includes('font-family') || name === '--imj-font-body' || name === '--imj-font-heading') {
			box.style.fontFamily = value;
		} else if (name.includes('font-size') || name.startsWith('--imj-text-')) {
			box.style.fontSize = value;
		} else if (name.includes('font-weight') || name.startsWith('--imj-weight-')) {
			box.style.fontWeight = value;
		} else if (name.startsWith('--imj-space-')) {
			box.textContent = value;
			box.style.borderLeftWidth = value;
		}
		return box;
	}

	function showDiff(before, after) {
		const table = document.createElement('table');
		table.className = 'widefat striped';
		const header = table.createTHead().insertRow();
		[__('Token', 'imajiner-editor'), __('Before', 'imajiner-editor'), __('After', 'imajiner-editor')].forEach(text => {
			const cell = document.createElement('th');
			cell.textContent = text;
			header.appendChild(cell);
		});
		const body = table.createTBody();
		const names = Array.from(new Set(Object.keys(before).concat(Object.keys(after)))).sort();
		names.forEach(name => {
			const row = body.insertRow();
			row.className = before[name] === after[name] ? '' : 'imj-token-changed';
			row.insertCell().textContent = name;
			[before[name], after[name]].forEach(value => {
				const cell = row.insertCell();
				const code = document.createElement('code');
				code.textContent = value || __('Not set', 'imajiner-editor');
				cell.append(code, sample(name, value));
			});
		});
		el('diff').replaceChildren(table);
	}

	el('form').addEventListener('submit', async event => {
		event.preventDefault();
		if (busy || !state) return;
		clearProposal();
		setBusy(true);
		status(__('Extracting for review. No files are being saved.', 'imajiner-editor'));
		try {
			const queued = await api('extract', { async: true, prompt: el('prompt').value, url: el('url').value.trim(), attachment, hash: state.hash });
			job = queued.id;
			setBusy(true);
			while (job === queued.id) {
				const current = await api('jobs/' + queued.id, null, true);
				if (job !== queued.id) break;
				if (current.state === 'complete') { proposal = current.result; break; }
				if (current.state === 'failed' || current.state === 'cancelled') throw new Error(current.message || __('Extraction stopped. Nothing was saved.', 'imajiner-editor'));
				status(__('Extracting in the background…', 'imajiner-editor') + ' ' + current.progress + '%');
				await new Promise(resolve => setTimeout(resolve, 1500));
			}
			if (!proposal) return;
			el('summary').textContent = proposal.summary;
			el('warnings').replaceChildren();
			proposal.warnings.forEach(warning => {
				const item = document.createElement('li');
				item.textContent = warning;
				el('warnings').appendChild(item);
			});
			showDiff(proposal.before, proposal.after);
			el('before').textContent = proposal.beforeCss;
			el('after').textContent = proposal.afterCss;
			el('review').hidden = false;
			status(__('Review the swatches, typography and raw CSS differences. Confirm only if you want to save.', 'imajiner-editor'));
		} catch (error) { status(error.message, true); }
		finally { job = 0; setBusy(false); }
	});

	el('stop').addEventListener('click', async () => {
		if (!job) return;
		try {
			await api('jobs/' + job + '/cancel', {}, true);
			job = 0;
			status(__('Extraction cancelled. Nothing was saved.', 'imajiner-editor'));
		} catch (error) { status(error.message, true); }
	});

	el('media').addEventListener('click', () => {
		const frame = wp.media({ title: __('Design-system screenshot', 'imajiner-editor'), button: { text: __('Use screenshot', 'imajiner-editor') }, library: { type: ['image/png', 'image/jpeg', 'image/webp'] }, multiple: false });
		frame.on('select', () => {
			const image = frame.state().get('selection').first().toJSON();
			attachment = image.id;
			el('image-name').textContent = image.filename || __('Screenshot selected', 'imajiner-editor');
			el('remove').hidden = false;
			clearProposal();
		});
		frame.open();
	});
	el('remove').addEventListener('click', () => {
		attachment = 0;
		el('image-name').textContent = '';
		el('remove').hidden = true;
		clearProposal();
	});
	['prompt', 'url'].forEach(name => el(name).addEventListener('input', clearProposal));
	el('confirm').addEventListener('change', () => setBusy(busy));
	el('discard').addEventListener('click', clearProposal);
	el('save').addEventListener('click', async () => {
		if (busy || !proposal || !el('confirm').checked) return;
		setBusy(true);
		try {
			await api('accept', { proposal: proposal.proposal, confirm: true });
			clearProposal();
			await load();
			el('restore-review').hidden = true;
			el('revisions').replaceChildren();
			status(__('Design tokens saved to the child theme. A private revision was retained.', 'imajiner-editor'));
		} catch (error) { status(error.message, true); }
		finally { setBusy(false); }
	});

	el('history').addEventListener('click', async () => {
		if (busy) return;
		setBusy(true);
		try {
			await load();
			const revisions = await api('revisions');
			el('revisions').replaceChildren();
			if (!revisions.length) el('revisions').textContent = __('No saved revisions yet.', 'imajiner-editor');
			revisions.forEach(revision => {
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'button';
				button.textContent = __('Review revision', 'imajiner-editor') + ' ' + revision.date;
				button.addEventListener('click', () => {
					if (busy) return;
					restore = { id: revision.id, hash: state.hash };
					el('restore-current').textContent = state.css;
					el('restore-css').textContent = revision.css;
					el('restore-confirm').checked = false;
					el('restore-review').hidden = false;
					setBusy(false);
				});
				el('revisions').appendChild(button);
			});
		} catch (error) { status(error.message, true); }
		finally { setBusy(false); }
	});
	el('restore-confirm').addEventListener('change', () => setBusy(busy));
	el('restore').addEventListener('click', async () => {
		if (busy || !restore || !el('restore-confirm').checked) return;
		setBusy(true);
		try {
			await api('restore', { revision: restore.id, hash: restore.hash, confirm: true });
			restore = null;
			clearProposal();
			el('restore-review').hidden = true;
			el('revisions').replaceChildren();
			await load();
			status(__('Design-system revision restored.', 'imajiner-editor'));
		} catch (error) { status(error.message, true); }
		finally { setBusy(false); }
	});
	setBusy(true);
	load().catch(error => status(error.message, true)).finally(() => setBusy(false));
}());
