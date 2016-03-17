<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

class MemreasStripeTables {
	protected $service_locator = NULL;
	protected $userTable = NULL;
	protected $accountTable = NULL;
	protected $accountBalancesTable = NULL;
	protected $accountDetailTable = NULL;
	protected $bankAccountTable = NULL;
	protected $paymentMethodTable = NULL;
	protected $subscriptionTable = NULL;
	protected $transactionTable = NULL;
	protected $transactionRecieverTable = NULL;
	protected $accountPurchasesTable = NULL;
	function __construct($sl) {
		$this->service_locator = $sl;
	}
	
	// User related tables
	public function getUserTable() {
		if (! $this->userTable) {
			$this->userTable = $this->service_locator->get ( 'Application\Model\UserTable' );
		}
		return $this->userTable;
	}
	
	// Payment related tables
	public function getAccountTable() {
		if (! $this->accountTable) {
			$this->accountTable = $this->service_locator->get ( 'Application\Model\AccountTable' );
		}
		return $this->accountTable;
	}
	public function getAccountBalancesTable() {
		if (! $this->accountBalancesTable) {
			$this->accountBalancesTable = $this->service_locator->get ( 'Application\Model\AccountBalancesTable' );
		}
		return $this->accountBalancesTable;
	}
	public function getAccountDetailTable() {
		if (! $this->accountDetailTable) {
			$this->accountDetailTable = $this->service_locator->get ( 'Application\Model\AccountDetailTable' );
		}
		return $this->accountDetailTable;
	}
	public function getBankAccountTable() {
		if (! $this->bankAccountTable) {
			$this->bankAccountTable = $this->service_locator->get ( 'Application\Model\BankAccountTable' );
		}
		return $this->bankAccountTable;
	}
	public function getTransactionTable() {
		if (! $this->transactionTable) {
			$this->transactionTable = $this->service_locator->get ( 'Application\Model\TransactionTable' );
		}
		return $this->transactionTable;
	}
	public function getTransactionReceiverTable() {
		if (! $this->transactionRecieverTable) {
			$this->transactionRecieverTable = $this->service_locator->get ( 'Application\Model\TransactionRecieverTable' );
		}
		return $this->transactionRecieverTable;
	}
	public function getSubscriptionTable() {
		if (! $this->subscriptionTable) {
			$this->subscriptionTable = $this->service_locator->get ( 'Application\Model\SubscriptionTable' );
		}
		return $this->subscriptionTable;
	}
	public function getPaymentMethodTable() {
		if (! $this->paymentMethodTable) {
			$this->paymentMethodTable = $this->service_locator->get ( 'Application\Model\PaymentMethodTable' );
		}
		return $this->paymentMethodTable;
	}
	public function getAccountPurchasesTable() {
		if (! $this->accountPurchasesTable) {
			$this->accountPurchasesTable = $this->service_locator->get ( 'Application\Model\AccountPurchasesTable' );
		}
		return $this->accountPurchasesTable;
	}
}
?>
