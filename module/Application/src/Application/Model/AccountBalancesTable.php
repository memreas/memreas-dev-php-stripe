<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;
use Zend\Db\Sql\Select;

class AccountBalancesTable {
	protected $tableGateway;
	protected $account_id;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	public function getAccountBalances($account_id) {
		$this->account_id = $account_id;
		$rowset = $this->tableGateway->select ( function (Select $select) {
			$select->where->equalTo ( 'account_id', $this->account_id );
			$select->order ( 'create_time desc' )->limit ( 1 );
		} );
		
		$row = $rowset->current ();
		if (! $row) {
			return null; // no entry found ... must be new account :)
		}
		return $row;
	}
	public function getAccountBalanceByTransactionId($transaction_id) {
		$rowset = $this->tableGateway->select ( array (
				'transaction_id' => $transaction_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function saveAccountBalances(AccountBalances $account_balances) {
		$data = array (
				'account_id' => $account_balances->account_id,
				'transaction_id' => $account_balances->transaction_id,
				'transaction_type' => $account_balances->transaction_type,
				'starting_balance' => $account_balances->starting_balance,
				'amount' => $account_balances->amount,
				'ending_balance' => $account_balances->ending_balance,
				'create_time' => $account_balances->create_time 
		);
		$this->tableGateway->insert ( $data );
		
		return $data ['transaction_id'];
	}
	public function deleteAccountBalances($transaction_id) {
		$this->tableGateway->delete ( array (
				'transaction_id' => $transaction_id 
		) );
	}
	public function deleteAll() {
		$this->tableGateway->delete ( "1" );
	}
}