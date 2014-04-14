<?php

/**
 * Elasticsearch replacement for WP_Query
 */
abstract class ES_WP_Query_Wrapper extends WP_Query {

	protected $es_map = array();

	abstract protected function query_es( $es_args );

	protected function set_posts( $q, $es_response ) {
		$this->posts = array();
		if ( isset( $es_response['hits']['hits'] ) ) {
			switch ( $q['fields'] ) {
				case 'ids' :
					foreach ( $es_response['hits']['hits'] as $hit ) {
						$this->posts[] = $hit['fields'][ $this->es_map['post_id'] ];
					}
					return;

				case 'id=>parent' :
					foreach ( $es_response['hits']['hits'] as $hit ) {
						$this->posts[ $hit['fields'][ $this->es_map['post_id'] ] ] = $hit['fields'][ $this->es_map['post_parent'] ];
					}
					return;

				default :
					if ( apply_filters( 'es_query_use_source', false ) ) {
						$this->posts = wp_list_pluck( $es_response['hits']['hits'], '_source' );
						return;
					} else {
						$post_ids = array();
						foreach ( $es_response['hits']['hits'] as $hit ) {
							$post_ids[] = absint( $hit['fields'][ $this->es_map['post_id'] ] );
						}
						$post_ids = array_filter( $post_ids );
						if ( ! empty( $post_ids ) ) {
							global $wpdb;
							return $wpdb->get_results( "SELECT $wpdb->posts.* FROM $wpdb->posts WHERE ID IN (" . implode( ',', $post_ids ) . ")" );
						}
						return;
					}
			}
		} else {
			$this->posts = array();
		}
	}

	/**
	 * Set up the amount of found posts and the number of pages (if limit clause was used)
	 * for the current query.
	 *
	 * @access private
	 */
	public function set_found_posts( $q, $es_response ) {
		if ( isset( $es_response['hits']['total'] ) ) {
			$this->found_posts = absint( $es_response['hits']['total'] );
		} else {
			$this->found_posts = 0;
		}
		$this->found_posts = apply_filters_ref_array( 'es_found_posts', array( $this->found_posts, &$this ) );
		$this->max_num_pages = ceil( $this->found_posts / $q['posts_per_page'] );
	}

