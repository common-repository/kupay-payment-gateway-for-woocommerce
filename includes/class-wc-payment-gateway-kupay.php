<?php

/**
 * Kupay Payment Gateway.
 *
 * Provides a Kupay Payment Gateway for WooCommerce.
 *
 * @class       WC_Gateway_Kupay
 * @extends     WC_Payment_Gateway
 * @version     0.1.3
 * @package     WooCommerce/Classes/Payment
 */
class WC_Gateway_KuPay extends WC_Payment_Gateway {

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {

		$this->init_form_fields();
		$this->setup_properties();
		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Exit if we don't have an API key
		if ( $this->api_key == '' ) {
			if ( $this->is_accessing_settings( 1 ) ) {
				$class = 'notice notice-error';
				$message = __( $this->api_key_issue_msg, 'woocommerce' );
				printf( '<div class="%1$s"><p><span class="dashicons dashicons-warning"></span> %2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			}
			return false;
		}

		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Setup general properties for the gateway.
	 */
	protected function setup_properties() {

		$this->id                 = 'kupay';
		//$this->icon               = apply_filters( 'woocommerce_kupay_icon', plugins_url('../assets/icon.png', __FILE__ ) );
		$this->api_key_issue_msg  = 'KuPay Payment Gateway Error: Enter your API key.';
		$this->title              = 'KuPay Crypto Payment Gateway';
		$this->method_title       = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->api_key            = trim( $this->get_option( 'api_key' ) );
		$this->instructions       = $this->get_option( 'instructions' );
		$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
		$this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';
		$this->method_description = __( 'Let your customers pay with KuPay crypto payments via Meta Mask.' . PHP_EOL .
										'<ul class="ul-disc">' .
										( $this->api_key == '' ? '<li>You need an API key. Takes one minute only. <a href="https://kupay.finance/checkout" target="_blank">Sign up now</a>!' . '</li>' : '' ) .
										'<li>Chains supported: KCC, BSC (KCC KuCoin Community Chain, Binance Smart Chain). New chains added soon.' . '</li>' .
										'<li><a href="https://docs.kupay.finance/guides/setup-woocommerce" target="_blank">Setup guide</a>' . '</li>' .
										'</ul>', 'woocommerce' );
		$this->has_fields         = false;

	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'       => __( 'Enable/Disable', 'woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable KuPay Crypto Payment Gateway', 'woocommerce' ),
				'default'     => 'yes',
			),
			'title'              => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'KuPay Payment method description that the customer will see on your checkout.', 'woocommerce' ),
				'default'     => __( 'KuPay Crypto Payment Gateway', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'api_key'             => array(
				'title'       => __( 'API Key', 'woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Add your API key', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Gateway Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'KuPay Payment method description that the customer will see on your website.', 'woocommerce' ),
				'default'     => __( 'KuPay Payment Gateway - Defi Payments made easy via Meta Mask', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'instructions'       => array(
				'title'       => __( 'Instructions for Thank you Page', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the Thank-You page.', 'woocommerce' ),
				'default'     => __( 'Thank you for your payment via the KuPay Payment Gateway. We will soon receive confirmation about your payment and will then process your order. Thank you!', 'woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Check If The Gateway Is Available For Use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$order          = null;
		$needs_shipping = false;

		// Test if shipping is needed first.
		if ( WC()->cart && WC()->cart->needs_shipping() ) {
			$needs_shipping = true;
		} elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = wc_get_order( $order_id );

			// Test if order needs shipping.
			if ( 0 < count( $order->get_items() ) ) {
				foreach ( $order->get_items() as $item ) {
					$_product = $item->get_product();
					if ( $_product && $_product->needs_shipping() ) {
						$needs_shipping = true;
						break;
					}
				}
			}
		}

		$needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

		// Virtual order, with virtual disabled.
		if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
			return false;
		}

		// Only apply if all packages are being shipped via chosen method, or order is virtual.
		if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
			$order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
			$chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

			if ( $order_shipping_items ) {
				$canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
			} else {
				$canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
			}

			if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
				return false;
			}
		}

		return parent::is_available();
	}

	/**
	 * Checks to see whether or not the admin settings are being accessed by the current request.
	 * @var int $level how deep to check (3=kupay settings, 2=checkout settings, 1=woo settings, 0=only if is_admin)
	 * @return bool
	 */
	private function is_accessing_settings( $level = 3 ) {

		if ( is_admin() ) {
			// phpcs:disable WordPress.Security.NonceVerification
			if ( $level >= 1 && ( ! isset( $_REQUEST['page'] ) || 'wc-settings' !== $_REQUEST['page'] ) ) {
				return false;
			}
			if ( $level >= 2 && ( ! isset( $_REQUEST['tab'] ) || 'checkout' !== $_REQUEST['tab'] ) ) {
				return false;
			}
			if ( $level >= 3 && ( ! isset( $_REQUEST['section'] ) || 'kupay' !== $_REQUEST['section'] ) ) {
				return false;
			}
			// phpcs:enable WordPress.Security.NonceVerification

			return true;
		}

		return false;
	}

	/**
	 * Loads all of the shipping method options for the enable_for_methods field.
	 *
	 * @return array
	 */
	private function load_shipping_method_options() {
		// Since this is expensive, we only want to do it if we're actually on the settings page.
		if ( ! $this->is_accessing_settings() ) {
			return array();
		}

		$data_store = WC_Data_Store::load( 'shipping-zone' );
		$raw_zones  = $data_store->get_zones();

		foreach ( $raw_zones as $raw_zone ) {
			$zones[] = new WC_Shipping_Zone( $raw_zone );
		}

		$zones[] = new WC_Shipping_Zone( 0 );

		$options = array();
		foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

			$options[ $method->get_method_title() ] = array();

			// Translators: %1$s shipping method name.
			$options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );

			foreach ( $zones as $zone ) {

				$shipping_method_instances = $zone->get_shipping_methods();

				foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

					if ( $shipping_method_instance->id !== $method->id ) {
						continue;
					}

					$option_id = $shipping_method_instance->get_rate_id();

					// Translators: %1$s shipping method title, %2$s shipping method id.
					$option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

					// Translators: %1$s zone name, %2$s shipping method instance name.
					$option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );

					$options[ $method->get_method_title() ][ $option_id ] = $option_title;
				}
			}
		}

