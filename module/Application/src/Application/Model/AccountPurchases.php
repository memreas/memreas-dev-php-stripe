<?php
    namespace Application\Model;

    class AccountPurchases{

        public $account_id;
        public $transaction_id;
        public $event_id;
        public $transaction_type;
        public $amount;
        public $start_date;
        public $end_date;
        public $create_time;

        public function exchangeArray($data)
        {
            $this->account_id     = (isset($data['account_id'])) ? $data['account_id'] : $this->account_id;
            $this->event_id = (isset($data['event_id'])) ? $data['event_id'] : $this->event_id;
            $this->transaction_id = (isset($data['transaction_id'])) ? $data['transaction_id'] : $this->transaction_id;
            $this->transaction_type  = (isset($data['transaction_type'])) ? $data['transaction_type'] : $this->transaction_type;
            $this->start_date  = (isset($data['start_date'])) ? $data['start_date'] : $this->start_date;
            $this->end_date  = (isset($data['end_date'])) ? $data['end_date'] : $this->end_date;
            $this->create_time  = (isset($data['create_time'])) ? $data['create_time'] : $this->create_time;
        }
    }