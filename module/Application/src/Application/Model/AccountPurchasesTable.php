<?php
    namespace Application\Model;

    use Zend\Db\TableGateway\TableGateway;
    use Zend\Db\Sql\Select;

    class AccountPurchasesTable {
        protected $tableGateway;
        protected $account_id;
        protected $event_id;

        public function __construct(TableGateway $tableGateway) {
            $this->tableGateway = $tableGateway;
        }

        public function fetchAll() {
            $resultSet = $this->tableGateway->select();
            return $resultSet;
        }

        public function getAccountPurchase($account_id, $event_id) {
            $this->account_id = $account_id;
            $this->event_id = $event_id;

            $rowset = $this->tableGateway->select(function (Select $select) {
                $select->where->equalTo('account_id', $this->account_id);
                $select->where->equalTo('event_id', $this->event_id);
            });

            $row = $rowset->current();
            if (!$row) {
                return null;
            }
            return $row;
        }

        public function saveAccountPurchase(AccountPurchases $account_purchase) {
            $data = array (
                'account_id' => $account_purchase->account_id,
                'transaction_id' => $account_purchase->transaction_id,
                'event_id' => $account_purchase->event_id,
                'start_date' => $account_purchase->start_date,
                'starting_balance' => $account_purchase->starting_balance,
                'end_date' => $account_purchase->end_date,
                'create_time' => $account_purchase->create_time
            );
            $this->tableGateway->insert($data);
        }

        public function deleteAccountPurchase($account_id, $event_id) {
            $this->tableGateway->delete ( array ('account_id' => $account_id, 'event_id' => $event_id ) );
        }
    }