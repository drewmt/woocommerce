<?php
/**
 * Tests for the default store tax estimate.
 *
 * @package WooCommerce\Tests\Includes
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Enums\DefaultCustomerAddress;
use Automattic\WooCommerce\Enums\TaxBasedOn;

/**
 * Tests for WC_Tax's default customer location.
 */
class WC_Tax_Default_Location_Test extends WC_Unit_Test_Case {
	/**
	 * Original session customer.
	 *
	 * @var mixed
	 */
	private $session_customer;

	/**
	 * Original chosen shipping methods.
	 *
	 * @var mixed
	 */
	private $chosen_shipping_methods;

	/**
	 * Set up the store address and tax settings.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->session_customer        = WC()->session->get( 'customer' );
		$this->chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		update_option( 'woocommerce_default_customer_address', DefaultCustomerAddress::BASE );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_postcode', '94110' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_tax_based_on', TaxBasedOn::SHIPPING );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_allowed_countries', 'all' );
		WC()->session->set( 'customer', null );
		WC()->session->set( 'chosen_shipping_methods', array() );
	}

	/**
	 * Restore session values that the base lifecycle does not reset.
	 */
	public function tearDown(): void {
		try {
			WC()->session->set( 'customer', $this->session_customer );
			WC()->session->set( 'chosen_shipping_methods', $this->chosen_shipping_methods );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Keeps tax lookups safe before the countries singleton is initialized.
	 */
	public function test_tax_lookup_before_countries_initialization(): void {
		global $wp_actions;
		$customer = new WC_Customer();
		$customer->set_shipping_country( 'US' );
		$customer->set_shipping_state( 'CA' );
		$countries                      = WC()->countries;
		$initializations                = $wp_actions['woocommerce_init'];
		WC()->countries                 = null;
		$wp_actions['woocommerce_init'] = 0; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The original initialization count is restored below.
		try {
			$location = WC_Tax::get_tax_location( '', $customer );
			$this->assertSame( '', $location[2] );
			$this->assertSame( '', $location[3] );
		} finally {
			WC()->countries                 = $countries;
			$wp_actions['woocommerce_init'] = $initializations; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original lifecycle state.
		}
	}

	/**
	 * @testdox Matches the store's restricted rate without populating the guest's address.
	 * @testWith ["shipping", "postcode"]
	 *           ["shipping", "city"]
	 *           ["billing", "postcode"]
	 *           ["billing", "city"]
	 * @param string $tax_based_on Address used for tax.
	 * @param string $restriction Tax rate restriction.
	 */
	public function test_matches_store_rate_without_populating_address( string $tax_based_on, string $restriction ): void {
		update_option( 'woocommerce_tax_based_on', $tax_based_on );
		$rate_id  = $this->create_store_rate( $restriction );
		$customer = new WC_Customer( 0, true );

		$rates = WC_Tax::get_rates( '', $customer );

		$this->assertArrayHasKey( $rate_id, $rates, 'The initial quote should match the store address.' );
		$this->assertSame( 20.0, (float) $rates[ $rate_id ]['rate'] );
		$this->assertSame( '', $customer->get_billing_postcode(), 'The tax estimate must not prefill billing.' );
		$this->assertSame( '', $customer->get_billing_city() );
		$this->assertSame( '', $customer->get_shipping_postcode(), 'The tax estimate must not change shipping-zone selection.' );
		$this->assertSame( '', $customer->get_shipping_city() );
	}

	/**
	 * @testdox Uses the store estimate in cart totals without changing the shipping destination.
	 * @testWith ["shipping", false]
	 *           ["billing", false]
	 *           ["shipping", true]
	 *           ["billing", true]
	 * @param string $tax_based_on Address used for tax.
	 * @param bool   $prices_include_tax Whether product prices include tax.
	 */
	public function test_cart_estimate_preserves_shipping_destination( string $tax_based_on, bool $prices_include_tax ): void {
		update_option( 'woocommerce_tax_based_on', $tax_based_on );
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax ? 'yes' : 'no' );
		$this->create_store_rate( 'postcode' );
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '100' );
		$product->set_price( '100' );
		$product->save();
		$original_customer = WC()->customer;
		WC()->customer     = new WC_Customer( 0, true );
		try {
			$destination = WC()->cart->get_shipping_packages()[0]['destination'];
			WC()->cart->add_to_cart( $product->get_id(), 1 );
			WC()->cart->calculate_totals();

			$this->assertEqualsWithDelta( $prices_include_tax ? 100 / 6 : 20, (float) WC()->cart->get_cart_contents_tax(), 0.01 );
			$this->assertEqualsWithDelta( $prices_include_tax ? 100 : 120, WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax(), 0.01 );
			$packages = WC()->cart->get_shipping_packages();
			$this->assertCount( 1, $packages );
			$this->assertSame( $destination, $packages[0]['destination'], 'Tax estimation must not change the shipping destination.' );
			$this->assertSame( '', WC()->customer->get_shipping_postcode() );
			$this->assertSame( '', WC()->customer->get_shipping_city() );
		} finally {
			WC()->customer = $original_customer;
		}
	}

	/**
	 * @testdox Allows the existing tax-location filter to override the store estimate.
	 */
	public function test_preserves_tax_location_filter(): void {
		$rate_id  = $this->create_store_rate( 'postcode' );
		$customer = new WC_Customer( 0, true );
		add_filter(
			'woocommerce_get_tax_location',
			static function ( $location ) {
				$location[2] = '94107';
				return $location;
			}
		);

		$this->assertArrayNotHasKey( $rate_id, WC_Tax::get_rates( '', $customer ), 'Extensions should retain the final tax-location override.' );
		$this->assertSame( '94107', WC_Tax::get_tax_location( '', $customer )[2] );
	}

