<?php
/**
 * Controller Tests.
 */

namespace Automattic\WooCommerce\Tests\Blocks\StoreApi\Routes;

use Automattic\WooCommerce\Tests\Blocks\StoreApi\Routes\ControllerTestCase;
use Automattic\WooCommerce\Tests\Blocks\Helpers\FixtureData;

/**
 * Batch Controller Tests.
 */
class Batch extends ControllerTestCase {

	/**
	 * Setup test product data. Called before every test.
	 */
	protected function setUp(): void {
		add_filter(
			'__experimental_woocommerce_store_api_batch_request_methods',
			function ( $methods ) {
				$methods[] = 'GET';
				return $methods;
			}
		);
		parent::setUp();

		$fixtures = new FixtureData();

		$this->products = array(
			$fixtures->get_simple_product(
				array(
					'name'          => 'Test Product 1',
					'regular_price' => 10,
				)
			),
			$fixtures->get_simple_product(
				array(
					'name'          => 'Test Product 2',
					'regular_price' => 10,
				)
			),
		);
	}

	/**
	 * Test that a batch of requests are successful.
	 */
	public function test_success_cart_route_batch() {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method'  => 'POST',
						'path'    => '/wc/store/v1/cart/add-item',
						'body'    => array(
							'id'       => $this->products[0]->get_id(),
							'quantity' => 1,
						),
						'headers' => array(
							'Nonce' => wp_create_nonce( 'wc_store_api' ),
						),
					),
					array(
						'method'  => 'POST',
						'path'    => '/wc/store/v1/cart/add-item',
						'body'    => array(
							'id'       => $this->products[1]->get_id(),
							'quantity' => 1,
						),
						'headers' => array(
							'Nonce' => wp_create_nonce( 'wc_store_api' ),
						),
					),
				),
			)
		);
		$response      = rest_get_server()->dispatch( $request );
		$response_data = $response->get_data();

		// Assert that there were 2 successful results from the batch.
		$this->assertEquals( 2, count( $response_data['responses'] ) );
		$this->assertEquals( 201, $response_data['responses'][0]['status'] );
		$this->assertEquals( 201, $response_data['responses'][1]['status'] );
	}

	/**
	 * Test for a mixture of successful and non-successful requests in a batch.
	 */
	public function test_mix_cart_route_batch() {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method'  => 'POST',
						'path'    => '/wc/store/v1/cart/add-item',
						'body'    => array(
							'id'       => 99,
							'quantity' => 1,
						),
						'headers' => array(
							'Nonce' => wp_create_nonce( 'wc_store_api' ),
						),
					),
					array(
						'method'  => 'POST',
						'path'    => '/wc/store/v1/cart/add-item',
						'body'    => array(
							'id'       => $this->products[1]->get_id(),
							'quantity' => 1,
						),
						'headers' => array(
							'Nonce' => wp_create_nonce( 'wc_store_api' ),
						),
					),
				),
			)
		);
		$response      = rest_get_server()->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 2, count( $response_data['responses'] ) );
		$this->assertEquals( 400, $response_data['responses'][0]['status'], $response_data['responses'][0]['status'] );
		$this->assertEquals( 201, $response_data['responses'][1]['status'], $response_data['responses'][1]['status'] );
	}


	/**
	 * @testdox Should reject malformed product IDs before executing a strict batch.
	 * @dataProvider invalid_product_id_provider
	 * @param mixed $product_id Invalid product ID.
	 */
	public function test_batch_validates_product_id_before_cart_changes( $product_id ): void {
		$response = $this->dispatch_add_item_batch( $product_id, 'require-all-validate' );
		$data     = $response->get_data();

		$this->assertSame( 207, $response->get_status() );
		$this->assertSame( 'validation', $data['failed'] ?? null, 'Invalid product IDs should fail batch preflight.' );
		$this->assertNull( $data['responses'][0], 'The valid sibling request should not execute.' );
		$this->assertSame( 400, $data['responses'][1]['status'] );
		$this->assertSame( 'rest_invalid_param', $data['responses'][1]['body']['code'] );
		$this->assertArrayHasKey( 'id', $data['responses'][1]['body']['data']['params'] );
		$this->assertTrue( WC()->cart->is_empty(), 'A failed preflight should leave the cart unchanged.' );
	}

	/**
	 * Product IDs that must not be coerced by absint before validation.
	 *
	 * @return array
	 */
	public function invalid_product_id_provider(): array {
		return array(
			'nonnumeric string' => array( 'banana' ),
			'fractional number' => array( 1.5 ),
			'boolean'           => array( true ),
			'array'             => array( array( 1 ) ),
		);
	}

	/**
	 * @testdox Should execute valid sibling requests when normal batch validation is used.
	 */
	public function test_normal_batch_executes_valid_requests_with_invalid_product_id(): void {
		$data = $this->dispatch_add_item_batch( 'banana', 'normal' )->get_data();

		$this->assertArrayNotHasKey( 'failed', $data );
		$this->assertSame( 201, $data['responses'][0]['status'] );
		$this->assertSame( 400, $data['responses'][1]['status'] );
		$this->assertSame( 'rest_invalid_param', $data['responses'][1]['body']['code'] );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'Normal batches should still execute valid requests.' );
	}

	/**
	 * @testdox Should accept integer-string product IDs and preserve quantity defaults in strict batches.
	 */
	public function test_strict_batch_accepts_numeric_string_product_id(): void {
		$data = $this->dispatch_add_item_batch( (string) $this->products[1]->get_id(), 'require-all-validate' )->get_data();

		$this->assertArrayNotHasKey( 'failed', $data );
		$this->assertSame( 201, $data['responses'][0]['status'] );
		$this->assertSame( 201, $data['responses'][1]['status'] );
		$this->assertSame( 2, WC()->cart->get_cart_contents_count(), 'Both products should be added with the default quantity of one.' );
	}

	/**
	 * @testdox Should keep runtime product errors distinct from strict batch validation failures.
	 */
	public function test_strict_batch_does_not_roll_back_runtime_product_errors(): void {
		$data = $this->dispatch_add_item_batch( 0, 'require-all-validate' )->get_data();

		$this->assertArrayNotHasKey( 'failed', $data, 'A schema-valid ID should reach the product lookup.' );
		$this->assertSame( 201, $data['responses'][0]['status'] );
		$this->assertSame( 'woocommerce_rest_cart_invalid_product', $data['responses'][1]['body']['code'] );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'Strict validation does not provide runtime rollback.' );
	}

	/**
	 * Dispatch a valid cart mutation followed by an add-item request with the given ID.
	 *
	 * @param mixed  $product_id Product ID for the second request.
	 * @param string $validation Batch validation mode.
	 * @return \WP_REST_Response
	 */
	private function dispatch_add_item_batch( $product_id, string $validation ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$nonce   = wp_create_nonce( 'wc_store_api' );
		$request->set_header( 'Nonce', $nonce );
		$requests = array();

		foreach ( array( $this->products[0]->get_id(), $product_id ) as $id ) {
			$requests[] = array(
				'method'  => 'POST',
				'path'    => '/wc/store/v1/cart/add-item',
				'body'    => array( 'id' => $id ),
				'headers' => array( 'Nonce' => $nonce ),
			);
		}

		$request->set_body_params(
			array(
				'validation' => $validation,
				'requests'   => $requests,
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Do a batch request with a get request.
	 */
	public function test_batch_get_requests() {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'GET',
						'path'   => '/wc/store/v1/products',
					),
					array(
						'method' => 'GET',
						'path'   => '/wc/store/v1/products/collection-data',
					),
				),
			)
		);

		$response      = rest_get_server()->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 2, count( $response_data['responses'] ) );
		$this->assertEquals( 200, $response_data['responses'][0]['status'] );
	}

	/**
	 * @testdox Should reject batch sub-request with path outside Store API namespace.
	 * @dataProvider invalid_batch_paths_data
	 * @param string $path The path to test.
	 */
	public function test_batch_rejects_invalid_path( string $path ): void {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'POST',
						'path'   => $path,
						'body'   => array(),
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status(), "Path '$path' should be rejected" );
		$this->assertEquals( 'woocommerce_rest_invalid_path', $response->get_data()['code'], "Path '$path' should return woocommerce_rest_invalid_path error code" );
	}

	/**
	 * Data provider for paths that should be rejected by batch path validation.
	 *
	 * @return array
	 */
	public function invalid_batch_paths_data(): array {
		return array(
			'non-store-api path'                         => array( '/wp/v2/users' ),
			'query string containing wc/store'           => array( '/wp/v2/users?query=wc/store' ),
			'fragment containing wc/store'               => array( '/wp/v2/users#wc/store' ),
			'wc/store appears in middle of non-api path' => array( '/other/wc/store/endpoint' ),
			'empty path'                                 => array( '' ),
		);
	}

	/**
	 * @testdox Should accept batch sub-request with valid Store API path.
	 * @dataProvider valid_batch_paths_data
	 * @param string $path The path to test.
	 */
	public function test_batch_accepts_valid_store_api_path( string $path ): void {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'GET',
						'path'   => $path,
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertNotEquals( 'woocommerce_rest_invalid_path', $response->get_data()['code'] ?? '', "Path '$path' should not be rejected by path validation" );
	}

	/**
	 * Data provider for paths that should pass batch path validation.
	 *
	 * @return array
	 */
	public function valid_batch_paths_data(): array {
		return array(
			'store api cart'             => array( '/wc/store/v1/cart' ),
			'store api products'         => array( '/wc/store/v1/products' ),
			'store api with query param' => array( '/wc/store/v1/products?per_page=5' ),
		);
	}

	/**
	 * @testdox Should reject batch when one sub-request has a valid path and another has an invalid path.
	 */
	public function test_batch_rejects_if_any_path_is_invalid(): void {
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/batch' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params(
			array(
				'requests' => array(
					array(
						'method' => 'GET',
						'path'   => '/wc/store/v1/cart',
					),
					array(
						'method' => 'POST',
						'path'   => '/wp/v2/users?query=wc/store',
						'body'   => array(
							'username' => 'newuser',
							'email'    => 'newuser@example.com',
							'password' => 'password123',
						),
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status(), 'Batch should be rejected when any sub-request path is invalid' );
		$this->assertEquals( 'woocommerce_rest_invalid_path', $response->get_data()['code'] );
	}
}
