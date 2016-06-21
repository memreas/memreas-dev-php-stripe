<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Application\Entity\EventMedia;
use Application\Entity\Media;
use Application\Model\Account;
use Application\Model\AccountBalances;
use Application\Model\AccountDetail;
use Application\Model\AccountPurchases;
use Application\Model\BankAccount;
use Application\Model\MemreasConstants;
use Application\Model\PaymentMethod;
use Application\Model\Subscription;
use Application\Model\Transaction as Memreas_Transaction;
use Aws\Ses\Exception\SesException;
use Zend\View\Model\ViewModel;

class MemreasStripe extends StripeInstance {
	public $stripeClient;
	public $stripeInstance;
	public $memreasStripeTables;
	protected $clientSecret;
	protected $clientPublic;
	protected $user_id;
	protected $aws;
	protected $dbAdapter;
	public $service_locator;
	public function __construct($service_locator, $aws) {
		try {
			$this->service_locator = $service_locator;
			// $this->retreiveStripeKey ();
			// $this->stripeClient = new StripeClient ( $this->clientSecret, '2014-06-17' );
			/**
			 * migrate to pure Stripe PHP API - 2016-03-07 (version)
			 * needed for upgrade to managed accounts - payout failing for recipients...
			 */
			$this->stripeClient = new StripeClient ();
			$this->memreasStripeTables = new MemreasStripeTables ( $service_locator );
			$this->stripeInstance = parent::__construct ( $this->stripeClient, $this->memreasStripeTables );
			$this->aws = $aws;
			$this->dbAdapter = $service_locator->get ( 'doctrine.entitymanager.orm_default' );
			// Mlog::addone ( __CLASS__ . __METHOD__ . '__construct $_SESSION', $_SESSION );
			
			/**
			 * -
			 * Retrieve memreas user_id from session
			 */
			if (isset ( $_SESSION ['user_id'] )) {
				$this->user_id = $_SESSION ['user_id'];
			}
		} catch ( Exception $e ) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '$e->getMessage()', $e->getMessage () );
			throw new \Exception ( $e->getMessage () );
		}
	}
	
	/**
	 * -
	 * Retreive Stripe account configuration SECRET and PUBLIC key
	 * ** Refer file : Application/Config/Autoload/Local.php ** temp moved to constants
	 */
	private function retreiveStripeKey() {
		$this->clientSecret = MemreasConstants::SECRET_KEY;
		$this->clientPublic = MemreasConstants::PUBLIC_KEY;
	}
}

/**
 * -
 * Stripe Class
 */
class StripeInstance {
	public $service_locator;
	// private $stripeCustomer;
	// private $stripeRecipient;
	// private $stripeCard;
	private $stripePlan;
	private $stripeClient;
	protected $session;
	protected $memreasStripeTables;
	/*
	 * -
	 * Constructor
	 */
	public function __construct($stripeClient, $memreasStripeTables) {
		// $this->stripeCustomer = new StripeCustomer ( $stripeClient );
		$this->stripeClient = $stripeClient;
		// $this->stripeRecipient = new StripeRecipient ( $stripeClient );
		// $this->stripeCard = new StripeCard ( $stripeClient );
		$this->stripePlan = new StripePlansConfig ( $stripeClient );
		$this->memreasStripeTables = $memreasStripeTables;
	}
	public function get($propertyName) {
		return $this->{$propertyName};
	}
	
