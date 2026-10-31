<?php
/**
 * Tests for shipping method rate taxes.
 *
 * @package WooCommerce\Tests\Includes
 */

declare( strict_types=1 );

/**
 * Tests for WC_Shipping_Method.
 */
class WC_Shipping_Method_Test extends WC_Unit_Test_Case {

	/**
	 * @testdox Per-item shipping skips unavailable items but retains order-level taxes.
	 * @testWith ["missing", 2]
	 *           ["empty", 2]
	 *           ["populated", 3]
	 * @param string $cart_state Cart availability and contents.
	 * @param int    $expected_tax Expected shipping tax.
	 */
	public function test_per_item_taxes_with_cart_states( string $cart_state, int $expected_tax ): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_shipping_tax_class', 'inherit' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		$rate_id  = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'GB',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'Shipping tax',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			)
		);
		$cart     = WC()->cart;
		$customer = WC()->customer;
		WC()->cart->empty_cart();
		WC()->customer = new WC_Customer();
		WC()->customer->set_shipping_country( 'GB' );
		$item_key = 'unavailable-item';
		if ( 'populated' === $cart_state ) {
			$product = new WC_Product_Simple();
			$product->set_regular_price( 10 );
			$product->save();
			$item_key = WC()->cart->add_to_cart( $product->get_id() );
		}
		$sut             = new WC_Shipping_Flat_Rate();
		$sut->tax_status = \Automattic\WooCommerce\Enums\ProductTaxStatus::TAXABLE;

		try {
			if ( 'missing' === $cart_state ) {
				WC()->cart = null;
			}
			$sut->add_rate(
				array(
					'id'       => 'shipping-test',
					'label'    => 'Shipping',
					'calc_tax' => 'per_item',
					'cost'     => array(
						$item_key => 5,
						'order'   => 10,
					),
				)
			);
			$rate = $sut->rates['shipping-test'];
			$this->assertSame( 15.0, (float) $rate->get_cost() );
			$this->assertSame( array( $rate_id ), array_keys( $rate->get_taxes() ) );
			$this->assertSame( (float) $expected_tax, (float) $rate->get_taxes()[ $rate_id ] );
		} finally {
			WC()->cart     = $cart;
			WC()->customer = $customer;
		}
	}
}
