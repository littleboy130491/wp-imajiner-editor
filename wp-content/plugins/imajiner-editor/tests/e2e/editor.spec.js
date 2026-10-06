const { test, expect } = require( '@playwright/test' );

test.beforeEach( async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( process.env.IMAJINER_E2E_USERNAME );
	await page.locator( '#user_pass' ).fill( process.env.IMAJINER_E2E_PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await expect( page ).toHaveURL( /wp-admin/ );
} );

test( 'create, open, edit, stage, undo, save and review/restore a private revision', async ( { page } ) => {
	const name = process.env.IMAJINER_E2E_RUN_ID + '-manual';
	await page.goto( '/wp-admin/themes.php?page=imajiner-templates' );
	const form = page.locator( 'form' ).filter( { has: page.locator( 'input[value="imajiner_create_template"]' ) } );
	await form.locator( 'input[name="name"]' ).fill( name );
	await form.getByRole( 'button', { name: 'Create template', exact: true } ).click();
	await expect( page.locator( '#imj-preview' ) ).toBeVisible();
	await expect.poll( () => page.evaluate( () => window.imajinerEditor?.getState?.().template ) ).toBeTruthy();
	const preview = page.frameLocator( '#imj-preview' );
	const heading = preview.locator( 'h1[data-imj-id]' ).first();
	const original = await heading.innerText();
	await heading.click();
	await page.locator( '#imj-props textarea.imj-input--text' ).first().fill( 'Disposable edited heading' );
	await expect( page.locator( '#imj-save' ) ).toBeEnabled();
	await page.locator( '#imj-undo' ).click();
	await expect( heading ).toHaveText( original );
	await page.locator( '#imj-redo' ).click();
	await expect( heading ).toHaveText( 'Disposable edited heading' );
	await page.locator( '#imj-add-section' ).click();
	await expect( page.locator( '#imj-status' ) ).toContainText( 'Unsaved changes staged' );
	const sectionCount = await page.evaluate( () => window.imajinerEditor?.getState?.().structure.tree.filter( ( node ) => node.type === 'section' ).length );
	await page.locator( '#imj-undo' ).click();
	await expect.poll( () => page.evaluate( () => window.imajinerEditor?.getState?.().structure.tree.filter( ( node ) => node.type === 'section' ).length ) ).toBe( sectionCount - 1 );
	await page.locator( '#imj-redo' ).click();
	await expect.poll( () => page.evaluate( () => window.imajinerEditor?.getState?.().structure.tree.filter( ( node ) => node.type === 'section' ).length ) ).toBe( sectionCount );
	await page.locator( '#imj-save' ).click();
	await expect( page.locator( '#imj-status' ) ).toHaveText( 'Saved' );
	await page.reload();
	await expect( preview.locator( 'h1[data-imj-id]' ).first() ).toHaveText( 'Disposable edited heading' );
	await page.locator( '#imj-history-toggle' ).click();
	await page.locator( '#imj-history-panel' ).getByRole( 'button', { name: 'Restore', exact: true } ).first().click();
	await expect( page.locator( '#imj-history-panel .imj-diff' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Confirm restore', exact: true } ).click();
	await expect( preview.locator( 'h1[data-imj-id]' ).first() ).toHaveText( original );
} );

test( 'background AI generation uses deterministic WordPress provider and explicit acceptance', async ( { page } ) => {
	await page.goto( '/wp-admin/themes.php?page=imajiner-templates' );
	await page.locator( '#imj-ai-name' ).fill( process.env.IMAJINER_E2E_RUN_ID + '-ai' );
	await page.locator( '#imj-ai-prompt' ).fill( 'Create a disposable heading and token-based spacing.' );
	await page.locator( '#imj-ai-generate' ).click();
	await expect( page.locator( '#imj-ai-review' ) ).toBeVisible();
	await expect( page.locator( '#imj-ai-comparison textarea[aria-label="After — PHP"]' ) ).toHaveValue( /E2E generated heading/ );
	await expect( page.locator( '#imj-ai-accept' ) ).toBeEnabled();
	await page.locator( '#imj-ai-accept' ).click();
	await expect( page.locator( '#imj-preview' ) ).toBeVisible();
	await expect( page.frameLocator( '#imj-preview' ).locator( 'h1' ).first() ).toHaveText( 'E2E generated heading' );
} );

test( 'design proposal is not saved before review and tokens persist after acceptance', async ( { page } ) => {
	await page.goto( '/wp-admin/themes.php?page=imajiner-design-system' );
	await page.locator( '#imj-design-prompt' ).fill( 'Extract a deterministic disposable design system.' );
	await page.locator( '#imj-design-extract' ).click();
	await expect( page.locator( '#imj-design-review' ) ).toBeVisible();
	await expect( page.locator( '#imj-design-after' ) ).toContainText( '#13579b' );
	await expect( page.locator( '#imj-design-save' ) ).toBeDisabled();
	await page.locator( '#imj-design-confirm' ).check();
	await page.locator( '#imj-design-save' ).click();
	await expect( page.locator( '#imj-design-status' ) ).toContainText( 'saved' );
	await page.reload();
	await page.locator( '#imj-design-prompt' ).fill( 'Preserve accepted tokens.' );
	await page.locator( '#imj-design-extract' ).click();
	await expect( page.locator( '#imj-design-before' ) ).toContainText( '#13579b' );
	await page.locator( '#imj-design-discard' ).click();
	await page.locator( '#imj-design-history' ).click();
	await expect( page.locator( '#imj-design-revisions button' ).first() ).toBeVisible();
} );