	/**
	 * Retrieve the posts based on query variables.
	 *
	 * There are a few filters and actions that can be used to modify the post
	 * database query.
	 *
	 * @since 1.5.0
	 * @access public
	 * @uses do_action_ref_array() Calls 'pre_get_posts' hook before retrieving posts.
	 *
	 * @return array List of posts.
	 */
	public function get_posts() {
		global $wpdb;

		$this->es_map = apply_filters( 'es_field_map', array(
			'post_id'               => 'post_id',
			'post_author'           => 'post_author',
			'post_date'             => 'post_date',
			'post_date_gmt'         => 'post_date_gmt',
			'post_content'          => 'post_content',
			'post_title'            => 'post_title',
			'post_excerpt'          => 'post_excerpt',
			'post_status'           => 'post_status',
			'comment_status'        => 'comment_status',
			'ping_status'           => 'ping_status',
			'post_password'         => 'post_password',
			'post_name'             => 'post_name',
			'to_ping'               => 'to_ping',
			'pinged'                => 'pinged',
			'post_modified'         => 'post_modified',
			'post_modified_gmt'     => 'post_modified_gmt',
			'post_content_filtered' => 'post_content_filtered',
			'post_parent'           => 'post_parent',
			'guid'                  => 'guid',
			'menu_order'            => 'menu_order',
			'post_type'             => 'post_type',
			'post_mime_type'        => 'post_mime_type',
			'comment_count'         => 'comment_count',
			'post_meta.meta_value'  => 'post_meta.%s',
		) );

		$this->parse_query();

		do_action_ref_array('pre_get_posts', array(&$this));
		do_action_ref_array('es_pre_get_posts', array(&$this));

		// Shorthand.
		$q = &$this->query_vars;

		// Fill again in case pre_get_posts unset some vars.
		$q = $this->fill_query_vars($q);

		// Parse meta query
		$this->meta_query = new ES_WP_Meta_Query();
		$this->meta_query->parse_query_vars( $q );

		// Set a flag if a pre_get_posts hook changed the query vars.
		$hash = md5( serialize( $this->query_vars ) );
		if ( $hash != $this->query_vars_hash ) {
			$this->query_vars_changed = true;
			$this->query_vars_hash = $hash;
		}
		unset($hash);

		// First let's clear some variables
		$distinct = '';
		$whichauthor = '';
		$whichmimetype = '';
		$where = '';
		$limits = '';
		$join = '';
		$search = '';
		$groupby = '';
		$post_status_join = false;
		$page = 1;

		// ES
		$filter = array();
		$query = array();
		$sort = array();
		$fields = array();
		$from = -1;
		$size = -1;

		if ( !isset( $q['ignore_sticky_posts'] ) )
			$q['ignore_sticky_posts'] = false;

		if ( !isset($q['suppress_filters']) )
			$q['suppress_filters'] = false;

		if ( !isset($q['cache_results']) ) {
			if ( wp_using_ext_object_cache() )
				$q['cache_results'] = false;
			else
				$q['cache_results'] = true;
		}

		if ( !isset($q['update_post_term_cache']) )
			$q['update_post_term_cache'] = true;

		if ( !isset($q['update_post_meta_cache']) )
			$q['update_post_meta_cache'] = true;

		if ( !isset($q['post_type']) ) {
			if ( $this->is_search )
				$q['post_type'] = 'any';
			else
				$q['post_type'] = '';
		}
		$post_type = $q['post_type'];
		if ( !isset($q['posts_per_page']) || $q['posts_per_page'] == 0 )
			$q['posts_per_page'] = get_option('posts_per_page');
		if ( isset($q['showposts']) && $q['showposts'] ) {
			$q['showposts'] = (int) $q['showposts'];
			$q['posts_per_page'] = $q['showposts'];
		}
		if ( (isset($q['posts_per_archive_page']) && $q['posts_per_archive_page'] != 0) && ($this->is_archive || $this->is_search) )
			$q['posts_per_page'] = $q['posts_per_archive_page'];
		if ( !isset($q['nopaging']) ) {
			if ( $q['posts_per_page'] == -1 ) {
				$q['nopaging'] = true;
			} else {
				$q['nopaging'] = false;
			}
		}
		if ( $this->is_feed ) {
			$q['posts_per_page'] = get_option('posts_per_rss');
			$q['nopaging'] = false;
		}
		$q['posts_per_page'] = (int) $q['posts_per_page'];
		if ( $q['posts_per_page'] < -1 )
			$q['posts_per_page'] = abs($q['posts_per_page']);
		else if ( $q['posts_per_page'] == 0 )
			$q['posts_per_page'] = 1;

		if ( !isset($q['comments_per_page']) || $q['comments_per_page'] == 0 )
			$q['comments_per_page'] = get_option('comments_per_page');

		if ( $this->is_home && (empty($this->query) || $q['preview'] == 'true') && ( 'page' == get_option('show_on_front') ) && get_option('page_on_front') ) {
			$this->is_page = true;
			$this->is_home = false;
			$q['page_id'] = get_option('page_on_front');
		}

		if ( isset($q['page']) ) {
			$q['page'] = trim($q['page'], '/');
			$q['page'] = absint($q['page']);
		}

		switch ( $q['fields'] ) {
			case 'ids':
				$fields = array( $this->es_map['post_id'] );
				break;
			case 'id=>parent':
				$fields = array( $this->es_map['post_id'], $this->es_map['post_parent'] );
				break;
			default:
				if ( apply_filters( 'es_query_use_source', false ) ) {
					$fields = array( '_source' );
				} else {
					$fields = array( $this->es_map['post_id'] );
				}
		}

		if ( '' !== $q['menu_order'] ) {
			$filter[] = $this->dsl_terms( $this->es_map['menu_order'], $q['menu_order'] );
		}

		// The "m" parameter is meant for months but accepts datetimes of varying specificity
		if ( $q['m'] ) {
			$date = array( 'year' => substr( $q['m'], 0, 4 ) );
			$m_len = strlen( $q['m'] );
			if ( $m_len > 5 ) {
				$date['month'] = substr( $q['m'], 4, 2 );
			}
			if ( $m_len > 7 ) {
				$date['day'] = substr( $q['m'], 6, 2 );
			}
			if ( $m_len > 9 ) {
				$date['hour'] = substr( $q['m'], 8, 2 );
			}
			if ( $m_len > 11 ) {
				$date['minute'] = substr( $q['m'], 10, 2 );
			}
			if ( $m_len > 13 ) {
				$date['second'] = substr( $q['m'], 12, 2 );
				// If we have absolute precision, we can use a term filter instead of a range
				$filter[] = $this->dsl_terms( $this->es_map['post_date'], ES_WP_Date_Query::build_datetime( $date ) );
			} else {
				// We don't have second-level precision, so we need to build a range query from what we have
				$date_query = new ES_WP_Date_Query( array( 'after' => $date, 'before' => $date, 'inclusive' => true ) );
				$date_filter = $date_query->get_es_query();
				if ( ! empty( $date_filter ) ) {
					$filter[] = $date_filter;
				}
			}
		}
		unset( $date_query, $date_filter, $date, $m_len );

		// Handle the other individual date parameters
		$date_parameters = array();

		if ( '' !== $q['hour'] )
			$date_parameters['hour'] = $q['hour'];

		if ( '' !== $q['minute'] )
			$date_parameters['minute'] = $q['minute'];

		if ( '' !== $q['second'] )
			$date_parameters['second'] = $q['second'];

		if ( $q['year'] )
			$date_parameters['year'] = $q['year'];

		if ( $q['monthnum'] )
			$date_parameters['monthnum'] = $q['monthnum'];

		if ( $q['w'] )
			$date_parameters['week'] = $q['w'];

		if ( $q['day'] )
			$date_parameters['day'] = $q['day'];

		if ( $date_parameters ) {
			$date_query = new ES_WP_Date_Query( array( $date_parameters ) );
			$filter[] = $date_query->get_es_query();
		}
		unset( $date_parameters, $date_query );

		// Handle complex date queries
		if ( ! empty( $q['date_query'] ) ) {
			$this->date_query = new ES_WP_Date_Query( $q['date_query'] );
			$date_filter = $this->date_query->get_es_query();
			if ( ! empty( $date_filter ) ) {
				$filter[] = $date_filter;
			}
			unset( $date_filter );
		}


		// If we've got a post_type AND it's not "any" post_type.
		if ( !empty($q['post_type']) && 'any' != $q['post_type'] ) {
			foreach ( (array)$q['post_type'] as $_post_type ) {
				$ptype_obj = get_post_type_object($_post_type);
				if ( !$ptype_obj || !$ptype_obj->query_var || empty($q[ $ptype_obj->query_var ]) )
					continue;

				if ( ! $ptype_obj->hierarchical || strpos($q[ $ptype_obj->query_var ], '/') === false ) {
					// Non-hierarchical post_types & parent-level-hierarchical post_types can directly use 'name'
					$q['name'] = $q[ $ptype_obj->query_var ];
				} else {
					// Hierarchical post_types will operate through the
					$q['pagename'] = $q[ $ptype_obj->query_var ];
					$q['name'] = '';
				}

				// Only one request for a slug is possible, this is why name & pagename are overwritten above.
				break;
			} //end foreach
			unset( $ptype_obj );
		}

		if ( '' != $q['name'] ) {
			$q['name'] = sanitize_title_for_query( $q['name'] );
			$filter[] = $this->dsl_terms( $this->es_map['post_name'], $q['name'] );
		} elseif ( '' != $q['pagename'] ) {
			if ( isset($this->queried_object_id) ) {
				$reqpage = $this->queried_object_id;
			} else {
				if ( 'page' != $q['post_type'] ) {
					foreach ( (array)$q['post_type'] as $_post_type ) {
						$ptype_obj = get_post_type_object($_post_type);
						if ( !$ptype_obj || !$ptype_obj->hierarchical )
							continue;

						$reqpage = get_page_by_path($q['pagename'], OBJECT, $_post_type);
						if ( $reqpage )
							break;
					}
					unset($ptype_obj);
				} else {
					$reqpage = get_page_by_path($q['pagename']);
				}
				if ( !empty($reqpage) )
					$reqpage = $reqpage->ID;
				else
					$reqpage = 0;
			}

			$page_for_posts = get_option('page_for_posts');
			if ( ('page' != get_option('show_on_front') ) || empty( $page_for_posts ) || ( $reqpage != $page_for_posts ) ) {
				$q['pagename'] = sanitize_title_for_query( wp_basename( $q['pagename'] ) );
				$q['name'] = $q['pagename'];
				$filter[] = $this->dsl_terms( $this->es_map['post_id'], absint( $reqpage ) );
				$reqpage_obj = get_post( $reqpage );
				if ( is_object($reqpage_obj) && 'attachment' == $reqpage_obj->post_type ) {
					$this->is_attachment = true;
					$post_type = $q['post_type'] = 'attachment';
					$this->is_page = true;
					$q['attachment_id'] = $reqpage;
				}
			}
		} elseif ( '' != $q['attachment'] ) {
			$q['attachment'] = sanitize_title_for_query( wp_basename( $q['attachment'] ) );
			$q['name'] = $q['attachment'];
			$filter[] = $this->dsl_terms( $this->es_map['post_name'], $q['attachment'] );
		}


		if ( intval($q['comments_popup']) )
			$q['p'] = absint($q['comments_popup']);

		// If an attachment is requested by number, let it supersede any post number.
		if ( $q['attachment_id'] )
			$q['p'] = absint($q['attachment_id']);

		// If a post number is specified, load that post
		if ( $q['p'] ) {
			$filter[] = $this->dsl_terms( $this->es_map['post_id'], absint( $q['p'] ) );
		} elseif ( $q['post__in'] ) {
			$post__in = array_map( 'absint', $q['post__in'] );
			$filter[] = $this->dsl_terms( $this->es_map['post_id'], $post__in );
		} elseif ( $q['post__not_in'] ) {
			$post__not_in = array_map( 'absint', $q['post__not_in'] );
			$filter[] = array( 'not' => $this->dsl_terms( $this->es_map['post_id'], $post__not_in ) );
		}

		if ( is_numeric( $q['post_parent'] ) ) {
			$filter[] = $this->dsl_terms( $this->es_map['post_parent'], absint( $q['post_parent'] ) );
		} elseif ( $q['post_parent__in'] ) {
			$post_parent__in = array_map( 'absint', $q['post_parent__in'] );
			$filter[] = $this->dsl_terms( $this->es_map['post_parent'], $post_parent__in );
		} elseif ( $q['post_parent__not_in'] ) {
			$post_parent__not_in = array_map( 'absint', $q['post_parent__not_in'] );
			$filter[] = array( 'not' => $this->dsl_terms( $this->es_map['post_parent'], $post_parent__not_in ) );
		}

		if ( $q['page_id'] ) {
			if  ( ('page' != get_option('show_on_front') ) || ( $q['page_id'] != get_option('page_for_posts') ) ) {
				$q['p'] = $q['page_id'];
				$filter[] = $this->dsl_terms( $this->es_map['post_id'], absint( $q['page_id'] ) );
			}
		}

		// If a search pattern is specified, load the posts that match.
		if ( ! empty( $q['s'] ) ) {
			$search = $this->parse_search( $q );
		}

		/**
		 * Filter the search query.
		 *
		 * @since 3.0.0
		 *
		 * @param string   $search Search SQL for WHERE clause.
		 * @param WP_Query $this   The current WP_Query object.
		 */
		if ( ! empty( $search ) ) {
			$query[] = apply_filters_ref_array( 'es_posts_search', array( $search, &$this ) );
			if ( ! is_user_logged_in() ) {
				$filter[] = array( 'or' => array(
					$this->dsl_terms( $this->es_map['post_password'], '' ),
					array( 'missing' => array(
						'field'      => $this->es_map['post_password'],
						'existence'  => true,
						'null_value' => true
					) )
				) );
			}
		}

		// Taxonomies
		if ( ! $this->is_singular ) {
			$this->parse_tax_query( $q );
			$this->tax_query = new ES_WP_Tax_Query( $this->tax_query );

			$tax_filter = $this->tax_query->get_es_query();
			if ( ! empty( $tax_filter ) ) {
				$filter[] = $tax_filter;
			}
			unset( $tax_filter );
		}

		if ( $this->is_tax ) {
			if ( empty($post_type) ) {
				// Do a fully inclusive search for currently registered post types of queried taxonomies
				$post_type = array();
				$taxonomies = wp_list_pluck( $this->tax_query->queries, 'taxonomy' );
				foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $pt ) {
					$object_taxonomies = $pt === 'attachment' ? get_taxonomies_for_attachments() : get_object_taxonomies( $pt );
					if ( array_intersect( $taxonomies, $object_taxonomies ) )
						$post_type[] = $pt;
				}
				if ( ! $post_type )
					$post_type = 'any';
				elseif ( count( $post_type ) == 1 )
					$post_type = $post_type[0];

				// @todo: no good way to do this in ES; workarounds?
				$post_status_join = true;
			} elseif ( in_array('attachment', (array) $post_type) ) {
				// @todo: no good way to do this in ES; workarounds?
				$post_status_join = true;
			}
		}

