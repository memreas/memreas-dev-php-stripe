<?php

namespace Application\Model;

use Zend\Db\Sql\Select;
use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;

class TransactionTable {
	protected $tableGateway;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
    public function getAllTransactions(){
        $resultSet = $this->tableGateway->select(function(Select $select){
            $select->order('transaction_sent DESC');
        });
        return $resultSet;
    }
	public function getTransactionByAccountId($account_id) {
		$resultSet = $this->tableGateway->select ( array (
				'account_id' => $account_id 
		) );
		return $resultSet;
	}
	public function getTransactionByPayPalTxnId($paypal_txn_id) {
		$resultSet = $this->tableGateway->select ( array (
				'paypal_txn_id' => $paypal_txn_id 
		) );
		return $resultSet;
	}
	
	public function getTransaction($transaction_id) {
		$rowset = $this->tableGateway->select ( array (
				'transaction_id' => $transaction_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $transactionId" );
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
				
				'paypal_item_name' => $transaction->paypal_item_name,
				'paypal_item_number' => $transaction->paypal_item_number,
				'paypal_payer_id' => $transaction->paypal_payer_id,
				'paypal_payer_email' => $transaction->paypal_payer_email,
				'paypal_receiver_id' => $transaction->paypal_receiver_id,
				'paypal_receiver_email' => $transaction->paypal_receiver_email,
				
				'paypal_payment_status' => $transaction->paypal_payment_status,
				'paypal_payment_amount' => $transaction->paypal_payment_amount,
				'paypal_payment_currency' => $transaction->paypal_payment_currency,
				'paypal_payment_fee' => $transaction->paypal_payment_fee,
				'paypal_tax' => $transaction->paypal_tax,
				
				'paypal_txn_type' => $transaction->paypal_txn_type,
				'paypal_parent_payment_id' => $transaction->paypal_parent_payment_id,
				'paypal_correlation_id' => $transaction->paypal_correlation_id,
				'paypal_txn_id' => $transaction->paypal_txn_id,
				'paypal_ipn_track_id' => $transaction->paypal_ipn_track_id 
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
				'transactionId' => $transactionId ) );
	}
}