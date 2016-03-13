<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;
use Application\memreas\MUUID;
use Application\memreas\MNow;

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
	public function getSubscriptionByStripeId($stripe_subscription_id) {
		$rowset = $this->tableGateway->select ( array (
				'stripe_subscription_id' => $stripe_subscription_id 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find row $stripe_subscription_id" );
		}
		return $row;
	}
	public function getActiveSubscription($account_id) {
		$rowset = $this->tableGateway->select ( array (
				'account_id' => $account_id,
				'active' => '1' 
		) );
		$row = $rowset->current ();
		if (! $row) {
			throw new \Exception ( "Could not find active subscription for account_id: " . $account_id );
		}
		return $row;
	}
	public function saveSubscription(Subscription $subscription) {
		error_log ( "About to saveSubscription...." . PHP_EOL );
		$data = array (
				'subscription_id' => $subscription->subscription_id,
				'account_id' => $subscription->account_id,
				'stripe_subscription_id' => $subscription->stripe_subscription_id,
				'currency_code' => $subscription->currency_code,
				'plan' => $subscription->plan,
				'plan_amount' => $subscription->plan_amount,
				'plan_description' => $subscription->plan_description,
				'gb_storage_amount' => $subscription->gb_storage_amount,
				'billing_frequency' => $subscription->billing_frequency,
				'active' => $subscription->active,
				'start_date' => $subscription->start_date,
				'end_date' => $subscription->end_date,
				'create_date' => $subscription->createDate,
				'update_time' => $subscription->updateTime 
		);
		
		try {
			if (isset ( $subscription->subscription_id )) {
				if ($this->getSubscription ( $subscription->subscription_id )) {
					$this->tableGateway->update ( $data, array (
							'subscription_id' => $subscription->subscription_id 
					) );
				} else {
					throw new \Exception ( 'Form subscription_id does not exist' );
				}
			} else {
				$data ['subscription_id'] = MUUID::fetchUUID ();
				$this->tableGateway->insert ( $data );
			}
		} catch ( \Exception $e ) {
			\Zend\Debug\Debug::dump ( $e->__toString () );
		}
		return $data ['subscription_id'];
	}
	public function deactivateSubscription($subscription_id) {
		try {
			$data = array (
					'active' => 0,
					'end_date' => MNow::now (),
					'update_time' => MNow::now () 
			);
			$this->tableGateway->update ( $data, array (
					'subscription_id' => $subscription_id 
			) );
		} catch ( \Exception $e ) {
			\Zend\Debug\Debug::dump ( $e->__toString () );
		}
		return $subscription_id;
	}
	public function countUser($planId) {
		$rowset = $this->tableGateway->select ( array (
				'plan' => $planId 
		) );
		return count ( $rowset );
	}
}