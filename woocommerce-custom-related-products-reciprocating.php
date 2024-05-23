<?php
/*
Plugin Name: Custom Reciprocating Related Products for WooCommerce
Description: Select your own related products instead of pulling them in by category and have them reciprocate.
Version:     2.0
Plugin URI:  https://github.com/ddegil/custom-related-products-reciprocating-for-woocommerce
Author:      Scott Nelle (abonded) modified by David de Gil
Author URI:  https://www.coolcybercats.com
*/

/**
 * Force related products to show if some have been selected.
 * This is required for WooCommerce 3.0, which will not display products if
 * There are no categories or tags.
 *
 * @param bool $result Whether or not we should force related posts to display.
 * @param int $product_id The ID of the current product.
 *
 * @return bool Modified value - should we force related products to display?
 */
function crpr_force_display( $result, $product_id ) {
	$related_ids = get_post_meta( $product_id, '_related_ids', true );
	return empty( $related_ids ) ? $result : true;
}
add_filter( 'woocommerce_product_related_posts_force_display', 'crpr_force_display', 10, 2 );

/**
 * Determine whether we want to consider taxonomy terms when selecting related products.
 * This is required for WooCommerce 3.0.
 *
 * @param bool $result Whether or not we should consider tax terms during selection.
 * @param int $product_id The ID of the current product.
 *
 * @return bool Modified value - should we consider tax terms during selection?
 */
function crpr_taxonomy_relation( $result, $product_id ) {
	$related_ids = get_post_meta( $product_id, '_related_ids', true );
	if ( ! empty( $related_ids ) ) {
		return false;
	} else {
		return 'none' === get_option( 'crpr_empty_behavior' ) ? false : $result;
	}
}
add_filter( 'woocommerce_product_related_posts_relate_by_category', 'crpr_taxonomy_relation', 10, 2 );
add_filter( 'woocommerce_product_related_posts_relate_by_tag', 'crpr_taxonomy_relation', 10, 2 );

/**
 * Add related products selector to product edit screen
 */
function crpr_select_related_products() {
	global $post, $woocommerce;
	$product_ids = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_related_ids', true ) ) );

	?>
	<div class="options_group">
		<p class="form-field">
			<label for="related_ids"><?php _e( 'Related Products', 'woocommerce' ); ?></label>
			<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="related_ids" name="related_ids[]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-exclude="<?php echo intval( $post->ID ); ?>">
				<?php
					foreach ( $product_ids as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( is_object( $product ) ) {
							echo '<option value="' . esc_attr( $product_id ) . '"' . selected( true, true, false ) . '>' . wp_kses_post( $product->get_formatted_name() ) . '</option>';
						}
					}
				?>
			</select> <?php echo wc_help_tip( __( 'Related products are displayed on the product detail page.', 'woocommerce' ) ); ?>
		</p>

	</div>
	<?php
}
add_action('woocommerce_product_options_related', 'crpr_select_related_products');

/**
 * Save related products selector on product edit screen.
 *
 * @param int $post_id ID of the post to save.
 * @param obj WP_Post object.
 */
function crpr_save_related_products( $post_id, $post ) {
	global $woocommerce;
    $related = array();
	if ( isset( $_POST['related_ids'] ) ) {
		$related = array();
		$ids = $_POST['related_ids'];
		foreach ( $ids as $id ) {
			if ( $id && $id > 0 ) { $related[] = absint( $id ); }
		}
		update_post_meta( $post_id, '_related_ids', $related );

		// dbg
        //$related = ((get_option('crpr_reciprocate')) ? 'checked' : '');
		if (get_option('crpr_reciprocate') ) {
			foreach ($_POST['related_ids'] as $id) {
				if ($id && $id > 0) {
					crpr_save_reciprocating_product($post_id, $id);
				}
			}
		}
	} else {
		delete_post_meta( $post_id, '_related_ids' );
	}


}
add_action( 'woocommerce_process_product_meta', 'crpr_save_related_products', 10, 2 );

