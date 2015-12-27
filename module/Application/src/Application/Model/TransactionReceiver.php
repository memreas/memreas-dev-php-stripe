<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class TransactionReceiver {
	public $transactionReceiverId;
	public $transactionId;
	public $accountId;
	public $amount;
	public $email;
	public $primaryReceiver;
	public $createTime;
	public $updateTime;
	public function exchangeArray($data) {
		$this->transactionReceiverId = (isset ( $data ['transaction_receiver_id'] )) ? $data ['transaction_receiver_id'] : null;
		$this->transactionId = (isset ( $data ['transaction_id'] )) ? $data ['transaction_id'] : null;
		$this->accountId = (isset ( $data ['account_id'] )) ? $data ['account_id'] : null;
		$this->amount = (isset ( $data ['amount'] )) ? $data ['amount'] : null;
		$this->email = (isset ( $data ['email'] )) ? $data ['email'] : null;
		$this->primaryReceiver = (isset ( $data ['primary_receiver'] )) ? $data ['primary_receiver'] : null;
		$this->createTime = (isset ( $data ['create_time'] )) ? $data ['create_time'] : null;
		$this->updateTime = (isset ( $data ['update_time'] )) ? $data ['update_time'] : null;
	}
}