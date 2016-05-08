<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

use Zend\Db\Sql\Sql;
use Zend\Db\TableGateway\TableGateway;
use Application\memreas\Mlog;

class AccountPurchasesTable {
	protected $tableGateway;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	public function getAccountPurchase($account_id, $event_id) {
		Mlog::addone(__CLASS__.__METHOD__.__LINE__.'::$account_id',$account_id);
		Mlog::addone(__CLASS__.__METHOD__.__LINE__.'::$event_id',$event_id);
		$rowset = $this->tableGateway->select ( array (
				'account_id' => $account_id,
				'event_id' => $event_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}

	public function getPurchaseByTransactionId($transaction_id) {
		$rowset = $this->tableGateway->select ( array (
				'transaction_id' => $transaction_id
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}

	public function getAccountPurchases($account_id) {

		$adapter = $this->tableGateway->getAdapter ();
		// Setup the query
		$sql = new Sql ( $adapter );
		$select = $sql->select ();
		$select->from ( $this->tableGateway->table )->columns ( array (
			'*'
		) )->where ( array (
			'account_id' => $account_id
		) );
		$sqlString = $sql->getSqlStringForSqlObject ( $select );

		$statement = $sql->prepareStatementForSqlObject ( $select );
		$result = $statement->execute ();

		return $result;
	}
	public function saveAccountPurchase(AccountPurchases $account_purchase) {
		$data = array (
				'account_id' => $account_purchase->account_id,
				'transaction_id' => $account_purchase->transaction_id,
				'transaction_type' => $account_purchase->transaction_type,
				'event_id' => $account_purchase->event_id,
				'amount' => $account_purchase->amount,
				'start_date' => $account_purchase->start_date,
				'end_date' => $account_purchase->end_date,
				'create_time' => $account_purchase->create_time 
		);
		$this->tableGateway->insert ( $data );
	}
	public function deleteAccountPurchase($account_id, $event_id) {
		$this->tableGateway->delete ( array (
				'account_id' => $account_id,
				'event_id' => $event_id 
		) );
	}
}