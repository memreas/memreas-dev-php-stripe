<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class Account {
	public $account_id;
	public $user_id;
	public $username;
	public $account_type;
	public $balance;
	public $create_time;
	public $update_time;
	public function exchangeArray($data) {
		$this->account_id = (isset ( $data ['account_id'] )) ? $data ['account_id'] : $this->account_id;
		$this->user_id = (isset ( $data ['user_id'] )) ? $data ['user_id'] : $this->user_id;
		$this->username = (isset ( $data ['username'] )) ? $data ['username'] : $this->username;
		$this->account_type = (isset ( $data ['account_type'] )) ? $data ['account_type'] : $this->account_type;
		$this->balance = (isset ( $data ['balance'] )) ? $data ['balance'] : $this->balance;
		$this->create_time = (isset ( $data ['create_time'] )) ? $data ['create_time'] : $this->create_time;
		$this->update_time = (isset ( $data ['update_time'] )) ? $data ['update_time'] : $this->update_time;
	}
}