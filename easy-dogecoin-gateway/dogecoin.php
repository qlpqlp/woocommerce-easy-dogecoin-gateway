<?php
/**
 * Plugin Name: Easy Dogecoin Gateway
 * Plugin URI: https://github.com/qlpqlp/woocommerce-easy-dogecoin-payment
 * Text Domain: dogecoin
 * Description: Accept Dogecoin Payments using simple your Dogecoin Address without the need of any third party payment processor, banks, extra fees | Your Store, your wallet, your Doge.
 * Author: Dogecoin Foundation
 * Author URI: https://foundation.dogecoin.com
 * Version: 69.420.9
 * Requires at least: 5.6
 * Tested up to: 6.9.4
 * WC requires at least: 5.7
 * WC tested up to: 10.6.1
 * Requires PHP: 7.0
 * Woo: 12345:342928dfsfhsf2349842374wdf4234sfd
 * WooCommerce: true
 * WooCommerce HPOS: true
 */

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

// Ensure WooCommerce is fully loaded before initializing
add_action( 'woocommerce_init', 'easydoge_payment_init' );
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
    // Debug logging for WordPress.com
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Easy Dogecoin Gateway: Initializing payment gateway' );
    }
    
    if( class_exists( 'WC_Payment_Gateway' ) ) {
        class WC_EasyDoge_Gateway extends WC_Payment_Gateway {
            // PHP 8.2+: declare properties to avoid deprecated dynamic property creation.
            public $doge_address = '';
            public $mydoge_x_user = '';
            public $sodoge_x_user = '';
            public $instructions = '';

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
                // Check if WooCommerce is available
                if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
                    echo '<p>' . __( 'WooCommerce is not available. Please refresh the page.', 'easydoge-pay-woo' ) . '</p>';
                    return;
                }
                
                $total = WC()->cart->total;
                $woo_currency = get_woocommerce_currency();
                
                // Fallback if currency detection fails
                if ( empty( $woo_currency ) ) {
                    $woo_currency = 'USD';
                }
                
                $total = $this->convert_to_crypto($total, $woo_currency);
                echo '<h2 style="font-weight: bold;">&ETH; ' . esc_html($total) . '</h2><input type="hidden" name="muchdoge" value="' . esc_html($total) . '" />';
            }

            /**
             * Payment process handler
             *
             * @param int $order_id
             * @return array
             */
            function process_payment($order_id) {
                // Check if WooCommerce is available
                if ( ! function_exists( 'WC' ) || ! WC() ) {
                    wc_add_notice( __( 'WooCommerce is not available. Please try again.', 'easydoge-pay-woo' ), 'error' );
                    return array( 'result' => 'failure' );
                }
                
                $order = wc_get_order($order_id);
                
                if ( ! $order ) {
                    wc_add_notice( __( 'Order not found. Please try again.', 'easydoge-pay-woo' ), 'error' );
                    return array( 'result' => 'failure' );
                }

                $muchdoge = isset( $_POST['muchdoge'] ) ? sanitize_text_field( wp_unslash( $_POST['muchdoge'] ) ) : '';

                // Create redirect URL
                // Woo 2.1+ deprecates passing order IDs to the `woocommerce_checkout` shortcode via `order`.
                // Redirect to the order-pay endpoint so Woo can infer the order without the deprecated `order` query var.
                $redirect = '';
                if ( function_exists( 'wc_get_endpoint_url' ) ) {
                    // Base URL doesn't matter much; endpoint URL builder will append the correct structure.
                    $base_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : get_permalink( wc_get_page_id( 'pay' ) );
                    $redirect = wc_get_endpoint_url( 'order-pay', $order_id, $base_url );
                }

                if ( empty( $redirect ) ) {
                    if ( method_exists( $order, 'get_checkout_payment_url' ) ) {
                        $redirect = $order->get_checkout_payment_url();
                    } else {
                        // Last resort fallback (older Woo versions).
                        $redirect = get_permalink( wc_get_page_id( 'pay' ) );
                        $redirect = add_query_arg( 'key', $order->get_order_key(), $redirect );
                    }
                } else {
                    $redirect = add_query_arg( 'key', $order->get_order_key(), $redirect );
                }

                $redirect = add_query_arg( 'muchdoge', $muchdoge, $redirect );
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
                $order = wc_get_order($order_id);
                
                if ( ! $order ) {
                    echo '<p>' . __( 'Order not found.', 'easydoge-pay-woo' ) . '</p>';
                    return;
                }

                $muchdoge = isset( $_GET['muchdoge'] ) ? sanitize_text_field( wp_unslash( $_GET['muchdoge'] ) ) : '';
        
                echo '<style>
                    .easydoge-wrap{max-width:560px;margin:0 auto;}
                    .easydoge-card{background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);overflow:hidden;}
                    .easydoge-instructions{background:rgba(17,24,39,.04);border-top:5px solid rgba(148,163,184,1);padding:14px 16px;font-weight:600;text-align:center;color:#111827;}
                    .easydoge-amount{background:rgba(243,244,246,1);padding:14px 16px;color:#111827;text-align:center;font-weight:800;font-size:20px;}
                    .easydoge-qr{padding:14px 16px;text-align:center;}
                    .easydoge-address-row{padding:10px 16px 16px;display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap;background:#f9fafb;border-top:1px solid rgba(0,0,0,.06);}
                    .easydoge-address{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:15px;word-break:break-all;padding:10px 12px;border-radius:12px;background:#fff;border:1px dashed rgba(100,116,139,.55);}
                    .easydoge-copy-btn{border:0;border-radius:12px;padding:10px 14px;background:#111827;color:#fff;font-weight:700;cursor:pointer;}
                    .easydoge-copy-btn:hover{opacity:.95}
                    .easydoge-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:14px;padding:12px 16px;font-weight:800;width:100%;max-width:320px;margin:0 auto;box-shadow:0 6px 18px rgba(0,0,0,.10);background:linear-gradient(135deg,#e5e7eb,#cbd5e1);color:#0f172a;border:1px solid rgba(148,163,184,.55);}
                    .easydoge-x-section{margin-top:16px;}
                    .easydoge-action-title{border-top:5px solid rgba(148,163,184,1);border-top-left-radius:15px;border-top-right-radius:15px;padding:12px 16px;text-align:center;color:#111827;font-weight:800;background:rgba(17,24,39,.02);}
                    .easydoge-x-grid{display:flex;gap:14px;justify-content:space-between;flex-wrap:wrap;padding:14px 16px 16px;}
                    .easydoge-x-col{flex:1 1 240px;min-width:240px;}
                    .easydoge-x-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;box-shadow:0 10px 22px rgba(0,0,0,.08);transition:transform .15s ease;overflow:hidden;}
                    .easydoge-x-card:hover{transform:translateY(-1px);}
                    .easydoge-x-inner{background-repeat:no-repeat;background-position:center left 15px;background-size:contain;text-align:center;margin:15px;}
                </style>';

                echo '<div class="easydoge-wrap">';

                echo '<p style="text-align: center"><img style="margin:auto" src="'.plugins_url('/assets/wow.gif', __FILE__ ).'" alt="wow" /></p>';

                echo '<div class="easydoge-card">';
                echo '<div class="easydoge-instructions">' . esc_html($this->instructions) . '</div>';
                echo '<div class="easydoge-amount">Ð ' . esc_html($muchdoge) . '</div>';
                echo '<div class="easydoge-qr"><doge-qr address="' . esc_html($this->doge_address) . '" amount="' . esc_html($muchdoge) . '" fill="#666,#000" img="https://fetch.dogecoin.org/resources/img/qr/so-coin.png"></doge-qr></div>';
                echo '<div class="easydoge-address-row">';
                echo '<div class="easydoge-address" id="easydoge-address">' . esc_html($this->doge_address) . '</div>';
                echo '<button type="button" class="easydoge-copy-btn" data-address="' . esc_attr($this->doge_address) . '">' . esc_html__( 'Copy', 'easydoge-pay-woo' ) . '</button>';
                echo '</div>';
                echo '</div>'; // easydoge-card

                // Button to open the native Dogecoin URI with the specified amount.
                $doge_uri = 'dogecoin:' . rawurlencode( trim( (string) $this->doge_address ) ) . '?amount=' . rawurlencode( $muchdoge );
                echo '<div style="padding:14px 16px 0; text-align:center;">';
                echo '<a class="button easydoge-btn" href="' . esc_attr( $doge_uri ) . '">' . esc_html__( 'Pay in Doge', 'easydoge-pay-woo' ) . '</a>';
                echo '</div>';

                if ( trim( $this->mydoge_x_user ) != "" || trim( $this->sodoge_x_user ) != "" ) {
                    echo '<div class="easydoge-x-section">';
                    echo '<div class="easydoge-action-title">' . __( 'Pay directly in Dogecoin using <b>X</b> Doge Wallet Bots!', 'easydoge-pay-woo' ) . '</div>';
                    echo '<div class="easydoge-x-grid">';

                    if ( trim( $this->mydoge_x_user ) != "" ) {
                        $mydoge_pay = "%0a%0a🥳🎉🐶🔥🚀%0a@MyDogeTip%20tip%20" . trim( $this->mydoge_x_user ) . "%20" . esc_html( $muchdoge ) . "%20Doge%20";
                        $mydoge_wallet_link = 'https://x.com/intent/tweet?text=' . trim( $this->mydoge_x_user ) . '%20 XPay Order id:' . $order_id . $mydoge_pay . '%0a%0a' . get_site_url() . '%0a&hashtags=Doge,Dogecoin';
                        echo '<div class="easydoge-x-col"><a href="' . esc_attr( $mydoge_wallet_link ) . '" target="_blank" rel="noopener noreferrer"><div class="easydoge-x-card"><div class="easydoge-x-inner" style="background-image:url(' . plugins_url('/assets/x.png', __FILE__ ) . ');"><img src="' . plugins_url('/assets/mydoge.png', __FILE__ ) . '" style="padding: 10px; display: inline; max-height: 50px" alt="MyDoge" /></div></div></a></div>';
                    }

                    if ( trim( $this->sodoge_x_user ) != "" ) {
                        $sodoge_pay = "%0a%0a🥳🎉🐶🔥🚀%0a@sodogetip%20tip%20" . trim( $this->sodoge_x_user ) . "%20" . esc_html( $muchdoge ) . "%20Doge%20";
                        $sodoge_wallet_link = 'https://x.com/intent/tweet?text=' . trim( $this->sodoge_x_user ) . '%20 XPay Order id:' . $order_id . $sodoge_pay . '%0a%0a' . get_site_url() . '%0a&hashtags=Doge,Dogecoin';
                        echo '<div class="easydoge-x-col"><a href="' . esc_attr( $sodoge_wallet_link ) . '" target="_blank" rel="noopener noreferrer"><div class="easydoge-x-card"><div class="easydoge-x-inner" style="background-image:url(' . plugins_url('/assets/x.png', __FILE__ ) . ');"><img src="' . plugins_url('/assets/sodoge.png', __FILE__ ) . '" style="padding: 10px; display: inline; max-height: 50px" alt="SoDoge" /></div></div></a></div>';
                    }

                    echo '</div>'; // easydoge-x-grid
                    echo '</div>'; // easydoge-x-section
                }

                // Small helper to copy DOGE address.
                echo '<script>
                    (function(){
                        var btn = document.querySelector(".easydoge-copy-btn");
                        if(!btn) return;
                        btn.addEventListener("click", async function(){
                            var addr = btn.getAttribute("data-address") || "";
                            try{
                                if(navigator.clipboard && navigator.clipboard.writeText){
                                    await navigator.clipboard.writeText(addr);
                                }else{
                                    var ta = document.createElement("textarea");
                                    ta.value = addr;
                                    document.body.appendChild(ta);
                                    ta.select();
                                    document.execCommand("copy");
                                    document.body.removeChild(ta);
                                }
                                btn.textContent = "Copied";
                                setTimeout(function(){ btn.textContent = "' . esc_js( __( 'Copy', 'easydoge-pay-woo' ) ) . '"; }, 1200);
                            }catch(e){
                                alert(addr);
                            }
                        });
                    })();
                </script>';

                echo '</div>'; // easydoge-wrap
        
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
    // Debug logging
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Easy Dogecoin Gateway: Adding gateway to WooCommerce gateways list' );
    }
    
    $gateways[] = 'WC_EasyDoge_Gateway';
    return $gateways;
}

// Add admin notice if gateway is not showing up
add_action( 'admin_notices', 'easydoge_admin_notices' );

function easydoge_admin_notices() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        echo '<div class="notice notice-error"><p>' . __( 'Easy Dogecoin Gateway requires WooCommerce to be installed and active.', 'easydoge-pay-woo' ) . '</p></div>';
    }
}