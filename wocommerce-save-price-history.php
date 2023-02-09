<?php
/**
* Plugin Name: Woocommerce Save Product Price History
* Description: Saving history for all woocommerce products.
* Version: 0.1
* Author: Matic Pogladic
* Author URI: maticpogladic.com
*/

/*
This file is part of Woocommerce-Save-Product-Price-History.

Woocommerce-Save-Product-Price-History is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Woocommerce-Save-Product-Price-History is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Woocommerce-Save-Product-Price-History.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

	if ( ! class_exists( 'WC_Save_Product_Price_History' ) ) {

	class WC_Save_Product_Price_History {

		/**
		 * Database table name.
		 *
		 * @var string
		 */
		protected static $table_name = 'woocommerce_prices_history_products';

		/**
		 * Initialize the plugin.
		 */
		public function __construct() {

			register_activation_hook( __FILE__, array( $this,  'wsh_create_db_table' ) );
			register_deactivation_hook(__FILE__, array( $this,  'wsh_deactivate_plugin' ) );
			add_action( 'admin_menu', array( $this,  'wsh_add_submenu_admin' ), 10, 1 );
			add_action( 'wsh_task_update_prices', array( $this,  'wsh_run_prices_update' ), 10, 1 );

			// settings style
			add_action( 'load-woocommerce_page_wsh-save-history-tab' , array( &$this , 'wsh_enqueue_style_scripts' ) );

		} // End __construct()

		//Add styles and scripts
		public function wsh_enqueue_style_scripts(){

			wp_enqueue_style( 'wsh_admin_setting_style', plugin_dir_url( __FILE__ ) . '/assets/css/wocommerce-save-price-history-style.css', array() );
			wp_enqueue_style( 'wsh_wc_admin_style', plugin_dir_url( __FILE__ ) . '/assets/css/wc-admin.css', array() );

			wp_register_script( 'wsh_admin_setting_script', plugin_dir_url( __FILE__ ) . '/assets/js/wocommerce-save-price-history-script.js', array( 'jquery' ) );

		    wp_localize_script( 'wsh_admin_setting_script', 'securityObj', array(
		        'ajaxurl'   => admin_url( 'admin-ajax.php' ),
		        'security'  => wp_create_nonce( 'search-products' )
		    ));

		    wp_enqueue_script( 'wsh_admin_setting_script' );

			wp_enqueue_script( 'jquery-ui-autocomplete' );
			wp_enqueue_script( 'wsh_admin_setting_script_select2', plugin_dir_url( __FILE__ ) . '/assets/js/select2.min.js' , array('jquery'));

		}

		//Add submenu to Woocommerce menu item
		public function wsh_add_submenu_admin() {
		    add_submenu_page('woocommerce', __('Price History', 'wsh'), __('Price History', 'wsh'), 'manage_options', 'wsh-save-history-tab', array( $this,  'wsh_save_history_settings' ) );
		}

		//Output on admin page
		public function wsh_save_history_settings() {

		    echo '<div class="wrap"><div id="icon-tools" class="icon32"></div>';
		    echo '<h2 class="wsh_title">' . __('Woocommerce Price History per Product', 'wsh') . '</h2>';

		    $this->choose_run_form_handler();
		    $this->manual_run_form_handler();
		    $this->show_product_history_form_handler();

		}

		/**
		 * Outputs table with history prices
		 */
		private function show_product_history_form_handler(){

			if( isset($_POST['wsh_product_id_to_search']) ){
				$searched_ID = $_POST['wsh_product_id_to_search'];
			}

			?>	

			<form action="<?php echo $_SERVER['PHP_SELF'] . '?page=wsh-save-history-tab' ?>" method="post">
				<h2><?php echo __('Get prices history of product', 'wsh'); ?></h2>
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="woocommerce_price_thousand_sep">Product ID</label>
							</th>
							<td class="forminp forminp-text" id="wsh_autocomplete_selector">
								<input type="hidden" class="js-example-basic-multiple" name="wsh_product_id_to_search">
							</td>
						</tr>
					</tbody>
				</table>
				<input class="button-large button-primary" type="submit" value="Show history" />
			</form>

			<?php

			if( !empty( $searched_ID ) ){

				$_product = wc_get_product( $searched_ID );

				if( $_product->is_type('variable') ){

					$variations = $_product->get_available_variations();

					foreach ($variations as $variation) {
						$this->show_table_prices_history( $variation['variation_id'], $searched_ID );
					}

				} else {
					$this->show_table_prices_history( trim( $searched_ID ) );
				}

			}
		}

		/**
		 * Form showing prices history of chosen product id
		 */
		private function show_table_prices_history( $id, $parent_id = null ){

			global $wpdb;

			$table_name = $wpdb->prefix . self::$table_name;

			$prices_data = $wpdb->get_row( "SELECT data FROM $table_name WHERE product_id = $id", ARRAY_N );

			if( empty( $prices_data ) ){

				echo "<h2>". __('No product with id', 'wsh') . ": " . $id . "</h2>";
				return;

			}

			$currency = get_woocommerce_currency_symbol();
			$_product = wc_get_product( $id );
			$sale_price = $_product->get_sale_price();

			?>
			
			<br>
			<br>
			<h2><?php echo get_the_title( $id ); ?></h2>
			<p>Regular Price: <?php echo $_product->get_regular_price() . " " . $currency ?><br>Sale Price: <?php echo ( !empty( $sale_price ) ? $sale_price . " " . $currency : "/" ); ?></p>
			<a href="<?php echo get_site_url(); ?>/wp-admin/post.php?post=<?php echo !empty( $parent_id ) ? $parent_id : $id; ?>&action=edit" target="_blank"><?php echo __('Edit', 'wsh'); ?></a>
			<a href="<?php echo !empty( $parent_id ) ? get_permalink($parent_id) : get_permalink($id); ?>" target="_blank"><?php echo __('View', 'wsh'); ?></a>
			<br><br>
			<table class="wc-shipping-classes widefat" id="table-product-history-prices">
				<thead>
					<tr>
						<th><?php echo __('Date', 'wsh'); ?></th>
						<th><?php echo __('Regular price', 'wsh'); ?></th>
						<th><?php echo __('Sale price', 'wsh'); ?></th>
					</tr>
				</thead>
				<tbody>

					<?php
						$prices_data = unserialize($prices_data[0]);
						foreach ($prices_data as $time => $prices) {
							echo "<tr>";
							echo "<td>" . $time . "</td>";
							echo "<td>" . $prices['r_p'] . $currency . "</td>";
							echo "<td>" . ( !empty( $prices['s_p'] ) ? $prices['s_p'] . $currency : "/" ) . "</td>";
							echo "</tr>";
						}
					?>

				</tbody>
			</table>

			<?php
		}

		/**
		 * Form for manually updating the prices of all the products
		 */
		private function manual_run_form_handler(){

			if (isset($_POST['wsh_manual_run'])) {

				$this->wsh_run_prices_update();
				$this->wsh_notice('Product prices updated manually.', 'updated');
			    
			}

			$wsh_last_run_price_update = get_option('wsh_prices_last_updated');

			?>

		    <form action="<?php echo $_SERVER['PHP_SELF'] . '?page=wsh-save-history-tab' ?>" method="post">
	    		<h2><?php echo __('Manually update prices', 'wsh'); ?></h2>
	    	    <p>Last updated: <?php echo $wsh_last_run_price_update; ?></p>
		        <input class="button-large button-primary" type="submit" value="Run manual update" name="wsh_manual_run" />
		    </form><br><br>

		    <?php
		}

		/**
		 * Form for choosing cron schedule in admin area
		 */
		private function choose_run_form_handler(){

			if (isset($_POST['wsh_setting'])) {
			    update_option('wsh_updating_settings', $_POST['wsh_setting']);
			    $this->wsh_notice('Frequency setting is updated.', 'updated');
			    $this-> wsh_set_new_cron_job( $_POST['wsh_setting']['default_option_wsh_upd'] );

			}

			$wsh_updating_settings = get_option('wsh_updating_settings', array('default_option_wsh_upd'=>'none'));
			$default_option_wsh_upd = $wsh_updating_settings['default_option_wsh_upd'];

			?>
				<form action="<?php echo $_SERVER['PHP_SELF'] . '?page=wsh-save-history-tab' ?>" method="post" class="wsh_setting_form">
				    <div class="postbox">
				        <h2><?php echo __('Choose when prices of products should update', 'wsh'); ?></h2>
				        <select name="wsh_setting[default_option_wsh_upd]">
				            <option value="none" <?php selected( $default_option_wsh_upd, 'none' ) ?>>Do not update scheduled</option>
				            <option value="hourly" <?php selected( $default_option_wsh_upd, 'hourly' ) ?>>Update every hour</option>
				            <option value="daily" <?php selected( $default_option_wsh_upd, 'daily' ) ?>>Update daily</option>
				            <option value="twicedaily" <?php selected( $default_option_wsh_upd, 'twicedaily' ) ?>>Update two times per day</option>
				        </select>
				        <br>
				    </div>
				    <input class="button-large button-primary" type="submit" value="Save changes" />
				</form>
			<?php
		}

		/**
		 * Create table for storing all product prices
		 */
		public function wsh_create_db_table() {

			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table_name = $wpdb->prefix . self::$table_name;

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
	    public function wsh_notice($message, $type){
            $html = '<div class="{$type} notice">
			<p>'.$message.'</p>
			</div>';
			echo $html;
        }

        /**
         * Go through all products and update prices in database table
         */
        public function wsh_run_prices_update(){

        	global $wpdb;

        	$table_name = $wpdb->prefix . self::$table_name;

        	//If table does not exist yet create it
        	if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        		$this->wsh_create_db_table();
        	}

        	//get table data
        	$results = $wpdb->get_results( "SELECT * FROM {$table_name}", ARRAY_A );

        	$args = array(
        		'post_type'      => array('product'),
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

                        $this->wsh_db_price_handler( $id_variation, $_product_variation, $results );

        			}

        		} else {

        			$this->wsh_db_price_handler( $id, $_product, $results );

        		}
        	}

        	update_option('wsh_prices_last_updated', current_time( 'mysql' ));
        }
        
        /**
         * Creates row or updates row of product with id
         * @param  int    $id 	id of product
         * @param  object $product   product object
         * @param  boolean  $position   if product id is in db or not yet
         */
        private function wsh_db_price_handler( $id, $_product, $results ){

        	$regular_price = $_product->get_regular_price();
        	$sale_price = $_product->get_sale_price();

        	$position = array_search($id, array_column($results, 'product_id'));

    		if( $position === false ){ //if not in db yet

    			$product_prices_array = array(
    	    		current_time( 'mysql' ) => array(
    	    			'r_p' => $regular_price,
    	    			's_p' => $sale_price,
    	    		),
    	    	);

    			$this->wsh_insert_first_prices( $id, $product_prices_array );

    		} else { //if already in database

    			$prices_history = unserialize( $results[$position]['data'] );

    			$current_prices = end($prices_history);

    			//if prices did not change continue
    			if( $regular_price == $current_prices['r_p'] && $sale_price == $current_prices['s_p'] ) return;

    			$prices_history[current_time( 'mysql' )] = array(
        			'r_p' => $regular_price,
        			's_p' => $sale_price,
        		);

    			$this->wsh_update_prices( $id, $prices_history  );

    		}
        }

        /**
         * Insert new row for product with current prices
         */
        private function wsh_insert_first_prices( $id, $product_prices_array ){
        	global $wpdb;

        	$table_name = $wpdb->prefix . self::$table_name;

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
         * Insert new row in db for product with current active prices
         */
        private function wsh_update_prices( $id, $product_prices_array ){
        	global $wpdb;

        	$table_name = $wpdb->prefix . self::$table_name;

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

        /**
         * Set new cron job upon select
         */
        private function wsh_set_new_cron_job( $timing ){

        	wp_clear_scheduled_hook('wsh_task_update_prices');

        	if( $timing != 'none' ){
        		if (!wp_next_scheduled('wsh_task_update_prices')) {
        		    wp_schedule_event( time(), $timing, 'wsh_task_update_prices' );
        		}
        	}
        }

        /**
         * Clear cron job on deactivation
         */
        public function wsh_deactivate_plugin(){
        	wp_clear_scheduled_hook('wsh_task_update_prices');
        }

	}

	$GLOBALS['wc_save_product_price_history'] = new WC_Save_Product_Price_History();

	}

}