	/*
	 * Stripe Webhook Receiver
	 */
	public function webHookReceiver($eventArr) {
		$cm = __CLASS__ . __METHOD__;
		
		//
		// Handle transfers for subscriptions to add to log transaction and update memreas_master
		// - invoice.payment.succeeded (subscription payment)
		// - invoice.payment.failed (subscription payment failed - send email)
		//
		
		if ($eventArr ['type'] == 'invoice.payment_succeeded') {
			//
			// Log transaction - note float balance is not incremented for subscriptions
			//
			$account_memreas_float = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
			$stripe_subscription_amount = $eventArr ['data'] ['object'] ['lines'] ['data'] [0] ['amount'];
			if ($stripe_subscription_amount > 0) {
				$subscription_amount = $stripe_subscription_amount / 100; // convert stripe to $'s
			} else {
				$subscription_amount = 0;
			}
			$transactionDetail = array (
					'account_id' => $account_memreas_float->account_id,
					'transaction_type' => 'subscription_payment_float',
					'amount' => $subscription_amount,
					'currency' => 'USD',
					'transaction_request' => $eventArr ['type'],
					'transaction_response' => $eventArr,
					'transaction_sent' => MNow::now (),
					'transaction_receive' => MNow::now () 
			);
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( $transactionDetail );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// Transfer funds to memreas_master
			// Stripe transfer parameters
			//
			if ($subscription_amount > 0) {
				$account_memreas_master = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_MASTER );
				$transferParams = array (
						'amount' => $stripe_subscription_amount, // stripe stores in cents
						'currency' => 'USD',
						'destination' => $account_memreas_master->stripe_account_id,
						'description' => "transfer subscription payment to memreas master" 
				);
				
				//
				// Create transaction to log transfer
				//
				$transaction = new Memreas_Transaction ();
				$transaction->exchangeArray ( array (
						'account_id' => $account_memreas_master->account_id,
						'transaction_type' => 'subscription_payment_float_to_master',
						'transaction_status' => 'subscription_payment_float_to_master_fail',
						'pass_fail' => 0,
						'amount' => $subscription_amount,
						'currency' => "USD",
						'transaction_request' => json_encode ( $transferParams ),
						'transaction_sent' => MNow::now () 
				) );
				$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
				
				//
				// Make call to stripe for seller payee transfer
				//
				try {
					
					$transferResponse = $this->stripeClient->createTransfer ( $transferParams );
				} catch ( ZfrStripe\Exception\BadRequestException $e ) {
					return array (
							'status' => 'Failure',
							'message' => $e->getMessage () 
					);
				}
				$memreas_master_transfer_id = $transferResponse ['id'];
				Mlog::addone ( $cm . __LINE__ . '::$payee_transfer_id--->', $memreas_master_transfer_id );
				
				//
				// Update transaction for response
				//
				
				$transaction->exchangeArray ( array (
						'transaction_status' => 'subscription_payment_float_to_master_success',
						'pass_fail' => 1,
						'transaction_response' => json_encode ( $transferResponse ),
						'transaction_receive' => MNow::now () 
				) );
				$memreas_master_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
				
				if ($memreas_master_transaction_id) {
					//
					// Send email to admin to check account
					//
					$subject = "Stripe::Subscription payment success!";
					$data ['memreas_master_transaction_id'] = $memreas_master_transaction_id;
					$data ['transaction_type'] = 'subscription_payment_float_to_master';
					$data ['amount'] = $subscription_amount;
					$data ['event_data'] = $eventArr;
					$this->aws->sendSeSMail ( array (
							MemreasConstants::ADMIN_EMAIL 
					), $subject, json_encode ( $eventArr, JSON_PRETTY_PRINT ) );
				}
			}
		} else if ($eventArr ['type'] == 'invoice.payment_failed') {
			//
			// Log transaction
			//
			$account_memreas_float = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
			$stripe_customer_id = $eventArr ['data'] ['object'] ['customer'];
			$transactionDetail = array (
					'account_id' => $account_memreas_float->account_id,
					'transaction_type' => 'subscription_payment_failed_float',
					'amount' => 0,
					'currency' => 'USD',
					'transaction_request' => $eventArr ['type'],
					'transaction_response' => $eventArr,
					'transaction_sent' => MNow::now (),
					'transaction_receive' => MNow::now () 
			);
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( $transactionDetail );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// lookup customer by stripe customer id
			//
			$account_for_stripe_customer_id = $this->memreasStripeTables->getAccountTable ()->getAccountByStripeCustomerId ( $stripe_customer_id );
			if ($account_for_stripe_customer_id) {
				//
				// Send email to admin to check account
				//
				
				$subject = "Stripe Error::Subscription payment failed!";
				$data ['username'] = $account->username;
				$data ['user_id'] = $account->user_id;
				$data ['stripe_email_address'] = $account->stripe_email_address;
				$data ['stripe_customer_id'] = $account->stripe_customer_id;
				$data ['event_data'] = $eventArr;
				$this->aws->sendSeSMail ( array (
						MemreasConstants::ADMIN_EMAIL 
				), $subject, json_encode ( $eventArr, JSON_PRETTY_PRINT ) );
			}
		}
		die ();
	}
	
	/*
	 * -
	 * create customer for registration (buyer)
	 */
	public function createCustomer($data) {
		$cm = __CLASS__ . __METHOD__;
		
		Mlog::addone ( $cm . __LINE__ . '::$data', $data );
		
		try {
			
			/*
			 * -
			 * create stripe customer account with free plan
			 */
			$stripe_data = [ ];
			$stripe_data ['email'] = $data ['email'];
			$stripe_data ['description'] = $data ['description'];
			$stripe_data ['metadata'] = $data ['metadata'];
			$stripe_data ['plan'] = ( string ) MemreasConstants::PLAN_ID_A;
			
			$result = $this->stripeClient->createCustomer ( $stripe_data );
			$stripe_customer_id = $result ['id'];
			// Mlog::addone ( $cm . __LINE__ . '::$result', $result );
			Mlog::addone ( $cm . __LINE__ . '::$stripe_customer_id', $stripe_customer_id );
			
			/*
			 * -
			 * create account table entry
			 */
			$account = new Account ();
			$account->exchangeArray ( array (
					'user_id' => $data ['user_id'],
					'username' => $data ['username'],
					'account_type' => MemreasConstants::ACCOUNT_TYPE_BUYER,
					'balance' => 0,
					'stripe_customer_id' => $stripe_customer_id,
					'stripe_email_address' => $stripe_data ['email'],
					'create_time' => MNow::now (),
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			Mlog::addone ( $cm . __LINE__ . '::$account_id', $account_id );
			
			$data = [ ];
			$data ['stripe_customer_id'] = $stripe_customer_id;
			$data ['plan'] = ( string ) MemreasConstants::PLAN_ID_A;
			$result = $this->stripeClient->createSubscription ( $data );
			$stripe_subscription_id = $result ['id'];
			
			/*
			 * -
			 * create subscription entry
			 */
			$subscription = new Subscription ();
			$subscription->exchangeArray ( array (
					'account_id' => $account_id,
					'stripe_subscription_id' => $stripe_subscription_id,
					'currency_code' => 'USD',
					'plan' => MemreasConstants::PLAN_ID_A,
					'plan_amount' => MemreasConstants::PLAN_AMOUNT_A,
					'plan_description' => MemreasConstants::PLAN_DETAILS_A,
					'gb_storage_amount' => MemreasConstants::PLAN_GB_STORAGE_AMOUNT_A,
					'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY,
					'active' => '1',
					'start_date' => MNow::now (),
					'create_date' => MNow::now (),
					'update_time' => MNow::now () 
			) );
			$subscription_id = $this->memreasStripeTables->getSubscriptionTable ()->saveSubscription ( $subscription );
			
			return array (
					'status' => 'Success',
					'message' => 'account and subscription created' 
			);
		} catch ( Exception $e ) {
			return array (
					'status' => 'Failure',
					'message' => 'account and subscription failed' 
			);
		}
	}
	
	/*
	 * -
	 * get stripe customer data from stripe
	 */
	public function getCustomer($data, $stripe = false) {
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$data-->', $data );
		$account_found = false;
		$accounts = array ();
		
		//
		// Fetch Buyer Account
		//
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '$data [user_id]', $data ['user_id'] );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $data ['user_id'] );
		// Check if exist account
		if (! $account) {
			//
			// if not account must be registration so create account with free plan
			//
			$user = $this->memreasStripeTables->getUserTable ()->getUser ( $user_id );
			// for testing
			$data = [ ];
			$data ['user_id'] = $user->user_id;
			$data ['username'] = $user->username;
			$data ['email'] = $user->email_address;
			$data ['description'] = "Stripe account for email: " . $user->email_address;
			$data ['metadata'] = array (
					'user_id' => $user_id 
			);
			$data ['plan'] = ( string ) MemreasConstants::PLAN_ID_A;
			$result = $this->createCustomer ( $data );
			// Mlog::addone ( $cm . '::$this->createCustomer ( $data )', $result );
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
			
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
			//
			// Check again
			//
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $data ['user_id'] );
			// Check if exist account
			if (! $account) {
				
				return array (
						'status' => 'Failure',
						'message' => 'Account not found' 
				);
			}
		}
		
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '...' );
		if (! empty ( $account )) {
			$account_found = true;
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
			$accounts ['account'] ['customer'] = ($stripe) ? $this->stripeClient->getCustomer ( $account->stripe_customer_id ) : null;
			$accounts ['buyer_account'] ['accountHeader'] = $account;
			$accounts ['buyer_account'] ['accountDetail'] = $accountDetail;
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
			//
			// Fetch Subscription
			//
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$account->account_id-->', $account->account_id );
			$subscription = $this->memreasStripeTables->getSubscriptionTable ()->getActiveSubscription ( $account->account_id );
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
			$accounts ['buyer_account'] ['subscription'] = $subscription;
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
		}
		
		//
		// Fetch Seller Account
		//
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $data ['user_id'], 'seller' );
		if (! empty ( $account )) {
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
			$account_found = true;
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
			$accounts ['account'] ['customer'] = ($stripe) ? $this->stripeClient->getCustomer ( $account->stripe_customer_id ) : null;
			$accounts ['seller_account'] ['accountHeader'] = $account;
			$accounts ['seller_account'] ['accountDetail'] = $accountDetail;
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '', '' );
		}
		
		// account exists
		$accounts ['status'] = 'Success';
		
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$accounts-->', $accounts );
		return $accounts;
	}
	
	/*
	 * -
	 * provide refund via stripe and store data
	 */
	public function refundAmount($data) {
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $data ['user_id'] );
		
		// Check if exist account
		if (empty ( $account ))
			return array (
					'status' => 'Failure',
					'message' => 'Please add a payment method.' 
			);
		
		$account->reason = $data ['reason'];
		$transactionDetail = array (
				'account_id' => $account->account_id,
				'transaction_type' => 'refund_amount',
				'amount' => $data ['amount'],
				'currency' => 'USD',
				'transaction_request' => json_encode ( $account ),
				'transaction_response' => json_encode ( $account ),
				'transaction_sent' => MNow::now (),
				'transaction_receive' => MNow::now () 
		);
		$memreas_transaction = new Memreas_Transaction ();
		$memreas_transaction->exchangeArray ( $transactionDetail );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
		
		// Update Account Balance
		$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $account->account_id );
		$startingAccountBalance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
		$endingAccountBalance = $startingAccountBalance + $data ['amount'];
		
		$accountBalance = new AccountBalances ();
		$accountBalance->exchangeArray ( array (
				'account_id' => $account->account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "refund_amount",
				'starting_balance' => $startingAccountBalance,
				'amount' => $data ['amount'],
				'ending_balance' => $endingAccountBalance,
				'create_time' => MNow::now () 
		) );
		$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $accountBalance );
		
		// Update account table
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $account->account_id );
		$account->exchangeArray ( array (
				'balance' => $endingAccountBalance,
				'update_time' => MNow::now () 
		) );
		$accountId = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		
		return array (
				'status' => 'Success',
				'message' => 'Refund completed' 
		);
	}
	
	/*
	 * -
	 * Retrieve subscriptions customer has
	 */
	public function getCustomerPlans($user_id) {
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		
		// Check if exist account
		if (empty ( $account ))
			return array (
					'status' => 'Failure',
					'message' => 'Please add a payment method for your account' 
			);
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		$customer = $this->stripeClient->getCustomer ( $account->stripe_customer_id );
		$customerPlans = $customer ['info'] ['subscriptions'] ['data'];
		return array (
				'status' => 'Success',
				'plans' => $customerPlans 
		);
	}
	
	/*
	 * -
	 * Retrieve Stripe plans
	 */
	public function listPlans() {
		return array (
				'status' => 'Success',
				'plans' => $this->stripePlan->getAllPlans () 
		);
	}
	public function getTotalPlanUser($planId) {
		$countUserPlan = $this->memreasStripeTables->getSubscriptionTable ()->countUser ( $planId );
		return $countUserPlan;
	}
	public function getOrderHistories($user_id, $search_username, $page, $limit) {
		if ($user_id) {
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
			
			// Check if exist account
			if (empty ( $account ))
				return array (
						'status' => 'Failure',
						'message' => 'please add your payment method' 
				);
			
			$transactions = $this->memreasStripeTables->getTransactionTable ()->getTransactionByAccountId ( $account->account_id, $page, $limit );
		} else {
			if ($search_username) {
				$accounts = $this->memreasStripeTables->getAccountTable ()->searchAccountByName ( $search_username );
				$account_range = array ();
				foreach ( $accounts as $value )
					$account_range [] = $value->account_id;
				
				if (! empty ( $account_range ))
					$transactions = $this->memreasStripeTables->getTransactionTable ()->getAllTransactions ( $account_range, $page, $limit );
				else
					$transactions = $this->memreasStripeTables->getTransactionTable ()->getAllTransactions ( null, $page, $limit );
			} else
				$transactions = $this->memreasStripeTables->getTransactionTable ()->getAllTransactions ( null, $page, $limit );
		}
		
		$orders = array ();
		if (! empty ( $transactions )) {
			foreach ( $transactions as $transaction ) {
				$user = $this->memreasStripeTables->getAccountTable ()->getAccount ( $transaction->account_id );
				$AccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalanceByTransactionId ( $transaction->transaction_id );
				$balance = array ();
				if (! empty ( $AccountBalance )) {
					$balance ['starting_balance'] = $AccountBalance->starting_balance;
					$balance ['amount'] = $AccountBalance->amount;
					$balance ['ending_balance'] = $AccountBalance->ending_balance;
				} else {
					$balance ['starting_balance'] = 0;
					$balance ['amount'] = 0;
					$balance ['ending_balance'] = 0;
				}
				
				if ($search_username) {
				}
				$orders [] = array (
						// 'username' => $user->username,
						'username' => $search_username,
						'transaction' => $transaction,
						'balance' => $balance 
				);
			}
			return array (
					'status' => 'Success',
					'transactions' => $orders 
			);
		} else
			return array (
					'status' => 'Failure',
					'message' => 'No record' 
			);
	}
	
	/*
	 * -
	 * retrieve order data
	 */
	public function getOrder($transaction_id) {
		return $this->memreasStripeTables->getTransactionTable ()->getTransaction ( $transaction_id );
	}
	
	/*
	 * -
	 * retrieve account detail
	 */
	public function getAccountDetailByAccountId($account_id) {
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $account_id );
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::account--->', $account );
		if ($account) {
			$userDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
			return $userDetail;
		}
		Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
		return null;
	}
	
	/*
	 * -
	 * get account and user detail by memreas user_id
	 */
	public function getUserById($user_id) {
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		$userDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		return $userDetail;
	}
	
	/*
	 * -
	 * Check user type based on user id
	 */
	public function checkUserType($username) {
		Mlog::addone ( __CLASS__ . __METHOD__, '...' );
		$user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( $username );
		if (! $user)
			return array (
					'status' => 'Failure',
					'message' => 'No user related to this username' 
			);
			
			// Fetch the account
		$type = array ();
		$buyer_amount = 0;
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id );
		if (! empty ( $account )) {
			Mlog::addone ( __CLASS__ . __METHOD__, 'buyer' );
			$type [] = 'buyer';
			$buyer_amount = $account->balance;
			Mlog::addone ( __CLASS__ . __METHOD__ . '$account->balance', $account->balance );
		}
		
		$seller_amount = 0;
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id, 'seller' );
		if (! empty ( $account )) {
			Mlog::addone ( __CLASS__ . __METHOD__, 'seller' );
			$type [] = 'seller';
			$seller_amount = $account->balance;
			Mlog::addone ( __CLASS__ . __METHOD__ . '$account->balance', $account->balance );
		}
		
		if (! empty ( $type ))
			return array (
					'status' => 'Success',
					'types' => $type,
					'buyer_balance' => $buyer_amount,
					'seller_balance' => $seller_amount 
			);
		else
			return array (
					'status' => 'Failure',
					'message' => 'please add your payment method' 
			);
	}
	
	/*
	 * Override customer's function
	 */
	public function addSeller($seller_data) {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm . __LINE__ . '::$seller_data--->', $seller_data );
		
		// Get memreas user name
		$user_name = $seller_data ['user_name'];
		$user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( $user_name );
		if (! $user) {
			return array (
					'status' => 'Failure',
					'message' => 'No user related to this username' 
			);
		}
		
		// Fetch User, Account
		$account_type = (isset ( $seller_data ['add_seller_account_type'] )) ? $seller_data ['add_seller_account_type'] : 'seller';
		$user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( $user_name );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id, 'seller' );
		if (! $account) {
			/*
			 * -
			 * Call Stripe and create account if seller
			 * - tracker accounts don't need stripe ids (i.e. memreas_float, memreas_payer
			 */
			$ssn_last4 = '';
			$stripe_customer_id = '';
			$stripe_email_address = $user->email_address;
			$stripe_account_id = '';
			$stripe_account_id = '';
			$stripe_bank_account_id = '';
			$keys = '';
			$seller_info = '';
			$response = $account_type;
			$bank_account_id = '';
			
			if ($account_type == 'seller') {
				// create customer for seller
				$managedAccount = [ ];
				$data = [ ];
				$data ['email'] = $user->email_address;
				$data ['description'] = "stripe seller account for email: " . $user->email_address;
				$data ['metadata'] = array (
						'user_id' => $user->user_id,
						'username' => $user->username 
				);
				$managedAccount ['request'] ['customer'] = $data;
				$managedAccount ['response'] ['customer'] = $result = $this->stripeClient->createCustomer ( $data );
				$stripe_customer_id = $result ['id'];
				
				// build basic info to create account
				// - split dob - 0-month, 1-day, 2-year
				$dob_split = explode ( "-", $seller_data ['date_of_birth'] );
				$seller_info = [ ];
				$seller_info ['managed'] = true;
				$seller_info ['country'] = 'US'; // US accounts only for now
				$seller_info ['email'] = $user->email_address; // email address on file.
				                                               
				// extended data
				$seller_info ['metadata'] ['user_id'] = $user->user_id;
				$seller_info ['metadata'] ['username'] = $user->username;
				$seller_info ['tos_acceptance'] ['date'] = strtotime ( MNow::now () );
				$seller_info ['tos_acceptance'] ['ip'] = $seller_data ['ip_address'];
				$seller_info ['tos_acceptance'] ['user_agent'] = $seller_data ['user_agent'];
				
				// legal_entity
				$ssn_last4 = substr ( $seller_data ['tax_ssn_ein'], - 4, 4 );
				$seller_info ['legal_entity'] ['business_tax_id'] = $seller_data ['tax_ssn_ein'];
				$seller_info ['legal_entity'] ['dob'] ['month'] = $dob_split [1];
				$seller_info ['legal_entity'] ['dob'] ['day'] = $dob_split [2];
				$seller_info ['legal_entity'] ['dob'] ['year'] = $dob_split [0];
				$seller_info ['legal_entity'] ['first_name'] = $seller_data ['first_name'];
				$seller_info ['legal_entity'] ['last_name'] = $seller_data ['last_name'];
				$seller_info ['legal_entity'] ['type'] = "individual"; // company later
				                                                       
				// verification
				$seller_info ['legal_entity'] ['address'] ['line1'] = $seller_data ['address_line_1'];
				$seller_info ['legal_entity'] ['address'] ['city'] = $seller_data ['city'];
				$seller_info ['legal_entity'] ['address'] ['state'] = $seller_data ['state'];
				$seller_info ['legal_entity'] ['address'] ['postal_code'] = $seller_data ['zip_code'];
				$seller_info ['legal_entity'] ['ssn_last_4'] = $ssn_last4;
				$seller_info ['legal_entity'] ['personal_id_number'] = $seller_data ['tax_ssn_ein'];
				
				// bank data
				$seller_info ['external_account'] ['object'] = "bank_account";
				$seller_info ['external_account'] ['account_number'] = $seller_data ['account_number'];
				$seller_info ['external_account'] ['routing_number'] = $seller_data ['routing_number'];
				$seller_info ['external_account'] ['account_holder_name'] = $seller_data ['first_name'] . ' ' . $seller_data ['last_name'];
				$seller_info ['external_account'] ['account_holder_type'] = "individual"; // "company" later
				$seller_info ['external_account'] ['country'] = 'US'; // US accounts only for now
				$seller_info ['external_account'] ['currency'] = "USD";
				$seller_info ['external_account'] ['currency'] = "USD";
				$seller_info ['transfer_schedule'] ['interval'] = "manual";
				
				/*
				 * -
				 * Make call to stripe to create account and bank account and send verification data
				 */
				$managedAccount ['request'] ['account'] = $seller_info;
				$managedAccount ['response'] ['account'] = $response = $this->stripeClient->createManagedAccount ( $seller_info );
				$stripe_account_id = $managedAccount ['response'] ['account'] ['id'];
				$stripe_bank_account_id = $managedAccount ['response'] ['account'] ['external_accounts'] ['data'] [0] ['id'];
				$keys = $managedAccount ['response'] ['account'] ['keys'];
			} // end if ($account_type == 'seller')
			
			/*
			 * -
			 * Create Account
			 */
			$account = new Account ();
			$account->exchangeArray ( array (
					'user_id' => $user->user_id,
					'username' => $user->username,
					'account_type' => $account_type,
					'balance' => 0,
					// store only last 4 of ssn for PII purposes...
					// 'tax_ssn_ein' => $seller_data ['tax_ssn_ein'],
					'tax_ssn_ein' => $ssn_last4,
					'stripe_customer_id' => $stripe_customer_id,
					'stripe_email_address' => $user->email_address,
					'stripe_account_id' => $stripe_account_id,
					'create_time' => MNow::now (),
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			// Store the transaction request and response with Stripe
			// account_id is required for transaction
			$transaction = new Memreas_Transaction ();
			$request = $seller_info;
			$transaction->exchangeArray ( array (
					'account_id' => $account_id,
					'transaction_type' => 'add_seller',
					'transaction_request' => json_encode ( $request ),
					'transaction_request' => json_encode ( $response ),
					'pass_fail' => 1,
					'transaction_status' => 'add_seller_passed',
					'transaction_sent' => MNow::now (),
					'transaction_receive' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		} else {
			// If user has an account with type is buyer, register a new seller account
			$account_id = $account->account_id;
			// Return a success message:
			$result = array (
					"status" => "Failure",
					"account_id" => $account_id,
					"message" => "Seller already exists" 
			);
			return $result;
		}
		
		$accountDetail = new AccountDetail ();
		$accountDetail->exchangeArray ( array (
				'account_id' => $account_id,
				'first_name' => $seller_data ['first_name'],
				'last_name' => $seller_data ['last_name'],
				'address_line_1' => $seller_data ['address_line_1'],
				'address_line_2' => $seller_data ['address_line_2'],
				'city' => $seller_data ['city'],
				'state' => $seller_data ['state'],
				'zip_code' => $seller_data ['zip_code'],
				'postal_code' => $seller_data ['zip_code'],
				'stripe_email_address' => $user->email_address 
		) );
		$account_detail_id = $this->memreasStripeTables->getAccountDetailTable ()->saveAccountDetail ( $accountDetail );
		
		// Insert account balances as needed
		$account_balances = new AccountBalances ();
		$account_balances->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => 'add seller',
				'starting_balance' => 0,
				'amount' => 0,
				'ending_balance' => 0,
				'create_time' => MNow::now () 
		) );
		$account_balances_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $account_balances );
		
		if ($account_type == 'seller') {
			// Insert bank account
			$bank_account = new BankAccount ();
			$bank_account->exchangeArray ( array (
					'account_id' => $account_id,
					'account_detail_id' => $account_detail_id,
					'stripe_bank_acount_id' => $stripe_bank_account_id,
					'account_holder_name' => $seller_data ['first_name'] . ' ' . $seller_data ['last_name'],
					'account_number' => $seller_data ['account_number'],
					'routing_number' => $seller_data ['routing_number'],
					'tax_ssn_ein' => $ssn_last4,
					'keys' => json_encode ( $keys ),
					'create_time' => MNow::now (),
					'update_time' => MNow::now () 
			) );
			$bank_account_id = $this->memreasStripeTables->getBankAccountTable ()->saveBankAccount ( $bank_account );
		}
		
		// Return a success message:
		$result = array (
				"status" => "Success",
				"account_id" => $account_id,
				"account_detail_id" => $account_detail_id,
				"transaction_id" => $transaction_id,
				"account_balances_id" => $account_balances_id,
				"bank_account_id" => $bank_account_id 
		);
		
		return $result;
	}
	
	/*
	 * Add value to account
	 */
	public function addValueToAccount($data) {
		$cm = __CLASS__ . __METHOD__;
		if (isset ( $data ['userid'] )) {
			$userid = $data ['userid'];
		} else {
			$userid = $_SESSION ['user_id'];
		}
		Mlog::addone ( $cm, __LINE__ );
		
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $userid );
		$currency = 'USD';
		if (empty ( $account )) {
			return array (
					'status' => 'Failure',
					'message' => 'You have no account at this time. Please add card first.' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		// Mlog::addone ( 'addValueToAccount($data) - $account -->', $account );
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		
		if (empty ( $accountDetail )) {
			return array (
					'status' => 'Failure',
					'message' => 'There is no data with your account.' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodByStripeReferenceId ( $data ['stripe_card_reference_id'] );
		
		if (empty ( $paymentMethod )) {
			return array (
					'status' => 'Failure',
					'message' => 'This card not relate to your account' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		// Mlog::addone ( 'addValueToAccount($data) - $paymentMethod -->', $paymentMethod );
		try {
			/**
			 * -
			 * Generate Stripe paramters
			 */
			$cardId = $paymentMethod->stripe_card_reference_id;
			$customerId = $account->stripe_customer_id;
			$amount = $data ['amount'];
			$transactionAmount = ( int ) ($data ['amount'] * 100); // Stripe use minimum amount conver for transaction. Eg: input $5
			                                                       // convert request to stripe value is 500
			
			/*
			 * -
			 * Fethch the memreas float account for charge
			 * - uses customer id but places funds in memreas float account
			 * -
			 */
			$account_memreas_float = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
			$stripeChargeParams = array (
					'amount' => $transactionAmount,
					'currency' => $currency,
					'customer' => $customerId,
					'capture' => false,
					'card' => $cardId, // If this card param is null, Stripe will get primary customer card to charge
					'description' => 'Add value to account',
					'statement_descriptor' => "memreas purchase" 
			);
			// Set description more details later
			// 'destination' => $account_memreas_float->stripe_account_id
			
			/**
			 * -
			 * Store transaction data prior to sending to Stripe
			 */
			
			// Begin storing transaction to DB
			$transactionRequest = $stripeChargeParams;
			$transactionRequest ['account_id'] = $account->account_id;
			$transactionRequest ['stripe_details'] = array (
					'stripeCustomer' => $customerId,
					'stripeCardId' => $cardId,
					'stripeChargeParams' => $stripeChargeParams 
			);
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $account->account_id,
					'transaction_type' => 'add_value_to_account',
					'transaction_request' => json_encode ( $transactionRequest ),
					'amount' => $data ['amount'],
					'currency' => $currency,
					'transaction_sent' => MNow::now () 
			) );
			$activeCreditToken = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			$stripeChargeParams ['metadata'] = array (
					"transaction_id" => "$transaction_id" 
			); // Set description more details later
			
			$chargeResult = $this->stripeClient->createCharge ( $stripeChargeParams );
			$charge_authorization_id = $chargeResult ['id'];
			if ($chargeResult) {
				// Check if Charge is successful or not
				if (! $chargeResult ['paid']) {
					return array (
							'status' => 'Failure',
							'Transaction declined! Please check your Stripe account and cards' 
					);
				}
				
				/**
				 * -
				 * Buyer Section
				 * - Store response to transaction
				 * - Store the account balance
				 * - Update the account
				 */
				
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '$chargeResult-->', $chargeResult );
				$transactionDetail = array (
						'transaction_id' => $transaction_id,
						'account_id' => $account->account_id,
						'pass_fail' => 1,
						'transaction_response' => json_encode ( $chargeResult ),
						'transaction_receive' => MNow::now (),
						'transaction_status' => 'buy_credit_email' 
				);
				$memreas_transaction->exchangeArray ( $transactionDetail );
				$authorization_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
				
				/**
				 * -
				 * Send activation email
				 */
				$viewModel = new ViewModel ( array (
						'username' => $accountDetail->first_name . ' ' . $accountDetail->last_name,
						'active_link' => MemreasConstants::MEMREAS_WSPROXYPAY . 'stripe_activeCredit&token=' . $activeCreditToken . '&cid=' . $charge_authorization_id,
						'amount' => $amount,
						'currency' => $currency 
				) );
				$viewModel->setTemplate ( 'email/buycredit' );
				$viewRender = $this->service_locator->get ( 'ViewRenderer' );
				
				Mlog::addone ( 'addValueToAccount($data) - ', 'past view model' );
				
				$html = $viewRender->render ( $viewModel );
				$subject = 'memreas buy credit - your purchased credit is ready for activation';
				
				$user = $this->memreasStripeTables->getUserTable ()->getUser ( $userid );
				
				Mlog::addone ( 'addValueToAccount($data) - $user->email_address', $user->email_address );
				
				$this->aws->sendSeSMail ( array (
						$user->email_address 
				), 

				$subject, $html );
				
				Mlog::addone ( 'addValueToAccount($data) - ', 'about to return success' );
				
				return array (
						'status' => 'Success',
						'message' => 'Order completed! We have sent you activation email.' 
				);
			} else {
				return array (
						'status' => 'Failure',
						'message' => 'Unable to process payment' 
				);
			}
		} catch ( Exception $e ) {
			$error_data = array ();
			$error_data ['user_id'] = (isset ( $user_id )) ? $user_id : $_SESSION ['user_id'];
			$error_data ['account_id'] = (isset ( $account )) ? $account->account_id : '';
			$error_data ['$transaction_id'] = (isset ( $transaction_id )) ? $transaction_id : '';
			$this->aws->sendSeSMail ( array (
					MemreasConstants::ADMIN_EMAIL 
			), "Stripe Error: AddValueAction", "An error has occurred for error data:: " + json_encode ( $error_data ) . ' e->getMessage() ' . $e->getMessage () );
			return array (
					'status' => 'Failure',
					'message' => 'Unable to process payment' 
			);
		}
	}
	
	/*
	 * -
	 * email activation response to capture authorization
	 */
	public function activePendingBalanceToAccount($authorization_transaction_id, $charge_authorization_id) {
		$cm = __CLASS__ . __METHOD__;
		$Transaction = $this->memreasStripeTables->getTransactionTable ()->getTransaction ( $authorization_transaction_id );
		
		if (empty ( $Transaction )) {
			return array (
					'status' => 'Failure',
					'message' => 'No record found' 
			);
		}
		Mlog::addone ( $cm . __LINE__ . 'pendingAccountBalance->auth_transaction_status', $Transaction->transaction_status );
		if (strpos ( $Transaction->transaction_status, 'activated' )) {
			return array (
					'status' => 'activated',
					'message' => 'These credits were applied in a prior activation.' 
			);
		}
		
		/*
		 * -
		 * Setup parms for capture
		 */
		$stripeCaptureParams = array (
				'id' => $charge_authorization_id,
				'capture' => true 
		);
		
		/*
		 * -
		 * Store transaction data prior to sending to Stripe
		 */
		
		// Begin storing transaction to DB
		$transactionRequest = $stripeCaptureParams;
		$transactionRequest ['account_id'] = $Transaction->account_id;
		$transactionRequest ['stripe_charge_authorization_id'] = array (
				'id' => $charge_authorization_id 
		);
		$memreas_transaction = new Memreas_Transaction ();
		$memreas_transaction->exchangeArray ( array (
				'account_id' => $Transaction->account_id,
				'transaction_type' => 'add_value_to_account_capture',
				'transaction_status' => 'add_value_to_account_capture_fail',
				'pass_fail' => 0,
				'transaction_request' => json_encode ( $transactionRequest ),
				'amount' => $Transaction->amount,
				'currency' => $Transaction->currency,
				'ref_transaction_id' => $authorization_transaction_id,
				'transaction_sent' => MNow::now () 
		) );
		$capture_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
		
		/*
		 * -
		 * Make Capture call to Stripe
		 */
		$chargeResult = $this->stripeClient->captureCharge ( $stripeCaptureParams );
		if ($chargeResult) {
			// Check if Charge is successful or not
			if (! $chargeResult ['paid']) {
				return array (
						'status' => 'Failure',
						'Transaction declined! Please check your Stripe account and cards' 
				);
			}
		}
		
		// update original transaction as activated
		$authorization_transaction = $this->memreasStripeTables->getTransactionTable ()->getTransaction ( $authorization_transaction_id );
		$authorization_transaction->exchangeArray ( array (
				'transaction_status' => 'buy_credit_email, buy_credit_verified, buy_credit_activated',
				'ref_transaction_id' => $capture_transaction_id,
				'transaction_receive' => MNow::now () 
		) );
		$authorization_transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $authorization_transaction );
		
		// update transaction with response data
		$Transaction = $this->memreasStripeTables->getTransactionTable ()->getTransaction ( $capture_transaction_id );
		$memreas_transaction->exchangeArray ( array (
				'transaction_status' => 'buy_credit_email, buy_credit_verified, buy_credit_activated',
				'pass_fail' => 1,
				'transaction_response' => json_encode ( $transactionRequest ),
				'transaction_receive' => MNow::now () 
		) );
		$capture_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
		
		/**
		 * -
		 * Store Buyer Account_Balances
		 */
		$account_id = $Transaction->account_id;
		$amount = $Transaction->amount;
		$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $account_id );
		$startingAccountBalance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
		$endingAccountBalance = $startingAccountBalance + $Transaction->amount;
		
		$accountBalance = new AccountBalances ();
		$accountBalance->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "add_value_to_account_capture",
				'starting_balance' => $startingAccountBalance,
				'amount' => $amount,
				'ending_balance' => $endingAccountBalance,
				'create_time' => MNow::now () 
		) );
		$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $accountBalance );
		
		/**
		 * -
		 * Update Account to reflect the latest balance
		 */
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $account_id );
		$account->exchangeArray ( array (
				'balance' => $endingAccountBalance,
				'update_time' => MNow::now () 
		) );
		$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		
		/**
		 * -
		 * memreas_float section
		 * Store to memreas_float here (add transaction entries to deduct fee and store remainder as float)
		 * -
		 * - insert account_balances - reference capture id
		 * - update account
		 * - store the fee with memreas_fees
		 * - insert account_balances - reference capture id
		 * - update account
		 */
		$account_memreas_float = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
		// Get the last balance - Insert the new account balance
		
		$starting_balance = (isset ( $account_memreas_float )) ? $account_memreas_float->balance : '0.00';
		$ending_balance = $starting_balance + $amount;
		$memreasFloatAccountBalance = new AccountBalances ();
		$memreasFloatAccountBalance->exchangeArray ( array (
				'account_id' => $account_memreas_float->account_id,
				'transaction_id' => $capture_transaction_id,
				'transaction_type' => "add_value_to_account_capture_memreas_float",
				'starting_balance' => $startingAccountBalance,
				'amount' => $amount,
				'ending_balance' => $endingAccountBalance,
				'create_time' => MNow::now () 
		) );
		$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $memreasFloatAccountBalance );
		
		// Update the account table with new balance
		$account_memreas_float->exchangeArray ( array (
				'balance' => $endingAccountBalance,
				'update_time' => MNow::now () 
		) );
		$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_float );
		
		/**
		 * -
		 * Deduct the fees from memreas_fees
		 * setup paramters to fetch fees
		 */
		$stripeBalanceTransactionParams = array (
				'id' => $chargeResult ['balance_transaction'] 
		);
		
		/**
		 * -
		 * Store balance transaction fee request prior to sending to stripe
		 */
		
		$memreas_transaction = new Memreas_Transaction ();
		$memreas_transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'add_value_to_account_memreas_fees',
				'pass_fail' => 0,
				'ref_transaction_id' => $capture_transaction_id,
				'transaction_status' => 'add_value_to_account_capture_memreas_float_fail',
				'transaction_request' => json_encode ( $stripeBalanceTransactionParams ),
				'transaction_sent' => MNow::now () 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
		
		/**
		 * -
		 * Make call to Stripe
		 */
		$balance_transaction = $this->stripeClient->getBalanceTransaction ( $stripeBalanceTransactionParams );
		// Mlog::addone ( $cm . 'addValueToAccount()->$balance_transaction::', $balance_transaction );
		$fees = $balance_transaction ['fee'] / 100; // stripe stores in cents
		
		/**
		 * -
		 * Store balance transaction fee response from stripe
		 */
		$memreas_transaction->exchangeArray ( array (
				'transaction_id' => $transaction_id,
				'amount' => "-$fees",
				'currency' => 'USD',
				'pass_fail' => 1,
				'transaction_status' => 'add_value_to_account_memreas_fees_success',
				'transaction_response' => json_encode ( $balance_transaction ),
				'transaction_receive' => MNow::now () 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
		Mlog::addone ( $cm, __LINE__ );
		
		/**
		 * -
		 * Deduct fee from fees account
		 */
		$account_memreas_fees = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FEES );
		$starting_balance = (isset ( $account_memreas_fees )) ? $account_memreas_fees->balance : '0.00';
		$ending_balance = $starting_balance - $fees;
		
		// Insert the new account balance
		$endingAccountBalance = new AccountBalances ();
		Mlog::addone ( $cm, __LINE__ );
		$endingAccountBalance->exchangeArray ( array (
				'account_id' => $account_memreas_fees->account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "add_value_to_account_capture_memreas_fees",
				'starting_balance' => $starting_balance,
				'amount' => "-$fees",
				'ending_balance' => $ending_balance,
				'create_time' => MNow::now () 
		) );
		$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
		
		// Update the account table with new balance
		$account_memreas_fees->exchangeArray ( array (
				'balance' => $ending_balance,
				'update_time' => MNow::now () 
		) );
		$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_fees );
		
		return array (
				'status' => 'Success',
				'message' => 'Amount has been captured and activated' 
		);
	}
	public function getAccountBalance($data) {
		$user_id = $data ['user_id'];
		$user = $this->memreasStripeTables->getUserTable ()->getUser ( $user_id );
		if (! $user)
			return array (
					'status' => 'Failure',
					'message' => 'No user related to this username' 
			);
			
			// Fetch the account
		$buyer_amount = 0;
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id );
		if (! empty ( $account ))
			$buyer_amount = $account->balance;
		
		$seller_amount = 0;
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id, 'seller' );
		if (! empty ( $account ))
			$seller_amount = $account->balance;
		
		return array (
				'status' => 'Success',
				'buyer_balance' => $buyer_amount,
				'seller_balance' => $seller_amount 
		);
	}
	public function buyMedia($data) {
		
		/**
		 * -
		 * This method is responsible for executing a purchase
		 * - the buyer's credits are debited
		 * - the seller's credits are credited minus the memreas processing fee
		 * - the memreas_float account is debited for the full purchase amount
		 * - the memreas_master account is credit for the processing fee
		 * - if an error occurs the transaction is rolled back and an email is sent.
		 */
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm . __LINE__ . 'buyMedia->$data', $data );
		// $user = $this->memreasStripeTables->getUserTable ()->getUser ( $data ['user_id'] );
		$user = $this->memreasStripeTables->getUserTable ()->getUser ( $_SESSION ['user_id'] );
		$password_verification = md5 ( $data ['password'] );
		$amount = $data ['amount'];
		$event_id = $data ['event_id'];
		$seller_id = $data ['seller_id'];
		$duration_from = $data ['duration_from'];
		$duration_to = $data ['duration_to'];
		
		/**
		 * -
		 * Validate buyer entry
		 */
		if (! $user) {
			return array (
					'status' => 'Failure',
					'message' => 'No user related to this username' 
			);
		}
		
		/**
		 * -
		 * Ensure buyer hasn't already purchased
		 */
		
		/**
		 * -
		 * Confirm password
		 */
		$buyer_email = $user->email_address;
		if (empty ( $data ['stripe_ws_tester'] )) {
			
			// Validate password
			if (md5 ( $data ['password'] ) != $user->password) {
				return array (
						'status' => 'Failure',
						'message' => 'verification failed - purchase cannot proceed' 
				);
			} else {
				$password_verified = 1;
			}
		}
		
		try {
			
			/**
			 * -
			 * Start transcation - if an error occurs rollback and send an email to investigate
			 */
			$dbAdapter = $this->service_locator->get ( MemreasConstants::MEMREASPAYMENTSDB );
			$connection = $dbAdapter->getDriver ()->getConnection ();
			$connection->beginTransaction ();
			
			/**
			 * -
			 * Buyer Section
			 * - create transaction for debit
			 * - insert entry for account balances
			 * - update account balance
			 */
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id );
			if (empty ( $account )) {
				return array (
						'status' => 'Failure',
						'message' => 'You have no account at this time. Please add card first.' 
				);
			}
			$accountId = $account->account_id;
			
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $accountId );
			
			if (! isset ( $currentAccountBalance ) || ($currentAccountBalance->ending_balance <= 0) || $account->balance <= 0 || ($currentAccountBalance->ending_balance <= $amount) || $account->balance <= $amount) {
				return array (
						"status" => "Failure",
						"Description" => "Account not found or does not have sufficient funds." 
				);
			}
			
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_type' => 'buy_media_purchase',
					'pass_fail' => 1,
					'amount' => "-$amount",
					'currency' => 'USD',
					'transaction_status' => 'success',
					'transaction_request' => "N/a",
					'transaction_sent' => MNow::now (),
					'transaction_response' => "N/a",
					'transaction_receive' => MNow::now () 
			) );
			$account_purchase_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// Starting decrement account balance
			//
			$startingBalance = $currentAccountBalance->ending_balance;
			$endingBalance = $startingBalance - $amount;
			
			// Insert the new account balance for the buyer
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase",
					'starting_balance' => $startingBalance,
					'amount' => "-$amount",
					'ending_balance' => $endingBalance,
					'create_time' => MNow::now () 
			) );
			$accountBalanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			//
			// Update the account table
			//
			$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $accountId );
			$account->exchangeArray ( array (
					'balance' => $endingBalance,
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			//
			// Update purchase table
			//
			$AccountPurchase = new AccountPurchases ();
			$verification_json = json_encode ( array (
					"password_verified" => $password_verified 
			) );
			$AccountPurchase->exchangeArray ( array (
					'account_id' => $account_id,
					'event_id' => $event_id,
					'amount' => $amount,
					'meta' => $verification_json,
					'transaction_id' => $transaction_id,
					'transaction_type' => 'buy_media_purchase',
					'create_time' => MNow::now (),
					'start_date' => $duration_from,
					'end_date' => $duration_to 
			) );
			$this->memreasStripeTables->getAccountPurchasesTable ()->saveAccountPurchase ( $AccountPurchase );
			
			//
			// store event_id with seller transaction for verifying payout later
			//
			$seller_event_id_purchased = $event_id;
			
			/**
			 * -
			 * determin seller amount due and memreas_payer
			 */
			$seller_amount = $amount * (1 - MemreasConstants::MEMREAS_PROCESSING_FEE);
			$memreas_payer_amount = $amount - $seller_amount;
			
			/**
			 * -
			 * Start credit process for the seller
			 */
			$seller = $this->memreasStripeTables->getUserTable ()->getUser ( $seller_id );
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $seller->user_id, 'seller' );
			if (! $account)
				return array (
						'status' => 'Failure',
						'message' => 'The target event owner is not registered as a seller, please try again later' 
				);
			$seller_account = $account;
			$accountId = $account->account_id;
			
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $accountId );
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_type' => 'buy_media_purchase',
					'pass_fail' => 1,
					'amount' => "+$seller_amount",
					'currency' => 'USD',
					'transaction_request' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id,
							"event_id" => $seller_event_id_purchased 
					) ),
					'transaction_status' => 'success',
					'transaction_sent' => MNow::now (),
					'transaction_response' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id,
							"event_id" => $seller_event_id_purchased 
					) ),
					'transaction_receive' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// Starting credit account balance
			//
			$startingBalance = $currentAccountBalance->ending_balance;
			$endingBalance = $startingBalance + $amount;
			
			//
			// Insert seller new account balance
			//
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase",
					'starting_balance' => $startingBalance,
					'amount' => "+$seller_amount",
					'ending_balance' => $endingBalance,
					'create_time' => MNow::now () 
			) );
			$accountBalanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			//
			// Update seller account table
			//
			$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $accountId, 'seller' );
			$account->exchangeArray ( array (
					'balance' => $endingBalance,
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			/**
			 * -
			 * Start bookkeeping process for the memreas_payer
			 */
			// ////////////////////////////////
			// Fetch the memreaspayer account
			// ////////////////////////////////
			$memreas_payer_user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( MemreasConstants::ACCOUNT_MEMREAS_PAYER );
			$memreas_payer_user_id = $memreas_payer_user->user_id;
			
			$memreas_payer_account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $memreas_payer_user_id, 'tracker' );
			if (! $memreas_payer_account) {
				$connection->rollback ();
				$result = array (
						"Status" => "Error",
						"Description" => "Could not find memreas_payer account" 
				);
				return $result;
			}
			$memreas_payer_account_id = $memreas_payer_account->account_id;
			
			// Increment the memreas_payer account for the processing fee - see constants
			// Fetch Account_Balances
			$payerAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $memreas_payer_account_id );
			
			// Log the transaction
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $memreas_payer_account_id,
					'transaction_type' => 'buy_media_purchase_memreas_payer',
					'pass_fail' => 1,
					'amount' => "+$memreas_payer_amount",
					'currency' => 'USD',
					'transaction_status' => 'success',
					'transaction_request' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_sent' => MNow::now (),
					'transaction_response' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_receive' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			// Credit the account
			// If no acount found set the starting balance to zero else use the ending balance.
			$starting_balance = (isset ( $payerAccountBalance )) ? $payerAccountBalance->ending_balance : '0.00';
			$ending_balance = $starting_balance + $memreas_payer_amount;
			
			// Insert the new account balance
			
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $memreas_payer_account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase_memreas_payer",
					'starting_balance' => $starting_balance,
					'amount' => "+$memreas_payer_amount",
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			// Update the account table
			
			// $account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $memreas_payer_account_id );
			$memreas_payer_account->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			// Add to the return message for debugging...
			$return_message [$memreas_payer_user->username] ['description-->'] = "Amount added to account for user--> " . $memreas_payer_user->username;
			$return_message [$memreas_payer_user->username] ['starting_balance'] = $starting_balance;
			$return_message [$memreas_payer_user->username] ['amount'] = $memreas_payer_amount;
			$return_message [$memreas_payer_user->username] ['ending_balance'] = $ending_balance;
			
			/**
			 * -
			 * Commit the transaction
			 */
			$connection->commit ();
			
			/**
			 * -
			 * Send Purchase Confirmation email
			 */
			$buyer = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $_SESSION ['user_id'] );
			$viewModel = new ViewModel ( array (
					'username' => $buyer->username,
					'seller_name' => $seller_account->username,
					'transaction_id' => $transaction_id,
					'amount' => $amount,
					'balance' => $buyer->balance,
					'event_name' => $data ['event_name'] 
			) );
			$viewModel->setTemplate ( 'email/buymedia' );
			$viewRender = $this->service_locator->get ( 'ViewRenderer' );
			
			$html = $viewRender->render ( $viewModel );
			$subject = 'memreas buy media confirmation receipt';
			
			$this->aws->sendSeSMail ( array (
					$buyer_email 
			), 

			$subject, $html );
			
			return array (
					'status' => 'Success',
					'message' => 'Buying media completed',
					'event_id' => $event_id,
					'transaction_id' => $transaction_id 
			);
		} catch ( \Exception $e ) {
			/**
			 * -
			 * Exception during purchase
			 */
			$connection->rollback ();
			$error_data = array ();
			$error_data ['user_id'] = (isset ( $user_id )) ? $user_id : $_SESSION ['user_id'];
			$error_data ['account_id'] = (isset ( $account )) ? $account->account_id : '';
			$error_data ['$transaction_id'] = (isset ( $transaction_id )) ? $transaction_id : '';
			$error_data ['$amount'] = (isset ( $amount )) ? $amount : '';
			$error_data ['$event_id'] = (isset ( $event_id )) ? $event_id : '';
			$error_data ['$seller_id'] = (isset ( $seller_id )) ? $seller_id : '';
			
			$this->aws->sendSeSMail ( array (
					MemreasConstants::ADMIN_EMAIL 
			), "Stripe Error: AddValueAction", "An error has occurred for error data:: " + json_encode ( $error_data ) . ' e->getMessage() ' . $e->getMessage () );
			
			return array (
					'status' => 'Failure',
					'message' => 'an error occurred - an email has been sent to our support team' 
			);
		}
	}
	public function checkOwnEvent($data) {
		$user_id = $data ['user_id'];
		
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		if (! $account) {
			return array (
					'status' => 'Failure',
					'message' => 'There is no account relates to this user' 
			);
		}
		
		$checkBuyMedia = $this->memreasStripeTables->getAccountPurchasesTable ()->getAccountPurchases ( $account->account_id );
		
		if (empty ( $checkBuyMedia )) {
			return array (
					'status' => 'Failure',
					'event_id' => 'Account has no purchase history' 
			);
		}
		
		$event_ids = array ();
		foreach ( $checkBuyMedia as $item ) {
			$event_ids [] = $item ['event_id'];
		}
		
		return array (
				'status' => 'Success',
				'events' => $event_ids 
		);
	}
	public function AccountHistory($data) {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm . __LINE__ . '::$data', $data );
		$userName = $data ['user_name'];
		$date_from = isset ( $data ['date_from'] ) ? $data ['date_from'] : '';
		$date_to = isset ( $data ['date_to'] ) ? $data ['date_to'] : MNow::now ();
		$user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( $userName );
		Mlog::addone ( $cm . __LINE__ . '::$user', $user );
		if ($date_from > $date_to) {
			return array (
					'status' => 'Failure',
					'message' => "from date: $date_from is greater than to date: $date_to" 
			);
		}
		if (! $user) {
			return array (
					'status' => 'Failure',
					'message' => 'User is not found' 
			);
		}
		Mlog::addone ( $cm . __LINE__ . '', '' );
		Mlog::addone ( $cm . __LINE__ . '::$user->user_id', $user->user_id );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id );
		Mlog::addone ( $cm . __LINE__ . '::$account', $account );
		if (! $account) {
			return array (
					'status' => 'Failure',
					'message' => 'Account associated with this user does not exist' 
			);
		}
		$accountId = $account->account_id;
		
		/**
		 * -
		 * Fetch the list of transactions by from/to or all for account
		 */
		if (! empty ( $date_from )) {
			// Mlog::addone ( 'AccountHistory:: with dates::', "from::$date_from to::$date_to" );
			$Transactions = $this->memreasStripeTables->getTransactionTable ()->getTransactionByAccountIdAndDateFromTo ( $accountId, $date_from, $date_to );
		} else {
			// Mlog::addone ( 'AccountHistory:: with dates::', "empty dates" );
			$Transactions = $this->memreasStripeTables->getTransactionTable ()->getTransactionByAccountId ( $accountId );
		}
		$TransactionsArray = array ();
		if ($Transactions) {
			foreach ( $Transactions as $Transaction ) {
				$row = array ();
				$row ["transaction_id"] = $Transaction->transaction_id;
				$row ["account_id"] = $Transaction->account_id;
				$row ["transaction_type"] = $Transaction->transaction_type;
				$row ["pass_fail"] = $Transaction->pass_fail;
				$row ["amount"] = $Transaction->amount;
				$row ["currency"] = $Transaction->currency;
				$row ["transaction_request"] = $Transaction->transaction_request;
				$row ["transaction_response"] = $Transaction->transaction_response;
				$row ["transaction_sent"] = $Transaction->transaction_sent;
				$row ["transaction_receive"] = $Transaction->transaction_receive;
				$TransactionsArray [] = $row;
			}
		}
		return array (
				'status' => 'Success',
				'account' => $account,
				'transactions' => $TransactionsArray 
		);
	}
	
	/*
	 * Override stripe recipient function
	 */
	public function createRecipient($data) {
		$this->stripeRecipient->setRecipientInfo ( $data );
		return $this->stripeRecipient->createRecipient ();
	}
	
	/*
	 * Override card's function
	 */
	public function storeCard($card_data = null) {
		$cm = __CLASS__ . __METHOD__;
		// Mlog::addone ( $cm . '::$card_data', $card_data );
		if (isset ( $card_data ['user_id'] )) {
			$user_id = $card_data ['user_id'];
		} else {
			$user_id = $_SESSION ['user_id'];
		}
		
		$user = $this->memreasStripeTables->getUserTable ()->getUser ( $user_id );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		if (! $account) {
			
			// for testing
			$data = [ ];
			$data ['user_id'] = $user->user_id;
			$data ['username'] = $user->username;
			$data ['email'] = $user->email_address;
			$data ['description'] = "Stripe account for email: " . $user->email_address;
			$data ['metadata'] = array (
					'user_id' => $user_id 
			);
			$data ['plan'] = ( string ) MemreasConstants::PLAN_ID_A;
			$result = $this->createCustomer ( $data );
			// Mlog::addone ( $cm . '::$this->createCustomer ( $data )', $result );
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		}
		
		// fetch data for card
		$account_id = $account->account_id;
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account_id );
		$stripe_customer_id = $account->stripe_customer_id;
		// $this->stripeCard->setCardAttribute ( 'customer', $stripe_customer_id );
		
		/*
		 * -
		 * Store the request transaction
		 */
		
		$transaction = new Memreas_Transaction ();
		// Copy the card and obfuscate the card number before storing the transaction
		$obfuscated_card = json_decode ( json_encode ( $card_data ), true );
		$obfuscated_card ['number'] = $this->obfuscateAccountNumber ( $obfuscated_card ['number'] );
		$obfuscated_card ['cvc'] = '';
		
		$transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'store_credit_card',
				'transaction_request' => json_encode ( $obfuscated_card ),
				'transaction_sent' => MNow::now () 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		/*
		 * -
		 * Call Stripe - Save customer card
		 */
		// $stripeCard = $this->stripeCard->storeCard ( $card_data );
		// new array - api complains about user_id
		$cardForStripe ['source'] ['object'] = "card";
		$cardForStripe ['source'] ['name'] = $card_data ['name'];
		$cardForStripe ['source'] ['number'] = $card_data ['number'];
		$cardForStripe ['source'] ['cvc'] = $card_data ['cvc'];
		$cardForStripe ['source'] ['exp_month'] = $card_data ['exp_month'];
		$cardForStripe ['source'] ['exp_year'] = $card_data ['exp_year'];
		$cardForStripe ['source'] ['address_line1'] = $card_data ['address_line1'];
		$cardForStripe ['source'] ['address_line2'] = $card_data ['address_line2'];
		$cardForStripe ['source'] ['address_city'] = $card_data ['address_city'];
		$cardForStripe ['source'] ['address_state'] = $card_data ['address_state'];
		$cardForStripe ['source'] ['address_zip'] = $card_data ['address_zip'];
		$cardForStripe ['source'] ['address_country'] = $card_data ['address_country'];
		$stripeCard = $this->stripeClient->createCard ( $stripe_customer_id, $cardForStripe );
		$card_id = $stripeCard ['id'];
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$stripeCard', $stripeCard );
		if (empty ( $card_id )) {
			/*
			 * -
			 * Handle error and store
			 */
			
			$transaction->exchangeArray ( array (
					'account_id' => $account_id,
					'pass_fail' => 0,
					'transaction_type' => 'store_credit_card_failed',
					'transaction_response' => $stripeCard,
					'transaction_receive' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			return array (
					'status' => 'Failure',
					'message' => $stripeCard ['message'] 
			);
		}
		
		/*
		 * -
		 * Handle success and store transaction
		 */
		$transaction->exchangeArray ( array (
				'transaction_id' => $transaction_id,
				'pass_fail' => 1,
				'transaction_status' => 'store_credit_card_passed',
				'transaction_response' => json_encode ( $stripeCard ),
				'transaction_receive' => MNow::now () 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		// Update account detail information
		$accountDetail = new AccountDetail ();
		$fullname = explode ( ' ', $card_data ['name'] );
		$firstName = $fullname [0];
		$lastName = $fullname [1];
		$accountDetail->exchangeArray ( array (
				'account_id' => $account_id,
				'first_name' => $firstName,
				'last_name' => $lastName,
				'address_line_1' => $card_data ['address_line1'],
				'address_line_2' => $card_data ['address_line2'],
				'city' => $card_data ['address_city'],
				'state' => $card_data ['address_state'],
				'zip_code' => $card_data ['address_zip'],
				'postal_code' => $card_data ['address_zip'] 
		) );
		$account_detail_id = $this->memreasStripeTables->getAccountDetailTable ()->saveAccountDetail ( $accountDetail );
		
		// Add the new payment method
		$payment_method = new PaymentMethod ();
		$payment_method->exchangeArray ( array (
				'account_id' => $account_id,
				'account_detail_id' => $account_detail_id,
				'stripe_card_reference_id' => $stripeCard ['id'],
				'card_type' => $stripeCard ['brand'],
				'obfuscated_card_number' => $obfuscated_card ['number'],
				'exp_month' => $stripeCard ['exp_month'],
				'exp_year' => $stripeCard ['exp_year'],
				'valid_until' => $stripeCard ['exp_month'] . '/' . $stripeCard ['exp_year'],
				'delete_flag' => '0',
				'create_time' => MNow::now (),
				'update_time' => MNow::now () 
		) );
		$payment_method_id = $this->memreasStripeTables->getPaymentMethodTable ()->savePaymentMethod ( $payment_method );
		
		// Return a success message:
		$result = array (
				"status" => "Success",
				"stripe_card_reference_id" => $stripeCard ['id'],
				"account_id" => $account_id,
				"account_detail_id" => $account_detail_id,
				"transaction_id" => $transaction_id,
				"payment_method_id" => $payment_method_id 
		);
		
		return $result;
	}
	
	/*
	 * -
	 * Set subscription for user
	 * - disk usage needs to be input
	 */
	public function setSubscription($data) {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm . __LINE__ . '::$data->', $data );
		
		/*
		 * -
		 * Check parameters
		 */
		if (isset ( $data ['userid'] )) {
			$userid = $data ['userid'];
		} else {
			$userid = $_SESSION ['user_id'];
		}
		Mlog::addone ( $cm, __LINE__ );
		
		if (isset ( $data ['card_id'] )) {
			$card = $data ['card_id'];
		} else {
			$card = null;
		}
		Mlog::addone ( $cm, __LINE__ );
		
		/*
		 * -
		 * Check acount
		 */
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $userid );
		if (! $account) {
			return array (
					'status' => 'Failure',
					'message' => 'please add your payment method' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		$account_id = $account->account_id;
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account_id );
		Mlog::addone ( $cm, __LINE__ );
		
		/*
		 * -
		 * Check acount detail
		 */
		if (! $accountDetail) {
			return array (
					'status' => 'Failure',
					'message' => 'Please update account detail first' 
			);
		}
		
		/*
		 * -
		 * Check plan
		 * - check local database for speed
		 */
		$subscription = $this->memreasStripeTables->getSubscriptionTable ()->getActiveSubscription ( $account_id );
		Mlog::addone ( $cm, __LINE__ );
		if (! $subscription) {
			return array (
					'status' => 'Failure',
					'message' => 'Subscription plan was not found' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		/*
		 * -
		 * Check if selected plan is different from existing
		 */
		if ($subscription->plan == $data ['plan']) {
			return array (
					'status' => 'Failure',
					'message' => 'This plan is active currently.' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		/*
		 * -
		 * Get Disk Usage
		 * - sent in as parameter from ws server as float
		 */
		$disk_usage = $data ['disk_usage'];
		$planDetail = $this->stripePlan->getPlanConfig ( $data ['plan'] );
		$amount = $planDetail ['plan_amount'];
		$plan_storage = $planDetail ['storage'] * 100000;
		Mlog::addone ( $cm . __LINE__ . '::$disk_usage-->', $disk_usage );
		Mlog::addone ( $cm . __LINE__ . '::$planDetail->', $planDetail );
		if ($disk_usage > $plan_storage) {
			return array (
					'status' => 'Failure',
					'message' => 'In order to downgrade your plan you must be within the required usage limits. Please remove media as needed before downgrading your plan' 
			);
		}
		
		Mlog::addone ( $cm . __LINE__ . '', '' );
		/*
		 * -
		 * Check for card if plan is > 0
		 */
		Mlog::addone ( $cm . __LINE__ . '::$amount--->', $amount );
		if ($amount > 0) {
			$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodByStripeReferenceId ( $card );
			Mlog::addone ( $cm . __LINE__ . '', '' );
			if (empty ( $paymentMethod )) {
				return array (
						'status' => 'Failure',
						'message' => 'Invalid card' 
				);
			}
		}
		Mlog::addone ( $cm . __LINE__ . '', '' );
		
		/*
		 * -
		 * Setup Stripe parameters
		 */
		$stripe_customer_id = $account->stripe_customer_id;
		$stripe_subscription_id = $subscription->stripe_subscription_id;
		$plan_id = $data ['plan'];
		$transactionParams = array (
				'stripe_customer_id' => $stripe_customer_id,
				'stripe_subscription_id' => $stripe_subscription_id,
				'plan_detail' => $planDetail 
		);
		
		/*
		 * -
		 * Create a transaction for tracking
		 */
		$transaction = new Memreas_Transaction ();
		$transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'update_subscription',
				'pass_fail' => '0',
				'transaction_status' => 'update_subscription_fail',
				'amount' => $amount,
				'transaction_request' => json_encode ( $transactionParams ),
				'transaction_sent' => MNow::now () 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		/*
		 * -
		 * Call Stripe and update subscription
		 */
		$updateSubscription = $this->stripeClient->updateSubscription ( $stripe_customer_id, $stripe_subscription_id, $plan_id );
		$stripe_subscription_id = $updateSubscription ['id'];
		
		if (! empty ( $updateSubscription ['id'] )) {
			/*
			 * -
			 * Update transaction on response
			 */
			// update transaction after response
			$transaction->exchangeArray ( array (
					'transaction_id' => $transaction_id,
					'pass_fail' => 1,
					'transaction_status' => 'update_subscription_success',
					'transaction_response' => json_encode ( $updateSubscription ),
					'transaction_receive' => MNow::now () 
			) );
			$this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			/*
			 * -
			 * De-activate existing subscription
			 */
			$this->memreasStripeTables->getSubscriptionTable ()->deactivateSubscription ( $subscription->subscription_id );
			
			/*
			 * -
			 * create a new subscription entry and set as active
			 */
			$memreasSubscription = new Subscription ();
			$memreasSubscription->exchangeArray ( array (
					'account_id' => $account->account_id,
					'stripe_subscription_id' => $stripe_subscription_id,
					'currency_code' => 'USD',
					'plan' => $planDetail ['plan_id'],
					'plan_amount' => $planDetail ['plan_amount'],
					'plan_description' => $planDetail ['plan_name'],
					'gb_storage_amount' => $planDetail ['storage'],
					'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY,
					'start_date' => MNow::now (),
					'active' => '1',
					'create_date' => MNow::now (),
					'update_time' => MNow::now () 
			) );
			$this->memreasStripeTables->getSubscriptionTable ()->saveSubscription ( $memreasSubscription );
			
			/*
			 * -
			 * Send email confirmation
			 */
			$viewModel = new ViewModel ( array (
					'username' => $accountDetail->first_name . ' ' . $accountDetail->last_name,
					'plan_name' => $planDetail ['plan_name'],
					'transaction_id' => $transaction_id 
			) );
			Mlog::addone ( $cm, __LINE__ );
			$viewModel->setTemplate ( 'email/subscription' );
			$viewRender = $this->service_locator->get ( 'ViewRenderer' );
			
			$html = $viewRender->render ( $viewModel );
			$subject = 'Your subscription plan has been activated';
			if (! isset ( $this->aws ) || empty ( $this->aws )) {
				$this->aws = new AWSManagerSender ( $this->service_locator );
			}
			try {
				Mlog::addone ( $cm, __LINE__ );
				$user = $this->memreasStripeTables->getUserTable ()->getUser ( $userid );
				$this->aws->sendSeSMail ( array (
						$user->email_address 
				), $subject, $html );
			} catch ( SesException $e ) {
				Mlog::addone ( $cm . __LINE__, $e->getMessage () );
			}
			
			return array (
					'status' => 'Success' 
			);
		} else {
			Mlog::addone ( $cm, __LINE__ );
			return array (
					'status' => 'Failure',
					'message' => 'update subscription failed' 
			);
		}
	}
	public function cancelSubscription($subscriptionId, $customerId) {
		$data = [ ];
		$data ['subscriptionId'] = $subscriptionId;
		$data ['customerId'] = $customerId;
		$this->stripeCustomer->cancelSubscription ( $data );
	}
	public function listMassPayee($data) {
		$username = $data ['payeelist'];
		$page = isset ( $data ['page'] ) ? $data ['page'] : 1;
		$limit = isset ( $data ['limit'] ) ? $data ['limit'] : 100;
		
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm, __LINE__ );
		$massPayees = $this->memreasStripeTables->getAccountTable ()->listMassPayee ( $username, $page, $limit );
		$countRow = count ( $massPayees );
		if (! $countRow) {
			return array (
					'status' => 'Failure',
					'message' => 'No record found' 
			);
		}
		
		//
		// Loop through each payee
		//
		$massPayeesArray = array ();
		foreach ( $massPayees as $massPayee ) {
			// Get Transactions - query has interval now - 30
			$transactions = $this->memreasStripeTables->getTransactionTable ()->getPayeeTransactionByAccountId ( $massPayee->account_id, MemreasConstants::LIST_MASS_PAYEE_INTERVAL );
			$transactions_array = array ();
			$clearedBalanceAmount = 0;
			$report_flags = '';
			$clearedTransactionIds = [ ];
			$failedTransactionIds = [ ];
			//
			// Loop through each transaction for the payee
			//
			foreach ( $transactions as $transaction ) {
				Mlog::addone ( $cm . __LINE__, '***********************************************************' );
				Mlog::addone ( $cm . __LINE__ . '::Top of For Loop -> $clearedBalanceAmount', $clearedBalanceAmount );
				//
				// Transaction data must be > 30 days old.
				//
				$now = time ();
				$transaction_date = strtotime ( $transaction->transaction_sent );
				$datediff = $now - $transaction_date;
				$days_passed = floor ( $datediff / (60 * 60 * 24) );
				$investigate = false;
				Mlog::addone ( $cm . __LINE__ . '::MNow::now()->', MNow::now () );
				Mlog::addone ( $cm . __LINE__ . '::$transaction_date->', $transaction_date );
				Mlog::addone ( $cm . __LINE__ . '::$days_passed->', $days_passed );
				if ($days_passed < 30) {
					// skip this transaction it has not passed the 30 day mark
					Mlog::addone ( $cm . __LINE__ . '::skipping $transaction->transaction_id', $transaction->transaction_id );
					continue;
				}
				
				//
				// Get event ID - retrieve from transaction_request as meta
				//
				$transaction_request_meta_array = json_decode ( $transaction->transaction_request, true );
				$buyer_transaction_id = (! empty ( $transaction_request_meta_array ['transaction_id'] )) ? $transaction_request_meta_array ['transaction_id'] : '';
				$AccountPurchase = '';
				if ($buyer_transaction_id) {
					// get event id
					$AccountPurchase = $this->memreasStripeTables->getAccountPurchasesTable ()->getPurchaseByTransactionId ( $buyer_transaction_id );
					Mlog::addone ( $cm . __LINE__ . '::$buyer_transaction_id', $buyer_transaction_id );
					Mlog::addone ( $cm . __LINE__ . '::$AccountPurchase->transaction_id', $AccountPurchase->transaction_id );
					Mlog::addone ( $cm . __LINE__ . '::$AccountPurchase->event_id', $AccountPurchase->event_id );
					Mlog::addone ( $cm . __LINE__ . '::$AccountPurchase->amount', $AccountPurchase->amount );
					Mlog::addone ( $cm . __LINE__ . '::$transaction->amount', $transaction->amount );
					
					$event_id = '';
					if (! empty ( $AccountPurchase->event_id )) {
						$event_id = $AccountPurchase->event_id;
						//
						// Check the media for the event
						// - fetch list of media and check report flag
						//
						$q_event_media = "select em.event_id, m.media_id, m.report_flag
							from 	Application\Entity\Media m,
									Application\Entity\EventMedia em
							where em.media_id = m.media_id
							and em.event_id = '$event_id'";
						$statement = $this->dbAdapter->createQuery ( $q_event_media );
						$event_media_array = $statement->getArrayResult ();
						foreach ( $event_media_array as $event_media ) {
							if ($event_media ['report_flag'] != 0) {
								// there is a problem and shoudld be checked
								$report_flags .= $event_media ['report_flag'] . ',';
								// media was reported
								Mlog::addone ( $cm . __LINE__ . '::media was reported -> $investigate', $investigate );
								$investigate = true;
							}
						}
					} else {
						// wrong transaction type likely
						Mlog::addone ( $cm . __LINE__ . '::wrong transaction type likely -> $investigate', $investigate );
						$investigate = true;
					}
				} else {
					// couldn't find corresponding account purchase
					Mlog::addone ( $cm . __LINE__ . '::couldnt find corresponding account purchase -> $investigate', $investigate );
					$investigate = true;
				}
				$transactions_array [] = array (
						'id' => $transaction->transaction_id,
						'amount' => $transaction->amount,
						'type' => $transaction->transaction_type,
						'date' => $transaction->transaction_sent,
						'investigate' => $investigate,
						'event_id' => $event_id 
				);
				if (! $investigate) {
					$clearedTransactionIds [] = $transaction->transaction_id;
					// Add to $clearedBalanceAmount
					$clearedBalanceAmount = ( float ) ($clearedBalanceAmount + $transaction->amount);
					Mlog::addone ( $cm . __LINE__ . '::$transaction->amount', $transaction->amount );
					Mlog::addone ( $cm . __LINE__ . '::$clearedBalanceAmount', $clearedBalanceAmount );
				} else {
					$failedTransactionIds [] = $transaction->transaction_id;
					Mlog::addone ( $cm . __LINE__ . '::FAIL $transaction->amount', $transaction->amount );
				}
				Mlog::addone ( $cm . __LINE__, '***********************************************************' );
			} // end transaction for loop
			  
			//
			  // If not report flags then payout is cleared to execute through admin
			  //
			if (empty ( $report_flags )) {
				$report_flags = '0';
			}
			
			Mlog::addone ( $cm . __LINE__ . '::$clearedBalanceAmount--->', ( float ) $clearedBalanceAmount );
			
			$massPayeesArray [] = array (
					'account_id' => $massPayee->account_id,
					'user_id' => $massPayee->user_id,
					'username' => $massPayee->username,
					'account_type' => $massPayee->account_type,
					'balance' => $massPayee->balance,
					'clearedBalanceAmount' => $clearedBalanceAmount,
					'report_flags' => $report_flags,
					'investigate' => $investigate,
					'transactions' => $transactions_array 
			);
			Mlog::addone ( $cm, __LINE__ );
		} // end mass_payee for loop
		
		return array (
				'status' => 'Success',
				'Numrows' => $countRow,
				'accounts' => $massPayeesArray 
		);
	}
	public function MakePayout($data) {
		$cm = __CLASS__ . __METHOD__;
		$payees = $data ['payees'];
		Mlog::addone ( $cm . __LINE__ . '::$payees--->', $payees );
		
		/*
		 * -
		 * Fetch memreas_float, memreas_payer, memreas_fees
		 */
		$account_memreas_float = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
		$account_memreas_payer = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_PAYER );
		$account_memreas_master = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_MASTER );
		$account_memreas_fees = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FEES );
		
		/*
		 * -
		 * Now start loop for payouts
		 */
		foreach ( $payees as $payee ) {
			
			/*
			 * -
			 * seller as payee
			 */
			$account_payee = $this->memreasStripeTables->getAccountTable ()->getAccount ( $payee ['account_id'], 'seller' );
			
			if (! $account_payee) {
				
				return array (
						'status' => 'Failure',
						'message' => 'Account does not exist' 
				);
			}
			
			//
			// Check if stripe customer / recipient is set
			//
			
			if (empty ( $account_payee->stripe_customer_id )) {
				Mlog::addone ( $cm, __LINE__ );
				return array (
						'status' => 'Failure',
						'message' => 'Stripe customer id related to this account is missing' 
				);
			}
			
			//
			// Check if stripeaccount_id is set
			//
			
			if (empty ( $account_payee->stripe_account_id )) {
				Mlog::addone ( $cm, __LINE__ );
				return array (
						'status' => 'Failure',
						'message' => 'Stripe account id related to this account is missing' 
				);
			}
			
			//
			// Check if account has available balance
			//
			
			if ($account_payee->balance < $payee ['amount']) {
				Mlog::addone ( $cm, __LINE__ );
				return array (
						'status' => 'Failure',
						'message' => 'Account balance has insufficient funds for the amount you requested' 
				);
			}
			
			// ******************************
			// Payee Section - start payout
			// ******************************
			
			//
			// Payee payout - amount logged has transfaction fee factored in
			//
			
			// $payee_amount = (($payee ['amount'] * (1 - MemreasConstants::MEMREAS_PROCESSING_FEE)) * 100);
			$payee_amount = $payee ['amount']; // don't convert for memreas storage
			
			/*
			 * -
			 * **********************************************************************
			 * DEBUGGING - TO BE REMOVED $memreas_payer_amount - temporary until transaction level implemented
			 * **********************************************************************
			 */
			$stripe_payee_amount = $payee ['amount'] * 100; // convert to cents for Stripe
			$stripe_memreas_payer_amount = ($stripe_payee_amount / 4);
			$memreas_payer_amount = ($payee ['amount'] / 4); // 20% of total is payee_amount/4 = 80% payment to seller
			                                                 
			//
			                                                 // Stripe transfer parameters
			                                                 //
			$transferParams = array (
					'amount' => $stripe_payee_amount, // stripe stores in cents
					'currency' => 'USD',
					'destination' => $account_payee->stripe_account_id,
					'description' => $payee ['description'] 
			);
			
			//
			// Create transaction to log transfer
			//
			$transaction = new Memreas_Transaction ();
			$transaction->exchangeArray ( array (
					'account_id' => $account_payee->account_id,
					'transaction_type' => 'payout_seller',
					'transaction_status' => 'payout_seller_fail',
					'pass_fail' => 0,
					'amount' => $payee_amount,
					'currency' => "USD",
					'transaction_request' => json_encode ( $transferParams ),
					'transaction_sent' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			//
			// Make call to stripe for seller payee transfer
			//
			try {
				
				$transferResponse = $this->stripeClient->createTransfer ( $transferParams );
			} catch ( ZfrStripe\Exception\BadRequestException $e ) {
				return array (
						'status' => 'Failure',
						'message' => $e->getMessage () 
				);
			}
			$payee_transfer_id = $transferResponse ['id'];
			Mlog::addone ( $cm . __LINE__ . '::$payee_transfer_id--->', $payee_transfer_id );
			
			//
			// Update transaction for response
			//
			
			$transaction->exchangeArray ( array (
					'transaction_status' => 'payout_seller_success',
					'pass_fail' => 1,
					'transaction_response' => json_encode ( $transferResponse ),
					'transaction_receive' => MNow::now () 
			) );
			$payee_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			//
			// Debit seller balance for payout
			//
			$starting_balance = $account_payee->balance;
			$ending_balance = $starting_balance - $payee_amount;
			$payee_account_balances = new AccountBalances ();
			$payee_account_balances->exchangeArray ( array (
					'account_id' => $account_payee->account_id,
					'transaction_id' => $payee_transaction_id,
					'transaction_type' => "payout_seller",
					'starting_balance' => $starting_balance,
					'amount' => $payee_amount,
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$account_balances_payee_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $payee_account_balances );
			
			//
			// Update the account table with new balance
			//
			$account_payee->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_payee_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_payee );
			
			/*
			 * -
			 * log results for return array
			 */
			$payouts [$account_payee->username] ['account_id'] = $account_payee->account_id;
			$payouts [$account_payee->username] ['transaction_id'] = $payee_transaction_id;
			$payouts [$account_payee->username] ['stripe_transfer_id'] = $payee_transfer_id;
			$payouts [$account_payee->username] ['starting_balance'] = $starting_balance;
			$payouts [$account_payee->username] ['amount'] = $payee_amount;
			$payouts [$account_payee->username] ['ending_balance'] = $ending_balance;
			$payouts [$account_payee->username] ['transaction_date'] = MNow::now ();
			
			// ***********************************************
			// memreas section - start payout and accounting
			// ***********************************************
			
			/*
			 * -
			 * memreas_master payout
			 */
			$memreas_payer_amount = ($payee ['amount'] / 4); // 20% of total is payee_amount/4 = 80% payment to seller
			$stripe_payer_amount = $memreas_payer_amount * 100; // convert to cents for Stripe
			$transferParams = array (
					'amount' => $stripe_payer_amount, // stripe stores in cents
					'currency' => 'USD',
					'destination' => $account_memreas_master->stripe_account_id,
					'description' => "memreas_master payout for seller account_id: " . $account_payee->account_id 
			);
			
			// Update transaction
			$transaction = new Memreas_Transaction ();
			$transaction->exchangeArray ( array (
					'account_id' => $account_memreas_master->account_id,
					'transaction_type' => 'payout_memreas_master',
					'transaction_status' => 'payout_memreas_master_fail',
					'transaction_request' => json_encode ( $transferParams ),
					'pass_fail' => 0,
					'amount' => $memreas_payer_amount,
					'currency' => $currency,
					'transaction_sent' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			try {
				$transferResponse = $this->stripeClient->createTransfer ( $transferParams );
			} catch ( Exception $e ) {
				return array (
						'status' => 'Failure',
						'message' => $e->getMessage () 
				);
			}
			$memreas_master_transfer_id = $transferResponse ['id'];
			Mlog::addone ( $cm . __LINE__ . '::$memreas_master_transfer_id--->', $memreas_master_transfer_id );
			
			// Update transaction
			$transaction->exchangeArray ( array (
					'transaction_status' => 'payout_memreas_master_success',
					'pass_fail' => 1,
					'transaction_response' => json_encode ( $transferResponse ),
					'transaction_receive' => MNow::now () 
			) );
			$memreas_master_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			//
			// Debit memreas_payer for payout
			//
			$starting_balance = $account_memreas_payer->balance;
			$ending_balance = $starting_balance - $memreas_payer_amount;
			$account_memreas_payer_balances = new AccountBalances ();
			$account_memreas_payer_balances->exchangeArray ( array (
					'account_id' => $account_memreas_payer->account_id,
					'transaction_id' => $memreas_master_transaction_id,
					'transaction_type' => "payout_seller",
					'starting_balance' => $starting_balance,
					'amount' => - $memreas_payer_amount,
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$account_balances_memreas_payer_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $account_memreas_payer_balances );
			
			//
			// Update the account table with new balance
			//
			$account_memreas_payer->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_memreas_payer_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_payer );
			
			//
			// Debit memreas_float for payout
			//
			$starting_balance = $account_memreas_float->balance;
			$ending_balance = $starting_balance - $memreas_payer_amount;
			$account_memreas_float_balances = new AccountBalances ();
			$account_memreas_float_balances->exchangeArray ( array (
					'account_id' => $account_memreas_float->account_id,
					'transaction_id' => $memreas_master_transaction_id,
					'transaction_type' => "payout_seller",
					'starting_balance' => $starting_balance,
					'amount' => - $memreas_payer_amount,
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$account_balances_memreas_float_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $account_memreas_float_balances );
			
			//
			// Update the account table with new balance
			//
			$account_memreas_float->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_memreas_float_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_float );
			
			//
			// Credit memreas_master for payout
			//
			$starting_balance = $account_memreas_master->balance;
			$ending_balance = $starting_balance + $memreas_payer_amount;
			$account_memreas_master_balances = new AccountBalances ();
			$account_memreas_master_balances->exchangeArray ( array (
					'account_id' => $account_memreas_master->account_id,
					'transaction_id' => $memreas_master_transaction_id,
					'transaction_type' => "payout_memreas_master",
					'starting_balance' => $starting_balance,
					'amount' => $memreas_payer_amount,
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$account_balances_memreas_float_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $account_memreas_master_balances );
			
			//
			// Update the account table with new balance
			//
			$account_memreas_master->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_memreas_float_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_master );
			
			/*
			 * -
			 * Call Stripe for payee transfer fees
			 */
			$stripeBalanceTransactionParams = array (
					'id' => $payee_transfer_id 
			);
			
			/**
			 * -
			 * Store balance transaction fee request prior to sending to stripe
			 */
			
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $account_memreas_fees->account_id,
					'transaction_type' => 'payout_seller_transfer_fees',
					'pass_fail' => 0,
					'ref_transaction_id' => $payee_transaction_id,
					'transaction_status' => 'payout_seller_transfer_fees_fail',
					'transaction_request' => json_encode ( $stripeBalanceTransactionParams ),
					'transaction_sent' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// Make call to Stripe to fetch fees
			//
			$balance_transaction = $this->stripeClient->getBalanceTransaction ( $stripeBalanceTransactionParams );
			$fees = $balance_transaction ['fee'] / 100; // stripe stores in cents so convert to $'s
			
			/**
			 * -
			 * Store balance transaction fee response from stripe
			 */
			$memreas_transaction->exchangeArray ( array (
					'transaction_id' => $transaction_id,
					'amount' => "-$fees",
					'currency' => 'USD',
					'pass_fail' => 1,
					'transaction_status' => 'payout_memreas_master_fees_success',
					'transaction_response' => json_encode ( $balance_transaction ),
					'transaction_receive' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			/**
			 * -
			 * apply fee to fees account
			 */
			$starting_balance = (isset ( $account_memreas_fees )) ? $account_memreas_fees->balance : '0.00';
			$ending_balance = $starting_balance - $fees;
			
			// Insert the new account balance
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $account_memreas_fees->account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "payout_seller_transfer_fees",
					'starting_balance' => $starting_balance,
					'amount' => "-$fees",
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			// Update the account table with new balance
			$account_memreas_fees->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_fees );
			
			/*
			 * -
			 * Call Stripe for memreas_master transfer fees
			 */
			$stripeBalanceTransactionParams = array (
					'id' => $memreas_master_transfer_id 
			);
			
			/*
			 * -
			 * Store balance transaction fee request prior to sending to stripe
			 */
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $account_memreas_fees->account_id,
					'transaction_type' => 'payout_memreas_master_fees',
					'pass_fail' => 0,
					'ref_transaction_id' => $memreas_master_transaction_id,
					'transaction_status' => 'payout_memreas_master_fees_fail',
					'transaction_request' => json_encode ( $stripeBalanceTransactionParams ),
					'transaction_sent' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// Make call to Stripe
			//
			$balance_transaction = $this->stripeClient->getBalanceTransaction ( $stripeBalanceTransactionParams );
			$fees = $balance_transaction ['fee'] / 100; // stripe stores in cents
			                                            
			//
			                                            // Store balance transaction fee response from stripe
			                                            //
			$memreas_transaction->exchangeArray ( array (
					'transaction_id' => $transaction_id,
					'amount' => "-$fees",
					'currency' => 'USD',
					'pass_fail' => 1,
					'transaction_status' => 'payout_memreas_master_fees_success',
					'transaction_response' => json_encode ( $balance_transaction ),
					'transaction_receive' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			//
			// apply fee to fees account
			//
			$starting_balance = $account_memreas_fees->balance;
			$ending_balance = $starting_balance - $fees;
			
			//
			// Insert the new account balance
			//
			$endingAccountBalance = new AccountBalances ();
			Mlog::addone ( $cm, __LINE__ );
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $account_memreas_fees->account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "payout_memreas_master_fees",
					'starting_balance' => $starting_balance,
					'amount' => "-$fees",
					'ending_balance' => $ending_balance,
					'create_time' => MNow::now () 
			) );
			$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			//
			// Update the account table with new balance
			//
			$account_memreas_fees->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => MNow::now () 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_fees );
			
			Mlog::addone ( $cm . __LINE__ . '::payouts-->', $payouts );
		} // end foreach($payees as $payee)
		
		return array (
				'status' => 'Success',
				'message' => 'Amount has been transferred',
				'payouts' => $payouts 
		);
	}
	
	/*
	 * List card by user_id
	 */
	public function listCards($user_id) {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm, __LINE__ );
		
		// Check if exist account
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		if (empty ( $account )) {
			Mlog::addone ( "listCards", "empty account" );
			return array (
					'status' => 'Failure',
					'message' => 'please add your payment method' 
			);
		}
		
		// Check if account has payment method
		$paymentMethods = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodsByAccountId ( $account->account_id );
		if (empty ( $paymentMethods )) {
			Mlog::addone ( "listCards", "empty payment methods" );
			
			return array (
					'status' => 'Failure',
					'message' => 'No record found.' 
			);
		}
		
		// Fetching results
		$listPayments = array ();
		$index = 0;
		foreach ( $paymentMethods as $paymentMethod ) {
			// Mlog::addone ( "listCards for loop ", $paymentMethod, 'p' );
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $paymentMethod ['account_id'] );
			
			if (empty ( $accountDetail ))
				return array (
						'status' => 'Failure',
						'message' => 'Data corrupt with this account. Please try add new card first.' 
				);
				
				// Check if this card has exist at Stripe
			$stripeCard = $this->stripeClient->getCard ( $account->stripe_customer_id, $paymentMethod ['stripe_card_reference_id'] );
			// Mlog::addone ( "listCards for loop ", $paymentMethod, 'p' );
			if (empty ( $stripeCard ['id'] )) {
				// Mlog::addone ( "listCards for loop - stripe card does not exist ", $stripeCard ['message'] );
				$listPayments [$index] ['stripe_card'] = 'Failure';
				$listPayments [$index] ['stripe_card_response'] = $stripeCard ['message'];
			} else {
				// Mlog::addone ( "listCards for loop - stripe card does exist ", $stripeCard ['info'] );
				// $listPayments [$index] ['stripe_card'] = 'Success';
				// $listPayments [$index] ['stripe_card_response'] = $stripeCard ['info'];
				
				// Payment Method Details
				$listPayments [$index] ['payment_method_id'] = $paymentMethod ['payment_method_id'];
				$listPayments [$index] ['account_id'] = $paymentMethod ['account_id'];
				$listPayments [$index] ['user_id'] = $user_id;
				$listPayments [$index] ['stripe_card_reference_id'] = $paymentMethod ['stripe_card_reference_id'];
				$listPayments [$index] ['card_type'] = $paymentMethod ['card_type'];
				$listPayments [$index] ['obfuscated_card_number'] = $paymentMethod ['obfuscated_card_number'];
				$listPayments [$index] ['exp_month'] = $paymentMethod ['exp_month'];
				$listPayments [$index] ['valid_until'] = $paymentMethod ['valid_until'];
				
				// Address Details
				$listPayments [$index] ['first_name'] = $accountDetail->first_name;
				$listPayments [$index] ['last_name'] = $accountDetail->last_name;
				$listPayments [$index] ['address_line_1'] = $accountDetail->address_line_1;
				$listPayments [$index] ['address_line_2'] = $accountDetail->address_line_2;
				$listPayments [$index] ['city'] = $accountDetail->city;
				$listPayments [$index] ['state'] = $accountDetail->state;
				$listPayments [$index] ['zip_code'] = $accountDetail->zip_code;
				Mlog::addone ( "listCards for loop bottom - stripe card does exist ", $listPayments [$index] );
				$index ++;
			}
		}
		
		return array (
				'status' => 'Success',
				'NumRows' => count ( $listPayments ),
				'payment_methods' => $listPayments 
		);
	}
	public function listCard($data) {
		Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
		if (empty ( $data ['user_id'] ))
			$user_id = $_SESSION ['user_id'];
		else
			$user_id = $data ['user_id'];
			
			// Check if exist account
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		if (empty ( $account ))
			return array (
					'status' => 'Failure',
					'message' => 'please add your payment method' 
			);
			
			// Check if account has payment method
		$paymentMethods = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodsByAccountId ( $account->account_id );
		if (empty ( $paymentMethods )) {
			return array (
					'status' => 'Failure',
					'message' => 'No record found.' 
			);
		}
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		
		if (empty ( $accountDetail )) {
			return array (
					'status' => 'Failure',
					'message' => 'Data corrupt with this account. Please try add new card first.' 
			);
		}
		
		// Check if this card exists at Stripe
		$stripeCard = $this->stripeClient->getCard ( $account->stripe_customer_id, $data ['card_id'] );
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$stripeCard-->', $stripeCard );
		if (! $stripeCard ['id']) {
			return array (
					'status' => 'Failure',
					'message' => 'failure retreiving card...' 
			);
		} else {
			return array (
					'status' => 'Success',
					'card' => $stripeCard 
			);
		}
	}
	public function saveCard($card_data) {
		if (empty ( $card_data ['user_id'] )) {
			$user_id = $_SESSION ['user_id'];
		} else {
			$user_id = $card_data ['user_id'];
		}
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		
		// Check if exist account
		if (empty ( $account ))
			return array (
					'status' => 'Failure',
					'message' => 'please add your payment method' 
			);
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		$card_data ['stripe_customer_id'] = $account->stripe_customer_id;
		
		$stripeCard = $this->stripeClient->updateCard ( $card_data );
		if (! $stripeCard ['id']) {
			return array (
					'status' => 'Failure',
					'message' => 'failure retreiving card...' 
			);
		} else {
			return array (
					'status' => 'Success',
					'card' => $stripeCard 
			);
		}
	}
	/*
	 * Delete cards
	 */
	public function DeleteCards($message_data) {
		$card_data = $message_data ['selectedCard'];
		if (empty ( $card_data )) {
			return array (
					'status' => 'Failure',
					'message' => 'No card input' 
			);
		}
		
		foreach ( $card_data as $stripe_card_reference_id ) {
			if (! empty ( $stripe_card_reference_id )) {
				$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodByStripeReferenceId ( $stripe_card_reference_id );
				$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $paymentMethod->account_id );
				$account = $this->memreasStripeTables->getAccountTable ()->getAccountById ( $paymentMethod->account_id );
				$stripeCustomerId = $account->stripe_customer_id;
				
				if ($paymentMethod) {
					// store request transaction
					$memreas_transaction = new Memreas_Transaction ();
					$transaction = array (
							'account_id' => $paymentMethod->account_id,
							'transaction_type' => 'delete_card',
							'pass_fail' => '0',
							'transaction_status' => 'delete_card_fail',
							'transaction_request' => json_encode ( array (
									'stripe_customer_id' => $stripeCustomerId,
									'stripe_card_reference_id' => $stripe_card_reference_id 
							) ),
							'transaction_sent' => MNow::now () 
					);
					$memreas_transaction->exchangeArray ( $transaction );
					$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
					
					// Call Stripe to delete card
					$deleteStripeCard = $this->stripeClient->deleteCard ( $stripeCustomerId, $stripe_card_reference_id );
					Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$stripeCustomerId-->', $stripeCustomerId );
					Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$stripe_card_reference_id-->', $stripe_card_reference_id );
					
					// store request transaction
					$transaction = array (
							'transaction_id' => $transaction_id,
							'pass_fail' => '1',
							'transaction_status' => 'delete_card_success',
							'transaction_response' => json_encode ( $deleteStripeCard ),
							'transaction_receive' => MNow::now () 
					);
					$memreas_transaction->exchangeArray ( $transaction );
					Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
					$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
					
					// update delete_flag
					Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
					$paymentMethod->exchangeArray ( array (
							'delete_flag' => 1,
							'update_time' => MNow::now () 
					) );
					Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
					$payment_method_id = $this->memreasStripeTables->getPaymentMethodTable ()->savePaymentMethod ( $paymentMethod );
					Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
					
					// stripe throws exception
					if (empty ( $deleteStripeCard ['id'] )) {
						Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
						return array (
								'status' => 'Failure',
								'message' => $deleteStripeCard ['message'] 
						);
					}
					Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
				} else {
					Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
					return array (
							'status' => 'Failure',
							'message' => 'Error while deleting card from DB.' 
					);
				}
			}
		}
		return array (
				'status' => 'Success',
				'message' => 'Cards have been deleted.' 
		);
	}
	
	/*
	 * Encode card number
	 */
	private function obfuscateAccountNumber($num) {
		$num = ( string ) preg_replace ( "/[^A-Za-z0-9]/", "", $num ); // Remove all non alphanumeric characters
		return str_pad ( substr ( $num, - 4 ), strlen ( $num ), "*", STR_PAD_LEFT );
	}
}

/*
 * Inherit Main Stripe Class
 * Implement stripe functions using Stripe PHP API here...
 */
class StripeClient {
	public function __construct() {
		\Stripe\Stripe::setApiKey ( ( string ) MemreasConstants::SECRET_KEY );
	}
	
	/**
	 * function deleteCard
	 * stripe arguments $data
	 */
	public function deleteCard($stripe_customer_id, $cardToken) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			$customer = \Stripe\Customer::retrieve ( $stripe_customer_id );
			$collection = $customer->sources->retrieve ( $cardToken )->delete ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function updateCard
	 * stripe arguments $data
	 */
	public function updateCard($card_data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$card_data--->', $card_data );
			
			$customer = \Stripe\Customer::retrieve ( $card_data ['stripe_customer_id'] );
			$card = $customer->sources->retrieve ( $card_data ['id'] );
			$card->name = $card_data ['name'];
			$card->exp_month = $card_data ['exp_month'];
			$card->exp_year = $card_data ['exp_year'];
			$card->address_line1 = $card_data ['address_line1'];
			$card->address_line2 = $card_data ['address_line2'];
			$card->address_city = $card_data ['address_city'];
			$card->address_state = $card_data ['address_state'];
			$card->address_zip = $card_data ['address_zip'];
			$card->metadata = array (
					"user_id" => $card_data ['user_id'] 
			);
			$collection = $card->save ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function getCard
	 * stripe arguments $data
	 */
	public function getCard($stripe_customer_id, $card_token) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			$customer = \Stripe\Customer::retrieve ( $stripe_customer_id );
			$collection = $customer->sources->retrieve ( $card_token );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createCard
	 * stripe arguments $data
	 */
	public function createCard($stripe_customer_id, $card_data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			$customer = \Stripe\Customer::retrieve ( $stripe_customer_id );
			$collection = $customer->sources->create ( $card_data );
			$result = $collection->__toArray ( true );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createCardToken
	 *
	 * stripe arguments $data
	 */
	public function createCardToken($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$collection = \Stripe\Token::create ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function captureCharge
	 *
	 * stripe arguments $data
	 */
	public function captureCharge($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$ch = \Stripe\Charge::retrieve ( $data ['id'] );
			$collection = $ch->capture ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createCharge
	 * stripe arguments $data
	 */
	public function createCharge($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$collection = \Stripe\Charge::create ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function getBalanceTransaction
	 * stripe arguments $data
	 * - returns fee from Stripe
	 */
	public function getBalanceTransaction($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$collection = \Stripe\BalanceTransaction::retrieve ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createTransfer
	 * stripe arguments $data
	 */
	public function createTransfer($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			// user master key to access account
			// \Stripe\Stripe::setApiKey ( $memreas_master_key );
			$collection = \Stripe\Transfer::create ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			
			// reset back to platform key
			\Stripe\Stripe::setApiKey ( MemreasConstants::SECRET_KEY );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createManagedAccount
	 * stripe arguments $data
	 * - this function is meant to create an account and bank account at Stripe for a seller
	 */
	public function createManagedAccount($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			
			// create account
			$managedAccount = [ ];
			$collection = \Stripe\Account::create ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::\Stripe\Account::create::$result--->', $result );
			
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function cancelSubscription
	 * stripe arguments $data
	 */
	public function cancelSubscription($stripe_customer_id, $subscription_id) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			die ();
			$cu = \Stripe\Customer::retrieve ( $stripe_customer_id );
			$collection = $cu->subscriptions->retreive ( $subscription_id )->cancel ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function updateSubscription
	 * stripe arguments $data
	 */
	public function updateSubscription($stripe_customer_id, $stripe_subscription_id, $plan_id) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			
			$customer = \Stripe\Customer::retrieve ( $stripe_customer_id );
			$subscription = $customer->subscriptions->retrieve ( $stripe_subscription_id );
			$subscription->plan = $plan_id;
			$collection = $subscription->save ();
			$result = $collection->__toArray ( true );
			
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createSubscription
	 * stripe arguments $data
	 */
	public function createSubscription($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$cu = \Stripe\Customer::retrieve ( $data ['stripe_customer_id'] );
			$collection = $cu->subscriptions->create ( array (
					"plan" => $data ['plan'] 
			) );
			$result = $collection->__toArray ( true );
			
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function deleteCustomer
	 * stripe arguments $data
	 */
	public function deleteCustomer($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$cu = \Stripe\Customer::retrieve ( $data );
			$collection = $cu->delete ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function updateCustomer
	 * stripe arguments $data
	 */
	public function updateCustomer($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			/**
			 * TODO - update per stripe ID
			 */
			$cu = \Stripe\Customer::retrieve ( $data ['id'] );
			if (isset ( $data ['email'] )) {
				$cu->email = $data ['email'];
			}
			if (isset ( $data ['description'] )) {
				$cu->description = $data ['description'];
			}
			if (isset ( $data ['metadata'] )) {
				$cu->metadata = $data ['metadata'];
			}
			if (isset ( $data ['source'] )) {
				$cu->source = $data ['source'];
			}
			$collection = $cu->save ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function getCustomers
	 * stripe arguments $data
	 */
	public function getCustomers($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$collection = \Stripe\Customer::all ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function getCustomer
	 * stripe arguments $data
	 */
	public function getCustomer($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$collection = \Stripe\Customer::retrieve ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createCustomer
	 * stripe arguments $data
	 */
	public function createCustomer($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::$data--->', $data );
			$collection = \Stripe\Customer::create ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function createPlan
	 * stripe arguments $data
	 */
	public function createPlan($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			Mlog::addone ( $cm . __LINE__ . '::Plan::create::$data--->', $data );
			$collection = \Stripe\Plan::create ( $data );
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::Plan::create::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function getPlans
	 * stripe arguments none
	 */
	public function getPlans() {
		try {
			$cm = __CLASS__ . __METHOD__;
			Mlog::addone ( $cm, __LINE__ );
			$collection = \Stripe\Plan::all ();
			$result = $collection->__toArray ( true );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function getPlan
	 * stripe arguments $data
	 */
	public function getPlan($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			$collection = \Stripe\Plan::retrieve ( $data );
			$result = $collection->__toArray ( true );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
	
	/**
	 * function deletePlan
	 * stripe arguments $data
	 */
	public function deletePlan($data) {
		try {
			$cm = __CLASS__ . __METHOD__;
			$plan = \Stripe\Plan::retrieve ( $data ['id'] );
			$collection = $plan->delete ();
			$result = $collection->__toArray ( true );
			Mlog::addone ( $cm . __LINE__ . '::$result--->', $result );
			return $result;
		} catch ( \Stripe\Error\Base $e ) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		} catch ( Exception $e ) {
			// Something else happened, completely unrelated to Stripe
			Mlog::addone ( $cm . __LINE__ . '::Error--->', $e->getMessage () );
			return null;
		}
	}
} //end StripeClient
