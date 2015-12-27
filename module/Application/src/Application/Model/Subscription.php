<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class Subscription {
	public $subscription_id;
	public $account_id;
	public $currency_code;
	public $plan;
	public $plan_amount;
	public $plan_description;
	public $gb_storage_amount;
	public $billing_frequency;
	public $start_date;
	public $end_date;
	public $create_date;
	public $update_time;
	public function exchangeArray($data) {
		$this->subscription_id = (isset ( $data ['subscription_id'] )) ? $data ['subscription_id'] : null;
		$this->account_id = (isset ( $data ['account_id'] )) ? $data ['account_id'] : null;
		$this->currency_code = (isset ( $data ['currency_code'] )) ? $data ['currency_code'] : null;
		$this->plan = (isset ( $data ['plan'] )) ? $data ['plan'] : null;
		$this->plan_amount = (isset ( $data ['plan_amount'] )) ? $data ['plan_amount'] : null;
		$this->plan_description = (isset ( $data ['plan_description'] )) ? $data ['plan_description'] : null;
		$this->gb_storage_amount = (isset ( $data ['gb_storage_amount'] )) ? $data ['gb_storage_amount'] : null;
		$this->billing_frequency = (isset ( $data ['billing_frequency'] )) ? $data ['billing_frequency'] : null;
		$this->start_date = (isset ( $data ['start_date'] )) ? $data ['start_date'] : null;
		$this->end_date = (isset ( $data ['end_date'] )) ? $data ['end_date'] : null;
		$this->paypal_subscription_profile_id = (isset ( $data ['paypal_subscription_profile_id'] )) ? $data ['paypal_subscription_profile_id'] : null;
		$this->paypal_subscription_profile_status = (isset ( $data ['paypal_subscription_profile_status'] )) ? $data ['paypal_subscription_profile_status'] : null;
		$this->createDate = (isset ( $data ['create_date'] )) ? $data ['create_date'] : null;
		$this->updateTime = (isset ( $data ['update_time'] )) ? $data ['update_time'] : null;
	}
}