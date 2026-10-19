type ButtonState = {
	addToCartText: string;
	addToCartDescription: string | null;
};

let mockContext: {
	addToCartText: string;
	addToCartDescription: string | null;
	tempQuantity: number;
	animationStatus: string;
	inTheCartText: string;
};
let mockButtonState: ButtonState;
let mockDescriptionTemplate: string;
const mockCartState = { cart: { items: [ { id: 42, quantity: 2 } ] } };
const mockProductsState = {
	productInContext: { id: 42, type: 'simple' },
	productVariationInContext: undefined,
};

jest.mock(
	'@wordpress/interactivity',
	() => ( {
		getContext: () => mockContext,
		getConfig: () => ( {
			addToCartDescriptionTemplate: mockDescriptionTemplate,
		} ),
		store: ( namespace: string, definition: { state?: ButtonState } ) => {
			if ( namespace === 'woocommerce' ) {
				return { state: mockCartState };
			}
			if ( namespace === 'woocommerce/products' ) {
				return { state: mockProductsState };
			}
			if ( namespace === 'woocommerce/product-button' ) {
				mockButtonState = definition.state as ButtonState;
				return { state: mockButtonState };
			}
			return { state: {} };
		},
	} ),
	{ virtual: true }
);

jest.mock( '@woocommerce/stores/woocommerce/products', () => ( {} ) );

describe( 'Product Button accessible description', () => {
	beforeEach( () => {
		jest.resetModules();
		mockDescriptionTemplate = '%1$s. %2$s';
		mockContext = {
			addToCartText: 'Add to cart',
			addToCartDescription: 'Add to cart: “Cap”',
			tempQuantity: 0,
			animationStatus: 'IDLE',
			inTheCartText: '### in cart',
		};
		jest.isolateModules( () => {
			jest.requireActual( '../frontend' );
		} );
	} );

	it( 'keeps the original description while the initial action text is visible', () => {
		expect( mockButtonState.addToCartDescription ).toBe(
			'Add to cart: “Cap”'
		);
	} );

	it.each( [ 'IDLE', 'SLIDE-OUT' ] )(
		'announces the visible temporary quantity during %s',
		( animationStatus ) => {
			mockContext.tempQuantity = 1;
			mockContext.animationStatus = animationStatus;
			expect( mockButtonState.addToCartDescription ).toBe(
				'1 in cart. Add to cart: “Cap”'
			);
		}
	);

	it( 'announces the new quantity when the new text slides in', () => {
		mockContext.tempQuantity = 1;
		mockContext.animationStatus = 'SLIDE-IN';
		expect( mockButtonState.addToCartDescription ).toBe(
			'2 in cart. Add to cart: “Cap”'
		);
	} );

	it( 'retains a filtered description after the visible cart text changes', () => {
		mockContext.tempQuantity = 3;
		mockContext.addToCartDescription = 'Purchase the handmade cap';
		expect( mockButtonState.addToCartDescription ).toBe(
			'3 in cart. Purchase the handmade cap'
		);
	} );

	it( 'does not replace a missing description with an unnecessary label', () => {
		mockContext.tempQuantity = 1;
		mockContext.addToCartDescription = null;
		expect( mockButtonState.addToCartDescription ).toBeNull();
	} );

	it( 'allows translations to put the original description first', () => {
		mockContext.tempQuantity = 1;
		mockDescriptionTemplate = '%2$s — %1$s';
		expect( mockButtonState.addToCartDescription ).toBe(
			'Add to cart: “Cap” — 1 in cart'
		);
	} );
} );
