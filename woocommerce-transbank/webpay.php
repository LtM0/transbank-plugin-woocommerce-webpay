<?php

use Transbank\WooCommerce\Webpay\Exceptions\TokenNotFoundOnDatabaseException;
use Transbank\WooCommerce\Webpay\Telemetry\PluginVersion;
use Transbank\WooCommerce\Webpay\TransbankWebpayOrders;
use Transbank\WooCommerce\Webpay\WordpressPluginVersion;

if (!defined('ABSPATH')) {
    exit();
} // Exit if accessed directly

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @wordpress-plugin
 * Plugin Name: Transbank Webpay Plus
 * Plugin URI: https://www.transbankdevelopers.cl/plugin/woocommerce/webpay
 * Description: Recibe pagos en l&iacute;nea con Tarjetas de Cr&eacute;dito y Redcompra en tu WooCommerce a trav&eacute;s de Webpay Plus.
 * Version: VERSION_REPLACE_HERE
 * Author: Transbank
 * Author URI: https://www.transbank.cl
 * WC requires at least: 3.4.0
 * WC tested up to: 4.0.1
 */

add_action('plugins_loaded', 'woocommerce_transbank_init', 0);

require_once plugin_dir_path(__FILE__) . "vendor/autoload.php";
require_once plugin_dir_path(__FILE__) . "libwebpay/HealthCheck.php";
require_once plugin_dir_path(__FILE__) . "libwebpay/LogHandler.php";
require_once plugin_dir_path(__FILE__) . "libwebpay/ConnectionCheck.php";
require_once plugin_dir_path(__FILE__) . "libwebpay/ReportGenerator.php";
require_once plugin_dir_path(__FILE__) . "libwebpay/TransbankSdkWebpay.php";

register_activation_hook(__FILE__, 'on_webpay_plugin_activation');
add_action( 'admin_init', 'on_transbank_webpay_plugins_loaded' );
add_action('wp_ajax_check_connection', 'ConnectionCheck::check');
add_action('wp_ajax_download_report', 'Transbank\Woocommerce\ReportGenerator::download');