/**
 * Save reciprocating product selector on product edit screen.
 *
 * @param int $post_id ID of the post to save.
 * @param obj WP_Post object.
 */
function crpr_save_reciprocating_product($current_id, $relate_id) {
    global $woocommerce;
	$related_ids = array_filter(array_map('absint', (array) get_post_meta($relate_id, '_related_ids', true))); //get_post_meta( $relate_id, '_related_ids', true );
 
	if (!is_array($related_ids) || empty($related_ids)) {
        $related_ids = array();
    }

	if (in_array($current_id, $related_ids)) {
        return; // id already in array, no change
    }
	array_push($related_ids, $current_id); // add the id and save
	update_post_meta($relate_id, '_related_ids', $related_ids);
}


/**
 * Filter the related product query args.
 *
 * @param array $query Query arguments.
 * @param int $product_id The ID of the current product.
 *
 * @return array Modified query arguments.
 */
function crpr_filter_related_products( $query, $product_id ) {
	$related_ids = get_post_meta( $product_id, '_related_ids', true );
	if ( ! empty( $related_ids ) && is_array( $related_ids ) ) {
		$related_ids = implode( ',', array_map( 'absint', $related_ids ) );
		$query['where'] .= " AND p.ID IN ( {$related_ids} )";
	}
	return $query;
}
add_filter( 'woocommerce_product_related_posts_query', 'crpr_filter_related_products', 20, 2 );


/**
 * Create the menu item.
 */
function crpr_create_menu() {
	add_submenu_page( 'woocommerce', 'Custom Related Products', 'Custom Related Products', 'manage_options', 'custom_related_products', 'crpr_settings_page');
}
add_action('admin_menu', 'crpr_create_menu', 99);

/**
 * Create the settings page.
 */
function crpr_settings_page() {
	if ( isset($_POST['submit_custom_related_products']) && current_user_can('manage_options') ) {
		check_admin_referer( 'custom_related_products', '_custom_related_products_nonce' );

		// save settings
		if (isset($_POST['crpr_empty_behavior']) && $_POST['crpr_empty_behavior'] != '') {
			update_option( 'crpr_empty_behavior', $_POST['crpr_empty_behavior'] );
		}
		else {
			delete_option( 'crpr_empty_behavior' );
		}

        if (isset($_POST['reciprocate_related_ids'])) {
            update_option('crpr_reciprocate', true);
        } else {
            delete_option('crpr_reciprocate');
        }

		echo '<div id="message" class="updated"><p>Settings saved</p></div>';
	}

	?>
	<div class="wrap" id="custom-related-products">
		<h2>Custom Related Products</h2>
	<?php
	$behavior_none_selected = (get_option( 'crpr_empty_behavior' ) == 'none') ? 'selected="selected"' : '';
	$reciprocate = (get_option( 'crpr_reciprocate' )) ? 'checked' : '';
	echo '
		<form method="post" action="admin.php?page=custom_related_products">
			'.wp_nonce_field( 'custom_related_products', '_custom_related_products_nonce', true, false ).'
			<p><label for="crpr_empty_behavior">If I have not selected related products:</label>
				<select id="crpr_empty_behavior" name="crpr_empty_behavior">
					<option value="">Select random related products by category</option>
					<option value="none" '.$behavior_none_selected.'>Don&rsquo;t show any related products</option>
				</select>
			</p>
			<p><label for="reciprocate_related_ids">Reciprocate related products:</label>
				<input type="checkbox" class="checkbox" id="reciprocate_related_ids" name="reciprocate_related_ids" ' . $reciprocate . ' />
			</p>
			<p>
				<input type="submit" name="submit_custom_related_products" value="Save" class="button button-primary" />
			</p>
		</form>
	';
	?>
	</div>

	<?php
} // end settings page
