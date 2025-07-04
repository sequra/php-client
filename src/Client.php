<?php

/**
 * Copyright © 2017 SeQura Engineering. All rights reserved.
 */

namespace Sequra\PhpClient;

class Client
{

    public static $endpoint = '';
    public static $user = '';
    public static $password = '';
    public static $user_agent = null;
    private $_endpoint;
    private $_user;
    private $_password;
    private $_user_agent;
    private $_debug;
    private $_logfile;

    private $success = false;
    private $cart_has_changed;
    private $response_headers;
    private $status;
    private $curl_result = null;
    private $json = null;
    private $ch = null;
    /**
     * An array to store request headers.
     * @var array<string, string>
     */
    private $request_headers;

    /**
     * Sets a request header for the cURL request.
     * 
     * @param string $name The name of the header. Must not be empty.
     * @param string $value The value of the header. Must not be empty.
     */
    private function setRequestHeader($name, $value)
    {
        if (!is_array($this->request_headers)) {
            $this->resetRequestHeaders();
        }
        $name  = trim($name);
        $value = trim($value);
        if ($value === '' || $name === '') {
            return;
        }
        $this->request_headers[$name] = $value;
    }

    /**
     * Returns the request headers as an array of strings formatted as "Name: Value".
     * 
     * @return array<string> An array of request headers formatted as "Name: Value".
     */
    private function getRequestHeaders()
    {
        if (!is_array($this->request_headers)) {
            return array();
        }
        $headers = array();
        foreach ($this->request_headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        return $headers;
    }

    /**
     * Reset the request headers to an empty array.
     */
    private function resetRequestHeaders()
    {
        $this->request_headers = array();
    }

    public function __construct($user = null, $password = null, $endpoint = null, $debug = false, $logfile = null)
    {
        $this->_debug    = $debug;
        $this->_user     = Helper::notNull($user, self::$user);
        $this->_password = Helper::notNull($password, self::$password);
        $this->_logfile  = $logfile;
        $url_parts       = parse_url(Helper::notNull($endpoint, self::$endpoint));
        if (count($url_parts) > 1) {
            $this->_endpoint = $url_parts['scheme'] . '://' . $url_parts['host'] . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '');
        }
        $this->_user_agent = Helper::notNull(self::$user_agent, 'cURL php ' . phpversion());
        $this->log(self::class . " created!");
    }

    public function isValidAuth($merchant = '')
    {
        $this->initCurl(
            $this->_endpoint .
                '/merchants' .
                ($merchant ? '/' . urlencode($merchant) : '') .
                '/credentials'
        );
        $this->setRequestHeader('Sequra-Merchant-Id', $merchant);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $this->sendRequest();
        $this->dealWithResponse();
        return $this->succeeded();
    }

