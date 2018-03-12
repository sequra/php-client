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

    public function startSolicitation($order)
    {
        if ( ! $this->qualifyForSolicitation($order) && ! $this->_debug) {
            return;
        }

        $this->initCurl($this->_endpoint . '/orders');
        $this->verbThePayload('POST', array('order' => $order));
        if ($this->status == 204) {
            $this->success = true;
            $this->log("Start " . $this->status . ": Ok!");
        } elseif ($this->status >= 200 && $this->status <= 299 || $this->status == 409) {
            $this->json = json_decode($this->curl_result, true); // return array, not object
            $this->log("Start " . $this->status . ": " . $this->curl_result);
        }
        curl_close($this->ch);
    }

    public function qualifyForSolicitation($order)
    {
        if ($order['cart']['order_total_with_tax'] <= 0) {
            return false;
        }
        if ( ! Helper::isConsistentCart($order['cart'])) {
            return false;
        }

        return true;
    }

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
        if ($this->_debug) {
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
                'Accept: application/json',
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        $this->sendRequest();
    }

    private function sendRequest()
    {
        $this->curl_result = curl_exec($this->ch);
        $this->status      = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    }

    function log($msg)
    {
        if ( ! $this->_debug) {
            return;
        }
        if ( ! $this->_logfile) {
            error_log($msg);
        } else {
            error_log($msg . "\n", 3, $this->_logfile);
        }
    }

    public function getIdentificationForm($uri, $options = array())
    {
        $options["product"] = array_key_exists('product', $options) ? $options["product"] : "i1";
        $options["ajax"]    = (isset($options["ajax"]) && $options["ajax"]) ? "true" : "false";
        $this->initCurl($uri . '/form_v2' . '?' . http_build_query($options));
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('Accept: text/html'));
        $this->sendRequest();

        if ($this->status >= 200 && $this->status <= 299) {
            curl_close($this->ch);
            $this->success = true;

            return $this->curl_result;
        } else {
            $this->log("Error " . $this->status . ": " . print_r($this->curl_result, true));
        }
        curl_close($this->ch);
    }

    public function sendIdentificationForm($uri, $options = array())
    {
        $options["product"] = array_key_exists('product', $options) ? $options["product"] : "i1";
        $options["product_code"] = $options["product"];
        $options["channel"] = array_key_exists('channel', $options) ? $options["channel"] : "sms";
        $this->initCurl($uri . '/form_deliveries');
        $this->verbThePayload('POST',$options);
        $this->sendRequest();

        if ($this->status >= 200 && $this->status <= 299) {
            curl_close($this->ch);
            $this->success = true;

            return $this->curl_result;
        } else {
            $this->log("Error " . $this->status . ": " . print_r($this->curl_result, true));
        }
        curl_close($this->ch);
    }

    public function getCreditAgreements($amount, $merchant)
    {
        $uri = $this->_endpoint . '/merchants/' . $merchant . '/credit_agreements?total_with_tax=' . $amount . '&currency=EUR&locale=es-ES&country=ES';
        $this->initCurl($uri);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "GET");
        $this->sendRequest();

        if ($this->status >= 200 && $this->status <= 299) {
            $this->success = true;
            curl_close($this->ch);

            return json_decode($this->curl_result, true);
        } else {
            $this->log("Error " . $this->status . ": " . print_r($this->curl_result, true));
        }
        curl_close($this->ch);
    }

    public function updateOrder($uri, $order)
    {
        if ( ! preg_match('!^https?://!', $uri)) {
            $uri = $this->_endpoint . '/orders/' . $uri;
        }
        $this->initCurl($uri);
        $this->verbThePayload('PUT', array('order' => $order));

        if ($this->status >= 200 && $this->status <= 299) {
            $this->success = true;
        } elseif ($this->status == 409) {
            $this->cart_has_changed = true;
            $this->json             = json_decode($this->curl_result, true);
        }
        curl_close($this->ch);
    }

    public function sendDeliveryReport($delivery_report)
    {
        $this->initCurl($this->_endpoint . '/delivery_reports');
        $this->verbThePayload('POST', array('delivery_report' => $delivery_report));

        if ($this->status >= 200 && $this->status <= 299) {
            $this->success = true;
            $this->log("Delivery " . $this->status . ": Ok!");
        } elseif ($this->status >= 200 && $this->status <= 299 || $this->status == 409) {
            $this->json = json_decode($this->curl_result, true); // return array, not object
            $this->log("Delivery " . $this->status . ": " . print_r($this->json, true));
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

    // Private methods below

    public function getStatus()
    {
        return $this->status;
    }

    public function cartHasChanged()
    {
        return $this->cart_has_changed;
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

    private function storeHeaders($ch, $header)
    {
        $this->headers .= $header;

        return strlen($header);
    }
}
