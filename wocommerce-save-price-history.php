<?php
/**
* Plugin Name: Woocommerce Save Product Price History
* Description: Saving history for all woocommerce products.
* Version: 0.1
* Author: Matic Pogladic
* Author URI: maticpogladic.com
*/

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

	register_activation_hook( __FILE__, 'wsh_create_db_table' );

	add_action('admin_menu', 'wsh_add_submenu_admin');

	function wsh_add_submenu_admin() {
	    add_submenu_page('woocommerce', __('Price History', 'wsh'), __('Price History', 'wsh'), 'manage_options', 'wsh-save-history-tab', 'wsh_save_history_settings');
	}

	function wsh_save_history_settings() {

	    echo '<div class="wrap"><div id="icon-tools" class="icon32"></div>';
	    echo '<h2 style="padding-bottom:15px; margin-bottom:20px; border-bottom:1px solid #ccc">' . __('Woocommerce Price History per Product', 'wsh') . '</h2>';

	    /**
	     * Settings default
	     */
	    if (isset($_POST['wsh_manual_run'])) {

	    	$is_price_update = wsh_run_prices_update();

	    	if( $is_price_update ){
	    		update_option('wsh_plugin_last_updated', current_time( 'mysql' ));
	    		wsh_notice('Product prices updated manually.', 'updated');
	    	} else {
	    		wsh_notice('Product prices failed to update.', 'error');
	    	}
	        
	    }
	    $softsdev_wps_plugin_settings = get_option('wsh_plugin_last_updated');
	    ?>
	    <p>Last updated: <?php echo $softsdev_wps_plugin_settings; ?></p>
	    <form action="<?php echo $_SERVER['PHP_SELF'] . '?page=wsh-save-history-tab' ?>" method="post">
	        <input class="button-large button-primary" type="submit" value="Run manual update" name="wsh_manual_run" />
	    </form>  <?php
	}

	/**
	 * Create table for storing all product prices
	 */
	function wsh_create_db_table() {

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name = $wpdb->prefix . 'prices_history_products';

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			product_id mediumint(9) NOT NULL,
			data text NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

    /**
     * Type: updated,error,update-nag
     */
	if (!function_exists('wsh_notice')) {
	    function wsh_notice($message, $type){
            $html = '<div class="{$type} notice">
			<p>'.$message.'</p>
			</div>';
			echo $html;
        }
    }

    function wsh_run_prices_update(){

    	global $woocommerce;
    	global $wpdb;

    	$table_name = $wpdb->prefix . "prices_history_products";

    	$results = $wpdb->get_results( "SELECT * FROM {$table_name}", ARRAY_A );

    	$args = array( 
    		'post_type' => array('product'),
    		'posts_per_page' => -1,
    	);

    	$products = get_posts($args);

    	foreach ($products as $product) {

    		$id = $product->ID;
    		$_product = wc_get_product( $id );

    		if( $_product->is_type('variable') ){

    			$variations = $_product->get_available_variations();

    			foreach ($variations as $variation) {

    				$id_variation = $variation['variation_id'];
    				$_product_variation = wc_get_product( $id_variation );

    				wsh_db_price_handler( $id_variation, $_product_variation, $results, $table_name );

    			}

    		} else {

    			wsh_db_price_handler( $id, $_product, $results, $table_name );

    		}
    	}

    	return true;

    }

    /**
     * Create or update row in db for product
     */
    
    /**
     * Creates row or updates row of product with id
     * @param  int $id
     * @param  object $product
     * @param  array $results
     * @param  string $table_name
     */
    function wsh_db_price_handler( $id, $_product, $results, $table_name ){

    	$position = array_search($id, array_column($results, 'product_id'));
    	$regular_price = $_product->get_regular_price();
    	$sale_price = $_product->get_sale_price();

		if( $position === false ){ //if not in db yet

			$product_prices_array = array(
	    		current_time( 'mysql' ) => array(
	    			'r_p'     => $regular_price,
	    			's_p'        => $sale_price,
	    		),
	    	);

			wsh_insert_first_prices( $table_name, $id, $product_prices_array );

		} else { //if already in database

			$prices_history = unserialize( $results[$position]['data'] );

			$current_prices = end($prices_history);

			//if prices did not change continue
			if( $regular_price == $current_prices['r_p'] && $sale_price == $current_prices['s_p'] ) return;

			$prices_history[current_time( 'mysql' )] = array(
    			'r_p'        => $regular_price,
    			's_p'        => $sale_price,
    		);

			wsh_update_prices( $table_name, $id, $prices_history  );

		}
    }

    /**
     * Insert new row for product with current prices
     */
    function wsh_insert_first_prices( $table_name, $id, $product_prices_array ){
    	global $wpdb;

    	$wpdb->insert( 
    		$table_name, 
    		array( 
    			'product_id' => $id, 
    			'data'       => serialize( $product_prices_array )
    		), 
    		array( 
    			'%d', 
    			'%s'
    		)
    	);
    }

    /**
     * Insert new row for product with current prices
     */
    function wsh_update_prices( $table_name, $id, $product_prices_array ){
    	global $wpdb;

    	$wpdb->update( 
    		$table_name, 
    		array( 
    			'data'       => serialize( $product_prices_array )
    		),
    		array( 'product_id' => $id ),
    		array( 
    			'%s'
    		),
    		array( '%d' )
    	);
    }
}

