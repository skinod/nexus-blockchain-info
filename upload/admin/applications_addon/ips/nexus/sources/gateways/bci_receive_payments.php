<?php

class gateway_bci_receive_payments extends gatewayCore
{

    /**
     * Specify the maximum amounts
     *
     * @var    array
     */
    public $maxAmounts = array(
		"*"    => "*",
	);
	
	public static $isItTrue = false;

		
    /**
     * Specify if this gateway requires https to be used
     *
     * @var    bool
     */
    public $requireHttps = FALSE;
	
	
    /**
     * Show payment screen
     *
     * @return    array
     */
    public function payScreen()
    {
		// init
		$return  = array(
            "formUrl"	=> $this->registry->output->buildSEOUrl( "app=nexus&module=payments&section=receive&check=bci_receive_payments&custom={$this->transaction['t_id']}", 'public' ),
            // "button"	=> $this->registry->output->getTemplate('nexus_payments')->confirmButton($this->invoice, "return bci_receive_payments(event)"),
		);
		
		// find secret code
		$extra = unserialize($this->invoice->__get('status_extra'));
		
		// create secret code if we don't have
		if(!isset($extra['bcirp_secret'])) {
			$extra['bcirp_secret'] = md5(md5(uniqid()) . md5($this->settings['sql_pass']) );
			$this->invoice->__set('status_extra', serialize($extra));
			$this->invoice->save();
		}
		
		// make callback url
		$callback_url = $this->registry->output->buildSEOUrl( "app=nexus&module=payments&section=receive&validate=bci_receive_payments&nexusinvoice={$this->invoice->__get('id')}&transid={$this->transaction['t_id']}&sec={$extra['bcirp_secret']}", 'public' );
		
		// make params
		$parameters = trim("method=create&address={$this->method['m_settings']['wallet_addr']}&callback=" . urlencode($callback_url));
		
		// create curl
		$ch = curl_init('https://blockchain.info/api/receive');
		curl_setopt_array($ch, array(
			CURLOPT_POSTFIELDS => $parameters,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_PORT => 443,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => 1,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FORBID_REUSE => 1,
			CURLOPT_FRESH_CONNECT => 1,
			CURLOPT_CAINFO => realpath(IPSLib::getAppDir('nexus') .'/sources/gateways/libs/data/ca-certificates.crt')
		));

		// run curl
		$response = json_decode(curl_exec($ch));
		
		// get curl errors
		$err = curl_error($ch);
		
		curl_close($ch);
		
		// send js code to submit form in error case
		if(!empty($err) or !$response->input_address) {
			$return['formUrl'] = $this->registry->output->buildSEOUrl( "app=nexus&module=payments&section=pay&id={$this->invoice->__get('id')}&fail=" . urlencode($this->lang->words['checkout_error']), 'public' );
			$return['js'] = "document.forms.do_pay.submit();";
		}else{ // make html and send it!
			$err = '';
			if($this->method['m_settings']['use_online_ex']) {
				// get price
				$ch = curl_init("https://blockchain.info/tobtc?currency={$this->settings['nexus_currency']}&value={$this->transaction['t_amount']}");
				curl_setopt_array($ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
					CURLOPT_PORT => 443,
					CURLOPT_TIMEOUT => 10,
					CURLOPT_SSL_VERIFYPEER => 1,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FORBID_REUSE => 1,
					CURLOPT_FRESH_CONNECT => 1,
					CURLOPT_CAINFO => realpath(IPSLib::getAppDir('nexus') .'/sources/gateways/libs/data/ca-certificates.crt')
				));

				// run curl
				$extra['bcirp_btc'] = floatval(curl_exec($ch));
				
				// get curl errors
				$err = curl_error($ch);
				
				curl_close($ch);
				
			}else{
				$extra['bcirp_btc'] = $this->transaction['t_amount'] * $this->method['m_settings']['fixed_price'];
			}
			
			// save btc amount to invoice
			if( empty($err) ) {
				$this->invoice->__set('status_extra', serialize($extra));
				$this->invoice->save();
			}
			
			$return['html']	= $this->registry->getClass("output")->getTemplate("nexus_payments")->sod_bci_payment($response->input_address, $extra['bcirp_btc'], $this->method['m_settings']['show_qr']);
			
			if($this->settings['sod_rm_bci_cpr'] !== TRUE)
				$return['html']	.= '<span style="float: right; font-weight: bold; clear: both; margin: -5px 5px 5px 0px; font-size: 11px;">By <a href="http://skinod.com">Skinod</a></span><br>';
		}
		
		// return stuff
		return $return;
		
    }

	
	/**
	 * Validate Payment
	 * 
	 * @return	array
	 */
    public function validatePayment()
    {
		// get secret code
		$extra = unserialize($this->invoice->__get('status_extra'));
		
		// check if we have secret code, is it correct?
		if(isset($extra['bcirp_secret']) && $extra['bcirp_secret'] === trim($_REQUEST['sec']) && isset($extra['bcirp_btc']) ) {
		
			// change Satoshi to BTC
			$value = floatval($_REQUEST['value']) / 100000000;
			
			// set it's true to echo ok
			$this->request['confirmations'] = intval($this->request['confirmations']);
			$c_num = intval($this->method['m_settings']['confirn_number'])?intval($this->method['m_settings']['confirn_number']):6;
			if($this->request['confirmations'] >= $c_num) {
				self::$isItTrue = true;
			}
			
			if(floatval($extra['bcirp_btc']) != $value) {
				return array( 'status' => "hold", 'amount' => $this->transaction['t_amount'], 'gw_id' => $this->request['transaction_hash'], 'note' => "Paid amount is different", );
			}
			
			// return successful transaction
			return array( 'status' => ($this->request['confirmations']>=$c_num?"okay":"hold"), 'amount' => $this->transaction['t_amount'], 'gw_id' => $this->request['transaction_hash'] );
		}
		
        return false;
    }
}