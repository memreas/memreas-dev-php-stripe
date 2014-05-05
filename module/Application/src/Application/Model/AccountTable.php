<?php
namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;

class AccountTable {
	protected $tableGateway;
	
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	
	public function listMassPayee() {
	
		$rowset = $this->tableGateway->select ( array ("account_type = 'seller'", "balance > 0" ) );
		if (! $rowset) {
			return null;
		}
		return $rowset;
	}
	
	public function getAccountByUserName($username) {
		$rowset = $this->tableGateway->select ( array ('username' => $username ) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	
	public function getAccountByUserId($user_id) {
		$rowset = $this->tableGateway->select ( array ('user_id' => $user_id ) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	
	public function getAccount($account_id) {
		$rowset = $this->tableGateway->select ( array ('account_id' => $account_id ) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $account_id" );
		}
		return $row;
	}
	
	public function saveAccount(Account $account) {
		$data = array (
			'account_id' => $account->account_id, 
			'user_id' => $account->user_id, 
			'username' => $account->username, 
			'account_type' => $account->account_type, 
			'balance' => $account->balance, 
			'create_time' => $account->create_time, 
			'update_time' => $account->update_time );
		
		if (isset($account->account_id)) {
			if ($this->getAccount ( $account->account_id )) {
				$this->tableGateway->update ( $data, array ('account_id' =>  $account->account_id ) );
			} else {
				throw new \Exception ( 'Form account_id does not exist' );
			}
		} else {
			$account_id = MUUID::fetchUUID();
			//$account->account_id = $account_id;	
			$data['account_id'] = $account_id;	
			$this->tableGateway->insert ( $data );
		}
		return $data['account_id'];
	}
	
	public function deleteAccount($account_id) {
		$this->tableGateway->delete ( array ('account_id' => $account_id ) );
	}
}