		// Back-compat
		if ( ! empty( $this->tax_query->queries ) ) {
			$tax_query_in_and = wp_list_filter( $this->tax_query->queries, array( 'operator' => 'NOT IN' ), 'NOT' );
			if ( !empty( $tax_query_in_and ) ) {
				if ( !isset( $q['taxonomy'] ) ) {
					foreach ( $tax_query_in_and as $a_tax_query ) {
						if ( !in_array( $a_tax_query['taxonomy'], array( 'category', 'post_tag' ) ) ) {
							$q['taxonomy'] = $a_tax_query['taxonomy'];
							if ( 'slug' == $a_tax_query['field'] )
								$q['term'] = $a_tax_query['terms'][0];
							else
								$q['term_id'] = $a_tax_query['terms'][0];

							break;
						}
					}
				}

				$cat_query = wp_list_filter( $tax_query_in_and, array( 'taxonomy' => 'category' ) );
				if ( ! empty( $cat_query ) ) {
					$cat_query = reset( $cat_query );

					if ( ! empty( $cat_query['terms'][0] ) ) {
						$the_cat = get_term_by( $cat_query['field'], $cat_query['terms'][0], 'category' );
						if ( $the_cat ) {
							$this->set( 'cat', $the_cat->term_id );
							$this->set( 'category_name', $the_cat->slug );
						}
						unset( $the_cat );
					}
				}
				unset( $cat_query );

				$tag_query = wp_list_filter( $tax_query_in_and, array( 'taxonomy' => 'post_tag' ) );
				if ( ! empty( $tag_query ) ) {
					$tag_query = reset( $tag_query );

					if ( ! empty( $tag_query['terms'][0] ) ) {
						$the_tag = get_term_by( $tag_query['field'], $tag_query['terms'][0], 'post_tag' );
						if ( $the_tag )
							$this->set( 'tag_id', $the_tag->term_id );
						unset( $the_tag );
					}
				}
				unset( $tag_query );
			}
		}

