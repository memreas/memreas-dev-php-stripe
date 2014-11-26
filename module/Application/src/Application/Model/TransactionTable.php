<?php

namespace Application\Model;

use Zend\Db\Sql\Select;
use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;

class TransactionTable {
	protected $tableGateway;

    public $account_id;
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
    public function getAllTransactions($account_range, $page, $limit){
        $this->offset = ($page - 1) * $limit;
        $this->limit = $limit;
        $this->account_range = $account_range;
        $resultSet = $this->tableGateway->select(function(Select $select){
            if ($this->search_username) {
                echo 'run 1';
                $account_range = implode("','", $this->account_range);
                $select->where("account_id IN({$account_range})")
                    ->order('transaction_sent DESC')
                    ->offset($this->offset)
                    ->limit($this->limit);
            }
            else {
                $select->order('transaction_sent DESC')
                    ->offset($this->offset)
                    ->limit($this->limit);
                echo 'run 2';
            }
            die();
        });
        return $resultSet;
    }
	public function getTransactionByAccountId($account_id, $page = null, $limit = null) {
        $this->account_id = $account_id;
        if ($page && $limit){
            $this->offset = ($page - 1) * $limit;
            $this->limit = $limit;
            $resultSet = $this->tableGateway->select(function (Select $select){
                $select->where(array('account_id' => $this->account_id))
                        ->order('transaction_sent DESC')
                        ->offset($this->offset)
                        ->limit($this->limit);
            });
        }
		else {
            $resultSet = $this->tableGateway->select ( array (
                'account_id' => $account_id
            ) );
        }
		return $resultSet;
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
				'transactionId' => $transactionId ) );
	}

    public function deleteAll(){
        $this->tableGateway->delete("1");
    }
}