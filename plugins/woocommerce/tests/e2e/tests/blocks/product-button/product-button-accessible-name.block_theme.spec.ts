/**
 * External dependencies
 */
import { expect, test } from '@woocommerce/e2e-utils';

/**
 * Internal dependencies
 */
import AddToCartWithOptionsPage from '../add-to-cart-with-options/add-to-cart-with-options.page';

test( 'keeps the accessible name in sync with the visible cart quantity', async ( {
	page,
	admin,
	editor,
} ) => {
	const productPage = new AddToCartWithOptionsPage( { page, admin, editor } );
	await productPage.createPostWithProductBlock( 'beanie' );

	const button = page.locator( '.single_add_to_cart_button' );
	await expect( button ).toHaveAccessibleName( 'Add to cart: “Beanie”' );
	await button.focus();
	const firstResponse = page.waitForResponse( '**/wc/store/v1/batch**' );
	await page.keyboard.press( 'Enter' );
	await firstResponse;
	await expect( button ).toHaveText( '1 in cart' );
	await expect( button ).toHaveAccessibleName( /1 in cart/ );

	const secondResponse = page.waitForResponse( '**/wc/store/v1/batch**' );
	await button.click();
	await secondResponse;
	await expect( button ).toHaveText( '2 in cart' );
	await expect( button ).toHaveAccessibleName( /2 in cart/ );
	await page.reload();
	await expect( button ).toHaveText( '2 in cart' );
	await expect( button ).toHaveAccessibleName( /2 in cart/ );
} );
