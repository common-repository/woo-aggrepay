<?php
/*
 * Plugin Name: AggrePay WooCommerce v2
 * Plugin URI: https://aggrepay.in
 * Description: AggrePay Payment Gateway for WooCommerce
 * Version: 1.0.9
 * Stable tag: 1.0.9
 * Author: AggrePay PSPL
 * WC tested up to: 4.4.1
 * Author URI: https://profiles.wordpress.org/aggrepay/

*/

$bd=ABSPATH.'wp-content/plugins/'.dirname( plugin_basename( __FILE__ ) );

add_action('plugins_loaded', 'woocommerce_aggrepay_init', 0);

function woocommerce_aggrepay_init() {

	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	/**
	 * Localisation
	 */
	load_plugin_textdomain('wc-aggrepay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');

	if(!empty($_GET['msg']) && $_GET['msg']!=''){
		add_action('the_content', 'showAggrepayMessage');
	}

	function showAggrepayMessage($content){
		return '<div class="box '.htmlentities($_GET['type']).'-box">'.htmlentities(urldecode($_GET['msg'])).'</div>'.$content;
	}
	/**
	 * Gateway class
	 */
	class WC_Aggrepay extends WC_Payment_Gateway {
		protected $msg = array();
		public function __construct(){
			global $wpdb;
			$this -> id = 'aggrepay';
			$this -> method_title = __('AggrePay Payments', 'aggrepay');
			$this -> icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/aggrepay.png';
			$this -> has_fields = false;
			$this -> init_form_fields();
			$this -> init_settings();
			$this -> title = 'AggrePay Payments'; //$this -> settings['title'];
			$this -> description = $this -> settings['description'];
			$this -> gateway_mode = $this -> settings['gateway_mode'];
			$this -> payment_url = $this -> settings['payment_url'];
			$this -> redirect_page_id = $this -> settings['redirect_page_id'];
			$this -> aggrepay_key = $this -> settings['aggrepay_key'];
			$this -> aggrepay_salt = $this -> settings['aggrepay_salt'];
			$this -> msg['message'] = "";
			$this -> msg['class'] = "";

			add_action('init', array(&$this, 'check_aggrepay_response'));
			//update for woocommerce >2.0
			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_aggrepay_response' ) );

			add_action('valid-aggrepay-request', array(&$this, 'SUCCESS'));

			if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
			} else {
				add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
			}

			add_action('woocommerce_receipt_aggrepay', array(&$this, 'receipt_page'));
			
		}

		function init_form_fields(){

			$this -> form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'aggrepay'),
					'type' => 'checkbox',
					'label' => __('Enable AggrePay Payments Module.', 'aggrepay'),
					'default' => 'no'),
				'description' => array(
					'title' => __('Description:', 'aggrepay'),
					'type' => 'textarea',
					'description' => __('This controls the description which the user sees during checkout.', 'aggrepay'),
					'default' => __('Use AggrePay for a Secure Online Transaction .', 'aggrepay')),
				'gateway_mode' => array(
					'title' => __('Gateway Mode', 'aggrepay'),
					'type' => 'select',
					'options' => array("0"=>"Select","TEST"=>"TEST","LIVE"=>"LIVE"),
					'description' => __('Mode of gateway subscription.','aggrepay')
				),
				'aggrepay_key' => array(
					'title' => __('AggrePay Payments API Key', 'aggrepay'),
					'type' => 'text',
					'description' =>  __('API Key Provided by AggrePay Payments Solutions Private Limited via E-Mail', 'aggrepay')
				),
				'aggrepay_salt' => array(
					'title' => __('AggrePay Payments Salt', 'aggrepay'),
					'type' => 'text',
					'description' =>  __('Salt Key Provided by AggrePay Payments Solutions Private Limited via E-Mail.', 'aggrepay')
				),
				'payment_url' => array(
					'title' => __('AggrePay Payments API URL', 'aggrepay'),
					'type' => 'text',
					'description' =>  __('For Advanced Users.', 'aggrepay'),
					'default' => __('https://biz.aggrepaypayments.com/v2/paymentrequest', 'aggrepay')
				),
				'redirect_page_id' => array(
					'title' => __('Return Page'),
					'type' => 'select',
					'options' => $this -> get_pages('Select Page'),
					'description' => "URL of success page"
				)
			);
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 **/
		public function admin_options(){
			echo '<h3>'.__('AggrePay', 'aggrepay').'</h3>';
			echo '<p>'.__('Use AggrePay to accept money online !').'</p>';
			echo '<script src="https://code.jquery.com/jquery-3.2.1.min.js"></script>';
			echo '<table class="form-table">';
			$this -> generate_settings_html();
			echo '</table>';

		}

		/**
		 *  There are no payment fields for Citrus, but we want to show the description if set.
		 **/
		function payment_fields(){
			if($this -> description) echo wpautop(wptexturize($this -> description));
		}

		/**
		 * Receipt Page
		 **/
		function receipt_page($order){
			echo '<p>'.__('Thank you for your order, please click the button below to pay.', 'aggrepay').'</p>';
			echo $this -> generate_aggrepay_form($order);
		}

		/**
		 * Process the payment and return the result
		 **/
		function process_payment($order_id){
			$order = new WC_Order($order_id);

			if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
				return array(
					'result' => 'success',
					'redirect' => add_query_arg('order', $order->id,
						add_query_arg('key', $order->order_key, $order->get_checkout_payment_url(true)))
				);
			}
			else {
				return array(
					'result' => 'success',
					'redirect' => add_query_arg('order', $order->id,
						add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
				);
			}
		}
		/**
		 * Check for valid Citrus server callback
		 **/
		function check_Aggrepay_response(){

			global $woocommerce;

			$postdata = $_POST;

			$hash_columns=$_POST;
			unset($hash_columns['hash']);
			ksort($hash_columns);

			$hash_data = $this->aggrepay_salt;

			foreach ($hash_columns as $key=>$value) {
				if (strlen($value) > 0) {
					$hash_data .= '|' . $value;
				}
			}
			$hash = null;
			if (strlen($hash_data) > 0) {
				$hash = strtoupper(hash("sha512", $hash_data));
			}
			$order_id = $postdata['order_id'];
			$order_id = explode('_', $order_id)[0];
			$amount = $postdata['amount'];
			$txnid = $postdata['transaction_id'];
			$order = new WC_Order($order_id);

			if($hash == $postdata['hash']){
				if($postdata['response_code'] == 0){
					$this -> msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful with following order details: 
								
							<br> 
								Order Id: $order_id <br/>
								Amount: $amount 
								<br />
								
									
						We will be shipping your order to you soon.";

					$this -> msg['class'] = 'success';

					if($order -> status == 'processing' || $order -> status == 'completed' )
					{
						//do nothing
					}
					else
					{
						//complete the order
						$order -> payment_complete();
						$order -> add_order_note('AggrePay Payments has processed the payment. Transaction Id: '. $txnid);
						$order -> add_order_note($this->msg['message']);
						$order -> add_order_note("Paid By AggrePay Payments");
						$woocommerce -> cart -> empty_cart();
					}

					if (function_exists('wc_add_notice')) {
						wc_add_notice( $this->msg['message'], $this->msg['class'] );
					}
					else {
							$woocommerce->add_message($this->msg['message']);
					}
					$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
					$redirect_url = $redirect_url ."order-received/".$order_id."/?key=".$order->order_key;
					wp_redirect( $redirect_url );
					exit;
				}else {
					//tampered
					$this->msg['class'] = 'error';
					$this->msg['message'] = "Transaction failed.Please try again";
					$order -> update_status('failed');
					$order -> add_order_note('Failed');
					$order -> add_order_note($this->msg['message']);
				}
			}else{
				$this -> msg['class'] = 'error';
				$this -> msg['message'] = "The transaction has been declined.";
			}

			//manage msessages
			if (function_exists('wc_add_notice')) {
				wc_add_notice( $this->msg['message'], $this->msg['class'] );
			}
			else {
				if($this->msg['class']=='success'){
					$woocommerce->add_message($this->msg['message']);
				}
				else{
					$woocommerce->add_error($this->msg['message']);
				}
				$woocommerce->set_messages();
			}

			$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
			wp_redirect( $redirect_url );
			exit;

		}

		/*
		 //Removed For WooCommerce 2.0
		function showMessage($content){
			 return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
		 }*/

		/**
		 * Generate Citrus button link
		 **/
		public function generate_aggrepay_form($order_id){

			global $woocommerce;
			$order = new WC_Order($order_id);

			$billing_info = json_decode($order)->billing;
			$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
			//For wooCoomerce 2.0
			$redirect_url = add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
			$redirect_url = add_query_arg( 'pg',$this -> title, $redirect_url );  //pass gateway selection in response
			$order_id = $order_id.'_'.date("ymds");

			$action = $this -> payment_url;

			$data = Array();
			$data['api_key'] = $this->aggrepay_key;
			$data['order_id'] = $order_id;
			$data['currency'] = get_woocommerce_currency();
			$data['name'] = $billing_info->first_name.' '.$billing_info->last_name;
			$data['email'] = $billing_info->email;
			$data['amount'] = $order->get_total();
			$data['phone'] = $billing_info->phone;
			$data['address_line_1'] = $billing_info->address_1;
			$data['address_line_2'] = $billing_info->address_2;
			$data['city'] = $billing_info->city;
			if($billing_info->country == 'IN'){
				$data['country'] = 'IND';
			}
			$data['description'] = 'Product Information';
			$data['state'] = $billing_info->state;
			$data['zip_code'] = $billing_info->postcode;
			$data['return_url'] = $redirect_url;
			$data['mode'] = $this->gateway_mode;
			$hash_columns = [
				'address_line_1',
				'address_line_2',
				'amount',
				'api_key',
				'city',
				'country',
				'currency',
				'description',
				'email',
				'mode',
				'name',
				'order_id',
				'phone',
				'return_url',
				'state',
				'udf1',
				'udf2',
				'udf3',
				'udf4',
				'udf5',
				'zip_code',
			];

			ksort($hash_columns);
			$hash_data = $this->aggrepay_salt;
			foreach ($hash_columns as $column) {
				if (isset($data[$column])) {
					if (strlen($data[$column]) > 0) {
						$hash_data .= '|' . trim($data[$column]);
					}
				}
			}
			$hash = null;
			if (strlen($hash_data) > 0) {
				$hash = strtoupper(hash("sha512", $hash_data));
			}
			$data['hash'] = $hash;

				$html = "<html><body><form action=\"".$action ."\" method=\"post\" id=\"aggrepay_form\" name=\"aggrepay_form\">
						<input type=\"hidden\" name=\"api_key\" value=\"". $data['api_key']. "\" />
						<input type=\"hidden\" name=\"order_id\" value=\"".$data['order_id']."\" />
						<input type=\"hidden\" name=\"amount\" value=\"".$data['amount']."\" />
						<input type=\"hidden\" name=\"description\" value=\"".$data['description']."\" />
						<input type=\"hidden\" name=\"name\" value=\"". $data['name']."\" />
						<input type=\"hidden\" name=\"zip_code\" value=\"". $data['zip_code']. "\" />
						<input type=\"hidden\" name=\"email\" value=\"". $data['email']."\" />
						<input type=\"hidden\" name=\"phone\" value=\"".$data['phone']."\" />
						<input type=\"hidden\" name=\"mode\" value=\"". $data['mode']. "\" />
						<input type=\"hidden\" name=\"return_url\" value=\"". $data['return_url']."\" />
						<input type=\"hidden\" name=\"hash\" value=\"".$data['hash']."\" />
						<input type=\"hidden\" name=\"address_line_1\" value=\"".$data['address_line_1'] ."\" />
						<input type=\"hidden\" name=\"address_line_2\" value=\"".$data['address_line_2'] ."\" />
					    <input type=\"hidden\" name=\"city\" value=\"". $data['city']."\" />
				        <input type=\"hidden\" name=\"country\" value=\"".$data['country']."\" />
				        <input type=\"hidden\" name=\"state\" value=\"". $data['state']."\" />
				        <input type=\"hidden\" name=\"currency\" value=\"". $data['currency']."\" />
				        <button style='display:none' id='submit_aggrepay_payment_form' name='submit_aggrepay_payment_form'>Pay Now</button>
					</form>
					<script type=\"text/javascript\">document.getElementById(\"aggrepay_form\").submit();</script>
					</body></html>";

				return $html;
		}


		function get_pages($title = false, $indent = true) {
			$wp_pages = get_pages('sort_column=menu_order');
			$page_list = array();
			if ($title) $page_list[] = $title;
			foreach ($wp_pages as $page) {
				$prefix = '';
				// show indented child pages?
				if ($indent) {
					$has_parent = $page->post_parent;
					while($has_parent) {
						$prefix .=  ' - ';
						$next_page = get_page($has_parent);
						$has_parent = $next_page->post_parent;
					}
				}
				// add to page list array array
				$page_list[$page->ID] = $prefix . $page->post_title;
			}
			return $page_list;
		}

	}


	/**
	 * Add the Gateway to WooCommerce
	 **/
	function woocommerce_add_aggrepay_gateway($methods) {
		$methods[] = 'WC_Aggrepay';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_aggrepay_gateway' );
}

?>
