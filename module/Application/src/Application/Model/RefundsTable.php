<?php
    namespace Application\Model;

    use Zend\Db\TableGateway\TableGateway;
    use Zend\Db\Sql\Sql;
    use Zend\Db\Sql\Where;
    use Zend\Db\ResultSet;
    use Zend\Db\Sql\Select;
    use Application\memreas\MUUID;


    class RefundsTable
    {
        protected $tableGateway;

        public function __construct(TableGateway $tableGateway)
        {
            $this->tableGateway = $tableGateway;
        }

        public function fetchAll($where=null)
        {
            $resultSet = $this->tableGateway->select();
            $resultSet->buffer();
            $resultSet->next();
            return $resultSet;
        }

        public function getRefund($refund_id){
            $rowset = $this->tableGateway->select ( array ('refund_id' => $refund_id ) );
            $row = $rowset->current ();
            if (! $row) {
                return null;
            }
            return $row;
        }

        public function saveRefund(Refunds $refund){
            $data = array(
                'transaction_id' => $refund->transaction_id,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'created' => $refund->created,
            );
            if (isset($refund->refund_id)) {
                if ($this->getRefund ( $refund->refund_id )) {
                    $this->tableGateway->update ( $data, array ('account_id' =>  $refund->account_id ) );
                } else {
                    throw new \Exception ( 'Form account_id does not exist' );
                }
            } else {
                $refund_id = MUUID::fetchUUID();
                $data['refund_id'] = $refund_id;
                $this->tableGateway->insert ( $data );
            }
            return $data['refund_id'];
        }
    }