	/**
	 * @testdox Matches the store country and state using the tax lookup's case-insensitive rules.
	 * @testWith ["us", "CA"]
	 *           ["US", "ca"]
	 *           ["us", "ca"]
	 * @param string $country Shopper country.
	 * @param string $state Shopper state.
	 */
	public function test_store_region_match_is_case_insensitive( string $country, string $state ): void {
		$rate_id  = $this->create_store_rate( 'postcode' );
		$customer = new WC_Customer();
		$customer->set_shipping_country( $country );
		$customer->set_shipping_state( $state );

		$this->assertArrayHasKey( $rate_id, WC_Tax::get_rates( '', $customer ), 'The estimate should follow the tax matcher country/state rules.' );
		$this->assertSame( $country, $customer->get_shipping_country() );
		$this->assertSame( $state, $customer->get_shipping_state() );
	}

	/**
	 * @testdox Stops estimating at the store once the shopper enters their postcode or city.
	 * @testWith ["shipping", "postcode", "94107", ""]
	 *           ["billing", "postcode", "94107", ""]
	 *           ["shipping", "city", "", "Oakland"]
	 *           ["billing", "city", "", "Oakland"]
	 * @param string $tax_based_on Address used for tax.
	 * @param string $restriction Tax rate restriction.
	 * @param string $postcode Shopper postcode.
	 * @param string $city Shopper city.
	 */
	public function test_switches_to_shopper_location( string $tax_based_on, string $restriction, string $postcode, string $city ): void {
		update_option( 'woocommerce_tax_based_on', $tax_based_on );
		$rate_id  = $this->create_store_rate( $restriction );
		$customer = new WC_Customer( 0, true );
		$this->assertArrayHasKey( $rate_id, WC_Tax::get_rates( '', $customer ), 'The initial estimate must exercise the fallback.' );

		$customer->set_props(
			array(
				$tax_based_on . '_postcode' => $postcode,
				$tax_based_on . '_city'     => $city,
			)
		);
		$rates = WC_Tax::get_rates( '', $customer );

		$this->assertArrayNotHasKey( $rate_id, $rates, 'The store rate should not override the shopper location.' );
		$location = WC_Tax::get_tax_location( '', $customer );
		$this->assertSame( $postcode, $location[2] );
		$this->assertSame( $city, $location[3] );
	}

	/**
	 * @testdox Leaves geolocation and no-default-location tax lookups unchanged.
	 * @testWith [""]
	 *           ["geolocation"]
	 *           ["geolocation_ajax"]
	 * @param string $default_location Default location setting.
	 */
	public function test_preserves_other_default_location_modes( string $default_location ): void {
		update_option( 'woocommerce_default_customer_address', $default_location );
		$customer = new WC_Customer();
		$customer->set_shipping_country( 'US' );
		$customer->set_shipping_state( 'CA' );

		$location = WC_Tax::get_tax_location( '', $customer );

		$this->assertSame( '', $location[2] );
		$this->assertSame( '', $location[3] );
	}

	/**
	 * @testdox Does not replace an explicit location or one outside the store country and state.
	 * @testWith ["CA", "BC", "", ""]
	 *           ["US", "NY", "", ""]
	 *           ["US", "CA", "94107", ""]
	 *           ["US", "CA", "", "Oakland"]
	 *           ["US", "CA", "0", ""]
	 * @param string $country Shopper country.
	 * @param string $state Shopper state.
	 * @param string $postcode Shopper postcode.
	 * @param string $city Shopper city.
	 */
	public function test_preserves_explicit_locations( string $country, string $state, string $postcode, string $city ): void {
		$customer = new WC_Customer();
		$customer->set_props(
			array(
				'shipping_country'  => $country,
				'shipping_state'    => $state,
				'shipping_postcode' => $postcode,
				'shipping_city'     => $city,
			)
		);

		$location = WC_Tax::get_tax_location( '', $customer );

		$this->assertSame( $country, $location[0] );
		$this->assertSame( $state, $location[1] );
		$this->assertSame( $postcode, $location[2] );
		$this->assertSame( $city, $location[3] );
	}

	/**
	 * @testdox Keeps local pickup and tax-based-on-store lookups at the full store address.
	 * @testWith ["base", false]
	 *           ["shipping", true]
	 * @param string $tax_based_on Address used for tax.
	 * @param bool   $local_pickup Whether local pickup is selected.
	 */
	public function test_preserves_store_and_local_pickup_tax( string $tax_based_on, bool $local_pickup ): void {
		update_option( 'woocommerce_tax_based_on', $tax_based_on );
		if ( $local_pickup ) {
			WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:1' ) );
		}
		$customer = new WC_Customer();
		$customer->set_shipping_country( 'US' );
		$customer->set_shipping_state( 'NY' );

		$location = WC_Tax::get_tax_location( '', $customer );

		$this->assertSame( 'CA', $location[1] );
		$this->assertSame( '94110', $location[2] );
		$this->assertSame( 'SAN FRANCISCO', strtoupper( $location[3] ) );
	}

	/**
	 * Create a tax rate restricted to the store postcode or city.
	 *
	 * @param string $restriction Tax rate restriction.
	 * @return int Tax rate ID.
	 */
	private function create_store_rate( string $restriction ): int {
		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '20',
				'tax_rate_name'     => 'Store estimate',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			)
		);
		if ( 'postcode' === $restriction ) {
			WC_Tax::_update_tax_rate_postcodes( $rate_id, '94110' );
		} else {
			WC_Tax::_update_tax_rate_cities( $rate_id, 'San Francisco' );
		}
		return $rate_id;
	}
}
