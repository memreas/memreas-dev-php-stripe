<?php
namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;

class AccountDetailTable {
	protected $tableGateway;
	
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	
	public function getAccountDetailByAccount($account_id) {
		//Note: this assumes account is 1:1 with account_detail - this may change later...
		$rowset = $this->tableGateway->select ( array ('account_id' => $account_id ) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $account_id" );
		}
		return $row;
	}
	
	public function getAccountDetail($account_detail_id) {
		$rowset = $this->tableGateway->select ( array ('account_detail_id' => $account_detail_id ) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $account_detail_id" );
		}
		return $row;
	}
	
	public function getAccountDetailByPayPalReferenceId($paypal_card_reference_id) {
		$rowset = $this->tableGateway->select ( array ('paypal_card_reference_id' => $paypal_card_reference_id ) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $payment_method_id" );
		}
		return $row;
	}
	
	public function saveAccountDetail(AccountDetail $account) {
		$data = array ('account_detail_id' => $account->account_detail_id, 
						'account_id' => $account->account_id, 
						'stripe_customer_id' => $account->stripe_customer_id,
						'first_name' => $account->first_name, 
						'last_name' => $account->last_name, 
						'address_line_1' => $account->address_line_1, 
						'address_line_2' => $account->address_line_2, 
						'city' => $account->city, 
						'state' => $account->state, 
						'zip_code' => $account->zip_code, 
						'postal_code' => $account->postal_code
						); 						
		
		if (isset($account->account_detail_id)) {
			if ($this->getAccountDetail ( $account->account_detail_id )) {
				$this->tableGateway->update ( $data, array ('account_detail_id' =>  $account->account_detail_id ) );
			} else {
				throw new \Exception ( 'Form account_id does not exist' );
			}
		} else {
			$account_detail_id = MUUID::fetchUUID();
			$account->account_detail_id = $account_detail_id;	
			$data['account_detail_id'] = $account_detail_id;	
			$this->tableGateway->insert ( $data );
		}
		return $data['account_detail_id'];
	}
	
	public function deleteAccountDetail($account_detail_id) {
		$this->tableGateway->delete ( array ('account_detail_id' => $account_detail_id ) );
	}

	public function deleteAccountDetailByPayPalCardReferenceId($stripe_card_reference_id) {
		$this->tableGateway->delete ( array ('paypal_card_reference_id' => $stripe_card_reference_id ) );
	}
}