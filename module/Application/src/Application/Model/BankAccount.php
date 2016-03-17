<?php

/**
 * Copyright (C) 2016 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class BankAccount {
	public $bank_account_id;
	public $account_id;
	public $account_detail_id;
	public $stripe_bank_acount_id;
	public $account_holder_name;
	public $account_number;
	public $routing_number;
	public $tax_ssn_ein;
	public $keys;
	public $delete_flag;
	public $create_time;
	public $update_time;
	public function exchangeArray($data) {
		$this->bank_account_id = (isset ( $data ['bank_account_id'] )) ? $data ['bank_account_id'] : $this->bank_account_id;
		$this->account_id = (isset ( $data ['account_id'] )) ? $data ['account_id'] : $this->account_id;
		$this->account_detail_id = (isset ( $data ['account_detail_id'] )) ? $data ['account_detail_id'] : $this->account_detail_id;
		$this->stripe_bank_acount_id = (isset ( $data ['stripe_bank_acount_id'] )) ? $data ['stripe_bank_acount_id'] : $this->stripe_bank_acount_id;
		$this->account_holder_name = (isset ( $data ['account_holder_name'] )) ? $data ['account_holder_name'] : $this->account_holder_name;
		$this->account_number = (isset ( $data ['account_number'] )) ? $data ['account_number'] : $this->account_number;
		$this->routing_number = (isset ( $data ['routing_number'] )) ? $data ['routing_number'] : $this->routing_number;
		$this->tax_ssn_ein = (isset ( $data ['tax_ssn_ein'] )) ? $data ['tax_ssn_ein'] : $this->tax_ssn_ein;
		$this->keys = (isset ( $data ['keys'] )) ? $data ['keys'] : $this->keys;
		$this->delete_flag = (isset ( $data ['delete_flag'] )) ? $data ['delete_flag'] : $this->delete_flag;
		$this->create_time = (isset ( $data ['create_time'] )) ? $data ['create_time'] : $this->create_time;
		$this->update_time = (isset ( $data ['update_time'] )) ? $data ['update_time'] : $this->update_time;
	}
}