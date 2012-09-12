<?php
/**
 * Copyright (c) 2012, Rakuten Deutschland GmbH. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Rakuten Deutschland GmbH nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL RAKUTEN DEUTSCHLAND GMBH BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING
 * IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class rakuten_checkout extends rakuten_checkout_parent /* extends oxbasket */
{
    const ROCKIN_SANDBOX_URL            = 'https://sandbox.rakuten-checkout.de/rockin';
    const ROCKIN_LIVE_URL               = 'https://secure.rakuten-checkout.de/rockin';

    const RAKUTEN_PIPE_URL              = 'https://images.rakuten-checkout.de/images/files/pipe.html';

    /**
     * Tax class mapping
     *
     * @var array
     */
    public $taxClassMap = array(
        '1' => 0,       // DE 0%
        '2' => 7,       // DE 7%
        '3' => 10.7,    // DE 10.7%
        '4' => 19,      // DE 19%
        //'5' => 0,     // AT 0%
        '6' => 10,      // AT 10%
        '7' => 12,      // AT 12%
        '8' => 20,      // AT 20%
    );

    /**
     * Default tax class
     *
     * @var string
     */
    public $taxClassDefault = '4';

    /**
     * Default log filename
     *
     * @var string
     */
    const DEFAULT_LOG_FILE = 'payment_rakuten_checkout.log';

    /**
     * ROPE request data
     *
     * @var SimpleXMLElement|string
     */
    protected $_request = null;

    /**
     * XML node to access ordered items
     *
     * @var string
     */
    protected $_orderNode = '';

    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();

    function _strGetCSV($input, $delimiter=',', $enclosure='"', $escape=null, $eol=null)
    {
        $temp=fopen("php://memory", "rw");
        fwrite($temp, $input);
        fseek($temp, 0);
        $r = array();
        while (($data = fgetcsv($temp, 4096, $delimiter, $enclosure)) !== false) {
            $r[] = $data;
        }
        fclose($temp);
        return $r;
    }

    /**
     * Convert encoding of the string to UTF-8
     * and escape ampersands in the string for XML
     * (required by addChild() simpleXML function)
     *
     * @param  string $string
     * @return string
     */
    protected function _escapeStr($string)
    {
        $string = mb_convert_encoding($string, 'UTF-8', 'auto');
        $string = str_replace('&', '&amp;', $string);
        return $string;
    }

    /**
     * Add CDATA to simpleXML node
     *
     * @param  SimpleXMLElement $node
     * @param  string $value
     * @return void
     */
    protected function _addCDATA($node, $value)
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'auto');
        $domNode = dom_import_simplexml($node);
        $domDoc = $domNode->ownerDocument;
        $domNode->appendChild($domDoc->createCDATASection($value));
    }

    /**
     * Get redirect URL or inline iFrame code
     *
     * @param  bool $inline
     * @return bool
     * @throws Exception|oxException
     */
    public function getRedirectUrl($inline = false)
    {
        // TODO: implement currency check
        // Is current currency supported?
        // if (!$this->canUseForCurrency()) {
        //     return false;
        // }
        // Create Rakuten Checkout Insert Cart XML request
        $xml = new SimpleXMLElement("<?xml version='1.0' encoding='UTF-8' ?><tradoria_insert_cart />");

        $merchantAuth = $xml->addChild('merchant_authentication');
        // TODO: investigate why shop is saved as "-1":
        $merchantAuth->addChild('project_id', $this->getConfig()->getShopConfVar('sRakutenProjectId', -1));
        $merchantAuth->addChild('api_key', $this->getConfig()->getShopConfVar('sRakutenApiKey', -1));


        $sLanguageCode = oxLang::getInstance()->getLanguageAbbr();
        if ($sLanguageCode) {
            $sLanguageCode = strtoupper($sLanguageCode);
        }
        if ($sLanguageCode != 'DE') {
            $sLanguageCode = 'DE'; // TODO: check supported languages based on supported languages list
        }

        $sCurrency = $this->getSession()->getBasket()->_oCurrency->name;
        if ($sCurrency != 'EUR') {
            die('Unsupported currency'); // TODO: improve currency check!
        }

        $xml->addChild('language', $sLanguageCode);
        $xml->addChild('currency', $sCurrency);

        $merchantCart = $xml->addChild('merchant_carts')->addChild('merchant_cart');

        $sSessionName = $this->getSession()->getName();
        $sSessionId = $this->getSession()->getId();

        $merchantCart->addChild('custom_1', $sSessionName);
        $merchantCart->addChild('custom_2', $sSessionId);
        $merchantCart->addChild('custom_3');
        $merchantCart->addChild('custom_4');

        $merchantCartItems = $merchantCart->addChild('items');

        $items = $this->getSession()->getBasket()->_aBasketContents;

        /** @var $item oxBasketItem */
        foreach ($items as $item) {
            $merchantCartItemsItem = $merchantCartItems->addChild('item');

            $merchantCartItemsItemName = $merchantCartItemsItem->addChild('name');
            $this->_addCDATA($merchantCartItemsItemName, $item->getTitle());

            $merchantCartItemsItem->addChild('sku', $this->_escapeStr($item->getArticle()->oxarticles__oxartnum->value));
            $merchantCartItemsItem->addChild('external_product_id');
            $merchantCartItemsItem->addChild('qty', $item->getAmount()); // positive integers
            $merchantCartItemsItem->addChild('unit_price', $item->getUnitPrice()->getBruttoPrice());
            $merchantCartItemsItem->addChild('tax_class', $this->getRakutenTaxClass($item->getPrice()->getVat()));
            $merchantCartItemsItem->addChild('image_url', $this->_escapeStr($item->getIconUrl()));
            $merchantCartItemsItem->addChild('product_url', $this->_escapeStr($item->getLink()));

            $options = $item->getVarSelect();
            if (!empty($options)) {
                $custom = $options;
                $comment = $options;
            } else {
                $custom = '';
                $comment = '';
            }

            $merchantCartItemsItemComment = $merchantCartItemsItem->addChild('comment');
            $this->_addCDATA($merchantCartItemsItemComment, $comment);

            $merchantCartItemsItemCustom = $merchantCartItemsItem->addChild('custom');
            $this->_addCDATA($merchantCartItemsItemCustom, $custom);
        }

        $merchantCartShippingRates = $merchantCart->addChild('shipping_rates');

        $shippingRates = $this->_strGetCSV($this->getConfig()->getShopConfVar('sRakutenShippingRates', -1));

        foreach ($shippingRates as $shippingRate) {
            if (isset($shippingRate[0]) && isset($shippingRate[1]) && is_numeric($shippingRate[1])) {
                // TODO: check if buyer country is supported
                $merchantCartShippingRate = $merchantCartShippingRates->addChild('shipping_rate');
                $merchantCartShippingRate->addChild('country', (string)$shippingRate[0]);
                $merchantCartShippingRate->addChild('price', (float)$shippingRate[1]);
                if (isset ($shippingRate[2]) && (int)$shippingRate[2]>0) {
                    $merchantCartShippingRate->addChild('delivery_date', date('Y-m-d', strtotime('+' . (int)$shippingRate[2] . ' days')));
                }
            }
        }

        $billingAddressRestrictions = $xml->addChild('billing_address_restrictions');
                                            // restrict invoice address to require private / commercial and by country
        $billingAddressRestrictions->addChild('customer_type')->addAttribute('allow', $this->getConfig()->getShopConfVar('iRakutenBillingAddr', -1));
                                                                                        // 1=all 2=business 3=private

        $aCountries = array();

        /** @var $oCountryList oxCountryList */
        $oCountryList = oxNew('oxcountrylist');
        $oCountryList->loadActiveCountries();

        /** @var $oCountry oxCountry */
        foreach ($oCountryList as $sCountryId => $oCountry) {
            $oCountry->load($sCountryId);
            $aCountries[] = $oCountry->oxcountry__oxisoalpha2->value;
        }

        if (!empty($aCountries)) {
            $billingAddressRestrictions->addChild('countries')->addAttribute('allow', implode(',', $aCountries));
        }

        
        $baseUrl = $this->getConfig()->getSslShopUrl();
        // Force SID for ROPE URL to load shopping cart data and flush it when order is saved
        $ropeUrl = $baseUrl . oxUtilsUrl::getInstance()->processUrl('index.php', true, array('cl'=>'rakuten', 'fnc'=>'rope'));
        // No forced SID for PIPE URL to avoid session switches after opening Rakuten Checkout iFrame
        $pipeUrl = oxUtilsUrl::getInstance()->processUrl($baseUrl . 'index.php', true, array('cl'=>'rakuten', 'fnc'=>'pipe'));

        $xml->addChild('callback_url', $ropeUrl);
        $xml->addChild('pipe_url', $pipeUrl);

        $request = $xml->asXML();

        $response = $this->sendRequest($request);

        if (!$response) {
            return false;
        }

        try {
            $response = new SimpleXMLElement($response);

            if ($response->success != 'true') {
                throw new oxException((string)$response->message, (int)$response->code);
            } else {
                $redirectUrl = $response->redirect_url;
                $inlineCode = $response->inline_code;
            }
        } catch (oxException $e) {
            oxUtilsView::getInstance()->addErrorToDisplay(sprintf('Error #%s: %s', $e->getCode(), $e->getMessage()));
            return false;
        } catch (Exception $e) {
            oxUtilsView::getInstance()->addErrorToDisplay('Unable to redirect to Rakuten Checkout.');
            return false;
        }

        if ($inline) {
            return $inlineCode;
        } else {
            return $redirectUrl;
        }
    }

    /**
     * Send request to Rakuten Checkout
     *
     * @param  string $xml
     * @return array|bool|string
     * @throws Exception
     */
    public function sendRequest($xml)
    {
        try {
            $rockinUrl = $this->getRockinUrl();

            // TODO: add debugging
            // $this->_debugData['request_url'] = $this->_config->getRockinUrl();
            // $this->_debugData['request'] = $xml;

            //setting the curl parameters.
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $rockinUrl);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);

            //setting the request
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            //getting response from server
            $response = curl_exec($ch);

            if(curl_errno($ch)) {
                // TODO: log error, redirect to ERROR_URL
                // moving to display page to display curl errors
                // $_SESSION['curl_error_no'] = curl_errno($ch);
                // $_SESSION['curl_error_msg'] = curl_error($ch);
                throw new Exception(curl_error($ch), curl_errno($ch));
                // return $this->errorUrl;
            } else {
                //closing the curl
                curl_close($ch);
            }
        } catch (Exception $e) {
            // TODO: log error, redirect to ERROR_URL
            // $this->_debugData['http_error'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
            // $this->_debug($this->_debugData);
            // throw $e;
            oxUtilsView::getInstance()->addErrorToDisplay(sprintf('CURL Error #%s: %s', $e->getCode(), $e->getMessage()));
            return false;
        }

        // TODO: log response...
        // $this->_debugData['response'] = $response;
        // $this->_debug($this->_debugData);

        return $response;
    }

    /**
     * Get API request URL on Rakuten Checkout side
     * Get either Live or Sandbox Rockin URL based on current configuration settings
     *
     * @return string
     */
    public function getRockinUrl()
    {
        if ($this->getConfig()->getShopConfVar('blRakutenSandboxMode', -1)) {
            return self::ROCKIN_SANDBOX_URL;
        } else {
            return self::ROCKIN_LIVE_URL;
        }
    }

    /**
     * Get Pipe Source URL for Inline integration method
     * (to avoid cross-domain iframe resize restrictions)
     *
     * @return string
     */
    public function getRakutenPipeUrl()
    {
        return self::RAKUTEN_PIPE_URL;
    }

    /**
     * Check if current currency is supported by Rakuten Checkout
     *
     * @param  float $percent
     * @return string
     */
    public function getRakutenTaxClass($percent)
    {
        if ($taxClass = array_search($percent, $this->taxClassMap)) {
            return $taxClass;
        } else {
            return $this->taxClassDefault;
        }
    }

    /**
     * Get ROPE data, run corresponding handler
     *
     * @param  string $request - incoming XML request
     * @return string
     * @throws Exception
     */
    public function processRopeRequest($request)
    {
        $this->_request = $request;
        //$this->_debugData['request'] = $request;

        try {
            $this->_request = new SimpleXMLElement(urldecode($request), LIBXML_NOCDATA);

            // Check type of request and call proper handler
            switch ($this->_request->getName()) {
                case 'tradoria_check_order':
                    $this->_orderNode = 'order';
                    $responseTag = 'tradoria_check_order_response';
                    $response = $this->_checkOrder();
                    break;
                case 'tradoria_order_process':
                    $this->_orderNode = 'cart';
                    $responseTag = 'tradoria_order_process_response';
                    $response = $this->_processOrder();
                    break;
                case 'tradoria_order_status':
                    $responseTag = 'tradoria_order_status_response';
                    $response = $this->_statusUpdate();
                    break;
                default:
                    // Error - Unrecognized request
                    $responseTag = 'unknown_error';
                    $response = false;
            }
        } catch (Exception $e) {
            //$this->_debugData['exception'] = $e->getMessage();
            return $this->prepareResponse(false);
        }

        return $this->prepareResponse($response, $responseTag);
    }

    /**
     * Prepare XML response
     *
     * @param  bool $success - if need to prepare successful or unsuccessful response
     * @param  string $tag - root node tag for the response
     * @return string
     */
    public function prepareResponse($success, $tag = 'general_error')
    {
        if ($success === true) {
            $success = 'true';
        } elseif ($success === false) {
            $success = 'false';
        } else {
            $success = (string)$success;
        }

        $xml = new SimpleXMLElement("<?xml version='1.0' encoding='UTF-8' ?><{$tag} />");
        $xml->addChild('success', $success);
        $response = $xml->asXML();

        return $response;
    }

    /**
     * Validate authentication data passed in the request against configuration values
     *
     * @return bool
     */
    protected function _auth()
    {
        $projectId = $this->getConfig()->getShopConfVar('sRakutenProjectId', -1);
        $apiKey = $this->getConfig()->getShopConfVar('sRakutenApiKey', -1);

        if ($this->_request->merchant_authentication->project_id == $projectId
            && $this->_request->merchant_authentication->api_key == $apiKey) {
            return true;
        }

        //$this->_debugData['reason'] = 'Auth failed';
        return false;
    }

    /**
     * Compare Oxid basket and shopping cart details from the request
     *
     * @return bool
     */
    protected function _validateQuote()
    {
        try{
            $quoteItems = $this->getSession()->getBasket()->_aBasketContents;

            $quoteItemsArray = array();

            /** @var $xmlItems SimpleXMLElement */
            $xmlItems = $this->_request->{$this->_orderNode}->items;

            $xmlItemsArray = array();

            foreach ($quoteItems as $item) {
                /** @var $item oxBasketItem */
                $quoteItemsArray[(string)$item->getArticle()->oxarticles__oxartnum->value] = $item;
            }

            foreach ($xmlItems->children() as $item) {
                /** @var $item SimpleXMLElement */
                $xmlItemsArray[(string)$item->sku] = $item;
            }

            //$this->_debugData['xmlItemsArray'] = implode(', ', array_keys($xmlItemsArray));

            // Validation of the shopping cart
            if (count($quoteItemsArray) != count($xmlItemsArray)) {
                //$this->_debugData['reason'] = 'Quote validation failed: Qty of items';
                return false;
            }

            foreach ($quoteItemsArray as $itemId=>$item) {
                if (!isset($xmlItemsArray[$itemId])) {
                    //$this->_debugData['reason'] = 'Quote validation failed: SKU doesn\'t exist';
                    return false;
                }
                $xmlItem = $xmlItemsArray[$itemId];
                if ($item->getAmount() != (int)$xmlItem->qty
                    || round($item->getUnitPrice()->getBruttoPrice(), 2) != round((float)$xmlItem->price, 2)
                ) {
                    //$this->_debugData['reason'] = 'Quote validation failed: Items don\'t match';
                    return false;
                }
            }
        } catch (Exception $e){
            //$this->debug($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Check qty in stock/product availability.
     * Called by Rakuten Checkout before order placement
     *
     * @return bool
     */
    protected function _checkOrder()
    {
        if (!$this->_auth()) {
            return false;
        }

        if (!$this->_validateQuote()) {
            return false;
        }

        /** @var $oOrder oxorder */
        $oOrder = oxNew( 'oxorder' );

        try {
            $oOrder->validateStock($this->getSession()->getBasket());
        } catch (Exception $e) {
            //$this->_debugData['reason'] = 'Item availability check failed';
            return false;
        }
        return true;
    }

    /**
     * Place the order
     *
     * @return bool
     */
    protected function _processOrder()
    {
        if (!$this->_auth()) {
            return false;
        }

        if (!$this->_validateQuote()) {
            return false;
        }

        try {
            // TODO: To avoid duplicates look for order with the same Rakuten order no

            /** @var $oUser oxUser */
            $oUser = oxNew('oxuser');
            $oUser->loadActiveUser();

            /** @var $oOrder oxOrder */
            $oOrder = oxNew('oxorder');

            /** @var $oCountry oxCountry */
            $oCountry = oxNew('oxcountry');

            // $oUser = $this->getSession()->getBasket->getUser();

            $address = $this->_request->client; // Billing Address

            $oUser->oxuser__oxcompany   = new oxField((string)$address->company);
            $oUser->oxuser__oxusername  = new oxField((string)$address->email);
            $oUser->oxuser__oxfname     = new oxField((string)$address->first_name);
            $oUser->oxuser__oxlname     = new oxField((string)$address->last_name);
            $oUser->oxuser__oxstreet    = new oxField((string)$address->street);
            $oUser->oxuser__oxstreetnr  = new oxField((string)$address->street_no);
            $oUser->oxuser__oxaddinfo   = new oxField((string)$address->address_add);
            $oUser->oxuser__oxustid     = new oxField('');
            $oUser->oxuser__oxcity      = new oxField((string)$address->city);

            $sCountryId = $oUser->getUserCountryId((string)$address->country);
            $oUser->oxuser__oxcountryid = new oxField($sCountryId ? $sCountryId : (string)$address->country);
            // $oUser->oxuser__oxcountry   = new oxField($oUser->getUserCountry($sCountryId));

            $oUser->oxuser__oxstateid   = new oxField('');
            $oUser->oxuser__oxzip       = new oxField((string)$address->zip_code);
            $oUser->oxuser__oxfon       = new oxField((string)$address->phone);
            $oUser->oxuser__oxfax       = new oxField('');

            switch ((string)$address->gender) {
                case 'Herr':
                    $sGender = 'MR';
                    break;
                case 'Frau':
                    $sGender = 'MRS';
                    break;
                default:
                    $sGender = '';
            }
            $oUser->oxuser__oxsal       = new oxField($sGender);

            // $oDelAdress = oxNew( 'oxaddress' );

            $address = $this->_request->delivery_address; // Shipping Address

            $oOrder->oxorder__oxdelcompany  = new oxField((string)$address->company);
            $oOrder->oxorder__oxdelfname    = new oxField((string)$address->first_name);
            $oOrder->oxorder__oxdellname    = new oxField((string)$address->last_name);
            $oOrder->oxorder__oxdelstreet   = new oxField((string)$address->street);
            $oOrder->oxorder__oxdelstreetnr = new oxField((string)$address->street_no);
            $oOrder->oxorder__oxdeladdinfo  = new oxField((string)$address->address_add);
            $oOrder->oxorder__oxdelcity     = new oxField((string)$address->city);

            $sCountryId = $oUser->getUserCountryId((string)$address->country);
            $oOrder->oxorder__oxdelcountryid= new oxField($sCountryId ? $sCountryId : (string)$address->country);
            // $oOrder->oxorder__oxdelcountry   = new oxField($oUser->getUserCountry($sCountryId));

            $oOrder->oxorder__oxdelstateid  = new oxField('');
            $oOrder->oxorder__oxdelzip      = new oxField((string)$address->zip_code);
            $oOrder->oxorder__oxdelfon      = new oxField((string)$address->phone);
            $oOrder->oxorder__oxdelfax      = new oxField('');

            switch ((string)$address->gender) {
                case 'Herr':
                    $sGender = 'MR';
                    break;
                case 'Frau':
                    $sGender = 'MRS';
                    break;
                default:
                    $sGender = '';
            }
            $oOrder->oxorder__oxdelsal      = new oxField($sGender);

            // get delivery country name from delivery country id
            // if ( $oDelAdress->oxaddress__oxcountryid->value && $oDelAdress->oxaddress__oxcountryid->value != -1 ) {
            //     $oCountry = oxNew( 'oxcountry' );
            //     $oCountry->load( $oDelAdress->oxaddress__oxcountryid->value );
            //     $oDelAdress->oxaddress__oxcountry = clone $oCountry->oxcountry__oxtitle;
            // }

            $sGetChallenge = oxSession::getVar( 'sess_challenge' );
            $oOrder->setId($sGetChallenge);

            $oOrder->oxorder__oxfolder = new oxField(key($this->getConfig()->getShopConfVar('aOrderfolder', $this->getConfig()->getShopId())), oxField::T_RAW);

            $message = '';

            if (trim((string)$this->_request->comment_client) != '') {
                $message .= sprintf('Customer\'s Comment: %s', trim((string)$this->_request->comment_client) . " // \n");
            }

            $message .= sprintf('Rakuten Order No: %s', (string)$this->_request->order_no . " // \n")
                . sprintf('Rakuten Client ID: %s', (string)$this->_request->client->client_id);

            $oOrder->oxorder__oxremark = new oxField($message, oxField::T_RAW);

            $res = $oOrder->finalizeOrder($this->getSession()->getBasket(), $oUser, true);
            if ($res == 1) { // OK
                $oOrder->oxorder__oxpaymenttype     = new oxField('rakuten');
                $oOrder->oxorder__oxpaymentid       = new oxField();
                $oOrder->oxorder__oxtransid         = new oxField((string)$this->_request->order_no);
                $oOrder->oxorder__oxtransstatus     = new oxField('New');

                $oOrder->oxorder__oxartvatprice1    = new oxField((float)$this->_request->total_tax_amount, oxField::T_RAW);
                $oOrder->oxorder__oxartvatprice2    = new oxField(0, oxField::T_RAW);
                $oOrder->oxorder__oxdelcost         = new oxField((float)$this->_request->shipping, oxField::T_RAW);
                $oOrder->oxorder__oxpaycost         = new oxField(0, oxField::T_RAW);
                $oOrder->oxorder__oxwrapcost        = new oxField(0, oxField::T_RAW); // TODO: support gift wrapping somehow
                $oOrder->oxorder__oxdiscount        = new oxField(0, oxField::T_RAW);

                $subtotal = (float)$this->_request->total - (float)$this->_request->total_tax_amount - (float)$this->_request->shipping;
                $oOrder->oxorder__oxtotalnetsum     = new oxField($subtotal, oxField::T_RAW);
                $oOrder->oxorder__oxtotalbrutsum    = new oxField($subtotal + (float)$this->_request->total_tax_amount, oxField::T_RAW);
                $oOrder->oxorder__oxtotalordersum   = new oxField((float)$this->_request->total, oxField::T_RAW);

                $oOrder->save();

                $this->getSession()->getBasket()->deleteBasket();
            } else { // Error
                return false;
            }
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Update order status (create invoice, shipment, cancel the order)
     *
     * @return bool
     */
    protected function _statusUpdate()
    {
        if (!$this->_auth()) {
            return false;
        }

        try {
            $rakuten_order_no = (string)$this->_request->order_no;

            /** @var $oOrder oxOrder */
            $oOrder = oxNew('oxorder');
            /**
             * Copy&paste from oxBase::load() to load order by Rakuten order number
             */
            $aSelect = $oOrder->buildSelectString(array($oOrder->getViewName().'.oxtransid' => $rakuten_order_no));
            $oOrder->assignRecord($aSelect);

            /**
             * Check if order exists
             */
            if (!$oOrder->getId()) {
                // $this->_debugData['reason'] = 'No corresponding orders found';
                return false;
            }

            $status = (string)$this->_request->status;

            switch ($status) {
                case 'editable':
                    // Processing
                    $oOrder->oxorder__oxtransstatus = new oxField('Processing');
                    $oOrder->save();
                    break;
                case 'shipped':
                    // Shipped
                    $oOrder->oxorder__oxtransstatus = new oxField('Shipped');
                    $oOrder->save();
                    break;
                case 'cancelled':
                    // Cancelled
                    $oOrder->oxorder__oxtransstatus = new oxField('Cancelled');
                    $oOrder->save();
                    break;
                default:
                    // Error - Unrecognized request
                    $oOrder->oxorder__oxtransstatus = new oxField('Unknown');
                    $oOrder->save();
                    return false;
            }
        } catch (Exception $e) {
            // $this->_debugData['exception'] = $e->getMessage();
            return false;
        }

        return true;
    }
}
