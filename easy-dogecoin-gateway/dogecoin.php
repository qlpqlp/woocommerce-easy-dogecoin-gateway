<?php
/**
 * Plugin Name: Easy Dogecoin Gateway
 * Plugin URI: https://github.com/qlpqlp/woocommerce-easy-dogecoin-payment
 * Text Domain: dogecoin
 * Description: Accept Dogecoin Payments using simple your Dogecoin Address without the need of any third party payment processor, banks, extra fees | Your Store, your wallet, your Doge.
 * Author: Dogecoin Foundation
 * Author URI: https://foundation.dogecoin.com
 * Version: 69.420.6
 * Requires at least: 5.6
 * Tested up to: 6.5.5
 * WC requires at least: 5.7
 * WC tested up to: 9.0.2
 */

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'plugins_loaded', 'easydoge_payment_init', 11 );
add_filter( 'woocommerce_currencies', 'bc_add_new_currency' );
add_filter( 'woocommerce_currency_symbol', 'bc_add_new_currency_symbol', 10, 2 );
add_action( 'wp_head', 'add_doge_qr_script' );

// Add Dogecoin Foundation Fetch Script to generate QR codes
function add_doge_qr_script() {
    echo '<script type="module" src="https://fetch.dogecoin.org/doge-qr.js"></script>';
}

function bc_add_new_currency( $currencies ) {
     $currencies['DOGE'] = __( 'Dogecoin', 'Dogecoin' );
     return $currencies;
}


function bc_add_new_currency_symbol( $symbol, $currency ) {

     if( $currency == 'DOGE' ) {
          $symbol = 'Ð';
     }
     return $symbol;
}