		return $options;
	}

	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
	 * @return array $canonical_rate_ids    Rate IDs in a canonical format.
	 */
	private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

		$canonical_rate_ids = array();

		foreach ( $order_shipping_items as $order_shipping_item ) {
			$canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
		}

		return $canonical_rate_ids;
	}

	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
	 * @return array $canonical_rate_ids  Rate IDs in a canonical format.
	 */
	private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

		$shipping_packages  = WC()->shipping()->get_packages();
		$canonical_rate_ids = array();

		if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
			foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
				if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
					$chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
					$canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
				}
			}
		}

		return $canonical_rate_ids;
	}

	/**
	 * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
	 *
	 * @since  3.4.0
	 *
	 * @param array $rate_ids Rate ids to check.
	 * @return boolean
	 */
	private function get_matching_rates( $rate_ids ) {
		// First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
		return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( $order->get_total() > 0 ) {
			$order->update_status( 'processing' );
		} else {
			$order->payment_complete();
		}

		// Remove cart.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}


	/**
	 * Output for the order received page (makes api call and redirects to gateway -or- display success/retry upon return)
	 * 
	 * @param int $order_id
	 * @return void
	 */
	public function thankyou_page( $order_id ) {

		$order = wc_get_order( $order_id );

		if (isset($_GET['status'])) {
			// Show the result
			if ($_GET['status'] == 'completed') {
				//$order->update_status('completed'); // only if confirmed on blockchain via callback
				echo "<h1>Thank you!</h1>";
				echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
			}
			else if ($_GET['status'] == 'cancelled') {
				$order->update_status('pending');
				echo '<div class="woocommerce-error" role="alert">';
				echo '<p>Try your payment again!</p>';
				echo '<p><a href="'.esc_url($order->get_checkout_payment_url()).'">Select Payment Method</a></p>';
				echo '</div>';
			}
		}
		else{
			$this->kupayApiCall( $order_id );
		}

	}

	/**
	 * Kupay Alert
	 * 
	 * @param int $code
	 * @return string $html
	 */
	private function kupayShowAlert( $code ) {
		$html = '<div class="woocommerce-error" role="alert">';
		$html .= '<p>Something went wrong ('.intval($code).')</p>';
		$html .= '<p>Perhaps the woocommerce currency is not supported by the payment gateway?</p>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Kupay API Call
	 * 
	 * @param int $order_id
	 * @return string $error (or redirects if successful)
	 */
	private function kupayApiCall( $order_id ) {

		$order = wc_get_order( $order_id );
		$order_data = $order->get_data();
		$key = $order->get_order_key();
		$payload = [
			"order" => $order_data,
			"cancel_url" => esc_url($order->get_checkout_order_received_url()).'&status=cancelled',
			"paid_url" => esc_url($order->get_checkout_order_received_url()).'&status=completed',
			"callback_url" => add_query_arg( 'key', $key, add_query_arg( 'wc-api', 'callback', home_url( '/' ) ) ),
		];

		$url = esc_url('https://api.kupay.finance/webhook/woocommerce/'.$this->api_key);
		// @test
		if ( 'testwoo.vh' === $_SERVER['HTTP_HOST'] ) {
			add_filter( 'https_ssl_verify', '__return_false' );
			$url = esc_url('https://kupay.vh/webhook/woocommerce/'.$this->api_key);
		}

		$response = wp_remote_post( $url, [
			'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
			'body'        => json_encode($payload),
			'method'      => 'POST',
			'data_format' => 'body',
		]);

		if ( is_wp_error( $response ) ) {
			//$error_message = $response->get_error_message();
			echo $this->kupayShowAlert(1003);
			return "Something went wrong (1003)";
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			//$error_message = $response->get_error_message();
			echo $this->kupayShowAlert(1002);
			return "Something went wrong (1002)";
		}

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {

			$api_response_obj = json_decode($response['body']);

			if ($api_response_obj === null) {
				//$error_message = "Invalid Payment Gateway API response (1000)";
				echo $this->kupayShowAlert(1000);
				return "Something went wrong (1000)";
			}

			$paymentUrl = isset($api_response_obj->pay_url) ? $api_response_obj->pay_url : null;

			if ($paymentUrl === null) {
				//$error_message = "Invalid Payment URL found on Payment Gateway API response (1001)";
				echo $this->kupayShowAlert(1001);
				return "Something went wrong (1001)";
			}

			wp_redirect($paymentUrl, 302); // 301 would be stored in browser cache, better 302 for temporary redirect

		}
	}

	/**
	 * Change payment complete order status to completed for kupay orders.
	 *
	 * @since  3.1.0
	 * @param  string         $status Current order status.
	 * @param  int            $order_id Order ID.
	 * @param  WC_Order|false $order Order object.
	 * @return string $status
	 */
	public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
		if ( $order && 'kupay' === $order->get_payment_method() ) {
			$status = 'completed';
		}
		return $status;
	}

	/**
	 * Add content to the WC emails
	 *
	 * @param WC_Order $order Order object
	 * @param bool     $sent_to_admin  Sent to admin
	 * @param bool     $plain_text Email format: plain text or HTML
	 * @return void
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
			//wp_kses_post() Sanitizes content for allowed HTML tags for post content.
			//wpautop() Replaces double line breaks with paragraph elements.
			//wptexturize() Replaces common plain text characters with formatted entities.
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
		}
	}

	/**
	 * Callback by KuPay gateway after successful payment
	 *
	 * @param string   $api_key to authenticate merchant with the KuPay gateway
	 * @param int      $woo_order_id WooCommerce order ID, eg. 12
	 * @param string   $woo_order_key WooCommerce internal order key, eg. wc_order_uRlHghnyjiilN
	 * @param string   $status Status of the payment (must exist in $ALLOWED_STATUSES array)
	 * @return void
	 */
	public function callback( $api_key, $woo_order_id, $woo_order_key, $status ) {

		// Fail if secret key incorrect
        if ( $api_key != $this->api_key ) {
            return;
        }

        // Fail if secret not configured
 		if ( $this->api_key == '' ) {
 			return;
 		}

		$order = wc_get_order( $woo_order_id );

		// Fail if order not found
		if( ! $order ) {
			return;
		}

		// Fail if order key incorrect
		if( $order->get_order_key() != $woo_order_key ) {
			return;
		}

		// If you update this, also update the $ALLOWED_STATUSES array
		switch ( $status ) {
			case 'completed':
				$order->update_status( 'completed' );
				//$order->payment_complete();
				break;
			case 'cancelled':
				$order->update_status( 'cancelled' ); // only support staff does this
				//$order->cancel_order();
				break;
			case 'open':
			case 'expired':
			case 'failure':
			case 'error':
			default:
				break;
		}

	}

}
