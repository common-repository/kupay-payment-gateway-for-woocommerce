<?php
/**
 * Plugin Name: KuPay Payment Gateway for WooCommerce
 * Plugin URI: https://docs.kupay.finance/guides/setup-woocommerce
 * Author: KuPay
 * Author URI: https://kupay.finance/
 * Description: KuPay Payment Gateway for WooCommerce
 * Version: 0.1.3
 * WC requires at least: 6.3.0
 * WC tested up to: 6.4.0
 * License: GPL2
 * License URL: http://www.gnu.org/licenses/gpl-2.0.txt
 * 
 * Class WC_Gateway_KuPay file.
 *
 * @package WooCommerce\KuPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Exit if WooCommerce itself is not active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'plugins_loaded', 'kupay_payment_init', 11 ); // The plugins_loaded action hook fires early, and precedes the setup_theme, after_setup_theme, init and wp_loaded action hooks.
//add_action( 'init', 'kupay_callback' ); // init hook runs a bit after plugins_loaded hook
add_action( 'woocommerce_api_callback', 'kupay_callback' );
add_filter( 'woocommerce_payment_gateways', 'kupay_add_to_payment_gateways' );

function kupay_payment_init() {
    if( class_exists( 'WC_Payment_Gateway' ) ) {
		require_once plugin_dir_path( __FILE__ ) . '/includes/class-wc-payment-gateway-kupay.php';
        require_once plugin_dir_path( __FILE__ ) . '/includes/kupay-checkout-description-fields.php';

        if ( is_admin() && isset( $_REQUEST['page'] ) && 'wc-settings' === $_REQUEST['page'] ) {

            // Show a warning if currency is not supported [only on woocommerce settings page]
            $kupay_currency = get_option( 'woocommerce_currency' );
            $kupay_currencies_supported = ['USD', 'EUR', 'JPY', 'GBP', 'AUD', 'CAD', 'CHF', 'CNY', 'HKD', 'NZD', 'SEK', 'KRW', 'SGD', 'NOK', 'MXN', 'INR', 'BRL', 'NGN'];
            $kupay_list_of_currencies = implode(", ", $kupay_currencies_supported);
            //$kupay_list_of_currencies = str_replace($kupay_currency, '<b>'.$kupay_currency.'</b>', $kupay_list_of_currencies);
            $kupay_currency_issue = ! in_array($kupay_currency, $kupay_currencies_supported);
            $kupay_currency_issue_msg = 'KuPay Payment Gateway Error: Your currency ('.$kupay_currency.') is not yet supported! Contact info@kupay.finance so we can add it! Choose another currency ('.$kupay_list_of_currencies.') in WooCommerce &gt; Settings &gt; General or disable the payment method until then.';

            if ( $kupay_currency_issue ) {
                $class = 'notice notice-error';
                $message = __( $kupay_currency_issue_msg, 'woocommerce' );
                printf( '<div class="%1$s"><p><span class="dashicons dashicons-warning"></span> %2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
            }

        }

	}
}

function kupay_add_to_payment_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_KuPay';
    return $gateways;
}

// eg. thank you page ?key=wc_order_uRlHghnyjiilN&status=cancelled
function kupay_callback() {

    $ALLOWED_STATUSES = ['completed', 'cancelled', 'open', 'expired', 'failure', 'error'];

    // sanitize and filter api key, eg. 25621898-6434-11ec-93c2-525401d2ddc2
    $api_key = isset($_GET['kupay_callback']) ? sanitize_text_field($_GET['kupay_callback']) : false;
    if (strlen($api_key) != 36) {
        $api_key = false;
    }
    if ( ! ctype_xdigit(str_replace("-", "", $api_key))) { // all hexadecimal digits?
        $api_key = false;
    }

    // sanitize and filter order_id, eg. 12
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

    // sanitize order key, eg. wc_order_uRlHghnyjiilN
    $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : false;
    if (substr($order_key, 0, 9) != 'wc_order_') {
        $order_key = false;
    }

    // sanitize and filter status
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : false;
    if ( ! in_array($status, $ALLOWED_STATUSES)) {
        $status = false;
    }

    if ($api_key && $order_id > 0 && $order_key && $status) {

        $kupay = new WC_Gateway_KuPay();

        $kupay->callback($api_key, $order_id, $order_key, $status);

    }
    else{
        // missing params: do not continue
    }

}
