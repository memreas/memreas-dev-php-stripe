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
	public $transaction_status;

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
		$this->transaction_status = (isset($data['transaction_status'])) ?  $data['transaction_status'] : $this->transaction_status;
	}
}