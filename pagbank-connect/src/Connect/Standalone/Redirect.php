<?php
namespace RM_PagBank\Connect\Standalone;

use RM_PagBank\Connect;
use RM_PagBank\Helpers\Api;
use RM_PagBank\Helpers\Functions;
use RM_PagBank\Helpers\Params;
use RM_PagBank\Traits\OrderInvoiceEmail;
use RM_PagBank\Traits\PaymentMethodIcon;
use RM_PagBank\Traits\PaymentUnavailable;
use RM_PagBank\Traits\ProcessPayment;
use RM_PagBank\Traits\StaticResources;
use RM_PagBank\Traits\ThankyouInstructions;
use WC_Payment_Gateway;
use WC_Data_Exception;
use WP_Error;

/** Standalone Pix */
class Redirect extends WC_Payment_Gateway
{
    use PaymentUnavailable;
    use ProcessPayment;
    use StaticResources;
    use PaymentMethodIcon;
    use ThankyouInstructions;
    use OrderInvoiceEmail;

    public string $code = '';

    public function __construct()
    {
        $this->code = 'redirect';
        $this->id = Connect::DOMAIN . '-' . $this->code;
        $this->has_fields = true;
        $this->supports = [
            'products',
            'refunds'
        ];
        $this->icon = plugins_url('public/images/pagseguro-icon.svg', WC_PAGSEGURO_CONNECT_PLUGIN_FILE);
        $this->method_title = $this->get_option(
            'title',
            __('Pagar no PagBank', 'pagbank-connect')
        );
        $this->method_description = __(
            'O Checkout PagBank permite que clientes sejam redirecionados para o PagBank para realizar o pagamento e informem menos dados em sua loja.',
            'pagbank-connect'
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('Checkout PagBank', 'pagbank-connect'));
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_filter('woocommerce_available_payment_gateways', [$this, 'disableIfOrderLessThanOneReal'], 10, 1);
        add_action('woocommerce_thankyou_' . Connect::DOMAIN . '-redirect', [$this, 'addThankyouInstructions']);
        add_action('woocommerce_email_after_order_table', [$this, 'addPaymentDetailsToEmail'], 10, 4);

        add_action('wp_enqueue_styles', [$this, 'addStyles']);
        add_action('wp_enqueue_scripts', [$this, 'addScripts']);
        add_action('admin_enqueue_scripts', [$this, 'addAdminStyles'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'addAdminScripts'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'addScriptRedirectSettings'], 20, 1);
    }

    public function init_form_fields()
    {
        $this->form_fields = include WC_PAGSEGURO_CONNECT_BASE_DIR . '/admin/views/settings/redirect-fields.php';
    }

    public function admin_options() {
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

        include WC_PAGSEGURO_CONNECT_BASE_DIR.'/admin/views/html-settings-page.php';
//        parent::admin_options();
    }


    /**
     * Process Payment.
     *
     * @param int $order_id Order ID.
     *
     * @return array
     * @throws WC_Data_Exception|\RM_PagBank\Connect\Exception
     */
    public function process_payment($order_id): array
    {
        global $woocommerce;
        $order = wc_get_order( $order_id );

        //sanitize $_POST['ps_connect_method']
        $payment_method = 'checkout';
        if(isset($_POST['payment_method'])){
            $payment_method = htmlspecialchars($_POST['payment_method'], ENT_QUOTES, 'UTF-8');
        }

        // region Add note if customer changed payment method
        $this->handleCustomerChangeMethod($order, $payment_method);
        // endregion

        $order->add_meta_data(
            '_rm_pagbank_checkout_blocks',
            wc_bool_to_string(isset($_POST['wc-rm-pagbank-redirect-new-payment-method'])),
            true
        );

        if(isset($_POST['rm-pagbank-customer-document'])) {
            $order->add_meta_data(
                '_rm_pagbank_customer_document',
                htmlspecialchars($_POST['rm-pagbank-customer-document'], ENT_QUOTES, 'UTF-8'),
                true
            );
        }

        $method = new \RM_PagBank\Connect\Payments\Redirect($order);
        $params = $method->prepare();

        $resp = $this->makeRequest($order, $params, $method);

        $method->process_response($order, $resp);
        self::updateTransaction($order, $resp);

        $this->maybeSendNewOrderEmail($order, $resp);

        // some notes to customer (or keep them private if order is pending)
        $shouldNotify = $order->get_status('edit') !== 'pending';
        $order->add_order_note('PagBank: Pedido criado com sucesso!', $shouldNotify);


        $woocommerce->cart->empty_cart();
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }


