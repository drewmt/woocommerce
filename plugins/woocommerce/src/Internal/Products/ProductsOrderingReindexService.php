<?php declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Products;

/**
 * Assigns sequential menu_order values to all products, enabling deterministic drag-and-drop ordering.
 */
final class ProductsOrderingReindexService {
	/**
	 * Designed for an HVM with 500K+ products catalog operating in a clustered environment. To satisfy this setup:
	 * - The batch size is set to 250. Increasing this value may negatively impact catalog browsing performance.
	 * - Cache invalidation targets the posts cache only, since menu_order lives in wp_posts and is not stored in meta or term caches.
	 * - Resources allocation for 500K products catalog: product-position map - 20 MB RAM, 2000 SQLs for full reindexing ± 2 seconds.
	 *
	 * @since 11.1.0
	 *
	 * @param int $batch_size Number of products included in each batch for re-indexing.
	 * @return array<int,int>
	 */
	public function reindex_products( int $batch_size = 250 ): array {
		global $wpdb;

		// Performance note: prefetch product ids; enables deterministic behaviour and faster queries below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' ORDER BY menu_order ASC, post_title ASC" );

		// Performance: IDs array processing (cut a chunk, re-add to result array) optimized for keeping the memory usage roughly constant here.
		$result           = array();
		$current_position = 1;
		while ( ! empty( $product_ids ) ) {
			$batch_ids       = array_splice( $product_ids, 0, $batch_size );
			$batch_positions = array();
			$batch_branches  = array();
			foreach ( $batch_ids as $id ) {
				$batch_positions[ $id ] = $current_position;
				$batch_branches[]       = sprintf( 'WHEN %d THEN %d', $id, $current_position++ );
			}

			$batch_branches = implode( ' ', $batch_branches );
			$in_values      = implode( ', ', $batch_ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = (int) $wpdb->query( "UPDATE {$wpdb->posts} SET menu_order = CASE ID {$batch_branches} END WHERE ID IN ( {$in_values} )" );
			if ( $updated > 0 ) {
				// Performance note: clear only the posts cache — menu_order lives in wp_posts, not in meta or term caches.
				wp_cache_delete_multiple( $batch_ids, 'posts' );
				wp_cache_set_posts_last_changed();

				// Update the result entries only if update is confirmed.
				foreach ( $batch_positions as $id => $position ) {
					$result[ $id ] = $position;
				}
			}
		}

		return $result;
	}
}
