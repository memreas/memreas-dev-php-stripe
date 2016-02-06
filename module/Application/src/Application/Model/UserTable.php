<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

use Application\memreas\Mlog;
use Zend\Db\TableGateway\TableGateway;

// For join tables
use Zend\Db\Sql\Sql;
// For condtion statment
use Zend\Db\ResultSet;
use Zend\Db\Sql\Select;

class UserTable {
	protected $tableGateway;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll($where = null) {
		$resultSet = $this->tableGateway->select ();
		$resultSet->buffer ();
		$resultSet->next ();
		return $resultSet;
	}
	public function getUser($id) {
		$rowset = $this->tableGateway->select ( array (
				'user_id' => $id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			return null;
		}
		return $row;
	}
	public function saveUser(User $user) {
		// (isset($user->user_id)) ? $data['user_id']= $user->user_id : null;
		(isset ( $user->database_id )) ? $data ['database_id'] = $user->database_id : null;
		(isset ( $user->username )) ? $data ['username'] = $user->username : null;
		(isset ( $user->password )) ? $data ['password'] = $user->password : null;
		(isset ( $user->email_address )) ? $data ['email_address'] = $user->email_address : null;
		(isset ( $user->role )) ? $data ['role'] = $user->role : null;
		(isset ( $user->profile_photo )) ? $data ['profile_photo'] = $user->profile_photo : null;
		(isset ( $user->facebook_username )) ? $data ['facebook_username'] = $user->facebook_username : null;
		(isset ( $user->twitter_username )) ? $data ['twitter_username'] = $user->twitter_username : null;
		(isset ( $user->disable_account )) ? $data ['disable_account'] = $user->disable_account : null;
		(isset ( $user->create_date )) ? $data ['create_date'] = $user->create_date : null;
		(isset ( $user->update_time )) ? $data ['update_time'] = strtotime ( date ( 'Y-m-d' ) ) : strtotime ( date ( 'Y-m-d' ) );
		(isset ( $user->metadata )) ? $data ['metadata'] = $user->metadata : null;
		
		$id = $user->user_id;
		if (empty ( $id )) {
			$data ['create_date'] = strtotime ( date ( Y - m - d ) );
			$this->tableGateway->insert ( $data );
			return true;
		} else {
			if ($this->getUser ( $id )) {
				$this->tableGateway->update ( $data, array (
						'user_id' => $id 
				) );
				return true;
			} else {
				throw new \Exception ( 'User does not exist' );
			}
		}
	}
	public function deleteUser($id) {
		$this->tableGateway->delete ( array (
				'user_id' => $id 
		) );
	}
	public function getUserByUsername($username) {
		$rowset = $this->tableGateway->select ( array (
				'username' => $username
		) );
		
		$row = $rowset->current ();
		if (! $row) {
			return false;
		}
		return $row;
	}
	public function getUserByRole($role) {
		$rowset = $this->tableGateway->select ( array (
				'role' => $role 
		) );
		return $rowset;
	}
	public function getUserBy($where) {
		$rowset = $this->tableGateway->select ( $where );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row " );
		}
		return $row;
	}
	public function isExist($where) {
		$select = new Select ();
		$select->from ( $this->tableGateway->getTable () )->where->NEST->like ( 'username', $where ['username'] )->or->like ( 'email_address', $where ['email_address'] )->UNNEST->and->notEqualTo ( 'user_id', $where ['user_id'] );
		
		$statement = $this->tableGateway->getAdapter ()->createStatement ();
		$select->prepareStatement ( $this->tableGateway->getAdapter (), $statement );
		
		$resultSet = new ResultSet\ResultSet ();
		$resultSet->initialize ( $statement->execute () );
		
		// echo "<pre>";echo $select->getSqlString();
		// print_r($resultSet->current());
		// exit(0);
		//
		if ($resultSet->current ())
			return true;
		else
			return false;
	}
}