		// @todo: hmmmm
		if ( !empty( $this->tax_query->queries ) || ! empty( $this->meta_query->queries ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}

		// Author/user stuff
		if ( ! empty( $q['author'] ) && $q['author'] != '0' ) {
			$q['author'] = addslashes_gpc( '' . urldecode( $q['author'] ) );
			$authors = array_unique( array_map( 'intval', preg_split( '/[,\s]+/', $q['author'] ) ) );
			foreach ( $authors as $author ) {
				$key = $author > 0 ? 'author__in' : 'author__not_in';
				$q[ $key ][] = abs( $author );
			}
			$q['author'] = implode( ',', $authors );
		}

		if ( ! empty( $q['author__not_in'] ) ) {
			$author__not_in = array_map( 'absint', array_unique( (array) $q['author__not_in'] ) );
			$filter[] = array( 'not' => $this->dsl_terms( $this->es_map['post_author'], $author__not_in ) );
		} elseif ( ! empty( $q['author__in'] ) ) {
			$author__in = array_map( 'absint', array_unique( (array) $q['author__in'] ) );
			$filter[] = $this->dsl_terms( $this->es_map['post_author'], $author__in );
		}

		// Author stuff for nice URLs
		if ( '' != $q['author_name'] ) {
			if ( strpos( $q['author_name'], '/' ) !== false ) {
				$q['author_name'] = explode( '/', $q['author_name'] );
				if ( $q['author_name'][ count( $q['author_name'] ) - 1 ] ) {
					$q['author_name'] = $q['author_name'][ count( $q['author_name'] ) - 1 ]; // no trailing slash
				} else {
					$q['author_name'] = $q['author_name'][ count( $q['author_name'] ) - 2 ]; // there was a trailing slash
				}
			}
			$q['author_name'] = sanitize_title_for_query( $q['author_name'] );
			$q['author'] = get_user_by( 'slug', $q['author_name'] );
			if ( $q['author'] ) {
				$q['author'] = $q['author']->ID;
			}
			$filter[] = $this->dsl_terms( $this->es_map['post_author'], absint( $q['author'] ) );
		}

		// MIME-Type stuff for attachment browsing
		if ( isset( $q['post_mime_type'] ) && '' != $q['post_mime_type'] ) {
			$es_mime = $this->post_mime_type_query( $q['post_mime_type'], $wpdb->posts );
			if ( ! empty( $es_mime['filters'] ) ) {
				$filter[] = $es_mime['filters'];
			}
			if ( ! empty( $es_mime['query'] ) ) {
				$query[] = array( 'or' => $es_mime['query'] );
			}
		}

		// @todo: hmmmmmm
		if ( empty( $q['order'] ) || ( ( strtolower( $q['order'] ) != 'asc' ) && ( strtolower( $q['order'] ) != 'desc' ) ) ) {
			$q['order'] = 'desc';
		}

		// Order by
		if ( empty( $q['orderby'] ) ) {
			$sort[] = array( $this->es_map['post_date'] => $q['order'] );
		} elseif ( 'none' == $q['orderby'] ) {
			// nothing to see here
		} elseif ( $q['orderby'] == 'post__in' && ! empty( $post__in ) ) {
			// Elasticsearch doesn't have an equivalent of this, so it may not work quite right.
			// However, we'll hang on to it and try our best.
			$orderby = "FIELD( {$wpdb->posts}.ID, $post__in )";
		} elseif ( $q['orderby'] == 'post_parent__in' && ! empty( $post_parent__in ) ) {
			// (see above)
			$orderby = "FIELD( {$wpdb->posts}.post_parent, $post_parent__in )";
		} else {
			// Used to filter values
			$allowed_keys = array( 'name', 'author', 'date', 'title', 'modified', 'menu_order', 'parent', 'ID', 'rand', 'comment_count' );
			if ( !empty($q['meta_key']) ) {
				$allowed_keys[] = $q['meta_key'];
				$allowed_keys[] = 'meta_value';
				$allowed_keys[] = 'meta_value_num';
			}
			$q['orderby'] = urldecode( $q['orderby'] );
			$q['orderby'] = addslashes_gpc( $q['orderby'] );

			foreach ( explode( ' ', $q['orderby'] ) as $i => $orderby ) {
				// Only allow certain values for safety
				if ( ! in_array( $orderby, $allowed_keys ) )
					continue;

				switch ( $orderby ) {
					case 'menu_order':
						$sort[] = array( $this->es_map['menu_order'] => $q['order'] );
						break;
					case 'ID':
						$sort[] = array( $this->es_map['post_id'] => $q['order'] );
						break;
					case 'rand':
						// There's no simple solution to this in ES
						$orderby = 'RAND() ';
						break;
					case $q['meta_key']:
					case 'meta_value':
					case 'meta_value_num':
						// casting doesn't work here, there's no ES equivalent
						$sort[] = array( sprintf( $this->es_map['post_meta.meta_value'], $q['meta_key'] ) => $q['order'] );
						break;
					case 'comment_count':
						$sort[] = array( $this->es_map['comment_count'] => $q['order'] );
						break;
					default:
						$sort[] = array( $this->es_map[ 'post_' . $orderby ] => $q['order'] );
				}

			}

			if ( empty( $sort ) ) {
				$sort[] = array( $this->es_map['post_date'] => $q['order'] );
			}
		}

		// Order search results by relevance only when another "orderby" is not specified in the query.
		if ( ! empty( $q['s'] ) ) {
			$search_orderby = array();
			if ( ( empty( $q['orderby'] ) && ! $this->is_feed ) || ( isset( $q['orderby'] ) && 'relevance' === $q['orderby'] ) ) {
				$search_orderby = array( '_score' );
			}

			/**
			 * Filter the ORDER BY used when ordering search results.
			 *
			 * @since 3.7.0
			 *
			 * @param string   $search_orderby The ORDER BY clause.
			 * @param WP_Query $this           The current WP_Query instance.
			 */
			$search_orderby = apply_filters( 'es_posts_search_orderby', $search_orderby, $this );
			if ( $search_orderby )
				$sort = $sort ? array_merge( $search_orderby, $sort ) : $search_orderby;
		}

		if ( is_array( $post_type ) && count( $post_type ) > 1 ) {
			$post_type_cap = 'multiple_post_type';
		} else {
			if ( is_array( $post_type ) )
				$post_type = reset( $post_type );
			$post_type_object = get_post_type_object( $post_type );
			if ( empty( $post_type_object ) )
				$post_type_cap = $post_type;
		}

		if ( 'any' == $post_type ) {
			$in_search_post_types = get_post_types( array('exclude_from_search' => false) );
			if ( empty( $in_search_post_types ) )
				// @todo Perhaps we should return a wp_error here instead?
				$filter[] = $this->dsl_terms( $this->es_map['post_id'], -1 );
			else
				$filter[] = $this->dsl_terms( $this->es_map['post_type'], $in_search_post_types );
		} elseif ( ! empty( $post_type ) ) {
			$filter[] = $this->dsl_terms( $this->es_map['post_type'], $post_type );
			if ( ! is_array( $post_type ) ) {
				$post_type_object = get_post_type_object ( $post_type );
			}
		} elseif ( $this->is_attachment ) {
			$filter[] = $this->dsl_terms( $this->es_map['post_type'], 'attachment' );
			$post_type_object = get_post_type_object ( 'attachment' );
		} elseif ( $this->is_page ) {
			$filter[] = $this->dsl_terms( $this->es_map['post_type'], 'page' );
			$post_type_object = get_post_type_object ( 'page' );
		} else {
			$filter[] = $this->dsl_terms( $this->es_map['post_type'], 'post' );
			$post_type_object = get_post_type_object ( 'post' );
		}

		$edit_cap = 'edit_post';
		$read_cap = 'read_post';

		if ( ! empty( $post_type_object ) ) {
			$edit_others_cap = $post_type_object->cap->edit_others_posts;
			$read_private_cap = $post_type_object->cap->read_private_posts;
		} else {
			$edit_others_cap = 'edit_others_' . $post_type_cap . 's';
			$read_private_cap = 'read_private_' . $post_type_cap . 's';
		}

		$user_id = get_current_user_id();

		if ( ! empty( $q['post_status'] ) ) {
			$statuswheres = array();
			$q_status = $q['post_status'];
			if ( ! is_array( $q_status ) )
				$q_status = explode(',', $q_status);
			$r_status = array();
			$p_status = array();
			$e_status = array();
			if ( in_array('any', $q_status) ) {
				$e_status = get_post_stati( array('exclude_from_search' => true) );
			} else {
				foreach ( get_post_stati() as $status ) {
					if ( in_array( $status, $q_status ) ) {
						if ( 'private' == $status )
							$p_status[] = $status;
						else
							$r_status[] = $status;
					}
				}
			}

			if ( empty( $q['perm'] ) || 'readable' != $q['perm'] ) {
				$r_status = array_merge($r_status, $p_status);
				unset( $p_status );
			}

			if ( ! empty( $e_status ) ) {
				// $statuswheres[] = "(" . join( ' AND ', $e_status ) . ")";
				$status_ands[] = array( 'not' => $this->dsl_terms( $this->es_map['post_status'], $e_status ) );
			}
			if ( ! empty( $r_status ) ) {
				if ( !empty($q['perm'] ) && 'editable' == $q['perm'] && !current_user_can($edit_others_cap) ) {
					// $statuswheres[] = "($wpdb->posts.post_author = $user_id " . "AND (" . join( ' OR ', $r_status ) . "))";
					$status_ands[] = array( 'bool' => array( 'must' => array(
						$this->dsl_terms( $this->es_map['post_author'], $user_id ),
						$this->dsl_terms( $this->es_map['post_status'], $r_status )
					) ) );
				} else {
					// $statuswheres[] = "(" . join( ' OR ', $r_status ) . ")";
					$status_ands[] = $this->dsl_terms( $this->es_map['post_status'], $r_status );
				}
			}
			if ( ! empty( $p_status ) ) {
				if ( ! empty( $q['perm'] ) && 'readable' == $q['perm'] && ! current_user_can( $read_private_cap ) ) {
					// $statuswheres[] = "($wpdb->posts.post_author = $user_id " . "AND (" . join( ' OR ', $p_status ) . "))";
					$status_ands[] = array( 'bool' => array( 'must' => array(
						$this->dsl_terms( $this->es_map['post_author'], $user_id ),
						$this->dsl_terms( $this->es_map['post_status'], $p_status )
					) ) );
				} else {
					// $statuswheres[] = "(" . join( ' OR ', $p_status ) . ")";
					$status_ands[] = $this->dsl_terms( $this->es_map['post_status'], $p_status );
				}
			}
			if ( $post_status_join ) {
				// @todo: no good way to do this in ES...
				/*
				$join .= " LEFT JOIN $wpdb->posts AS p2 ON ($wpdb->posts.post_parent = p2.ID) ";
				foreach ( $statuswheres as $index => $statuswhere )
					$statuswheres[$index] = "($statuswhere OR ($wpdb->posts.post_status = 'inherit' AND " . str_replace($wpdb->posts, 'p2', $statuswhere) . "))";
				*/
			}
			$filter = array_merge( $filter, $status_ands );
		} elseif ( !$this->is_singular ) {
			$singular_states = array( 'publish' );

			// Add public states.
			$singular_states = array_merge( $singular_states, (array) get_post_stati( array( 'public' => true ) ) );

			if ( $this->is_admin ) {
				// Add protected states that should show in the admin all list.
				$singular_states = array_merge( $singular_states, (array) get_post_stati( array( 'protected' => true, 'show_in_admin_all_list' => true ) ) );
			}

			if ( is_user_logged_in() ) {
				// Add private states that are limited to viewing by the author of a post or someone who has caps to read private states.
				$private_states = get_post_stati( array('private' => true) );
				$singular_states_ors = array();
				foreach ( (array) $private_states as $state ) {
					// @todo: leaving off here
					if ( current_user_can( $read_private_cap ) ) {
						$singular_states[] = $state;
					} else {
						$singular_states_ors[] = array( 'and' => array(
							$this->dsl_terms( $this->es_map['post_author'], $user_id ),
							$this->dsl_terms( $this->es_map['post_status'], $state )
						) );
					}
				}
			}

			$singular_states_filter = $this->dsl_terms( $this->es_map['post_status'], array_unique( $singular_states ) );
			if ( ! empty( $singular_states_ors ) ) {
				$filter[] = array( 'or' => array_merge( $singular_states_filter, $singular_states_ors ) );
			} else {
				$filter[] = $singular_states_filter;
			}
			unset( $singular_states, $singular_states_filter, $singular_states_ors, $private_states );
		}

		if ( ! empty( $this->meta_query->queries ) ) {
			$filter[] = $this->meta_query->get_es_query( 'post' );
		}

		// Apply filters on the filter clause prior to paging so that any
		// manipulations to them are reflected in the paging by day queries.
		if ( !$q['suppress_filters'] ) {
			$filter = apply_filters_ref_array( 'es_query_filter', array( $filter, &$this ) );
		}

		// Paging
		if ( empty($q['nopaging']) && !$this->is_singular ) {
			$page = absint( $q['paged'] );
			if ( !$page )
				$page = 1;

			if ( empty( $q['offset'] ) ) {
				$from = ( $page - 1 ) * $q['posts_per_page'];
			} else { // we're ignoring $page and using 'offset'
				$from = absint( $q['offset'] );
			}
			$size = $q['posts_per_page'];
		}

		// Comments feeds
		// @todo: come back to this
		if ( 0 && $this->is_comment_feed && ( $this->is_archive || $this->is_search || !$this->is_singular ) ) {
			if ( $this->is_archive || $this->is_search ) {
				$cjoin = "JOIN $wpdb->posts ON ($wpdb->comments.comment_post_ID = $wpdb->posts.ID) $join ";
				$cwhere = "WHERE comment_approved = '1' $where";
				$cgroupby = "$wpdb->comments.comment_id";
			} else { // Other non singular e.g. front
				$cjoin = "JOIN $wpdb->posts ON ( $wpdb->comments.comment_post_ID = $wpdb->posts.ID )";
				$cwhere = "WHERE post_status = 'publish' AND comment_approved = '1'";
				$cgroupby = '';
			}

			if ( !$q['suppress_filters'] ) {
				$cjoin = apply_filters_ref_array('es_comment_feed_join', array( $cjoin, &$this ) );
				$cwhere = apply_filters_ref_array('es_comment_feed_where', array( $cwhere, &$this ) );
				$cgroupby = apply_filters_ref_array('es_comment_feed_groupby', array( $cgroupby, &$this ) );
				$corderby = apply_filters_ref_array('es_comment_feed_orderby', array( 'comment_date_gmt DESC', &$this ) );
				$climits = apply_filters_ref_array('es_comment_feed_limits', array( 'LIMIT ' . get_option('posts_per_rss'), &$this ) );
			}
			$cgroupby = ( ! empty( $cgroupby ) ) ? 'GROUP BY ' . $cgroupby : '';
			$corderby = ( ! empty( $corderby ) ) ? 'ORDER BY ' . $corderby : '';

			$this->comments = (array) $wpdb->get_results("SELECT $distinct $wpdb->comments.* FROM $wpdb->comments $cjoin $cwhere $cgroupby $corderby $climits");
			$this->comment_count = count($this->comments);

			$post_ids = array();

			foreach ( $this->comments as $comment )
				$post_ids[] = (int) $comment->comment_post_ID;

			$post_ids = join(',', $post_ids);
			$join = '';
			if ( $post_ids )
				$where = "AND $wpdb->posts.ID IN ($post_ids) ";
			else
				$where = "AND 0";
		}

		// Run cleanup on our filter and query
		$filter = array_filter( $filter );
		if ( ! empty( $filter ) ) {
			$filter = array( 'and' => $filter );
		}

		$query = array_filter( $query );
		if ( ! empty( $query ) ) {
			$query = array( 'and' => $query );
		}

		$pieces = array( 'filter', 'query', 'sort', 'fields', 'size', 'from' );

		// Apply post-paging filters on our clauses. Only plugins that
		// manipulate paging queries should use these hooks.
		if ( !$q['suppress_filters'] ) {
			$filter	= apply_filters_ref_array( 'es_posts_filter_paged',	array( $filter, &$this ) );
			$query	= apply_filters_ref_array( 'es_posts_query_paged',	array( $query, &$this ) );
			$sort	= apply_filters_ref_array( 'es_posts_sort',			array( $sort, &$this ) );
			$fields	= apply_filters_ref_array( 'es_posts_fields',		array( $fields, &$this ) );
			$size	= apply_filters_ref_array( 'es_posts_size',			array( $size, &$this ) );
			$from	= apply_filters_ref_array( 'es_posts_from',			array( $from, &$this ) );

			// Filter all clauses at once, for convenience
			$clauses = (array) apply_filters_ref_array( 'posts_clauses', array( compact( $pieces ), &$this ) );
			foreach ( $pieces as $piece )
				$$piece = isset( $clauses[ $piece ] ) ? $clauses[ $piece ] : '';
		}

		// Announce current selection parameters. For use by caching plugins.
		do_action( 'es_posts_selection', array( 'filter' => $filter, 'query' => $query, 'sort' => $sort, 'fields' => $fields, 'size' => $size, 'from' => $from ) );

		// Filter again for the benefit of caching plugins. Regular plugins should use the hooks above.
		if ( !$q['suppress_filters'] ) {
			$filter	= apply_filters_ref_array( 'es_posts_filter_request',	array( $filter, &$this ) );
			$query	= apply_filters_ref_array( 'es_posts_query_request',	array( $query, &$this ) );
			$sort	= apply_filters_ref_array( 'es_posts_sort_request',		array( $sort, &$this ) );
			$fields	= apply_filters_ref_array( 'es_posts_fields_request',	array( $fields, &$this ) );
			$size	= apply_filters_ref_array( 'es_posts_size_request',		array( $size, &$this ) );
			$from	= apply_filters_ref_array( 'es_posts_from_request',		array( $from, &$this ) );

			// Filter all clauses at once, for convenience
			$clauses = (array) apply_filters_ref_array( 'es_posts_clauses_request', array( compact( $pieces ), &$this ) );
			foreach ( $pieces as $piece )
				$$piece = isset( $clauses[ $piece ] ) ? $clauses[ $piece ] : '';
		}

		$this->es_args = array(
			'filter' => $filter,
			'query'  => $query,
			'sort'   => $sort,
			'fields' => $fields,
			'size'   => $size,
			'from'   => $from
		);

		// Remove empty criteria
		foreach ( $this->es_args as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $this->es_args[ $key ] );
			}
		}

		$old_args = $this->es_args;

		if ( !$q['suppress_filters'] ) {
			$this->es_args = apply_filters_ref_array( 'es_posts_request', array( $this->es_args, &$this ) );
		}

		if ( 'ids' == $q['fields'] || 'id=>parent' == $q['fields'] ) {
			$this->response = $this->query_es( $this->es_args );
			$this->set_posts( $q, $this->response );
			$this->post_count = count( $this->posts );
			$this->set_found_posts( $q, $this->response );

			return $this->posts;
		}

		$this->response = $this->query_es( $this->es_args );
		$this->set_posts( $q, $this->response );
		$this->set_found_posts( $q, $this->response );

		// The rest of this method is mostly core

		// Convert to WP_Post objects
		if ( $this->posts )
			$this->posts = array_map( 'get_post', $this->posts );

		// Raw results filter. Prior to status checks.
		if ( !$q['suppress_filters'] )
			$this->posts = apply_filters_ref_array('posts_results', array( $this->posts, &$this ) );

		// @todo: address this
		if ( 0 && !empty($this->posts) && $this->is_comment_feed && $this->is_singular ) {
			$cjoin = apply_filters_ref_array('es_comment_feed_join', array( '', &$this ) );
			$cwhere = apply_filters_ref_array('es_comment_feed_where', array( "WHERE comment_post_ID = '{$this->posts[0]->ID}' AND comment_approved = '1'", &$this ) );
			$cgroupby = apply_filters_ref_array('es_comment_feed_groupby', array( '', &$this ) );
			$cgroupby = ( ! empty( $cgroupby ) ) ? 'GROUP BY ' . $cgroupby : '';
			$corderby = apply_filters_ref_array('es_comment_feed_orderby', array( 'comment_date_gmt DESC', &$this ) );
			$corderby = ( ! empty( $corderby ) ) ? 'ORDER BY ' . $corderby : '';
			$climits = apply_filters_ref_array('es_comment_feed_limits', array( 'LIMIT ' . get_option('posts_per_rss'), &$this ) );
			$comments_request = "SELECT $wpdb->comments.* FROM $wpdb->comments $cjoin $cwhere $cgroupby $corderby $climits";
			$this->comments = $wpdb->get_results($comments_request);
			$this->comment_count = count($this->comments);
		}

		// Check post status to determine if post should be displayed.
		if ( !empty($this->posts) && ($this->is_single || $this->is_page) ) {
			$status = get_post_status($this->posts[0]);
			$post_status_obj = get_post_status_object($status);
			//$type = get_post_type($this->posts[0]);
			if ( !$post_status_obj->public ) {
				if ( ! is_user_logged_in() ) {
					// User must be logged in to view unpublished posts.
					$this->posts = array();
				} else {
					if  ( $post_status_obj->protected ) {
						// User must have edit permissions on the draft to preview.
						if ( ! current_user_can($edit_cap, $this->posts[0]->ID) ) {
							$this->posts = array();
						} else {
							$this->is_preview = true;
							if ( 'future' != $status )
								$this->posts[0]->post_date = current_time('mysql');
						}
					} elseif ( $post_status_obj->private ) {
						if ( ! current_user_can($read_cap, $this->posts[0]->ID) )
							$this->posts = array();
					} else {
						$this->posts = array();
					}
				}
			}

			if ( $this->is_preview && $this->posts && current_user_can( $edit_cap, $this->posts[0]->ID ) )
				$this->posts[0] = get_post( apply_filters_ref_array( 'the_preview', array( $this->posts[0], &$this ) ) );
		}

		// @todo: address this
		// Put sticky posts at the top of the posts array
		$sticky_posts = get_option('sticky_posts');
		if ( 0 && $this->is_home && $page <= 1 && is_array($sticky_posts) && !empty($sticky_posts) && !$q['ignore_sticky_posts'] ) {
			$num_posts = count($this->posts);
			$sticky_offset = 0;
			// Loop over posts and relocate stickies to the front.
			for ( $i = 0; $i < $num_posts; $i++ ) {
				if ( in_array($this->posts[$i]->ID, $sticky_posts) ) {
					$sticky_post = $this->posts[$i];
					// Remove sticky from current position
					array_splice($this->posts, $i, 1);
					// Move to front, after other stickies
					array_splice($this->posts, $sticky_offset, 0, array($sticky_post));
					// Increment the sticky offset. The next sticky will be placed at this offset.
					$sticky_offset++;
					// Remove post from sticky posts array
					$offset = array_search($sticky_post->ID, $sticky_posts);
					unset( $sticky_posts[$offset] );
				}
			}

			// If any posts have been excluded specifically, Ignore those that are sticky.
			if ( !empty($sticky_posts) && !empty($q['post__not_in']) )
				$sticky_posts = array_diff($sticky_posts, $q['post__not_in']);

			// Fetch sticky posts that weren't in the query results
			if ( !empty($sticky_posts) ) {
				$stickies = get_posts( array(
					'post__in' => $sticky_posts,
					'post_type' => $post_type,
					'post_status' => 'publish',
					'nopaging' => true
				) );

				foreach ( $stickies as $sticky_post ) {
					array_splice( $this->posts, $sticky_offset, 0, array( $sticky_post ) );
					$sticky_offset++;
				}
			}
		}

		if ( !$q['suppress_filters'] )
			$this->posts = apply_filters_ref_array('the_posts', array( $this->posts, &$this ) );

		// Ensure that any posts added/modified via one of the filters above are
		// of the type WP_Post and are filtered.
		if ( $this->posts ) {
			$this->post_count = count( $this->posts );

			$this->posts = array_map( 'get_post', $this->posts );

			if ( $q['cache_results'] )
				update_post_caches($this->posts, $post_type, $q['update_post_term_cache'], $q['update_post_meta_cache']);

			$this->post = reset( $this->posts );
		} else {
			$this->post_count = 0;
			$this->posts = array();
		}

		return $this->posts;
	}


	/**
	 * Generate SQL for the WHERE clause based on passed search terms.
	 *
	 * @since 3.7.0
	 *
	 * @global wpdb $wpdb
	 * @param array $q Query variables.
	 * @return string WHERE clause.
	 */
	protected function parse_search( &$q ) {
		global $wpdb;

		$search = '';

		// added slashes screw with quote grouping when done early, so done later
		$q['s'] = stripslashes( $q['s'] );
		if ( empty( $_GET['s'] ) && $this->is_main_query() )
			$q['s'] = urldecode( $q['s'] );
		// there are no line breaks in <input /> fields
		$q['s'] = str_replace( array( "\r", "\n" ), '', $q['s'] );

		// @todo: add a wildcard match here, I guess...
		// $n = ! empty( $q['exact'] ) ? '' : '%';

		$search = array( 'multi_match' => array(
			'query'    => $q['s'],
			'fields'   => array( 'title', 'content', 'author', 'tag', 'category' ),
			'operator' => 'and'
		) );

		return $search;
	}


	/**
	 * Convert MIME types into SQL.
	 *
	 * @todo hmmmmmmmmmmmmm
	 *
	 * @param string|array $post_mime_types List of mime types or comma separated string of mime types.
	 * @param string $table_alias Optional. Specify a table alias, if needed.
	 * @return string The SQL AND clause for mime searching.
	 */
	public function post_mime_type_query( $post_mime_types ) {
		$wildcards = array('', '%', '%/%');
		$strict_mime_types = array();
		$query = array();
		$filter = array();

		if ( is_string($post_mime_types) )
			$post_mime_types = array_map('trim', explode(',', $post_mime_types));
		foreach ( (array) $post_mime_types as $mime_type ) {
			$mime_type = preg_replace('/\s/', '', $mime_type);
			$slashpos = strpos($mime_type, '/');
			if ( false !== $slashpos ) {
				$mime_group = preg_replace('/[^-*.a-zA-Z0-9]/', '', substr($mime_type, 0, $slashpos));
				$mime_subgroup = preg_replace('/[^-*.+a-zA-Z0-9]/', '', substr($mime_type, $slashpos + 1));
				if ( empty($mime_subgroup) )
					$mime_subgroup = '*';
				else
					$mime_subgroup = str_replace('/', '', $mime_subgroup);
				$mime_pattern = "$mime_group/$mime_subgroup";
			} else {
				$mime_pattern = preg_replace('/[^-*.a-zA-Z0-9]/', '', $mime_type);
				if ( false === strpos($mime_pattern, '*') )
					$mime_pattern .= '/*';
			}


			if ( in_array( $mime_type, $wildcards ) )
				return '';

			if ( false !== strpos($mime_pattern, '*') ) {
				$mime_pattern = preg_replace( '/\*+/', '', $mime_pattern );
				$query[] = array( 'prefix' => array( $this->es_map['post_mime_type'] => $mime_pattern ) );
			} else {
				$strict_mime_types[] = $mime_pattern;
			}
		}

		if ( ! empty( $strict_mime_types ) ) {
			$filter = $this->dsl_terms( $this->es_map['post_mime_type'], $strict_mime_types );
		}

		return compact( 'filters', 'query' );
	}

	public static function dsl_terms( $field, $values, $args = array() ) {
		$type = is_array( $values ) ? 'terms' : 'term';
		return array( $type => array_merge( array( $field => $values ), $args ) );
	}

	public static function dsl_range( $field, $args ) {
		return array( 'range' => array( $field => array( $args ) ) );
	}
}