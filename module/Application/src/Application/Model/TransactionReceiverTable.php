<?php
namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;

class TransactionReceiverTable {
	protected $tableGateway;
	
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	
	public function getTransactionReceiver($transaction_receiver_id) {
		$transaction_receiver_id = ( int ) $transaction_receiver_id;
		$rowset = $this->tableGateway->select ( array ('transaction_receiver_id' => $transaction_receiver_id ) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $transaction_receiver_id" );
		}
		return $row;
	}
	
	public function saveTransactionReceiver(TransactionReceiver $account) {
		$data = array ('transaction_receiver_id' => $account->transactionReceiverId, 'transaction_id' => $account->transactionId, 'account_id' => $account->accountId, 'amount' => $account->amount, 'email' => $account->email, 'primary_receiver' => $account->primaryReceiver, 'create_time' => $account->createTime, 'update_time' => $account->updateTime );
		
		$transactionReceiverId = ( int ) $account->transactionReceiverId;
		if ($transactionReceiverId == 0) {
			$this->tableGateway->insert ( $data );
		} else {
			if ($this->getTransactionReceiver ( $transactionReceiverId )) {
				$this->tableGateway->update ( $data, array ('transaction_receiver_id' => $transactionReceiverId ) );
			} else {
				throw new \Exception ( 'Form transaction_receiver_id does not exist' );
			}
		}
		
		return $this->tableGateway->getLastInsertValue();
	}
	
	public function deleteTransactionReceiver($transactionReceiverId) {
		$this->tableGateway->delete ( array ('transaction_receiver_id' => $transactionReceiverId ) );
	}
}