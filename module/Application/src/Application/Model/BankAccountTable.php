<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

use Application\memreas\MUUID;
use Application\memreas\MNow;
use Zend\Db\Sql\Sql;
use Zend\Db\TableGateway\TableGateway;

class BankAccountTable {
	protected $tableGateway;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	public function getBankAccountByAccountId($account_id) {
		$rowset = $this->tableGateway->select ( array (
				'account_id' => $account_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $account_id" );
		}
		return $row;
	}
	public function getBankAccount($bank_account_id) {
		$rowset = $this->tableGateway->select ( array (
				'bank_account_id' => $bank_account_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $bank_account_id" );
		}
		return $row;
	}
	public function getBankAccountByStripeBankAccountId($stripe_bank_account_id) {
		$rowset = $this->tableGateway->select ( array (
				'stripe_bank_account_id' => $stripe_bank_account_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $stripe_bank_account_id" );
		}
		return $row;
	}
	public function saveBankAccount(BankAccount $bank_account) {
		$data = array (
				'bank_account_id' => $bank_account->bank_account_id,
				'account_id' => $bank_account->account_id,
				'account_detail_id' => $bank_account->account_detail_id,
				'stripe_bank_acount_id' => $bank_account->stripe_bank_acount_id,
				'account_holder_name' => $bank_account->account_holder_name,
				'account_number' => $bank_account->account_number,
				'routing_number' => $bank_account->routing_number,
				'tax_ssn_ein' => $bank_account->tax_ssn_ein,
				'keys' => $bank_account->keys,
				'delete_flag' => $bank_account->delete_flag,
				'create_time' => $bank_account->create_time,
				'update_time' => $bank_account->update_time 
		);
		
		if (isset ( $bank_account->bank_account_id )) {
			if ($this->getBankAccount ( $bank_account->bank_account_id )) {
				$this->tableGateway->update ( $data, array (
						'bank_account_id' => $bank_account->bank_account_id 
				) );
			} else {
				throw new \Exception ( 'Form bank_account_id does not exist' );
			}
		} else {
			$bank_account_id = MUUID::fetchUUID ();
			$bank_account->bank_account_id = $bank_account_id;
			$data ['bank_account_id'] = $bank_account_id;
			$this->tableGateway->insert ( $data );
		}
		return $data ['bank_account_id'];
	}
	public function deleteBankAccount($bank_account_id) {
		$rowset = $this->tableGateway->select ( array (
				'bank_account_id' => $bank_account_id
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $bank_account_id" );
		}
		$data = array (
				'delete_flag' => $row->delete_flag = '1',
				'update_time' => $row->update_time = MNow::now ()
		);
		$this->tableGateway->update ( $data, array (
				'payment_method_id' => $payment_method_id
		) );
		//$this->tableGateway->delete ( array (
		//		'payment_method_id' => $payment_method_id
		//) );
	}
	
}