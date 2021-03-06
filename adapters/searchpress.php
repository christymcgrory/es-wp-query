<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * ES_WP_Query adapters: Searchpress adapter
 *
 * @package ES_WP_Query
 */

// phpcs:disable Generic.Classes.DuplicateClassName.Found

/**
 * An adapter for SearchPress.
 */
class ES_WP_Query extends ES_WP_Query_Wrapper {

	/**
	 * Implements the abstract function query_es from ES_WP_Query_Wrapper.
	 *
	 * @param array $es_args Arguments to pass to the Elasticsearch server.
	 * @access protected
	 * @return array The response from the Elasticsearch server.
	 */
	protected function query_es( $es_args ) {
		return SP_API()->search( wp_json_encode( $es_args ), array( 'output' => ARRAY_A ) );
	}
}

/**
 * Provides a mapping between WordPress fields and Elasticsearch DSL fields.
 *
 * @param array $es_map Custom mappings to merge with the defaults.
 * @return array
 */
function sp_es_field_map( $es_map ) {
	return wp_parse_args(
		array(
			'post_name'             => 'post_name.raw',
			'post_title'            => 'post_title.raw',
			'post_title.analyzed'   => 'post_title',
			'post_content.analyzed' => 'post_content',
			'post_author'           => 'post_author.user_id',
			'post_date'             => 'post_date.date',
			'post_date_gmt'         => 'post_date_gmt.date',
			'post_modified'         => 'post_modified.date',
			'post_modified_gmt'     => 'post_modified_gmt.date',
			'post_type'             => 'post_type.raw',
			'post_meta'             => 'post_meta.%s.raw',
			'post_meta.analyzed'    => 'post_meta.%s.value',
			'post_meta.signed'      => 'post_meta.%s.long',
			'post_meta.unsigned'    => 'post_meta.%s.long',
			'term_name'             => 'terms.%s.name.raw',
			'category_name'         => 'terms.%s.name.raw',
			'tag_name'              => 'terms.%s.name.raw',
		),
		$es_map 
	);
}
add_filter( 'es_field_map', 'sp_es_field_map' );

// This section only used for unit tests.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_print_r
if ( defined( 'ES_WP_QUERY_TEST_ENV' ) && ES_WP_QUERY_TEST_ENV ) {

	remove_action( 'save_post', array( SP_Sync_Manager(), 'sync_post' ) );
	remove_action( 'delete_post', array( SP_Sync_Manager(), 'delete_post' ) );
	remove_action( 'trashed_post', array( SP_Sync_Manager(), 'delete_post' ) );

	/**
	 * A function to make test data available in the index.
	 */
	function es_wp_query_index_test_data() {
		// If your ES server is not at localhost:9200, you need to set $_ENV['searchpress_host'].
		$host = ! empty( $_ENV['searchpress_host'] ) ? $_ENV['searchpress_host'] : 'http://localhost:9200';

		SP_Config()->update_settings(
			array(
				'active' => false,
				'host'   => $host,
			) 
		);
		SP_API()->index = 'es-wp-query-tests';

		SP_Config()->flush();
		SP_Config()->create_mapping();

		$posts = get_posts( // phpcs:ignore WordPressVIPMinimum.VIP.RestrictedFunctions.get_posts_get_posts
			array(
				'posts_per_page' => -1, // phpcs:ignore WordPress.VIP.PostsPerPage.posts_per_page_posts_per_page
				'post_type'      => 'any',
				'post_status'    => array_values( get_post_stati() ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			) 
		);

		$sp_posts = array();
		foreach ( $posts as $post ) {
			$sp_posts[] = new SP_Post( $post );
		}

		$response = SP_API()->index_posts( $sp_posts );
		if ( 200 !== intval( SP_API()->last_request['response_code'] ) ) {
			echo( "ES response not 200!\n" . print_r( $response, 1 ) );
		} elseif ( ! is_object( $response ) || ! is_array( $response->items ) ) {
			echo( "Error indexing data! Response:\n" . print_r( $response, 1 ) );
		}

		SP_Config()->update_settings(
			array(
				'active'    => true,
				'must_init' => false,
			) 
		);

		SP_API()->post( '_refresh' );
	}
}
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_print_r
