<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

use Zend\Db\Sql\Select;
use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;
use Application\memreas\Mlog;
use Application\Model\MemreasConstants;

class TransactionTable {
	protected $tableGateway;
	public $account_id;
	public $date_from;
	public $date_to;
	public $account_range;
	public $offset = 0;
	public $limit = 0;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	public function getAllTransactions($account_range, $page, $limit) {
		$this->offset = ($page - 1) * $limit;
		$this->limit = $limit;
		$this->account_range = $account_range;
		$resultSet = $this->tableGateway->select ( function (Select $select) {
			if ($this->account_range) {
				$account_range = implode ( "','", $this->account_range );
				$account_range = "'" . $account_range . "'";
				$select->where ( "account_id IN({$account_range})" )->order ( 'transaction_sent DESC' )->offset ( $this->offset )->limit ( $this->limit );
			} else {
				$select->order ( 'transaction_sent DESC' )->offset ( $this->offset )->limit ( $this->limit );
			}
		} );
		return $resultSet;
	}
	public function getTransactionByAccountId($account_id, $page = null, $limit = null) {
		Mlog::addone ( 'Inside getTransactionByAccountId', '...' );
		$this->account_id = $account_id;
		if ($page && $limit) {
			$this->offset = ($page - 1) * $limit;
			$this->limit = $limit;
			$resultSet = $this->tableGateway->select ( function (Select $select) {
				$select->where ( array (
						'account_id' => $this->account_id 
				) )->order ( 'transaction_sent DESC' )->offset ( $this->offset )->limit ( $this->limit );
			} );
		} else {
			$resultSet = $this->tableGateway->select ( array (
					'account_id' => $account_id 
			) );
		}
		return $resultSet;
	}

	public function getPayeeTransactionByAccountId($account_id, $page = null, $limit = null) {
		Mlog::addone ( 'Inside getTransactionByAccountId', '...' );
		$this->account_id = $account_id;
		if ($page && $limit) {
			$this->offset = ($page - 1) * $limit;
			$this->limit = $limit;
			$resultSet = $this->tableGateway->select ( function (Select $select) {
				$interval_day = MemreasConstants::LIST_MASS_PAYEE_INTERVAL;
				$select->where ( array (
					'account_id' => $this->account_id,
					'transaction_sent < ' => '(NOW() - INTERVAL ' . $interval_day . ' DAYS)'
				) )->order ( 'transaction_sent DESC' )->offset ( $this->offset )->limit ( $this->limit );
			} );
		} else {
			$interval_day = MemreasConstants::LIST_MASS_PAYEE_INTERVAL;
			$resultSet = $this->tableGateway->select ( array (
				'account_id' => $account_id,
				'transaction_sent < ' => '(NOW() - INTERVAL ' . $interval_day . ' DAYS)'
			) );
		}
		return $resultSet;
	}

	public function getTransactionByAccountIdAndDateFromTo($account_id, $date_from, $date_to, $page = null, $limit = null) {
		Mlog::addone ( 'Inside getTransactionByAccountIdAndDateFromTo', '...' );
		
		$this->account_id = $account_id;
		$this->date_from = $date_from;
		$this->date_to = $date_to;
		
		if (! empty ( $date_from )) {
			if ($page && $limit) {
				$this->offset = ($page - 1) * $limit;
				$this->limit = $limit;
				$resultSet = $this->tableGateway->select ( function (Select $select) {
					$select->where ( array (
							'account_id' => $this->account_id 
					) );
					$select->where->between ( 'transaction_receive', $this->date_from, $this->date_to );
					$select->order ( 'transaction_receive DESC' )->offset ( $this->offset )->limit ( $this->limit );
					Mlog::addone ( 'getTransactionByAccountIdAndDateFromTo sql with page and limit --> ', $select );
				} );
			} else {
				$resultSet = $this->tableGateway->select ( function (Select $select) {
					$select->where ( array (
							'account_id' => $this->account_id 
					) );
					$select->where->between ( 'transaction_receive', $this->date_from, $this->date_to );
					$select->order ( 'transaction_receive DESC' );
					Mlog::addone ( 'getTransactionByAccountIdAndDateFromTo sql --> ', $select );
				} );
			}
			return $resultSet;
		}
	}
	public function getTransaction($transaction_id) {
		$rowset = $this->tableGateway->select ( array (
				'transaction_id' => $transaction_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function saveTransaction(Transaction $transaction) {
		$data = array (
				'transaction_id' => $transaction->transaction_id,
				'account_id' => $transaction->account_id,
				'transaction_type' => $transaction->transaction_type,
				'pass_fail' => $transaction->pass_fail,
				'amount' => $transaction->amount,
				'currency' => $transaction->currency,
				'transaction_request' => $transaction->transaction_request,
				'transaction_response' => $transaction->transaction_response,
				'transaction_sent' => $transaction->transaction_sent,
				'transaction_receive' => $transaction->transaction_receive,
				'transaction_status' => $transaction->transaction_status 
		);
		try {
			if (isset ( $transaction->transaction_id )) {
				if ($this->getTransaction ( $transaction->transaction_id )) {
					$this->tableGateway->update ( $data, array (
							'transaction_id' => $transaction->transaction_id 
					) );
				} else {
					throw new \Exception ( 'Form transaction_id does not exist' );
				}
			} else {
				$transaction_id = MUUID::fetchUUID ();
				$data ['transaction_id'] = $transaction_id;
				$this->tableGateway->insert ( $data );
			}
		} catch ( \Exception $e ) {
			error_log ( "Transaction.saveTransaction error ---> " . $e->getMessage () . PHP_EOL );
			error_log ( \Zend\Debug\Debug::dump ( $e->__toString () ) . PHP_EOL );
		}
		return $data ['transaction_id'];
	}
	public function deleteTransaction($transactionId) {
		$this->tableGateway->delete ( array (
				'transactionId' => $transactionId 
		) );
	}
	public function deleteAll() {
		$this->tableGateway->delete ( "1" );
	}
}