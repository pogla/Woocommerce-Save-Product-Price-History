<?php
/**
* Plugin Name: Woocommerce Save Product Price History
* Description: Saving history for all woocommerce products.
* Version: 0.1
* Author: Matic Pogladic
* Author URI: maticpogladic.com
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

		} // End __construct()

		public function wsh_add_submenu_admin() {
		    add_submenu_page('woocommerce', __('Price History', 'wsh'), __('Price History', 'wsh'), 'manage_options', 'wsh-save-history-tab', array( $this,  'wsh_save_history_settings' ) );
		}

		public function wsh_save_history_settings() {

		    echo '<div class="wrap"><div id="icon-tools" class="icon32"></div>';
		    echo '<h2 style="padding-bottom:15px; margin-bottom:20px; border-bottom:1px solid #ccc">' . __('Woocommerce Price History per Product', 'wsh') . '</h2>';

		    /**
		     * Settings default
		     */
		    if (isset($_POST['wsh_manual_run'])) {

		    	$this->wsh_run_prices_update();

		    	update_option('wsh_prices_last_updated', current_time( 'mysql' ));
		    	$this->wsh_notice('Product prices updated manually.', 'updated');
		        
		    }

		    $wsh_last_run_price_update = get_option('wsh_prices_last_updated');

		    if (isset($_POST['wsh_setting'])) {
		        update_option('wsh_updating_settings', $_POST['wsh_setting']);
		        $this->wsh_notice('Update frequency setting is updated.', 'updated');
		        $this-> wsh_set_new_cron_job( $_POST['wsh_setting']['default_option_wsh_upd'] );

		    }

		    $wsh_updating_settings = get_option('wsh_updating_settings', array('default_option_wsh_upd'=>'none'));
		    $default_option_wsh_upd = $wsh_updating_settings['default_option_wsh_upd'];

		    ?>
			
			<form action="<?php echo $_SERVER['PHP_SELF'] . '?page=wsh-save-history-tab' ?>" method="post">
			    <div class="postbox " style="padding: 10px 0; margin: 10px 0px;background:none;border: none;box-shadow: none;">
			        <h3 class="hndle"><?php echo __('Choose when prices of products should update', 'wsh'); ?></h3>
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

		    <p>Last updated: <?php echo $wsh_last_run_price_update; ?></p>
		    <form action="<?php echo $_SERVER['PHP_SELF'] . '?page=wsh-save-history-tab' ?>" method="post">
		        <input class="button-large button-primary" type="submit" value="Run manual update" name="wsh_manual_run" />
		    </form>  <?php
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

        	global $woocommerce;
        	global $wpdb;

        	$table_name = $wpdb->prefix . self::$table_name;

        	//If table does not exist yet create it
        	if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        		$this->wsh_create_db_table();
        	}

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

        				$this->wsh_db_price_handler( $id_variation, $_product_variation, array_search($id_variation, array_column($results, 'product_id')) );

        			}

        		} else {

        			$this->wsh_db_price_handler( $id, $_product, array_search($id, array_column($results, 'product_id')) );

        		}
        	}

        }
        
        /**
         * Creates row or updates row of product with id
         * @param  int    $id 	id of product
         * @param  object $product   product object
         * @param  boolean  $position   if product id is in db or not yet
         */
        private function wsh_db_price_handler( $id, $_product, $position ){

        	$regular_price = $_product->get_regular_price();
        	$sale_price = $_product->get_sale_price();

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
         * Insert new row for product with current prices
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

        private function wsh_set_new_cron_job( $timing ){

        	wp_clear_scheduled_hook('wsh_task_update_prices');

        	if( $timing != 'none' ){
        		if (!wp_next_scheduled('wsh_task_update_prices')) {
        		    wp_schedule_event( time(), $timing, 'wsh_task_update_prices' );
        		}
        	}
        }

        public function wsh_deactivate_plugin(){
        	wp_clear_scheduled_hook('wsh_task_update_prices');
        }

	}

	$GLOBALS['wc_save_product_price_history'] = new WC_Save_Product_Price_History();

	}

}