    /**
     * Builds our payment fields area
     */
    public function payment_fields() {
        $this->form();
    }

    /**
     * @inheritDoc
     */
    public function form() {
        if ($this->paymentUnavailable()) {
            include WC_PAGSEGURO_CONNECT_BASE_DIR . '/src/templates/unavailable.php';
            return;
        }

        include WC_PAGSEGURO_CONNECT_BASE_DIR . '/src/templates/payments/redirect.php';
    }

    /**
     * Validate frontend fields
     *
     * @return bool
     */
    public function validate_fields():bool
    {
        return true; //@TODO validate_fields
    }

    public function addPaymentDetailsToEmail($order, $sent_to_admin, $plain_text, $email) {
        if ($order && $order->is_paid()) {
            return;
        }
        $emailIds = ['customer_invoice', 'new_order', 'customer_processing_order'];
        if ($order->get_meta('pagbank_payment_method') === 'redirect' && in_array($email->id, $emailIds)) {
            $redirectLink = $order->get_meta('pagbank_redirect_url');
            $timestamp = strtotime($order->get_meta('pagbank_redirect_expiration'));
            $date_format = get_option('date_format'); // Ex: d/m/Y
            $time_format = get_option('time_format'); // Ex: H:i
            $checkoutExpires = wp_date(sprintf("%s %s", $date_format, $time_format), $timestamp);
            ob_start();
            include WC_PAGSEGURO_CONNECT_BASE_DIR . '/src/templates/emails/redirect-payment-details.php';
            $output = ob_get_clean();
            echo $output;
        }
    }

    /**
     * Process refund.
     *
     * If the gateway declares 'refunds' support, this will allow it to refund.
     * a passed in amount.
     *
     * @param  int        $order_id Order ID.
     * @param  float|null $amount Refund amount.
     * @param  string     $reason Refund reason.
     * @return bool|WP_Error True or false based on success, or a WP_Error object.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return Api::refund($order_id, $amount);
    }

    /**
     * Send new order email with invoice and payment details
     *
     * @param $order
     * @param $resp
     * @return void
     */
    public function maybeSendNewOrderEmail($order, $resp) {
        $this->sendNewOrder($order);
        $shouldNotify = wc_string_to_bool(Params::getRedirectConfig('redirect_send_new_order_email', 'yes'));
        if (!$shouldNotify) {
            return;
        }
        $this->sendOrderInvoiceEmail($order);
    }


    /**
     * Changes success URL for Checkout Pagbank Orders when the order is not paid yet
     * @param $order_received_url
     * @param $order
     *
     * @return mixed
     */
    public static function getOrderReceivedURL($order_received_url, $order)
    {
        if ($order->get_payment_method() === Connect::DOMAIN . '-redirect'
        && $order->get_status() == 'pending' && !empty($order->get_meta('pagbank_redirect_url'))) {
            $order_received_url = $order->get_meta('pagbank_redirect_url');
        }
        return $order_received_url; 
    }

    /**
     * Changes Payment Link for Checkout Pagbank Orders (used in emails and buttons)
     * @param $pay_url
     * @param $order
     *
     * @return mixed
     */
    public static function changePaymentLink($pay_url, $order)
    {
        if ($order->get_payment_method() === Connect::DOMAIN . '-redirect') {
            $pay_url = $order->get_meta('pagbank_redirect_url');
        }
        return $pay_url;
    }

    public function addScriptRedirectSettings($hook) {
        if ($hook === 'woocommerce_page_wc-settings') {
            wp_enqueue_script(
                'pagbank-redirect-admin',
                plugins_url('public/js/admin/ps-redirect-admin.js', WC_PAGSEGURO_CONNECT_PLUGIN_FILE),
                ['jquery'],
                WC_PAGSEGURO_CONNECT_VERSION,
                true
            );
        }
    }
}