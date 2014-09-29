<?php

namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;

class SubscriptionTable {
	protected $tableGateway;
	public function __construct(TableGateway $tableGateway) {
		$this->tableGateway = $tableGateway;
	}
	public function fetchAll() {
		$resultSet = $this->tableGateway->select ();
		return $resultSet;
	}
	public function getSubscription($subscription_id) {
		$subscription_id = ( int ) $subscription_id;
		$rowset = $this->tableGateway->select ( array (
				'subscription_id' => $subscription_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $subscription_id" );
		}
		return $row;
	}
	public function saveSubscription(Subscription $subscription) {
error_log("About to saveSubscription....".PHP_EOL);
		$data = array (
				'subscription_id' => $subscription->subscription_id,
				'account_id' => $subscription->account_id,
				'currency_code' => $subscription->currency_code,
				'plan' => $subscription->plan,
				'plan_amount' => $subscription->plan_amount,
				'plan_description' => $subscription->plan_description,
				'gb_storage_amount' => $subscription->gb_storage_amount,
				'billing_frequency' => $subscription->billing_frequency,
				'start_date' => $subscription->start_date,
				'end_date' => $subscription->end_date,
				'paypal_subscription_profile_id' => $subscription->paypal_subscription_profile_id,
				'paypal_subscription_profile_status' => $subscription->paypal_subscription_profile_status,
				'create_date' => $subscription->createDate,
				'update_time' => $subscription->updateTime 
		);
		
		try {
		if (isset($subscription->subscription_id)) {
			if ($this->getSubscription( $subscription->subscription_id )) {
				$this->tableGateway->update ( $data, array ('subscription_id' =>  $subscription->subscription_id ) );
			} else {
				throw new \Exception ( 'Form subscription_id does not exist' );
			}
		} else {
			$data['subscription_id'] = MUUID::fetchUUID();
			$this->tableGateway->insert ( $data );
		}
		} catch (\Exception $e) {
			\Zend\Debug\Debug::dump($e->__toString()); 
		}
		return $data['subscription_id'];
	}

	public function deleteSubscription($subscription_id) {
		$this->tableGateway->delete ( array (
				'subscription_id' => $subscription_id 
		) );
	}

    public function countUser($planId){
        $rowset = $this->tableGateway->select ( array (
            'plan' => $planId
        ));
        return count($rowset);
    }
}