function woocommerce_transbank_init()
{
    if (!class_exists("WC_Payment_Gateway")) {
        return;
    }
    
    class WC_Gateway_Transbank extends WC_Payment_Gateway
    {
        private static $URL_RETURN;
        private static $URL_FINAL;
        
        var $notify_url;
        var $plugin_url;
        
        public function __construct()
        {
            
            self::$URL_RETURN = home_url('/') . '?wc-api=WC_Gateway_transbank';
            self::$URL_FINAL = '_URL_';
            
            $this->id = 'transbank';
            $this->icon = plugin_dir_url(__FILE__ ) . 'libwebpay/images/webpay.png';
            $this->method_title = __('Transbank Webpay Plus');
            $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/'));
            $this->title = 'Transbank Webpay';
            $this->description = 'Permite el pago de productos y/o servicios, con Tarjetas de Cr&eacute;dito y Redcompra a trav&eacute;s de Webpay Plus';
            $this->plugin_url = plugins_url('/', __FILE__);
            $this->log = new LogHandler();
            
            $certificates = include 'libwebpay/certificates.php';
            $webpay_commerce_code = $certificates['commerce_code'];
            $webpay_private_key = $certificates['private_key'];
            $webpay_public_cert = $certificates['public_cert'];
            $webpay_webpay_cert = (new TransbankSdkWebpay(null))->getWebPayCertDefault();
            
            $this->config = [
                "MODO" => trim($this->get_option('webpay_test_mode', 'INTEGRACION')),
                "COMMERCE_CODE" => trim($this->get_option('webpay_commerce_code', $webpay_commerce_code)),
                "PRIVATE_KEY" => trim(str_replace("<br/>", "\n",
                    $this->get_option('webpay_private_key', $webpay_private_key))),
                "PUBLIC_CERT" => trim(str_replace("<br/>", "\n",
                    $this->get_option('webpay_public_cert', $webpay_public_cert))),
                "WEBPAY_CERT" => trim(str_replace("<br/>", "\n",
                    $this->get_option('webpay_webpay_cert', $webpay_webpay_cert))),
                "URL_RETURN" => home_url('/') . '?wc-api=WC_Gateway_' . $this->id,
                "URL_FINAL" => "_URL_",
                "ECOMMERCE" => 'woocommerce',
                "VENTA_DESC" => [
                    "VD" => "Venta Deb&iacute;to",
                    "VN" => "Venta Normal",
                    "VC" => "Venta en cuotas",
                    "SI" => "3 cuotas sin inter&eacute;s",
                    "S2" => "2 cuotas sin inter&eacute;s",
                    "NC" => "N cuotas sin inter&eacute;s"
                ],
                "STATUS_AFTER_PAYMENT" => $this->get_option('after_payment_order_status')
            ];
            
            /**
             * Carga configuración y variables de inicio
             **/
            
            $this->init_form_fields();
            $this->init_settings();
            
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'registerPluginVersion']);
            add_action('woocommerce_api_wc_gateway_' . $this->id, [$this, 'check_ipn_response']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
            
            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }
        public function enqueueScripts()
        {
            wp_enqueue_script('ajax-script', plugins_url('/js/admin.js', __FILE__), ['jquery']);
            wp_localize_script('ajax-script', 'ajax_object', ['ajax_url' => admin_url('admin-ajax.php')]);
        }
        
        public function checkConnection()
        {
            global $wpdb;
            require_once('ConfigProvider.php');
            require_once('HealthCheck.php');
            
            $configProvider = new ConfigProvider();
            $config = [
                'MODO' => $configProvider->getConfig('webpay_test_mode'),
                'COMMERCE_CODE' => $configProvider->getConfig('webpay_commerce_code'),
                'PUBLIC_CERT' => $configProvider->getConfig('webpay_public_cert'),
                'PRIVATE_KEY' => $configProvider->getConfig('webpay_private_key'),
                'WEBPAY_CERT' => $configProvider->getConfig('webpay_webpay_cert'),
                'ECOMMERCE' => 'woocommerce'
            ];
            $healthcheck = new HealthCheck($config);
            $resp = $healthcheck->setInitTransaction();
            // ob_clean();
            echo json_encode($resp);
            exit;
        }
        
        public function registerPluginVersion()
        {
            if (!$this->get_option('webpay_test_mode', 'INTEGRACION') === 'PRODUCCION') {
                return;
            }
            
            $commerceCode = $this->get_option('webpay_commerce_code');
            $certificates = include 'libwebpay/certificates.php';
            if ($commerceCode == $certificates['commerce_code']) {
                // If we are using the default commerce code, then abort as the user have not updated that value yet.
                return;
            };
            
            $pluginVersion = $this->getPluginVersion();
            
            (new PluginVersion)->registerVersion($commerceCode, $pluginVersion, wc()->version,
                PluginVersion::ECOMMERCE_WOOCOMMERCE);
        }
        
        /**
         * Comprueba configuración de moneda (Peso Chileno)
         **/
        function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(),
                apply_filters('woocommerce_' . $this->id . '_supported_currencies', ['CLP']))) {
                return false;
            }
            
            return true;
        }
        
        /**
         * Inicializar campos de formulario
         **/
        function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Activar/Desactivar', 'woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'yes'
                ],
                'webpay_test_mode' => [
                    'title' => __('Ambiente', 'woocommerce'),
                    'type' => 'select',
                    'options' => [
                        'INTEGRACION' => 'Integraci&oacute;n',
                        'PRODUCCION' => 'Producci&oacute;n'
                    ],
                    'default' => 'INTEGRACION'
                ],
                'webpay_commerce_code' => [
                    'title' => __('C&oacute;digo de Comercio', 'woocommerce'),
                    'type' => 'text',
                    'default' => __($this->config['COMMERCE_CODE'], 'woocommerce')
                ],
                'webpay_private_key' => [
                    'title' => __('Llave Privada', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->config['PRIVATE_KEY']), 'woocommerce'),
                    'css' => 'font-family: monospace'
                ],
                'webpay_public_cert' => [
                    'title' => __('Certificado', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->config['PUBLIC_CERT']), 'woocommerce'),
                    'css' => 'font-family: monospace'
                ],
                'after_payment_order_status' => [
                    'title' => __('Estado de pedido después del pago'),
                    'type' => 'select',
                    'options' => [
                        'wc-pending' => 'Pendiente',
                        'wc-processing' => 'Procesando',
                        'wc-on-hold' => 'Retenido',
                        'wc-completed' => 'Completado',
                        'wc-cancelled' => 'Cancelado',
                        'wc-refunded' => 'Reembolsado',
                        'wc-failed' => 'Fallido'
                    ],
                    'default' => 'wc-processing'
                ]
            ];
        }
        
        /**
         * Pagina Receptora
         **/
        function receipt_page($order_id)
        {
            $order = new WC_Order($order_id);
            $amount = (int)number_format($order->get_total(), 0, ',', '');
            $sessionId = uniqid();
            $buyOrder = $order_id;
            $returnUrl = self::$URL_RETURN;
            $finalUrl = str_replace("_URL_",
                add_query_arg('key', $order->get_order_key(), $order->get_checkout_order_received_url()),
                self::$URL_FINAL);
            
            $transbankSdkWebpay = new TransbankSdkWebpay($this->config);
            $result = $transbankSdkWebpay->initTransaction($amount, $sessionId, $buyOrder, $returnUrl, $finalUrl);
            
            if (isset($result["token_ws"])) {

                $url = $result["url"];
                $token_ws = $result["token_ws"];

                TransbankWebpayOrders::createTransaction([
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'token' => $token_ws,
                    'session_id' => $sessionId,
                    'status' => TransbankWebpayOrders::STATUS_INIT
                ]);

                self::redirect($url, ["token_ws" => $token_ws]);
                exit;
                
            } else {
                wc_add_notice(__('ERROR: ',
                        'woocommerce') . 'Ocurri&oacute; un error al intentar conectar con WebPay Plus. Por favor intenta mas tarde.<br/>',
                    'error');
            }
        }
        
        /**
         * Obtiene respuesta IPN (Instant Payment Notification)
         **/
        function check_ipn_response()
        {
            @ob_clean();
            if (isset($_POST)) {
                header('HTTP/1.1 200 OK');
                $this->check_ipn_request_is_valid($_POST);
            } else {
                echo "Ocurrio un error al procesar su Compra";
            }
        }
        
        /**
         * Valida respuesta IPN (Instant Payment Notification)
         **/
        public function check_ipn_request_is_valid($postData)
        {
            $token_ws = $this->getTokenWs($postData);
    
            $webpayTransaction = $this->getWebpayTransactionByToken($token_ws);
            $transbankSdkWebpay = new TransbankSdkWebpay($this->config);
            $result = $transbankSdkWebpay->commitTransaction($token_ws);
            
            $wooCommerceOrder = $this->getWooCommerceOrderById($webpayTransaction->buyorder);
    
            WC()->session->set($wooCommerceOrder->get_order_key(), $result);

            if ($this->transactionIsApproved($result) && $this->validateTransactionDetails($result, $webpayTransaction)) {
                WC()->session->set($wooCommerceOrder->get_order_key() . "_transaction_paid", 1);
                $this->completeWooCommerceOrder($wooCommerceOrder, $result);
                return self::redirect($result->urlRedirection, ["token_ws" => $token_ws]);
            }
    
            $this->setWooCommerceOrderAsFailed($wooCommerceOrder, $result);
            return self::redirect($wooCommerceOrder->get_checkout_payment_url(), ["token_ws" => $token_ws]);
        }
        
        /**
         * Generar pago en Transbank
         **/
        public function redirect($url, $data)
        {
            echo "<form action='" . $url . "' method='POST' name='webpayForm'>";
            foreach ($data as $name => $value) {
                echo "<input type='hidden' name='" . htmlentities($name) . "' value='" . htmlentities($value) . "'>";
            }
            echo "</form>" . "<script>" . "document.webpayForm.submit();" . "</script>";
        }
        
        /**
         * Procesar pago y retornar resultado
         **/
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            ];
        }
        
        /**
         * Opciones panel de administración
         **/
        public function admin_options()
        {
            
            $this->healthcheck = new HealthCheck($this->config);
            include 'libwebpay/admin-options.php';
        }
       
        /**
         * @return mixed
         */
        public function getPluginVersion()
        {
            return (new WordpressPluginVersion())->get();
        }
        protected function throwError($msg)
        {
            $error_message = "Estimado cliente, le informamos que su orden termin&oacute; de forma inesperada " . $msg;
            wc_add_notice(__('ERROR: ', 'woocommerce') . $error_message, 'error');
            die();
        }
        /**
         * @param $token_ws
         * @return mixed
         */
        private function getWebpayTransactionByToken($token_ws)
        {
            global $wpdb;
            $transaction = TransbankWebpayOrders::getWebpayTransactionsTableName();
            $sql = "SELECT * FROM $transaction WHERE token = '{$token_ws}'";
            $sqlResult = $wpdb->get_results($sql);
            if (!is_array($sqlResult) || count($sqlResult) <= 0) {
                throw new TokenNotFoundOnDatabaseException("Token '{$token_ws}' no se encontró en la base de datos de transacciones, por lo que no se puede completar el proceso");
            }
            $webpayTransaction = $sqlResult[0];
        
            return $webpayTransaction;
        }
        /**
         * @param array $result
         * @return bool
         */
        private function transactionIsApproved(array $result)
        {
            return $result->detailOutput->responseCode == 0;
        }
        /**
         * @param array $result
         * @param $webpayTransaction
         * @return bool
         */
        private function validateTransactionDetails(array $result, $webpayTransaction)
        {
            return $result->detailOutput->buyOrder == $webpayTransaction->buyorder && $result->sessionId == $webpayTransaction->sessionid && $result->detailOutput->amount == $webpayTransaction->amount;
        }
        /**
         * @param WC_Order $wooCommerceOrder
         * @param array $result
         * @param $order_id
         */
        private function completeWooCommerceOrder(WC_Order $wooCommerceOrder, array $result)
        {
            $wooCommerceOrder->add_order_note(__('Pago exitoso con Webpay Plus', 'woocommerce'));
            $wooCommerceOrder->add_order_note(__(json_encode($result), 'woocommerce'));
            $wooCommerceOrder->payment_complete();
            $final_status = $this->config['STATUS_AFTER_PAYMENT'];
            $wooCommerceOrder->update_status($final_status);
            wc_reduce_stock_levels($wooCommerceOrder->get_id());
        }
        /**
         * @param WC_Order $wooCommerceOrder
         * @param array $result
         */
        private function setWooCommerceOrderAsFailed(WC_Order $wooCommerceOrder, array $result)
        {
            $msg = 'Pago rechazado';
            $wooCommerceOrder->add_order_note(__($msg, 'woocommerce'));
            $wooCommerceOrder->add_order_note(__(json_encode($result), 'woocommerce'));
            $wooCommerceOrder->update_status('failed');
            $error_message = "Estimado cliente, le informamos que su orden termin&oacute; de forma inesperada";
            wc_add_notice(__('ERROR: ', 'woocommerce') . $error_message, 'error');
        }
        /**
         * @param $data
         * @return |null
         */
        private function getTokenWs($data)
        {
            $token_ws = isset($data["token_ws"]) ? $data["token_ws"] : null;
        
            if (!isset($token_ws)) {
                $this->throwError('No se encontro el token');
            }
        
            return $token_ws;
        }
        /**
         * @param $orderId
         * @return WC_Order
         */
        private function getWooCommerceOrderById($orderId)
        {
            $wooCommerceOrder = new WC_Order($orderId);
        
            return $wooCommerceOrder;
        }
    }
    
    /**
     * Añadir Transbank Plus a Woocommerce
     **/
    function woocommerce_add_transbank_gateway($methods)
    {
        $methods[] = 'WC_Gateway_transbank';
        
        return $methods;
    }
    
    /**
     * Muestra detalle de pago a Cliente a finalizar compra
     **/
    function pay_content($orderId)
    {
        $order_info = new WC_Order($orderId);
        $transbank_data = new WC_Gateway_transbank();
        if ($order_info->get_payment_method_title() != $transbank_data->title) {
            return;
        }
    
        if (WC()->session->get($order_info->get_order_key() . "_transaction_paid") == "" && WC()->session->get($order_info->get_order_key()) == "" && $order_info->has_status('pending')) {
            $order_info->add_order_note(__('Pago cancelado con Webpay Plus', 'woocommerce'));
            $order_info->update_status('failed');
            
            wc_add_notice(__('Compra <strong>Anulada</strong>',
                    'woocommerce') . ' por usuario. Recuerda que puedes pagar o cancelar tu compra cuando lo desees desde <a href="' . wc_get_page_permalink('myaccount') . '">' . __('Tu Cuenta',
                    'woocommerce') . '</a>', 'error');
            wp_redirect($order_info->get_checkout_payment_url());
            die();
        }
        
        $finalResponse = WC()->session->get($order_info->get_order_key());
        WC()->session->set($order_info->get_order_key(), "");
        
        if(isset($finalResponse->detailOutput)){
            $detailOutput = $finalResponse->detailOutput;
            $paymentTypeCode = isset($detailOutput->paymentTypeCode) ? $detailOutput->paymentTypeCode : null;
            $authorizationCode = isset($detailOutput->authorizationCode) ? $detailOutput->authorizationCode : null;
            $amount = isset($detailOutput->amount) ? $detailOutput->amount : null;
            $sharesNumber = isset($detailOutput->sharesNumber) ? $detailOutput->sharesNumber : null;
            $responseCode = isset($detailOutput->responseCode) ? $detailOutput->responseCode : null;
        } else {
            $paymentTypeCode = null;
            $authorizationCode = null;
            $amount = null;
            $sharesNumber = null;
            $responseCode = null;
        }
        
        if(isset($transbank_data->config)){
            $paymenCodeResult = "Sin cuotas";
            if(array_key_exists('VENTA_DESC', $transbank_data->config)){
                if(array_key_exists($paymentTypeCode, $transbank_data->config['VENTA_DESC'])){
                    $paymenCodeResult = $transbank_data->config['VENTA_DESC'][$paymentTypeCode];
                }
            }
        }
        
        if ($responseCode == 0) {
            $transactionResponse = "Transacci&oacute;n Aprobada";
        } else {
            $transactionResponse = "Transacci&oacute;n Rechazada";
        }
        
        $transactionDate = isset($finalResponse->transactionDate) ? $finalResponse->transactionDate : null;
        $date_accepted = new DateTime($transactionDate);
        
        if ($finalResponse != null) {
            
            if ($paymentTypeCode == "SI" || $paymentTypeCode == "S2" || $paymentTypeCode == "NC" || $paymentTypeCode == "VC") {
                $installmentType = $paymenCodeResult;
            } else {
                $installmentType = "Sin cuotas";
            }
            
            if ($paymentTypeCode == "VD") {
                $paymentType = "Débito";
            } else {
                $paymentType = "Crédito";
            }
            
            update_post_meta($orderId, 'transactionResponse', $transactionResponse);
            update_post_meta($orderId, 'buyOrder', $finalResponse->buyOrder);
            update_post_meta($orderId, 'authorizationCode', $authorizationCode);
            update_post_meta($orderId, 'cardNumber', $finalResponse->cardDetail->cardNumber);
            update_post_meta($orderId, 'paymenCodeResult', $paymenCodeResult);
            update_post_meta($orderId, 'amount', $amount);
            update_post_meta($orderId, 'coutas', $sharesNumber);
            update_post_meta($orderId, 'transactionDate', $date_accepted->format('d-m-Y / H:i:s'));
            
            echo '</br><h2>Detalles del pago</h2>' . '<table class="shop_table order_details">' . '<tfoot>' . '<tr>' . '<th scope="row">Respuesta de la Transacci&oacute;n:</th>' . '<td><span class="RT">' . $transactionResponse . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">C&oacute;digo de la Transacci&oacute;n:</th>' . '<td><span class="CT">' . $finalResponse->detailOutput->responseCode . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Orden de Compra:</th>' . '<td><span class="RT">' . $finalResponse->buyOrder . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Codigo de Autorizaci&oacute;n:</th>' . '<td><span class="CA">' . $finalResponse->detailOutput->authorizationCode . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Fecha Transacci&oacute;n:</th>' . '<td><span class="FC">' . $date_accepted->format('d-m-Y') . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row"> Hora Transacci&oacute;n:</th>' . '<td><span class="FT">' . $date_accepted->format('H:i:s') . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Tarjeta de Cr&eacute;dito:</th>' . '<td><span class="TC">************' . $finalResponse->cardDetail->cardNumber . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Tipo de Pago:</th>' . '<td><span class="TP">' . $paymentType . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Tipo de Cuota:</th>' . '<td><span class="TC">' . $installmentType . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">Monto Compra:</th>' . '<td><span class="amount">' . $finalResponse->detailOutput->amount . '</span></td>' . '</tr>' . '<tr>' . '<th scope="row">N&uacute;mero de Cuotas:</th>' . '<td><span class="NC">' . $finalResponse->detailOutput->sharesNumber . '</span></td>' . '</tr>' . '</tfoot>' . '</table><br/>';
        }
    }
    
    add_action('woocommerce_thankyou', 'pay_content', 1);
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_transbank_gateway');
    
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_action_links');
    
    function add_action_links($links)
    {
        $newLinks = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=transbank') . '">Settings</a>',
        ];
        
        return array_merge($links, $newLinks);
    }
}

function on_webpay_plugin_activation()
{
    woocommerce_transbank_init();
    
    $pluginObject = new WC_Gateway_Transbank();
    $pluginObject->registerPluginVersion();
}

function on_transbank_webpay_plugins_loaded() {
    TransbankWebpayOrders::createTableIfNeeded();
}

function transbank_remove_database() {
    TransbankWebpayOrders::deleteTable();
}

register_uninstall_hook( __FILE__, 'transbank_remove_database' );
