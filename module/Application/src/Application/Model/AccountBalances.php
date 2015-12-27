<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class AccountBalances {
	public $account_id;
	public $transaction_id;
	public $transaction_type;
	public $starting_balance;
	public $amount;
	public $ending_balance;
	public $create_time;
	public function exchangeArray($data) {
		$this->account_id = (isset ( $data ['account_id'] )) ? $data ['account_id'] : $this->account_id;
		$this->transaction_id = (isset ( $data ['transaction_id'] )) ? $data ['transaction_id'] : $this->transaction_id;
		$this->transaction_type = (isset ( $data ['transaction_type'] )) ? $data ['transaction_type'] : $this->transaction_type;
		$this->starting_balance = (isset ( $data ['starting_balance'] )) ? $data ['starting_balance'] : $this->starting_balance;
		$this->amount = (isset ( $data ['amount'] )) ? $data ['amount'] : $this->amount;
		$this->ending_balance = (isset ( $data ['ending_balance'] )) ? $data ['ending_balance'] : $this->ending_balance;
		$this->create_time = (isset ( $data ['create_time'] )) ? $data ['create_time'] : $this->create_time;
	}
}