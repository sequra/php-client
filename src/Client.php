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
    private $headers;
    private $status;
    private $curl_result = null;
    private $json = null;
    private $ch = null;

    public function __construct($user = null, $password = null, $endpoint = null, $debug = false, $logfile = null)
    {
        $this->_debug    = $debug;
        $this->_user     = Helper::notNull($user, self::$user);
        $this->_password = Helper::notNull($password, self::$password);
        $url_parts       = parse_url(Helper::notNull($endpoint, self::$endpoint));
        if (count($url_parts) > 1) {
            $this->_endpoint = $url_parts['scheme'] . '://' . $url_parts['host'] . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '');
        }
        $this->_user_agent = Helper::notNull(self::$user_agent, 'cURL php ' . phpversion());
    }

    public function isValidAuth($merchant = '')
    {
        $this->initCurl(
            $this->_endpoint .
            '/merchants' .
            ($merchant?'/'.urlencode($merchant):'').
            '/credentials'
        );
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
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('Accept: text/html'));
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
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('Accept: text/html'));
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
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('Accept: text/html'));
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
        if (preg_match('/^Location:\s+([^\n\r]+)/mi', $this->headers, $m)) {
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
        curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, array(&$this, 'storeHeaders'));
        $this->headers = '';
    }

    private function verbThePayload($verb, $payload)
    {
        $data_string = json_encode(Helper::removeNulls($payload));
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $verb);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt(
            $this->ch,
            CURLOPT_HTTPHEADER,
            array(
                'Accept: application/json',
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        $this->sendRequest();
    }

    private function sendRequest()
    {
        $this->success = false;
        if ($this->_debug) {
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
        }
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

    private function storeHeaders($ch, $header)
    {
        $this->headers .= $header;

        return strlen($header);
    }
}
