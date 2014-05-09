<?php
namespace Application\Model;

class PaymentMethod{
		
	public $payment_method_id;
	public $account_id;
	public $stripe_card_reference_id;
	public $card_type;
	public $obfuscated_card_number;
	public $exp_month;
	public $exp_year;
	public $valid_until;
	public $create_time;
	public $update_time;
	
	public function exchangeArray($data)
	{
		$this->payment_method_id     = (isset($data['payment_method_id'])) ? $data['payment_method_id'] : $this->payment_method_id;
		$this->account_id = (isset($data['account_id'])) ? $data['account_id'] : $this->account_id;
		$this->stripe_card_reference_id  = (isset($data['stripe_card_reference_id'])) ? $data['stripe_card_reference_id'] : $this->stripe_card_reference_id;
		$this->card_type  = (isset($data['card_type'])) ? $data['card_type'] : $this->card_type;
		$this->obfuscated_card_number  = (isset($data['obfuscated_card_number'])) ? $data['obfuscated_card_number'] : $this->obfuscated_card_number;
		$this->exp_month  = (isset($data['exp_month'])) ? $data['exp_month'] : $this->exp_month;
		$this->exp_year  = (isset($data['exp_year'])) ? $data['exp_year'] : $this->exp_year;
		$this->valid_until  = (isset($data['valid_until'])) ? $data['valid_until'] : $this->valid_until;
		$this->create_time  = (isset($data['create_time'])) ? $data['create_time'] : $this->create_time;
		$this->update_time  = (isset($data['update_time'])) ? $data['update_time'] : $this->update_time;
	}
}