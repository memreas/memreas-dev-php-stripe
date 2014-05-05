<?php
namespace Application\Model;

class Transaction{
	
	public $transaction_id;
	public $account_id;
	public $transaction_type;
	public $pass_fail;
	public $amount;
	public $currency;
	public $transaction_request;
	public $transaction_response;
	public $transaction_sent;
	public $transaction_receive;
		
	public $paypal_item_name;
	public $paypal_item_number;
	public $paypal_payer_id;
	public $paypal_payer_email;
	public $paypal_receiver_id;
	public $paypal_receiver_email;
	
	public $paypal_payment_status;
	public $paypal_payment_amount;
	public $paypal_payment_currency;
	public $paypal_payment_fee;
	public $paypal_tax;
	
	public $paypal_txn_type;
	public $paypal_parent_payment_id;
	public $paypal_txn_id;
	public $paypal_ipn_track_id;
	public $paypal_correlation_id;
	
	public function exchangeArray($data)
	{
		$this->transaction_id = (isset($data['transaction_id'])) ?  $data['transaction_id'] : $this->transaction_id;
		$this->account_id = (isset($data['account_id'])) ?  $data['account_id'] : $this->account_id;
		$this->transaction_type = (isset($data['transaction_type'])) ?  $data['transaction_type'] : $this->transaction_type;
		$this->pass_fail = (isset($data['pass_fail'])) ?  $data['pass_fail'] : $this->pass_fail;
		$this->amount = (isset($data['amount'])) ?  $data['amount'] : $this->amount;
		$this->currency = (isset($data['currency'])) ?  $data['currency'] : $this->currency;
		$this->transaction_request = (isset($data['transaction_request'])) ?  $data['transaction_request'] : $this->transaction_request;
		$this->transaction_response = (isset($data['transaction_response'])) ?  $data['transaction_response'] : $this->transaction_response;
		$this->transaction_sent = (isset($data['transaction_sent'])) ?  $data['transaction_sent'] : $this->transaction_sent;
		$this->transaction_receive = (isset($data['transaction_receive'])) ?  $data['transaction_receive'] : $this->transaction_receive;
		$this->paypal_item_name = (isset($data['paypal_item_name'])) ?  $data['paypal_item_name'] : $this->paypal_item_name;
		$this->paypal_item_number = (isset($data['paypal_item_number'])) ?  $data['paypal_item_number'] : $this->paypal_item_number;
		$this->paypal_payer_id = (isset($data['paypal_payer_id'])) ?  $data['paypal_payer_id'] : $this->paypal_payer_id;
		$this->paypal_payer_email = (isset($data['paypal_payer_email'])) ?  $data['paypal_payer_email'] : $this->paypal_payer_email;
		$this->paypal_receiver_id = (isset($data['paypal_receiver_id'])) ?  $data['paypal_receiver_id'] : $this->paypal_receiver_id;
		$this->paypal_receiver_email = (isset($data['paypal_receiver_email'])) ?  $data['paypal_receiver_email'] : $this->paypal_receiver_email;
		$this->paypal_payment_status = (isset($data['paypal_payment_status'])) ?  $data['paypal_payment_status'] : $this->paypal_payment_status;
		$this->paypal_payment_amount = (isset($data['paypal_payment_amount'])) ?  $data['paypal_payment_amount'] : $this->paypal_payment_amount;
		$this->paypal_payment_currency = (isset($data['paypal_payment_currency'])) ?  $data['paypal_payment_currency'] : $this->paypal_payment_currency;
		$this->paypal_payment_fee = (isset($data['paypal_payment_fee'])) ?  $data['paypal_payment_fee'] : $this->paypal_payment_fee;
		$this->paypal_tax = (isset($data['paypal_tax'])) ?  $data['paypal_tax'] : $this->paypal_tax;
		$this->paypal_txn_type = (isset($data['paypal_txn_type'])) ?  $data['paypal_txn_type'] : $this->paypal_txn_type;
		$this->paypal_parent_payment_id = (isset($data['paypal_parent_payment_id'])) ?  $data['paypal_parent_payment_id'] : $this->paypal_parent_payment_id;
		$this->paypal_txn_id = (isset($data['paypal_txn_id'])) ?  $data['paypal_txn_id'] : $this->paypal_txn_id;
		$this->paypal_ipn_track_id = (isset($data['paypal_ipn_track_id'])) ?  $data['paypal_ipn_track_id'] : $this->paypal_ipn_track_id;
		$this->paypal_correlation_id = (isset($data['paypal_correlation_id'])) ?  $data['paypal_correlation_id'] : $this->paypal_correlation_id;
	}
}