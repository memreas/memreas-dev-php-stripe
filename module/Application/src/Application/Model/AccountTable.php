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
use Zend\Db\Adapter\Platform\PlatformInterface;
use Doctrine\DBAL\Driver\PDOException;

class AccountTable {
	protected $tableGateway;
	private $username;
	private $offset;
	private $limit;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	public function listMassPayee($username, $page, $limit) {
		$cm = __CLASS__ . __METHOD__;
		
		Mlog::addone ( $cm, '::enter listMassPayee' );
		$this->username = $username;
		$this->limit = $limit;
		$this->offset = ($page - 1) * $limit;
		$rowset = $this->tableGateway->select ( function (Select $select) {
			// Mlog::addone ( $cm, 'Inside select for listMassPayee' );
			$conditions = "account_type = 'seller'";
			$conditions .= " AND balance > 0";
			$conditions .= " AND (username != '" . MemreasConstants::ACCOUNT_MEMREAS_FLOAT . "')";
			$conditions .= " AND (username != '" . MemreasConstants::ACCOUNT_MEMREAS_MASTER . "')";
			if ($this->username != 'all') {
				$conditions .= " AND username = '" . $this->username . "'";
			}
			$select->where ( $conditions )->limit ( $this->limit )->offset ( $this->offset );
			// Mlog::addone ( '$select->getSqlString($this->tableGateway->adapter->platform)-->', $select->getSqlString ( $this->tableGateway->adapter->platform ) );
		} );
		
		if (! $rowset) {
			return null;
		}
		return $rowset;
	}
	public function getAccountByUserName($username) {
		$rowset = $this->tableGateway->select ( array (
				'username' => $username 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function getAccountByUserId($user_id, $account_type = 'buyer') {
		$rowset = $this->tableGateway->select ( array (
				'user_id' => $user_id,
				'account_type' => $account_type 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function searchAccountByName($username, $account_type = 'buyer') {
		$resultSet = $this->tableGateway->select ( array (
				"username LIKE '{$username}%'",
				'account_type' => $account_type 
		) );
		return $resultSet;
	}
	public function getAccount($account_id, $account_type = 'buyer') {
		$rowset = $this->tableGateway->select ( array (
				'account_id' => $account_id,
				'account_type' => $account_type 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function getAccountById($account_id) {
		$rowset = $this->tableGateway->select ( array (
				'account_id' => $account_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function getAccountByStripeCustomerId($stripe_customer_id) {
		$rowset = $this->tableGateway->select ( array (
				'stripe_customer_id' => $stripe_customer_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}	
	public function saveAccount(Account $account) {
		Mlog::addone(__CLASS__.__METHOD__,__LINE__);
		$data = array (
				'account_id' => $account->account_id,
				'user_id' => $account->user_id,
				'username' => $account->username,
				'account_type' => $account->account_type,
				'balance' => $account->balance,
				'tax_ssn_ein' => $account->tax_ssn_ein,
				'stripe_customer_id' => $account->stripe_customer_id,
				'stripe_email_address' => $account->stripe_email_address,
				'stripe_account_id' => $account->stripe_account_id,
				'create_time' => $account->create_time,
				'update_time' => $account->update_time 
		);
		Mlog::addone(__CLASS__.__METHOD__,__LINE__);
		Mlog::addone(__CLASS__.__METHOD__.__LINE__.'::$data-->', $data);
		
		if (isset ( $account->account_id )) {
		Mlog::addone(__CLASS__.__METHOD__,__LINE__);
			$this->tableGateway->update ( $data, array (
					'account_id' => $account->account_id 
			) );
		} else {
		Mlog::addone(__CLASS__.__METHOD__,__LINE__);
			$data ['account_id'] = MUUID::fetchUUID ();
			$this->tableGateway->insert ( $data );
		}
		Mlog::addone(__CLASS__.__METHOD__,__LINE__);
		return $data ['account_id'];
	}
	public function deleteAccount($account_id) {
		$this->tableGateway->delete ( array (
				'account_id' => $account_id 
		) );
	}
	public function deleteAll() {
		$this->tableGateway->delete ( "1" );
	}
}