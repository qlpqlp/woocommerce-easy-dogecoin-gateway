<?php
/*
Plugin Name: Dogecoin Easy Gateway
Plugin URI: https://github.com/qlpqlp/woocommerce-dogecoin-easy-payment
Description: Acept Dogecoin Payments using simple your Dogecoin Address without the need of any third party payment processor, banks, extra fees | Your Store, your wallet, your Doge.
Version: 69.420.0
Author: inevitable360
Author URI: https://github.com/qlpqlp
*/

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'plugins_loaded', 'easydoge_payment_init', 11 );

function easydoge_payment_init() {
    if( class_exists( 'WC_Payment_Gateway' ) ) {
        class WC_EasyDoge_Gateway extends WC_Payment_Gateway {
            public function __construct() {
                $this->id   = 'easydoge_payment';
                $this->icon = apply_filters( 'woocommerce_easydoge_icon', plugins_url('/assets/icon.svg', __FILE__ ) );
                $this->has_fields = false;
                $this->method_title = __( 'Dogecoin Easy Payment', 'easydoge-pay-woo');
                $this->method_description = __( 'Dogecoin Easy payment.', 'easydoge-pay-woo');

                $this->title = __( 'Dogecoin', 'easydoge-pay-woo');
                $this->doge_address = $this->get_option( 'doge_address' );
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

      $price = json_decode(file_get_contents("https://api.coingecko.com/api/v3/coins/markets?vs_currency=".strtolower($from)."&ids=dogecoin&per_page=1&page=1&sparkline=false"));
      $response = $value / $price[0]->current_price;
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
      ?>
     <h2 style="font-weight: bold;">&ETH; <?php echo esc_html($total); ?></h2>
      <input type="hidden" name="muchdoge" value="<?php echo esc_html($total); ?>" />
      <?php
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
      $redirect = add_query_arg('muchdoge', sanitize_text_field($_POST['muchdoge']), $redirect);
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
     public function receipt_page($order_id){
        $order = new WC_Order($order_id);
?>
      <div class="row">
        <div style="border-top: 1px solid rgba(204, 153, 51, 1); border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px"><?php echo $this->instructions; ?></div>
      <div class="col" style="float:none;margin:auto; text-align: center;max-width: 425px; border: 2px solid rgba(204, 153, 0, 1); border-radius: 15px; padding: 10px;">
        <div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px">
           <h2 style="font-size: 20px; color: rgba(0, 0, 0, 1); font-weight: bold">Ð <?php echo $_GET['muchdoge']; ?></h2>
        </div>
           <img id="qrcode" src="//api.qrserver.com/v1/create-qr-code/?data=<?php echo $this->doge_address; ?>&amp;size=400x400" alt="" title="Such QR Code!" style="max-width: 400px !important"/>
        <div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-top-left-radius: 0px; border-top-right-radius: 0px; color: rgba(0, 0, 0, 1)">
           <?php echo $this->doge_address; ?>
        </div>
      </div>
      </div>
<?php
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
  ?>
      <div class="row">
        <div style="border-top: 1px solid rgba(204, 153, 51, 1); border-top-left-radius: 15px; border-top-right-radius: 15px; padding: 10px"><?php echo $this->instructions; ?></div>
      <div class="col" style="float:none;margin:auto; text-align: center;max-width: 425px; border: 2px solid rgba(204, 153, 0, 1); border-radius: 15px; padding: 10px;">
        <div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px">
           <h2 style="font-size: 20px; color: rgba(0, 0, 0, 1); font-weight: bold">Ð <?php echo $_GET['muchdoge']; ?></h2>
        </div>
           <img id="qrcode" src="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo $this->doge_address; ?>&amp;size=400x400" alt="" title="Such QR Code!" style="max-width: 400px !important"/>
        <div style="background-color: rgba(204, 153, 0, 1); padding: 10px; border-radius: 15px; border-top-left-radius: 0px; border-top-right-radius: 0px; color: rgba(0, 0, 0, 1)">
           <?php echo $this->doge_address; ?>
        </div>
      </div>
      </div>
  <?php
  }

        }
    }
}

  add_filter( 'woocommerce_payment_gateways', 'add_to_woo_easydoge_payment_gateway');

  function add_to_woo_easydoge_payment_gateway( $gateways ) {
      $gateways[] = 'WC_EasyDoge_Gateway';
      return $gateways;
  }