function easydoge_payment_init() {
    if( class_exists( 'WC_Payment_Gateway' ) ) {
        class WC_EasyDoge_Gateway extends WC_Payment_Gateway {
            public function __construct() {
                $this->id   = 'easydoge_payment';
                $this->icon = apply_filters( 'woocommerce_easydoge_icon', plugins_url('/assets/icon.svg', __FILE__ ) );
                $this->has_fields = false;
                $this->method_title = __( 'Easy Dogecoin Payment', 'easydoge-pay-woo');
                $this->method_description = __( 'Easy Dogecoin payment.', 'easydoge-pay-woo');

                $this->title = __( 'Dogecoin', 'easydoge-pay-woo');
                $this->doge_address = $this->get_option( 'doge_address' );
                $this->mydoge_x_user = $this->get_option( 'mydoge_x_user' );
                $this->sodoge_x_user = $this->get_option( 'sodoge_x_user' );
                $this->instructions = $this->get_option( 'instructions' );
                $this->description = $this->get_option( 'instructions' ); // field needed to display fields on client side

                $this->init_form_fields();
                $this->init_settings();

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
                add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ));
                add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
            }

            public function init_form_fields() {
                $this->form_fields = apply_filters( 'woo_easydoge_pay_fields', array(
                    'enabled' => array(
                        'title' => __( 'Enable/Disable', 'easydoge-pay-woo'),
                        'type' => 'checkbox',
                        'label' => __( 'Enable or Disable Doge Payments', 'easydoge-pay-woo'),
                        'default' => 'no'
                    ),
                    'doge_address' => array(
                        'title' => __( 'Your Dogecoin Address', 'easydoge-pay-woo'),
                        'type' => 'text',
                        'default' => __( 'DXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', 'easydoge-pay-woo'),
                        'desc_tip' => true,
                        'description' => __( 'This address will be shown to the client', 'easydoge-pay-woo')
                    ),
                    'mydoge_x_user' => array(
                        'title' => __( 'Your X user connected to MyDoge.com Wallet', 'easydoge-pay-woo'),
                        'type' => 'text',
                        'default' => __( '', 'easydoge-pay-woo'),
                        'desc_tip' => true,
                        'description' => __( 'If this field is filled, will display an option to pay using x.com and you will be able to track Orders payments  also on X', 'easydoge-pay-woo')
                    ),
                    'sodoge_x_user' => array(
                        'title' => __( 'Your X user connected to SoDogeTip.xyz Wallet', 'easydoge-pay-woo'),
                        'type' => 'text',
                        'default' => __( '', 'easydoge-pay-woo'),
                        'desc_tip' => true,
                        'description' => __( 'If this field is filled, will display an option to pay using x.com and you will be able to track Orders payments  also on X', 'easydoge-pay-woo')
                    ),
                    'instructions' => array(
                        'title' => __( 'Instructions for the client to pay in Dogecoin', 'easydoge-pay-woo'),
                        'type' => 'textarea',
                        'default' => __( 'Please pay the exact amount of Dogecoin and send us the Transaction ID by email, to be able to check the payment.', 'easydoge-pay-woo'),
                        'desc_tip' => true,
                        'description' => __( 'This address will be shown to the client', 'easydoge-pay-woo')
                    )
                ));
            }


            /**
             * Convert value to cripto by request
             *
             * @param mixed $value
             * @param string $from
             * @return mixed
             */
            static public function convert_to_crypto($value, $from='usd') {
            if ($from != 'DOGE'){
                $response = wp_remote_get("https://api.coingecko.com/api/v3/coins/markets?vs_currency=".strtolower(esc_html($from))."&ids=dogecoin&per_page=1&page=1&sparkline=false");
                $price = json_decode($response["body"]);
                $response = $value / $price[0]->current_price;
            }else{
                $response = $value;
            };
            $response = number_format($response, 2, '.', '');

            if ( is_wp_error($response) )
                return false;

            if ($response > 0)
                return trim($response);

            return 0;

            }

            /**
             * Generate DOGE payment fields
             *
             * @return void
             */
            function payment_fields() {
            $total = WC()->cart->total;
            $woo_currency = get_woocommerce_currency();
            $total = $this->convert_to_crypto($total,$woo_currency);
            echo '<h2 style="font-weight: bold;">&ETH; ' . esc_html($total) . '</h2><input type="hidden" name="muchdoge" value="' . esc_html($total) . '" />';
            }

            /**
             * Payment process handler
             *
             * @param int $order_id
             * @return array
             */
            function process_payment($order_id) {
            $order = new WC_Order($order_id);

            // Create redirect URL
            $redirect = get_permalink(wc_get_page_id('pay'));
            $redirect = add_query_arg('order', $order->id, $redirect);
            $redirect = add_query_arg('key', $order->order_key, $redirect);
            $redirect = add_query_arg('muchdoge', sanitize_text_field(esc_html($_POST['muchdoge'])), $redirect);
            $order->reduce_order_stock();

            return array(
                'result'    => 'success',
                'redirect'  => $redirect,
            );
            }

            /**
             * Generate DOGE payment instructions and recipe
             *
             * @return void
             */
            public function receipt_page($order_id) {
                $order = new WC_Order($order_id);
        
                echo '<p style="text-align: center"><img style="margin:auto" src="'.plugins_url('/assets/wow.gif', __FILE__ ).'" alt="wow" /></p>';
        
                echo '<div class="row"><div style="border-top: 5px solid rgba(204, 153, 51, 1); border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px">' . esc_html($this->instructions) . '</div><div class="col" style="float:none;margin:auto; text-align: center;max-width: 425px; border: 2px solid rgba(204, 153, 0, 1); border-radius: 15px; padding: 10px;"><div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px; text-align: center"><h2 style="font-size: 20px; color: rgba(0, 0, 0, 1); font-weight: bold">Ð '. esc_html($_GET['muchdoge']) . '</h2></div>';
        
                echo '<doge-qr address="' . esc_html($this->doge_address) . '" amount="' . esc_html($_GET['muchdoge']) . '" fill="#666,#000" img="https://fetch.dogecoin.org/resources/img/qr/so-coin.png"></doge-qr>';
        
                echo '<div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-top-left-radius: 0px; border-top-right-radius: 0px; color: rgba(0, 0, 0, 1)">' . esc_html($this->doge_address) . '</div></div></div> ';
        
                if (trim($this->mydoge_x_user) != "" || trim($this->sodoge_x_user) != "") {
                    echo '<div class="row" style="padding-top: 15px;"><div style="border-top: 5px solid  rgba(51, 153, 255, 1); border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px"><div style="text-align: center;">'.__( 'Pay directly in Dogecoin using <b>X</b> Doge Wallet Bots!', 'easydoge-pay-woo').'</div>';
                    echo '<div class="row">';
                }
        
                if (trim($this->mydoge_x_user) != "") {
                    $mydoge_pay = "%0a%0a🥳🎉🐶🔥🚀%0a@MyDogeTip%20tip%20".trim($this->mydoge_x_user)."%20".esc_html($_GET['muchdoge'])."%20Doge%20";
                    $mydoge_wallet_link = 'https://x.com/intent/tweet?text='.trim($this->mydoge_x_user).'%20 XPay Order id:'.$order_id.$mydoge_pay.'%0a%0a'.get_site_url().'%0a&hashtags=Doge,Dogecoin';
                    echo '<div class="col" style="display:inline-block !important; width: 50%"><a href="'.$mydoge_wallet_link.'" target="_blank"><div style="background: #fff; border: 1px solid; border-radius: 15px; color: #000; background-image: url('.plugins_url('/assets/x.png', __FILE__ ).'); background-repeat: no-repeat; background-position: center left 15px; background-size: contain; text-align: center; margin:15px"><img src="'.plugins_url('/assets/mydoge.png', __FILE__ ).'" style="padding: 10px; display: inline; max-height: 50px"></div></a></div>';
                }
        
                if (trim($this->sodoge_x_user) != "") {
                    $sodoge_pay = "%0a%0a🥳🎉🐶🔥🚀%0a@sodogetip%20tip%20".trim($this->sodoge_x_user)."%20".esc_html($_GET['muchdoge'])."%20Doge%20";
                    $sodoge_wallet_link = 'https://x.com/intent/tweet?text='.trim($this->sodoge_x_user).'%20 XPay Order id:'.$order_id.$sodoge_pay.'%0a%0a'.get_site_url().'%0a&hashtags=Doge,Dogecoin';
                    echo '<div class="col" style="display:inline-block !important;width: 50%"><a href="'.$sodoge_wallet_link.'" target="_blank"><div style="background: #fff; border: 1px solid; border-radius: 15px; color: #000; background-image: url('.plugins_url('/assets/x.png', __FILE__ ).'); background-repeat: no-repeat; background-position: center left 15px; background-size: contain; text-align: center; margin:15px"><img src="'.plugins_url('/assets/sodoge.png', __FILE__ ).'" style="padding: 10px; display: inline; max-height: 50px"></div></a></div>';
                }
        
                if (trim($this->mydoge_x_user) != "" || trim($this->sodoge_x_user) != "") {
                    echo '</div></div></div>';
                }
        
                $order->payment_complete();
                $order->update_status( 'on-hold',  __( 'Awaiting Dogecoin Payment Confirmation', 'easydoge-pay-woo') );
                WC()->cart->empty_cart();
            }

            /**
             * Add content to the WC emails.
             *
             * @param WC_Order $order Order object.
             * @param bool     $sent_to_admin  Sent to admin.
             * @param bool     $plain_text Email format: plain text or HTML.
             */
            public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

                    // Check if the payment method is 'easydoge_payment'
                    if ( $order->get_payment_method() !== 'easydoge_payment' ) {
                        return;
                    }    

                    echo '<p style="text-align: center"><img style="margin:auto" src="'.plugins_url('/assets/wow.gif', __FILE__ ).'" alt="wow" /></p>';

                    echo '<div class="row"><div style="border-top: 5px solid rgba(204, 153, 51, 1); border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px; padding-bottom: 15px">' . esc_html($this->instructions) . '</div><div class="col" style="float:none;margin:auto; text-align: center;max-width: 425px; border: 2px solid rgba(204, 153, 0, 1); border-radius: 15px; padding: 10px;"><div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px; text-align: center !important"><h2 style="font-size: 20px; color: rgba(0, 0, 0, 1); font-weight: bold; text-align: center !important"">Ð '. esc_html($_GET['muchdoge']) . '</h2></div><div style="background-color: #fff; padding: 10px; border-radius: 15px; border-top-left-radius: 0px; border-top-right-radius: 0px; color: rgba(0, 0, 0, 1)">' . esc_html($this->doge_address) . '</div></div></div><br><br>';

            }

        }
    }
}

  add_filter( 'woocommerce_payment_gateways', 'add_to_woo_easydoge_payment_gateway');

  function add_to_woo_easydoge_payment_gateway( $gateways ) {
      $gateways[] = 'WC_EasyDoge_Gateway';
      return $gateways;
  }