    public function startSolicitation($order)
    {
        if (!$this->qualifyForSolicitation($order) && !$this->_debug) {
            return;
        }

        $this->initCurl($this->_endpoint . '/orders');
        $this->verbThePayload('POST', array('order' => $order));
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function qualifyForSolicitation($order)
    {
        if ($order['cart']['order_total_with_tax'] <= 0) {
            return false;
        }
        if (!Helper::isConsistentCart($order['cart'])) {
            return false;
        }

        return true;
    }

    public function getIdentificationForm($uri, $options = array())
    {
        $options["product"] = array_key_exists('product', $options) ? $options["product"] : "i1";
        $options["ajax"]    = (isset($options["ajax"]) && $options["ajax"]) ? "true" : "false";
        $this->initCurl($uri . '/form_v2' . '?' . http_build_query($options));
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $this->setRequestHeader('Accept', 'text/html');
        // TODO: Set merchant ID
        // $this->setRequestHeader('Sequra-Merchant-Id', '');

        $this->sendRequest();
        $this->dealWithResponse();
        curl_close($this->ch);
        return $this->curl_result;
    }

    public function sendIdentificationForm($uri, $options = array())
    {
        $options["product"] = array_key_exists('product', $options) ? $options["product"] : "i1";
        $options["product_code"] = $options["product"];
        $options["channel"] = array_key_exists('channel', $options) ? $options["channel"] : "sms";
        $this->initCurl($uri . '/form_deliveries');
        $this->verbThePayload('POST', $options);
        $this->dealWithResponse();
        curl_close($this->ch);
        return $this->curl_result;
    }

    public function startCards($order)
    {
        if (!$this->qualifyForstartCards($order) && !$this->_debug) {
            return;
        }

        $this->initCurl($this->_endpoint . '/cards');
        $this->verbThePayload('POST', array('order' => $order));
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function getCardsForm($uri, $options = array())
    {
        $this->initCurl($uri . '?' . http_build_query($options));
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $this->setRequestHeader('Accept', 'text/html');
        // TODO: Set merchant ID
        // $this->setRequestHeader('Sequra-Merchant-Id', '');

        $this->sendRequest();
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function qualifyForstartCards($order)
    {
        return
            isset($order['customer']['ref']) && $order['customer']['ref'] != '' &&
            isset($order['customer']['email']) && $order['customer']['email'] != '' &&
            (
                (isset($order['delivery_address']['mobile_phone']) && $order['delivery_address']['mobile_phone'] != '') ||
                (isset($order['delivery_address']['phone']) && $order['delivery_address']['phone'] != '')
            );
    }

    public function getMerchantPaymentMethods($merchant)
    {
        $this->getPaymentMethods($this->_endpoint . '/merchants/' . $merchant);
    }

    public function getPaymentMethods($uri, $options = array())
    {
        if (!preg_match('!^https?://!', $uri)) {
            $uri = $this->_endpoint . '/orders/' . $uri;
        }
        $this->initCurl($uri . '/payment_methods' . (count($options) > 0 ? '?' . http_build_query($options) : ''));
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $this->setRequestHeader('Accept', 'application/json');
        // TODO: Set merchant ID
        // $this->setRequestHeader('Sequra-Merchant-Id', '');

        $this->sendRequest();
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function getAvailableDisbursements($merchant)
    {
        $this->initCurl($this->_endpoint . '/merchants/' . $merchant . '/disbursements');
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $this->setRequestHeader('Accept', 'application/json');
        // TODO: Set merchant ID
        // $this->setRequestHeader('Sequra-Merchant-Id', '');

        $this->sendRequest();
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function getDisbursementDetails($path)
    {
        $this->initCurl($this->_endpoint . $path);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $this->setRequestHeader('Accept', 'application/json');
        // TODO: Set merchant ID
        // $this->setRequestHeader('Sequra-Merchant-Id', '');

        $this->sendRequest();
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function getCreditAgreements($amount, $merchant, $locale = 'es-ES', $country = 'ES', $currency = 'EUR')
    {
        $uri = $this->_endpoint .
            '/merchants/' . urlencode($merchant) .
            '/credit_agreements?total_with_tax=' . urlencode($amount) .
            '&currency=' . urlencode($currency) .
            '&locale=' . urlencode($locale) .
            '&country=' . urlencode($country);
        $this->initCurl($uri);

        $this->setRequestHeader('Sequra-Merchant-Id', $merchant);

        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $this->sendRequest();
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function updateOrder($uri, $order, $verb = 'PUT')
    {
        if (!preg_match('!^https?://!', $uri)) {
            $uri = $this->_endpoint . '/orders/' . $uri;
        }
        $this->initCurl($uri);
        $this->verbThePayload($verb, array('order' => $order));
        $this->dealWithResponse();
        if ($this->status == 409) {
            $this->cart_has_changed = true;
        }
        curl_close($this->ch);
    }

    public function sendDeliveryReport($delivery_report)
    {
        $this->initCurl($this->_endpoint . '/delivery_reports');
        $this->verbThePayload('POST', array('delivery_report' => $delivery_report));
        $this->dealWithResponse();
        curl_close($this->ch);
    }

    public function orderUpdate($order)
    {
        $uri = $this->_endpoint .
            '/merchants/' . urlencode($order['merchant']['id']) .
            '/orders/' . urlencode($order['merchant_reference']['order_ref_1']);
        $this->initCurl($uri);
        $this->verbThePayload('PUT', array('order' => $order));
        $this->dealWithResponse();
        if ($this->status == 409) {
            $this->cart_has_changed = true;
        }
        curl_close($this->ch);
    }

    public function callCron($cron_url)
    {
        $this->_user_agent = 'sequra-cron';
        $this->initCurl($cron_url);
        curl_setopt($this->ch, CURLOPT_HTTPGET, 1);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
        $this->sendRequest();
        curl_close($this->ch);
    }

    public function succeeded()
    {
        return $this->success;
    }

    public function getJson()
    {
        return $this->json;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function cartHasChanged()
    {
        return $this->cart_has_changed;
    }

    public function getRawResult()
    {
        return $this->curl_result;
    }

    public function getOrderUri()
    {
        if ($this->response_headers && preg_match('/^Location:\s+([^\n\r]+)/mi', $this->response_headers, $m)) {
            return $m[1];
        }
    }

    public function dump()
    {
        echo "Endpoit: \n";
        var_dump($this->_endpoint);
        echo "\nStatus: \n";
        var_dump($this->status);
        echo "\njson: \n";
        var_dump($this->json);
        echo "\nsuccess: \n";
        var_dump($this->success);
    }

    // Private methods below

    /**
     * Initializes the cURL session with the given URL and sets the necessary options.
     * 
     * @param string $url The URL to initialize the cURL session with.
     */
    private function initCurl($url)
    {
        $this->success = $this->json = null;
        $this->ch      = curl_init($url);
        curl_setopt($this->ch, CURLOPT_USERPWD, $this->_user . ':' . $this->_password);
        curl_setopt($this->ch, CURLOPT_USERAGENT, $this->_user_agent);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_FAILONERROR, false);
        // Some versions of openssl seem to need this
        // http://www.supermind.org/blog/763/solved-curl-56-received-problem-2-in-the-chunky-parser
        curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        // From http://it.toolbox.com/wiki/index.php/Use_curl_from_PHP_-_processing_response_headers
        curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, array(&$this, 'storeResponseHeaders'));
        $this->response_headers = '';
    }

    private function verbThePayload($verb, $payload)
    {
        $data_string = json_encode(Helper::removeNulls($payload));
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $verb);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data_string);

        $this->setRequestHeader('Accept', 'application/json');
        $this->setRequestHeader('Content-Type', 'application/json');
        $this->setRequestHeader('Content-Length', (string) strlen($data_string));
        // TODO: Set merchant ID
        // $this->setRequestHeader('Sequra-Merchant-Id', '');

        $this->sendRequest();
    }

    private function sendRequest()
    {
        $this->success = false;
        if ($this->_debug) {
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $headers = $this->getRequestHeaders();
        if (!empty($headers)) {
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
        }
        $this->resetRequestHeaders();

        $this->curl_result = curl_exec($this->ch);

        if ($this->curl_result === false) {
            $this->log(
                "cURL error: " . curl_errno($this->ch) .
                    " msg: " . curl_error($this->ch)
            );
        }
        $this->status      = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    }

    private function dealWithResponse()
    {
        $this->json = json_decode($this->curl_result, true);
        if (200 <= $this->status && $this->status <= 299) {
            $this->success = true;
            $this->log("Start " . $this->status . ": Ok!");
        } else {
            $this->success = false;
            $this->log("Start " . $this->status . ": " . $this->curl_result);
        }
    }

    private function log($msg)
    {
        if (!$this->_debug) {
            return;
        }
        if (!$this->_logfile) {
            error_log($msg);
        } else {
            error_log($msg . "\n", 3, $this->_logfile);
        }
    }

    private function storeResponseHeaders($ch, $header)
    {
        $this->response_headers .= $header;

        return strlen($header);
    }
}
