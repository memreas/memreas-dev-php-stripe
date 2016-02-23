<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Application\Entity\User;
use Application\memreas\AWSStripeManagerSender;
use Application\memreas\Mlog;
use Application\memreas\StripePlansConfig;
use Application\Model\Account;
use Application\Model\AccountBalances;
use Application\Model\AccountDetail;
use Application\Model\AccountPurchases;
use Application\Model\MemreasConstants;
use Application\Model\PaymentMethod;
use Application\Model\Subscription;
use Application\Model\Transaction as Memreas_Transaction;
use Aws\Ses\Exception\SesException;
use Guzzle\Http\Client;
use Zend\View\Model\ViewModel;
use Guzzle\Service\Exception\ValidationException;
use ZfrStripe;
use ZfrStripe\Client\StripeClient;
use ZfrStripe\Exception\BadRequestException;
use ZfrStripe\Exception\CardErrorException;
use ZfrStripe\Exception\NotFoundException;

class MemreasStripe extends StripeInstance {
	private $stripeClient;
	private $stripeInstance;
	public $memreasStripeTables;
	protected $clientSecret;
	protected $clientPublic;
	protected $user_id;
	protected $aws;
	protected $ses;
	protected $dbDoctrine;
	public $service_locator;
	public function __construct($service_locator) {
		try {
			$this->service_locator = $service_locator;
			$this->aws = new AWSStripeManagerSender ();			
			$this->retreiveStripeKey ();
			$this->stripeClient = new StripeClient ( $this->clientSecret, '2014-06-17' );
			$this->memreasStripeTables = new MemreasStripeTables ( $service_locator );
			$this->stripeInstance = parent::__construct ( $this->stripeClient, $this->memreasStripeTables );
				
			// Mlog::addone ( __CLASS__ . __METHOD__ . '__construct $_SESSION', $_SESSION );
			
			/**
			 * -
			 * Retrieve memreas user_id from session
			 */
			if (isset ( $_SESSION ['user_id'] )) {
		Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
					$this->user_id = $_SESSION ['user_id'];
			Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
			}
			Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
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
	private $stripeCustomer;
	private $stripeRecipient;
	private $stripeCard;
	private $stripePlan;
	protected $session;
	protected $memreasStripeTables;
	
	/*
	 * -
	 * Constructor
	 */
	public function __construct($stripeClient, $memreasStripeTables) {
		$this->stripeCustomer = new StripeCustomer ( $stripeClient );
		$this->stripeRecipient = new StripeRecipient ( $stripeClient );
		$this->stripeCard = new StripeCard ( $stripeClient );
		$this->stripePlan = new StripePlansConfig ( $stripeClient );
		$this->memreasStripeTables = $memreasStripeTables;
	}
	public function get($propertyName) {
		return $this->{$propertyName};
	}
	
	/*
	 * List stripe plan
	 */
	public function webHookReceiver() {
		$cm = __CLASS__ . __METHOD__;
		/**
		 * -
		 * Session is not required for webhooks
		 */
		\Stripe\Stripe::setApiKey ( MemreasConstants::SECRET_KEY );
		
		// Retrieve the request's body and parse it as JSON
		$input = @file_get_contents ( "php://input" );
		Mlog::addone ( $cm . __LINE__, 'webHookReceiver() received php://input' );
		$event_json = json_decode ( $input );
		Mlog::addone ( $cm . __LINE__ . '::$event_json::', $event_json );
		
		// Do something with $event_json
		http_response_code ( 200 ); // PHP 5.4 or greater
		Mlog::addone ( $cm . __LINE__, 'exit webHookReceiver()' );
		die ();
	}
	
	/*
	 * -
	 * get stripe customer data from stripe
	 */
	public function getCustomer($data, $stripe = false) {
		$account_found = false;
		$accounts = array ();
		
		//
		// Fetch Buyer Account
		//
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $data ['userid'] );
		if (! empty ( $account )) {
			$account_found = true;
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
			$accounts ['buyer_account'] ['customer'] = ($stripe) ? $this->stripeCustomer->getCustomer ( $accountDetail->stripe_customer_id ) : null;
			$accounts ['buyer_account'] ['accountHeader'] = $account;
			$accounts ['buyer_account'] ['accountDetail'] = $accountDetail;
		}
		
		//
		// Fetch Seller Account
		//
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $data ['userid'], 'seller' );
		if (! empty ( $account )) {
			$account_found = true;
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
			$accounts ['seller_account'] ['customer'] = ($stripe) ? $this->stripeCustomer->getCustomer ( $accountDetail->stripe_customer_id ) : null;
			$accounts ['seller_account'] ['accountHeader'] = $account;
			$accounts ['seller_account'] ['accountDetail'] = $accountDetail;
		}
		
		// Check if exist account
		if (! $account_found) {
			return array (
					'status' => 'Failure',
					'message' => 'Account not found' 
			);
		}
		
		// account exists
		$accounts ['status'] = 'Success';
		
		// return array (
		// 'status' => 'Success',
		// 'customer' => ($stripe) ? $this->stripeCustomer->getCustomer ( $accountDetail->stripe_customer_id ) : null,
		// 'account' => $account,
		// 'accountDetail' => $accountDetail
		// );
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
		
		$now = date ( 'Y-m-d H:i:s' );
		$account->reason = $data ['reason'];
		$transactionDetail = array (
				'account_id' => $account->account_id,
				'transaction_type' => 'refund_amount',
				'amount' => $data ['amount'],
				'currency' => 'USD',
				'transaction_request' => json_encode ( $account ),
				'transaction_response' => json_encode ( $account ),
				'transaction_sent' => $now,
				'transaction_receive' => $now 
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
				'create_time' => $now 
		) );
		$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $accountBalance );
		
		// Update account table
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $account->account_id );
		$account->exchangeArray ( array (
				'balance' => $endingAccountBalance,
				'update_time' => $now 
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
		$customer = $this->stripeCustomer->getCustomer ( $accountDetail->stripe_customer_id );
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
						'message' => 'You have no any payment method at this time. please try to add card first' 
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
				if (! empty ( $AccountBalance )) {
					$Balance = '<starting_balance>' . $AccountBalance->starting_balance . '</starting_balance>';
					$Balance .= '<amount>' . $AccountBalance->amount . '</amount>';
					$Balance .= '<ending_balance>' . $AccountBalance->ending_balance . '</ending_balance>';
				} else {
					$Balance = '<starting_balance></starting_balance>';
					$Balance .= '<amount></amount>';
					$Balance .= '<ending_balance></ending_balance>';
				}
				
				$orders [] = array (
						'username' => $user->username,
						'transaction' => $transaction,
						'balance' => $Balance 
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
		$userDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		return $userDetail;
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
					'message' => 'You have no any payment method at this time. please try to add card first' 
			);
	}
	
	/*
	 * Override customer's function
	 */
	public function addSeller($seller_data) {
		
		// Get memreas user name
		$user_name = $seller_data ['user_name'];
		$user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( $user_name );
		
		if (! $user)
			return array (
					'status' => 'Failure',
					'message' => 'No user related to this username' 
			);
		
		/**
		 * Get $stripe_email_address email address
		 */
		$stripe_email_address = $seller_data ['stripe_email_address'];
		
		// Fetch the Account
		$row = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id, 'seller' );
		if (! $row) {
			
			$bankData = array (
					'country' => 'US',
					'routing_number' => $seller_data ['bank_routing'],
					'account_number' => $seller_data ['account_number'] 
			);
			
			// Create Stripe Recipient data
			$recipientParams = array (
					'name' => $seller_data ['first_name'] . ' ' . $seller_data ['last_name'],
					'email' => $stripe_email_address,
					'description' => 1,
					'bank_account' => $bankData 
			);
			$this->stripeRecipient->setRecipientInfo ( $recipientParams );
			$recipientResponse = $this->stripeRecipient->createRecipient ();
			
			if (array_key_exists ( 'error', $recipientResponse ))
				return array (
						'status' => 'Failure',
						'message' => $recipientResponse ['message'] 
				);
				
				// Create an account entry
			$now = date ( 'Y-m-d H:i:s' );
			$account = new Account ();
			$account->exchangeArray ( array (
					'user_id' => $user->user_id,
					'username' => $user->username,
					'account_type' => 'seller',
					'balance' => 0,
					'create_time' => $now,
					'update_time' => $now 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		} else {
			// If user has an account with type is buyer, register a new seller account
			$account_id = $row->account_id;
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
				'stripe_customer_id' => $recipientResponse ['id'],
				'address_line_1' => $seller_data ['address_line_1'],
				'address_line_2' => $seller_data ['address_line_2'],
				'city' => $seller_data ['city'],
				'state' => $seller_data ['state'],
				'zip_code' => $seller_data ['zip_code'],
				'postal_code' => $seller_data ['zip_code'],
				'stripe_email_address' => $seller_data ['stripe_email_address'] 
		) );
		$account_detail_id = $this->memreasStripeTables->getAccountDetailTable ()->saveAccountDetail ( $accountDetail );
		
		// Store the transaction that is sent to Stripe
		$now = date ( 'Y-m-d H:i:s' );
		$transaction = new Memreas_Transaction ();
		$transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'add_seller',
				'transaction_request' => json_encode ( $recipientParams ),
				'transaction_request' => json_encode ( $recipientResponse ),
				'pass_fail' => 1,
				'transaction_status' => 'add_seller_passed',
				'transaction_sent' => $now,
				'transaction_receive' => $now 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		// Insert account balances as needed
		$account_balances = new AccountBalances ();
		$account_balances->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => 'add seller',
				'starting_balance' => 0,
				'amount' => 0,
				'ending_balance' => 0,
				'create_time' => $now 
		) );
		$account_balances_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $account_balances );
		
		// Return a success message:
		$result = array (
				"status" => "Success",
				"account_id" => $account_id,
				"account_detail_id" => $account_detail_id,
				"transaction_id" => $transaction_id,
				"account_balances_id" => $account_balances_id 
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
		
		// Mlog::addone ( 'addValueToAccount($data) - $accountDetail -->', $accountDetail );
		
		Mlog::addone ( $cm . __LINE__ . '::$data [stripe_card_reference_id]---->', $data [stripe_card_reference_id] );
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
			 * Send transaction to Stripe
			 */
			$cardToken = $paymentMethod->stripe_card_token;
			$cardId = $paymentMethod->stripe_card_reference_id;
			$customerId = $accountDetail->stripe_customer_id;
			$amount = $data ['amount'];
			$transactionAmount = ( int ) ($data ['amount'] * 100); // Stripe use minimum amount conver for transaction. Eg: input $5
			                                                       // convert request to stripe value is 500
			
			$stripeChargeParams = array (
					'amount' => $transactionAmount,
					'currency' => $currency,
					'customer' => $customerId,
					'card' => $cardId, // If this card param is null, Stripe will get primary customer card to charge
					'description' => 'Add value to account' 
			); // Set description more details later
			
			/**
			 * -
			 * Store transaction data prior to sending to Stripe
			 */
			$now = date ( 'Y-m-d H:i:s' );
			// Begin storing transaction to DB
			$transactionRequest = $stripeChargeParams;
			$transactionRequest ['account_id'] = $account->account_id;
			$transactionRequest ['stripe_details'] = array (
					'stripeCustomer' => $customerId,
					'stripeCardToken' => $cardToken,
					'stripeCardId' => $cardId 
			);
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $account->account_id,
					'transaction_type' => 'add_value_to_account',
					'transaction_request' => json_encode ( $transactionRequest ),
					'amount' => $data ['amount'],
					'currency' => $currency,
					'transaction_sent' => $now 
			) );
			$activeCreditToken = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			$chargeResult = $this->stripeCard->createCharge ( $stripeChargeParams );
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
				$now = date ( 'Y-m-d H:i:s' );
				$transactionDetail = array (
						'transaction_id' => $transaction_id,
						'account_id' => $account->account_id,
						'pass_fail' => 1,
						'transaction_response' => json_encode ( $chargeResult ),
						'transaction_receive' => $now,
						'transaction_status' => 'buy_credit_email' 
				);
				$memreas_transaction->exchangeArray ( $transactionDetail );
				$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
				
				/**
				 * -
				 * Store Account Balance
				 */
				$amount = $data ['amount'];
				$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $account->account_id );
				$startingAccountBalance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
				$endingAccountBalance = $startingAccountBalance + $amount;
				
				$accountBalance = new AccountBalances ();
				$accountBalance->exchangeArray ( array (
						'account_id' => $account->account_id,
						'transaction_id' => $transaction_id,
						'transaction_type' => "add_value_to_account",
						'starting_balance' => $startingAccountBalance,
						'amount' => $amount,
						'ending_balance' => $endingAccountBalance,
						'create_time' => $now 
				) );
				$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $accountBalance );
				
				/**
				 * -
				 * Update Account to reflect the latest balance
				 */
				// Update the account table
				$now = date ( 'Y-m-d H:i:s' );
				$account->exchangeArray ( array (
						'balance' => $endingAccountBalance,
						'update_time' => $now 
				) );
				$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
				
				/**
				 * -
				 * memreas_float section
				 * Store to memreas_float here (add transaction entries to deduct fee and store remainder as float)
				 * - store the transaction
				 * - store the account balance
				 * - update account
				 * - store the fee as a transaction
				 * - store the fees deducted account balance
				 * - update the account to reflect the fee
				 */
				$account_memreas_float = $this->memreasStripeTables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
				$memreas_transaction = new Memreas_Transaction ();
				// Get the last balance - Insert the new account balance
				$now = date ( 'Y-m-d H:i:s' );
				
				$memreas_transaction = new Memreas_Transaction ();
				$memreas_transaction->exchangeArray ( array (
						'account_id' => $account_memreas_float->account_id,
						'transaction_type' => "add_value_to_memreas_float_account",
						'starting_balance' => $starting_balance,
						'amount' => $amount,
						'currency' => $currency,
						'ending_balance' => $ending_balance,
						'pass_fail' => 1,
						'transaction_status' => "success",
						'transaction_request' => json_encode ( array (
								'correlated_transaction_id' => $transaction_id 
						) ),
						'transaction_response' => json_encode ( array (
								'correlated_transaction_id' => $transaction_id 
						) ),
						'transaction_sent' => $now,
						'transaction_receive' => $now 
				) );
				$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
				
				// If no account found set the starting balance to zero else use the ending balance.
				$starting_balance = (isset ( $account_memreas_float )) ? $account_memreas_float->ending_balance : '0.00';
				$ending_balance = $starting_balance + $amount;
				$memreasFloatAccountBalance = new AccountBalances ();
				$memreasFloatAccountBalance->exchangeArray ( array (
						'account_id' => $account_memreas_float->account_id,
						'transaction_id' => $transaction_id,
						'transaction_type' => "add_value_to_memreas_float_account",
						'starting_balance' => $startingAccountBalance,
						'amount' => $amount,
						'ending_balance' => $endingAccountBalance,
						'create_time' => $now 
				) );
				$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $memreasFloatAccountBalance );
				Mlog::addone ( $cm, __LINE__ );
				
				// Update the account table
				$now = date ( 'Y-m-d H:i:s' );
				$account_memreas_float->exchangeArray ( array (
						'balance' => $endingAccountBalance,
						'update_time' => $now 
				) );
				$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_float );
				Mlog::addone ( $cm, __LINE__ );
				
				/**
				 * -
				 * Deduct the fees from float
				 */
				$stripeBalanceTransactionParams = array (
						'id' => $chargeResult ['balance_transaction'] 
				);
				
				/**
				 * -
				 * Store balance transaction fee request prior to sending to stripe
				 */
				$now = date ( 'Y-m-d H:i:s' );
				$memreas_transaction = new Memreas_Transaction ();
				$memreas_transaction->exchangeArray ( array (
						'account_id' => $account_id,
						'transaction_type' => 'add_value_to_account_memreas_float_account_fees',
						'transaction_request' => json_encode ( $stripeBalanceTransactionParams ),
						'transaction_sent' => $now 
				) );
				$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
				Mlog::addone ( $cm, __LINE__ );
				
				/**
				 * -
				 * Make call to Stripe
				 */
				$balance_transaction = $this->stripeCard->getBalanceTransaction ( $stripeBalanceTransactionParams );
				Mlog::addone ( $cm . 'addValueToAccount()->$balance_transaction::', $balance_transaction );
				$fees = $balance_transaction ['fee'] / 100; // stripe stores in cents
				
				/**
				 * -
				 * Store balance transaction fee response from stripe
				 */
				
				$now = date ( 'Y-m-d H:i:s' );
				$memreas_transaction->exchangeArray ( array (
						'transaction_id' => $transaction_id,
						'amount' => "-$fees",
						'currency' => $currency,
						'pass_fail' => 1,
						'transaction_status' => 'success',
						'transaction_response' => json_encode ( $balance_transaction ),
						'transaction_receive' => $now 
				) );
				$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
				Mlog::addone ( $cm, __LINE__ );
				
				/**
				 * -
				 * Deduct fee from float account
				 */
				// Get the last balance
				// If no account found set the starting balance to zero else use the ending balance.
				$starting_balance = $ending_balance;
				$ending_balance = $starting_balance - $fees;
				
				// Insert the new account balance
				$now = date ( 'Y-m-d H:i:s' );
				$endingAccountBalance = new AccountBalances ();
				$endingAccountBalance->exchangeArray ( array (
						'account_id' => $account_memreas_float->account_id,
						'transaction_id' => $transaction_id,
						'transaction_type' => "add_value_to_memreas_float_account_fees",
						'starting_balance' => $starting_balance,
						'amount' => "-$fees",
						'ending_balance' => $ending_balance,
						'create_time' => $now 
				) );
				$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
				Mlog::addone ( $cm, __LINE__ );
				
				// Update the account table
				$now = date ( 'Y-m-d H:i:s' );
				$account_memreas_float->exchangeArray ( array (
						'balance' => $ending_balance,
						'update_time' => $now 
				) );
				$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account_memreas_float );
				Mlog::addone ( $cm, __LINE__ );
				
				/**
				 * -
				 * Send activation email
				 */
				$viewModel = new ViewModel ( array (
						'username' => $accountDetail->first_name . ' ' . $accountDetail->last_name,
						
						'active_link' => MemreasConstants::MEMREAS_WSPROXYPAY . 'stripe_activeCredit&token=' . $activeCreditToken,
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
				
				// Mlog::addone ( 'addValueToAccount($data) - $this->aws', $this->aws);
				
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
		$user = $this->memreasStripeTables->getUserTable ()->getUser ( $_SESSION ['user_id'] );
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
			 * Start debit amount from buyer
			 */
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id );
			if (! $account)
				return array (
						'status' => 'Failure',
						'message' => 'You have no account at this time. Please add card first.' 
				);
			$accountId = $account->account_id;
			
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $accountId );
			
			if (! isset ( $currentAccountBalance ) || ($currentAccountBalance->ending_balance <= 0) || $account->balance <= 0 || ($currentAccountBalance->ending_balance <= $amount) || $account->balance <= $amount) {
				return array (
						"status" => "Failure",
						"Description" => "Account not found or does not have sufficient funds." 
				);
			}
			
			$now = date ( 'Y-m-d H:i:s' );
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_type' => 'buy_media_purchase',
					'pass_fail' => 1,
					'amount' => "-$amount",
					'currency' => 'USD',
					'transaction_status' => 'success',
					'transaction_request' => "N/a",
					'transaction_sent' => $now,
					'transaction_response' => "N/a",
					'transaction_receive' => $now 
			) );
			$account_purchase_transaction_id = $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			// Starting decrement account balance
			$startingBalance = $currentAccountBalance->ending_balance;
			$endingBalance = $startingBalance - $amount;
			
			// Insert the new account balance for the buyer
			$now = date ( 'Y-m-d H:i:s' );
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase",
					'starting_balance' => $startingBalance,
					'amount' => "-$amount",
					'ending_balance' => $endingBalance,
					'create_time' => $now 
			) );
			$accountBalanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			// Update the account table
			$now = date ( 'Y-m-d H:i:s' );
			$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $accountId );
			$account->exchangeArray ( array (
					'balance' => $endingBalance,
					'update_time' => $now 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			// Update purchase table
			$AccountPurchase = new AccountPurchases ();
			$AccountPurchase->exchangeArray ( array (
					'account_id' => $account_id,
					'event_id' => $event_id,
					'amount' => $amount,
					'transaction_id' => $transaction_id,
					'transaction_type' => 'buy_media_purchase',
					'create_time' => $now,
					'start_date' => $duration_from,
					'end_date' => $duration_to 
			) );
			$this->memreasStripeTables->getAccountPurchasesTable ()->saveAccountPurchase ( $AccountPurchase );
			
			/**
			 * -
			 * Start credit process for the seller
			 */
			$seller_amount = $amount * (1 - MemreasConstants::MEMREAS_PROCESSING_FEE);
			$memreas_master_amount = $amount - $seller_amount;
			
			$seller = $this->memreasStripeTables->getUserTable ()->getUser ( $seller_id );
			$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $seller->user_id, 'seller' );
			if (! $account)
				return array (
						'status' => 'Failure',
						'message' => 'You have no account at this time. Please add card first.' 
				);
			
			$accountId = $account->account_id;
			
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $accountId );
			
			$now = date ( 'Y-m-d H:i:s' );
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_type' => 'buy_media_purchase',
					'pass_fail' => 1,
					'amount' => "+$seller_amount",
					'currency' => 'USD',
					'transaction_request' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_status' => 'success',
					'transaction_sent' => $now,
					'transaction_response' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_receive' => $now 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			// Starting decrement account balance
			$startingBalance = $currentAccountBalance->ending_balance;
			$endingBalance = $startingBalance + $amount;
			
			// Insert the new account balance
			$now = date ( 'Y-m-d H:i:s' );
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase",
					'starting_balance' => $startingBalance,
					'amount' => "+$seller_amount",
					'ending_balance' => $endingBalance,
					'create_time' => $now 
			) );
			$accountBalanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			// Update the account table
			$now = date ( 'Y-m-d H:i:s' );
			$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $accountId, 'seller' );
			$account->exchangeArray ( array (
					'balance' => $endingBalance,
					'update_time' => $now 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			/**
			 * -
			 * Start bookkeeping process for the memreas_float and memreas_master
			 */
			// ////////////////////////////////
			// Fetch the memreasmaster account
			// ////////////////////////////////
			$memreas_master_user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( MemreasConstants::ACCOUNT_MEMREAS_MASTER );
			$memreas_master_user_id = $memreas_master_user->user_id;
			
			$memreas_master_account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $memreas_master_user_id, 'seller' );
			if (! $memreas_master_account) {
				$connection->rollback ();
				$result = array (
						"Status" => "Error",
						"Description" => "Could not find memreas_master account" 
				);
				return $result;
			}
			$memreas_master_account_id = $memreas_master_account->account_id;
			
			// Increment the memreas_master account for the processing fee - see constants
			// Fetch Account_Balances
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $memreas_master_account_id );
			// Log the transaction
			$now = date ( 'Y-m-d H:i:s' );
			$memreas_transaction = new Memreas_Transaction ();
			
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $memreas_master_account_id,
					'transaction_type' => 'buy_media_purchase_memreas_master',
					'pass_fail' => 1,
					'amount' => "+$memreas_master_amount",
					'currency' => 'USD',
					'transaction_status' => 'success',
					'transaction_request' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_sent' => $now,
					'transaction_response' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_receive' => $now 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			// Increment the account
			// If no acount found set the starting balance to zero else use the ending balance.
			$starting_balance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
			$ending_balance = $starting_balance + $memreas_master_amount;
			
			// Insert the new account balance
			$now = date ( 'Y-m-d H:i:s' );
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $memreas_master_account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase_memreas_master",
					'starting_balance' => $starting_balance,
					'amount' => "+$memreas_master_amount",
					'ending_balance' => $ending_balance,
					'create_time' => $now 
			) );
			$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			// Update the account table
			$now = date ( 'Y-m-d H:i:s' );
			// $account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $memreas_master_account_id );
			$memreas_master_account->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => $now 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			// Add to the return message for debugging...
			$return_message [$memreas_master_user->username] ['description-->'] = "Amount added to account for user--> " . $memreas_master_user->username;
			$return_message [$memreas_master_user->username] ['starting_balance'] = $starting_balance;
			$return_message [$memreas_master_user->username] ['amount'] = $memreas_master_amount;
			$return_message [$memreas_master_user->username] ['ending_balance'] = $ending_balance;
			
			// ////////////////////////////////
			// Fetch the memreasfloat account
			// ////////////////////////////////
			$memreas_float_user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
			$memreas_float_user_id = $memreas_float_user->user_id;
			
			$memreas_float_account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $memreas_float_user_id, 'seller' );
			if (! $memreas_float_account) {
				$connection->rollback ();
				$result = array (
						"Status" => "Error",
						"Description" => "Could not find memreas_float account" 
				);
				return $result;
			}
			$memreas_float_account_id = $memreas_float_account->account_id;
			
			// Decrement the memreas_float account by 100% of the purchase
			// Fetch Account_Balances
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $memreas_float_account_id );
			// Log the transaction
			$now = date ( 'Y-m-d H:i:s' );
			$memreas_transaction = new Memreas_Transaction ();
			
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $memreas_float_account_id,
					'transaction_type' => 'buy_media_purchase_memreas_float',
					'pass_fail' => 1,
					'amount' => "-$amount",
					'currency' => 'USD',
					'transaction_status' => "success",
					'transaction_request' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_sent' => $now,
					'transaction_response' => json_encode ( array (
							"transaction_id" => $account_purchase_transaction_id 
					) ),
					'transaction_receive' => $now 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			
			// Decrement the account - account being decremented shouldn't be zero...
			// If no acount found set the starting balance to zero else use the ending balance.
			$starting_balance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
			$ending_balance = $starting_balance - $amount;
			
			// Insert the new account balance
			$now = date ( 'Y-m-d H:i:s' );
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $memreas_float_account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "buy_media_purchase_memreas_float",
					'starting_balance' => $starting_balance,
					'amount' => "-$amount",
					'ending_balance' => $ending_balance,
					'create_time' => $now 
			) );
			$transaction_id = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );
			
			// Update the account table
			$now = date ( 'Y-m-d H:i:s' );
			// $account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $memreas_float_account_id );
			$memreas_float_account->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => $now 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
			
			/**
			 * -
			 * Commit the transaction - chaching :)
			 */
			$connection->commit ();
			
			return array (
					'status' => 'Success',
					'message' => 'Buying media completed',
					'event_id' => $event_id 
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
		$event_id = $data ['event_id'];
		$user_id = $data ['user_id'];
		
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		if (! $account)
			return array (
					'status' => 'Failure',
					'event_id' => $event_id 
			);
		
		$checkBuyMedia = $this->memreasStripeTables->getAccountPurchasesTable ()->getAccountPurchase ( $account->account_id, $event_id );
		
		if (empty ( $checkBuyMedia ))
			return array (
					'status' => 'Failure',
					'event_id' => $event_id 
			);
		
		return array (
				'status' => 'Success',
				'event_id' => $event_id 
		);
	}
	public function activePendingBalanceToAccount($transaction_id) {
		$Transaction = $this->memreasStripeTables->getTransactionTable ()->getTransaction ( $transaction_id );
		
		if (empty ( $Transaction ))
			return array (
					'status' => 'Failure',
					'message' => 'No record found' 
			);
		
		if (strpos ( $Transaction->transaction_status, 'activated' ))
			return array (
					'status' => 'activated',
					'message' => 'These credits were applied in a prior activation.' 
			);
		
		$now = date ( 'Y-m-d H:i:s' );
		
		// Update Account Balance
		$currentAccountBalance = $this->memreasStripeTables->getAccountTable ()->getAccount ( $Transaction->account_id );
		$startingAccountBalance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->balance : '0.00';
		
		$endingAccountBalance = $startingAccountBalance + $Transaction->amount;
		
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $Transaction->account_id );
		$account->exchangeArray ( array (
				'balance' => $endingAccountBalance,
				'update_time' => $now 
		) );
		$accountId = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		
		$Transaction->transaction_status = 'buy_credit_email, buy_credit_verified, buy_credit_activated';
		$metadata = array (
				'IP' => $_SERVER ['REMOTE_ADDR'],
				'date' => $now,
				'url' => $_SERVER ['SERVER_NAME'] . $_SERVER ['REQUEST_URI'] 
		);
		$Transaction->metadata = json_encode ( $metadata );
		$this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $Transaction );
		
		return array (
				'status' => 'Success',
				'message' => 'Amount has been activated' 
		);
	}
	public function AccountHistory($data) {
		$now = date ( "Y-m-d H:i:s" );
		$userName = $data ['user_name'];
		$date_from = isset ( $data ['date_from'] ) ? $data ['date_from'] : '';
		$date_to = isset ( $data ['date_to'] ) ? $data ['date_to'] : $now;
		$user = $this->memreasStripeTables->getUserTable ()->getUserByUsername ( $userName );
		
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
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user->user_id );
		if (! $account) {
			return array (
					'status' => 'Failure',
					'message' => 'Account associate to this user does not exist' 
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
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$card_data', $card_data );
		if (isset ( $card_data ['user_id'] ))
			$user_id = $card_data ['user_id'];
		else
			$user_id = $_SESSION ['user_id'];
		
		$user = $this->memreasStripeTables->getUserTable ()->getUser ( $user_id );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		if (! $account) {
			
			// Create new stripe customer
			$userStripeParams = array (
					'email' => $user->email_address,
					'description' => 'Stripe account accociated with memreas user id : ' . $user->user_id 
			);
			
			$this->stripeCustomer->setCustomerInfo ( $userStripeParams );
			$stripeUser = $this->stripeCustomer->createCustomer ();
			
			$stripeCusId = $stripeUser ['response'] ['id'];
			
			// Create a new account if this account has no existed
			$now = date ( 'Y-m-d H:i:s' );
			$account = new Account ();
			$account->exchangeArray ( array (
					'user_id' => $user_id,
					'username' => $user->username,
					'account_type' => 'buyer',
					'balance' => 0,
					'create_time' => $now,
					'update_time' => $now 
			) );
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		} else {
			$account_id = $account->account_id;
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account_id );
			$stripeCusId = $accountDetail->stripe_customer_id;
		}
		
		// Update customer id in card
		$this->stripeCard->setCardAttribute ( 'customer', $stripeCusId );
		
		// Update account detail information
		$accountDetail = new AccountDetail ();
		$fullname = explode ( ' ', $card_data ['name'] );
		$firstName = $fullname [0];
		$lastName = $fullname [1];
		$accountDetail->exchangeArray ( array (
				'account_id' => $account_id,
				'stripe_customer_id' => $stripeCusId,
				'stripe_email_address' => $user->email_address,
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
		
		// Save customer card to Stripe
		$stripeCard = $this->stripeCard->storeCard ( $card_data );
		if (! $stripeCard ['card_added'])
			return array (
					'status' => 'Failure',
					'message' => $stripeCard ['message'] 
			);
		$stripeCard = $stripeCard ['response'];
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$stripeCard', $stripeCard );
		
		$now = date ( 'Y-m-d H:i:s' );
		$transaction = new Memreas_Transaction ();
		
		// Copy the card and obfuscate the card number before storing the transaction
		$obfuscated_card = json_decode ( json_encode ( $card_data ), true );
		$obfuscated_card ['number'] = $this->obfuscateAccountNumber ( $obfuscated_card ['number'] );
		
		$transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'store_credit_card',
				'transaction_request' => json_encode ( $obfuscated_card ),
				'transaction_sent' => $now 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		$transaction->exchangeArray ( array (
				'transaction_id' => $transaction_id,
				'pass_fail' => 1,
				'transaction_status' => 'store_credit_card_passed',
				'transaction_response' => json_encode ( $stripeCard ),
				'transaction_receive' => $now 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		// Add the new payment method
		$payment_method = new PaymentMethod ();
		$payment_method->exchangeArray ( array (
				'account_id' => $account_id,
				'account_detail_id' => $account_detail_id,
				'stripe_card_reference_id' => $stripeCard ['id'],
				'stripe_card_token' => $this->stripeCard->getCardAttribute ( 'card_token' ),
				'card_type' => $stripeCard ['brand'],
				'obfuscated_card_number' => $obfuscated_card ['number'],
				'exp_month' => $stripeCard ['exp_month'],
				'exp_year' => $stripeCard ['exp_year'],
				'valid_until' => $stripeCard ['exp_month'] . '/' . $stripeCard ['exp_year'],
				'create_time' => $now,
				'update_time' => $now 
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
	public function setSubscription($data) {
		$cm = __CLASS__ . __METHOD__;
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
		$checkExistStripePlan = $this->stripePlan->getPlan ( $data ['plan'] );
		if (empty ( $checkExistStripePlan ['plan'] )) {
			return array (
					'status' => 'Failure',
					'message' => 'Subscription plan was not found' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		Mlog::addone ( $cm . ':: $checkExistStripePlan -->', $checkExistStripePlan );
		$data ['amount'] = $checkExistStripePlan ['plan'] ['amount'] / 100; // stripe stores in cents
		
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $userid );
		if (! $account) {
			return array (
					'status' => 'Failure',
					'message' => 'You have no any payment method at this time. please try to add card first' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		$account_id = $account->account_id;
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account_id );
		Mlog::addone ( $cm, __LINE__ );
		
		if (! $accountDetail) {
			return array (
					'status' => 'Failure',
					'message' => 'Please update account detail first' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		$stripeCustomerId = $accountDetail->stripe_customer_id;
		
		// Create a charge for this subscription
		$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodByStripeReferenceId ( $card );
		if (empty ( $paymentMethod )) {
			return array (
					'status' => 'Failure',
					'message' => 'Invalid card' 
			);
		}
		Mlog::addone ( $cm, __LINE__ );
		
		// Check if user has activated subscription or not
		$stripeCustomerInfo = $this->stripeCustomer->getCustomer ( $stripeCustomerId );
		$upgrade = true;

		echo '<pre>'; print_r ($data); die();

		if ($stripeCustomerInfo ['info'] ['subscriptions'] ['total_count'] > 0) {
			$subscriptions = $stripeCustomerInfo ['info'] ['subscriptions'] ['data'];
			foreach ( $subscriptions as $subscription ) {
				
				// User has activated plan
				if ($subscription ['plan'] ['id'] == $data ['plan']) {
					return array (
							'status' => 'Failure',
							'message' => 'This plan is active currently.' 
					);
					Mlog::addone ( $cm, __LINE__ );
				}
			}
			
			$planLevel = $this->stripePlan->getPlanLevel ( $data ['plan'] );
			$customerPlanLevel = $this->stripePlan->getPlanLevel ( $subscriptions [0] ['plan'] ['id'] );
			
			// Checking for upgrade plan
			if ($planLevel > $customerPlanLevel) {
				$result = $this->stripeCustomer->cancelSubscription ( $subscriptions [0] ['id'], $stripeCustomerId );
				if ($result ['status'] == 'Failure') {
					Mlog::addone ( $cm, __LINE__ );
					return $result;
				}
			} else {
				
				Mlog::addone ( $cm, __LINE__ );
				// Downgrade plan
				$planDetail = $this->stripePlan->getPlanConfig ( $data ['plan'] );
				
				// Get user used detail
				$guzzle = new Client ();
				$xml = "<xml><getdiskusage><user_id>{$userid}</user_id></getdiskusage></xml>";
				Mlog::addone ( $cm, __LINE__ );
				$request = $guzzle->post ( MemreasConstants::MEMREAS_WS, null, array (
						'action' => 'getdiskusage',
						'xml' => $xml,
						'sid' => $_SESSION ['sid'] 
				) );
				Mlog::addone ( $cm, __LINE__ );
				
				$response = $request->send ();
				$data_usage = $response->getBody ( true );
				$data_usage = str_replace ( array (
						"\r",
						"\n" 
				), "", $data_usage );
				$data_usage = json_encode ( simplexml_load_string ( $data_usage ) );
				$plan = json_decode ( $data_usage );
				$plan = $plan->getdiskusageresponse;
				$dataUsage = str_replace ( " GB", "", $plan->total_used );
				Mlog::addone ( $cm, __LINE__ );
				if ($dataUsage > $planDetail ['storage']) {
					Mlog::addone ( $cm, __LINE__ );
					return array (
							'status' => 'Failure',
							'message' => 'In order to downgrade your plan you must be within the required usage limits. Please remove media as needed before downgrading your plan' 
					);
				} else {
					// Cancel current plan
					$result = $this->stripeCustomer->cancelSubscription ( $subscriptions [0] ['id'], $stripeCustomerId );
					Mlog::addone ( $cm, __LINE__ );
					if ($result ['status'] == 'Failure') {
						return $result;
					}
					$upgrade = false;
				}
			}
		}
		
		// Charge only if user upgrade or register as a new
		Mlog::addone ( $cm, __LINE__ );
		if ($upgrade) {
			Mlog::addone ( $cm, __LINE__ );
			// Begin going to charge on Stripe
			$cardId = $paymentMethod->stripe_card_reference_id;
			$customerId = $accountDetail->stripe_customer_id;
			$transactionAmount = ( int ) ($data ['amount'] * 100); // Stripe use minimum amount convert for transaction. Eg: input $5
			                                                       // convert request to stripe value is 500
			$stripeChargeParams = array (
					'amount' => $transactionAmount,
					'currency' => 'USD',
					'customer' => $customerId,
					'card' => $cardId, // If this card param is null, Stripe will get primary customer card to charge
					'description' => 'Charge for subscription : ' . $data ['plan'] 
			); // Set description more details later
			
			if ($transactionAmount > 0) {
				$chargeResult = $this->stripeCard->createCharge ( $stripeChargeParams );
			} else {
				$chargeResult = false;
			}
		} else {
			$chargeResult = true;
		}
		
		Mlog::addone ( $cm, __LINE__ );
		if ($chargeResult) {
			// Check if Charge is successful or not
			if (! $chargeResult ['paid'] && $upgrade) {
				return array (
						'status' => 'Failure',
						'message' => 'Transaction declined! Please check your Stripe account and cards' 
				);
			}
		}
		
		Mlog::addone ( $cm, __LINE__ );
		// Set stripe subscription
		$subscriptionParams = array (
				'plan' => $data ['plan'],
				'customer' => $stripeCustomerId 
		);
		
		Mlog::addone ( $cm, __LINE__ );
		// Set customer card for charging
		if (! empty ( $card )) {
			$this->stripeCustomer->setCustomerCardDefault ( $stripeCustomerId, $card );
		}
		$createSubscribe = $this->stripeCustomer->setSubscription ( $subscriptionParams );
		
		if ($createSubscribe ['status'] == 'Failure') {
			return $createSubscribe;
		}
		
		$createSubscribe = $createSubscribe ['result'];
		
		if (isset ( $createSubscribe ['id'] )) {
			Mlog::addone ( $cm, __LINE__ );
			$plan = $this->stripePlan->getPlan ( $data ['plan'] );
			$viewModel = new ViewModel ( array (
					'username' => $accountDetail->first_name . ' ' . $accountDetail->last_name,
					'plan_name' => $plan ['plan'] ['name'] 
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
			
			Mlog::addone ( $cm, __LINE__ );
			$now = date ( 'Y-m-d H:i:s' );
			// Save transaction table
			$transaction = new Memreas_Transaction ();
			
			$transaction->exchangeArray ( array (
					'account_id' => $account_id,
					'transaction_type' => 'buy_subscription',
					'amount' => $data ['amount'],
					'transaction_request' => json_encode ( $paymentMethod ),
					'transaction_sent' => $now 
			) );
			$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			Mlog::addone ( $cm, __LINE__ );
			$transaction->exchangeArray ( array (
					'transaction_id' => $transaction_id,
					'pass_fail' => 1,
					'transaction_response' => json_encode ( $createSubscribe ),
					'transaction_receive' => $now 
			) );
			$this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
			
			Mlog::addone ( $cm, __LINE__ );
			// Save subscription table
			$memreasSubscription = new Subscription ();
			$memreasSubscription->exchangeArray ( array (
					'account_id' => $account->account_id,
					'currency_code' => 'USD',
					'plan' => $data ['plan'],
					'plan_amount' => $data ['amount'],
					'plan_description' => $plan ['plan'] ['name'],
					'gb_storage_amount' => '',
					'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY,
					'start_date' => $now,
					'end_date' => null,
					'subscription_profile_id' => $createSubscribe ['id'],
					'subscription_profile_status' => 'Active',
					'create_date' => $now,
					'update_time' => $now 
			) );
			$this->memreasStripeTables->getSubscriptionTable ()->saveSubscription ( $memreasSubscription );
			
			Mlog::addone ( $cm, __LINE__ );
			// Update user plan table
			$user = $this->memreasStripeTables->getUserTable ()->getUser ( $userid );
			$metadata = json_decode ( $user->metadata, true );
			$planDetail = $this->stripePlan->getPlanConfig ( $data ['plan'] );
			$metadata ['subscription'] = array (
					'plan' => $data ['plan'],
					'name' => $planDetail ['plan_name'],
					'storage' => $planDetail ['storage'],
					'date_active' => $now,
					'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY 
			);
			$user->metadata = json_encode ( $metadata );
			$this->memreasStripeTables->getUserTable ()->saveUser ( $user );
			
			return array (
					'status' => 'Success' 
			);
		} else {
			Mlog::addone ( $cm, __LINE__ );
			return array (
					'status' => 'Failure',
					'message' => 'Subscription registering failed.' 
			);
		}
	}
	public function cancelSubscription($subscriptionId, $customerId) {
		$this->stripeCustomer->cancelSubscription ( $subscriptionId, $customerId );
	}
	public function listMassPayee($username = '', $page = 1, $limit = 1000) {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm, __LINE__ );
		$massPayees = $this->memreasStripeTables->getAccountTable ()->listMassPayee ( $username, $page, $limit );
		$countRow = count ( $massPayees );
		Mlog::addone ( $cm, $massPayees );
		
		if (! $countRow) {
			return array (
					'status' => 'Failure',
					'message' => 'No record found' 
			);
		}
		
		$massPayeesArray = array ();
		foreach ( $massPayees as $MassPee ) {
			$massPayeesArray [] = array (
					'account_id' => $MassPee->account_id,
					'user_id' => $MassPee->user_id,
					'username' => $MassPee->username,
					'account_type' => $MassPee->account_type,
					'balance' => $MassPee->balance 
			);
		}
		
		return array (
				'status' => 'Success',
				'Numrows' => $countRow,
				'accounts' => $massPayeesArray 
		);
	}
	public function MakePayout($data) {
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $data ['account_id'], 'seller' );
		
		if (! $account)
			return array (
					'status' => 'Failure',
					'message' => 'Account is not exist' 
			);
			
			// Check if account has available balance
		if ($account->balance < $data ['amount'])
			return array (
					'status' => 'Failure',
					'message' => 'Account balance is smaller than amount you requested' 
			);
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		
		// Check if stripe customer / recipient is set
		if (empty ( $accountDetail->stripe_customer_id ))
			return array (
					'status' => 'Failure',
					'message' => 'No stripe ID related to this account' 
			);
		
		$transferParams = array (
				'amount' => $data ['amount'],
				'currency' => 'USD',
				'recipient' => $accountDetail->stripe_customer_id,
				'description' => $data ['description'] 
		);
		
		try {
			$transferResponse = $this->stripeRecipient->makePayout ( $transferParams );
		} catch ( ZfrStripe\Exception\BadRequestException $e ) {
			return array (
					'status' => 'Failure',
					'message' => $e->getMessage () 
			);
		}
		
		$now = date ( 'Y-m-d H:i:s' );
		// Update transaction
		$transaction = new Memreas_Transaction ();
		
		$transaction->exchangeArray ( array (
				'account_id' => $account->account_id,
				'transaction_type' => 'make_payout_seller',
				'transaction_request' => '',
				'transaction_sent' => $now,
				'pass_fail' => 1,
				'transaction_response' => json_encode ( $transferResponse ),
				'transaction_receive' => $now 
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		// Update Account Balance
		$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances ( $account->account_id );
		$startingAccountBalance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
		$endingAccountBalance = $startingAccountBalance - $data ['amount'];
		$accountBalance = new AccountBalances ();
		$accountBalance->exchangeArray ( array (
				'account_id' => $account->account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "seller_payout",
				'starting_balance' => $startingAccountBalance,
				'amount' => $data ['amount'],
				'ending_balance' => $endingAccountBalance,
				'create_time' => $now 
		) );
		$balanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ( $accountBalance );
		
		// Update account table
		$account = $this->memreasStripeTables->getAccountTable ()->getAccount ( $account->account_id, 'seller' );
		$account->exchangeArray ( array (
				'balance' => $endingAccountBalance,
				'update_time' => $now 
		) );
		$accountId = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		
		return array (
				'status' => 'Success',
				'message' => 'Amount has been transferred' 
		);
	}
	
	/*
	 * List card by user_id
	 */
	public function listCards($user_id) {
		Mlog::addone ( __CLASS__ . __METHOD__, __LINE__ );
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		
		// Check if exist account
		if (empty ( $account )) {
			Mlog::addone ( "listCards", "empty account" );
			return array (
					'status' => 'Failure',
					'message' => 'You have no any payment method at this time. please try to add card first' 
			);
		}
		
		$paymentMethods = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodsByAccountId ( $account->account_id );
		
		// Check if account has payment method
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
			$stripeCard = $this->stripeCard->getCard ( $accountDetail->stripe_customer_id, $paymentMethod ['stripe_card_reference_id'] );
			if (! $stripeCard ['exist']) {
				// Mlog::addone ( "listCards for loop - stripe card does not exist ", $stripeCard ['message'] );
				$listPayments [$index] ['stripe_card'] = 'Failure';
				$listPayments [$index] ['stripe_card_respone'] = $stripeCard ['message'];
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
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		
		// Check if exist account
		if (empty ( $account ))
			return array (
					'status' => 'Failure',
					'message' => 'You have no any payment method at this time. please try to add card first' 
			);
		
		$paymentMethods = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodsByAccountId ( $account->account_id );
		
		// Check if account has payment method
		if (empty ( $paymentMethods ))
			return array (
					'status' => 'Failure',
					'message' => 'No record found.' 
			);
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		
		if (empty ( $accountDetail ))
			return array (
					'status' => 'Failure',
					'message' => 'Data corrupt with this account. Please try add new card first.' 
			);
			
			// Check if this card has exist at Stripe
		$stripeCard = $this->stripeCard->getCard ( $accountDetail->stripe_customer_id, $data ['card_id'] );
		if (! $stripeCard ['exist'])
			return array (
					'status' => 'Failure',
					'message' => 'This card is not belong to you.' 
			);
		else
			return array (
					'status' => 'Success',
					'card' => $stripeCard ['info'] 
			);
	}
	public function saveCard($card_data) {
		if (empty ( $card_data ['user_id'] ))
			$user_id = $_SESSION ['user_id'];
		else
			$user_id = $card_data ['user_id'];
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ( $user_id );
		
		// Check if exist account
		if (empty ( $account ))
			return array (
					'status' => 'Failure',
					'message' => 'You have no any payment method at this time. please try to add card first' 
			);
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $account->account_id );
		$card_data ['customer'] = $accountDetail->stripe_customer_id;
		
		return $this->stripeCard->updateCard ( $card_data );
	}
	/*
	 * Delete cards
	 */
	public function DeleteCards($message_data) {
		$card_data = $message_data ['selectedCard'];
		if (empty ( $card_data ))
			return array (
					'status' => 'Failure',
					'message' => 'No card input' 
			);
		
		foreach ( $card_data as $card ) {
			if (! empty ( $card )) {
				$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable ()->getPaymentMethodByStripeReferenceId ( $card );
				$accountDetail = $this->memreasStripeTables->getAccountDetailTable ()->getAccountDetailByAccount ( $paymentMethod->account_id );
				$stripeCustomerId = $accountDetail->stripe_customer_id;
				$deleteCardDB = $this->memreasStripeTables->getPaymentMethodTable ()->deletePaymentMethodByStripeCardReferenceId ( $card );
				if ($deleteCardDB) {
					// Remove this card from Stripe
					$deleteStripeCard = $this->stripeCard->deleteCard ( $stripeCustomerId, $card );
					if (! $deleteStripeCard ['deleted'])
						return array (
								'status' => 'Failure',
								'message' => $deleteStripeCard ['message'] 
						);
				} else
					return array (
							'status' => 'Failure',
							'message' => 'Error while deleting card from DB.' 
					);
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
 * Process all requests under customer
 */
class StripeCustomer {
	private $stripeClient;
	
	/*
	 * Define member variables
	 */
	private $id; // Customer's id
	private $email; // Customer's email
	private $description; // Customer's description
	public function __construct($stripeClient) {
		$this->stripeClient = $stripeClient;
	}
	
	/*
	 * Set customer's info
	 * @params: $data
	 * @return: TRUE if data is set - FAIL if data set failed
	 */
	public function setCustomerInfo($data) {
		if (is_array ( $data )) {
			$this->email = $data ['email'];
			$this->description = $data ['description'];
			return true;
		} else {
			if (is_object ( $data )) {
				$this->email = $data->email;
				$this->description = $data->description;
				return true;
			} else
				return false;
		}
	}
	
	/*
	 * Create a new customer account
	 * @params: $data - If $data is not null, this will use this data to create instead of class's members
	 * @return: result in JSON
	 */
	public function createCustomer($data = null) {
		if ($data) {
			$customer = $this->stripeClient->createCustomer ( array (
					'customer' => $data 
			) );
			$this->id = $customer ['id'];
		} else {
			$customer = $this->stripeClient->createCustomer ( array (
					'customer' => $this->getCustomerValues () 
			) );
			$this->id = $customer ['id'];
		}
		return $this->updateCustomer ( array (
				'id' => $this->id,
				'email' => $this->email,
				'description' => $this->description 
		) );
	}
	
	/*
	 * Get Customer's information
	 * @params: customer id
	 * @return: result in JSON
	 */
	public function getCustomer($customer_id) {
		Mlog::addone ( __CLASS__ . __METHOD__ . 'customer_id', $customer_id );
		
		try {
			$customer ['exist'] = true;
			$customer ['info'] = $this->stripeClient->getCustomer ( array (
					'id' => $customer_id 
			) );
		} catch ( NotFoundException $e ) {
			$customer ['exist'] = false;
			$customer ['message'] = $e->getMessage ();
		}
		return $customer;
	}
	
	/*
	 * Get Customers - list of all customers
	 * @params: null
	 * @return: Result in objects
	 */
	public function getCustomers() {
		return $this->stripeClient->getCustomers ();
	}
	
	/*
	 * Update Customer's information
	 * @params: $data - Contain new customer updates
	 * @Contain: account_balance, card(can be a token) - refer to storeCard function
	 * coupon, default_card(card id will be used for default), description,
	 * email, metadata
	 */
	public function updateCustomer($data) {
		try {
			$result ['updated'] = true;
			$result ['response'] = $this->stripeClient->updateCustomer ( $data ); // $data should contain id
		} catch ( NotFoundException $e ) {
			$result ['updated'] = false;
			$result ['message'] = $e->getMessage ();
		}
		return $result;
	}
	
	/*
	 * Delete customer
	 * @params: $customer_id
	 */
	public function deleteCustomer($customer_id) {
		try {
			return $this->stripeClient->deleteCustomer ( array (
					'id' => $customer_id 
			) );
		} catch ( NotFoundException $e ) {
			return array (
					'deleted' => 0,
					'message' => $e->getMessage () 
			);
		}
	}
	
	/*
	 * Retrieve current customer instance
	 * @params: $outputFormat : 1 - ARRAY, 0 - OBJECT
	 */
	public function getCustomerValues($outputFormat = 1) {
		if ($outputFormat)
			return array (
					'email' => $this->email,
					'description' => $this->description 
			);
		else {
			$customerObject = new StripeCustomer ( $this->stripeClient );
			$customerObject->email = $this->email;
			$customerObject->description = $this->description;
			return $customerObject;
		}
	}
	
	/*
	 * Set customer subscription
	 * @params: $data
	 */
	public function setSubscription($data) {
		try {
			$result = $this->stripeClient->createSubscription ( $data );
			
			/**
			 * -
			 * need to store to subscriptions table here...
			 */
			
			return array (
					'status' => 'Success',
					'result' => $result 
			);
		} catch ( ZfrStripe\Exception\BadRequestException $e ) {
			return array (
					'status' => 'Failure',
					'message' => $e->getMessage () 
			);
		}
	}
	public function cancelSubscription($subscriptionId, $customerId) {
		try {
			$this->stripeClient->cancelSubscription ( array (
					'customer' => $customerId,
					'id' => $subscriptionId 
			) );
			return array (
					'status' => 'Success' 
			);
		} catch ( ZfrStripe\Exception\BadRequestException $e ) {
			return array (
					'status' => 'Failure',
					'message' => $e->getMessage () 
			);
		}
	}
	public function setCustomerCardDefault($customerId, $cardId) {
		return $this->stripeClient->updateCustomer ( array (
				'id' => $customerId,
				'default_card' => $cardId 
		) );
	}
}

/*
 * Inherit Main Stripe Class
 * Process all requests under Recipient
 */
class StripeRecipient {
	private $stripeClient;
	
	/*
	 * Defone member variables
	 */
	private $id; // Recipient id
	private $name; // Recipient name
	private $type = 'individual'; // individual or corporation
	private $tax_id = null; // The recipient's tax ID
	private $bank_account = array (); // Bank account
	private $email; // Email
	private $description = ''; // Description
	private $metadata = array (); // Meta data, optional value
	public function __construct($stripeClient) {
		$this->stripeClient = $stripeClient;
	}
	
	/*
	 * Set recipient's info
	 * @params: $data
	 * @return: TRUE if data is set - FAIL if data set failed
	 */
	public function setRecipientInfo($data) {
		if (is_array ( $data )) {
			foreach ( $data as $key => $value )
				$this->{$key} = $value;
			return true;
		} else {
			if (is_object ( $data )) {
				$this->name = $data->name;
				$this->type = isset ( $data->type ) ? $data->type : $this->type;
				$this->tax_id = isset ( $data->tax_id ) ? $data->tax_id : $this->tax_id;
				$this->bank_account = isset ( $data->bank_account ) ? $data->bank_account : $this->bank_account;
				$this->email = $data->email;
				$this->description = $data->description;
				$this->metadata = isset ( $data->metadata ) ? $data->metadata : $this->metadata;
				return true;
			} else
				return false;
		}
	}
	
	/*
	 * Create a new recipient account
	 * @params: $data - If $data is not null, this will use this data to create instead of class's members
	 * @return: result in JSON
	 */
	public function createRecipient($data = null) {
		if ($data) {
			try {
				return $customer = $this->stripeClient->createRecipient ( $data );
				$this->id = $customer ['id'];
			} catch ( ZfrStripe\Exception\BadRequestException $e ) {
				return array (
						'error' => true,
						'message' => $e->getMessage () 
				);
			}
		} else {
			try {
				return $customer = $this->stripeClient->createRecipient ( $this->getRecipientValues () );
				$this->id = $customer ['id'];
			} catch ( ZfrStripe\Exception\BadRequestException $e ) {
				return array (
						'error' => true,
						'message' => $e->getMessage () 
				);
			}
		}
		return $this->updateCustomer ( array (
				'id' => $this->id,
				'email' => $this->email,
				'description' => $this->description 
		) );
	}
	
	/*
	 * Retrieve current recipient instance
	 * @params: $outputFormat : 1 - ARRAY, 0 - OBJECT
	 */
	public function getRecipientValues($outputFormat = 1) {
		if ($outputFormat) {
			$recipient = array (
					'id' => $this->id,
					'name' => $this->name,
					'type' => $this->type,
					'bank_account' => $this->bank_account,
					'email' => $this->email,
					'description' => $this->description,
					'metadata' => $this->metadata 
			);
			if (! empty ( $this->tax_id ))
				$recipient ['tax_id'] = $this->tax_id;
			return $recipient;
		} else {
			$recipientObject = new StripeRecipient ( $this->stripeClient );
			$recipientObject->id = $this->id;
			$recipientObject->name = $this->name;
			$recipientObject->type = $this->type;
			if (! empty ( $this->tax_id ))
				$recipientObject->tax_id = $this->tax_id;
			$recipientObject->bank_account = $this->bank_account;
			$recipientObject->email = $this->email;
			$recipientObject->description = $this->description;
			$recipientObject->metadata = $this->metadata;
			return $recipientObject;
		}
	}
	
	/*
	 * Make payout amount to Recipient
	 * params : $transferParams
	 */
	public function makePayout($transferParams) {
		return $this->stripeClient->createTransfer ( $transferParams );
	}
}

/*
 * Inherit Main Stripe Class
 * Proccess all requests under credit card
 */
class StripeCard {
	private $stripeClient;
	
	/*
	 * Define member variables
	 */
	private $id; // Card id - will get when generate card token
	private $number; // Credit card number
	private $exp_month; // Credit card expires month
	private $exp_year; // Credit card expires year
	private $cvc; // Credit card secret digits
	private $type; // Credit card type
	private $last4; // Last 4 digits
	private $object = 'card'; // Object is card
	private $fingerprint = ''; // Stripe card finger print
	private $customer = null; // Stripe card's customer
	private $name = null; // Name of card's holder
	private $country = ''; // Country code. Eg : US, UK,...
	private $address_line1 = ''; // Billing address no.1
	private $address_line2 = ''; // Billing address no.2
	private $address_city = ''; // Address city
	private $address_state = ''; // Address state
	private $address_zip = ''; // Address zip
	private $address_country = ''; // Address country
	private $cvc_check = 0; // Card verification credential
	private $address_line1_check = 0; // Check credit card address no.1
	private $address_zip_check = 0; // Check credit card zipcode
	protected $card_token;
	public function __construct($stripeClient) {
		$this->stripeClient = $stripeClient;
	}
	
	/*
	 * Charge value
	 */
	public function createCharge($data) {
		return $this->stripeClient->createCharge ( $data );
	}
	
	/*
	 * Get Balance Transaction (for charge fees)
	 */
	public function getBalanceTransaction($data) {
		return $this->stripeClient->getBalanceTransaction ( $data );
	}
	
	/*
	 * Construct card class
	 * @params : $card - an Array or Object contain StripeCard member variables
	 */
	public function setCard($card) {
		if ($card && (is_array ( $card ) || is_object ( $card ))) {
			if (! $this->setCarddata ( $card ))
				throw new Exception ( "Credit card set values failed! Please check back credit card's data.", 1 );
		}
	}
	
	/*
	 * Return current credit card instance
	 * @params : 1 - Array, 0 - Object
	 *
	 */
	public function getCardValues($outputFormat = 1) {
		if ($outputFormat) {
			return array (
					'id' => $this->id,
					'number' => $this->number,
					'exp_month' => $this->exp_month,
					'exp_year' => $this->exp_year,
					'cvc' => $this->cvc,
					'type' => $this->type,
					'last4' => $this->last4,
					'name' => $this->name,
					'object' => $this->object,
					'fingerprint' => $this->fingerprint,
					'customer' => $this->customer,
					'country' => $this->country,
					'address_line1' => $this->address_line1,
					'address_line2' => $this->address_line2,
					'address_city' => $this->address_city,
					'address_state' => $this->address_state,
					'address_zip' => $this->address_zip,
					'address_country' => $this->address_country,
					'cvc_check' => $this->cvc_check,
					'address_line1_check' => $this->address_line1_check,
					'address_zip_check' => $this->address_zip_check 
			);
		} else {
			$cardObject = new StripeCard ( $this->stripeClient );
			$cardObject->id = $this->id;
			$cardObject->number = $this->number;
			$cardObject->exp_month = $this->exp_month;
			$cardObject->exp_year = $this->exp_year;
			$cardObject->cvc = $this->cvc;
			$cardObject->type = $this->type;
			$cardObject->last4 = $this->last4;
			$cardObject->name = $this->name;
			$cardObject->object = $this->object;
			$cardObject->fingerprint = $this->fingerprint;
			$cardObject->customer = $this->customer;
			$cardObject->country = $this->country;
			$cardObject->address_line1 = $this->address_line1;
			$cardObject->address_line2 = $this->address_line2;
			$cardObject->address_city = $this->address_city;
			$cardObject->address_state = $this->address_state;
			$cardObject->address_zip = $this->address_zip;
			$cardObject->address_country = $this->address_country;
			$cardObject->cvc_check = $this->cvc_check;
			$cardObject->address_line1_check = $this->address_line1_check;
			$cardObject->address_zip_check = $this->address_zip_check;
			return $cardObject;
		}
	}
	
	/*
	 * Register / Create a new card
	 * @params: $card_data - predefined credit card's data to be stored
	 * You can pass data when init class or directly input without data init
	 * @return: JSON Object result
	 */
	public function storeCard($card_data = null) {
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$card_data', $card_data );
		if ($card_data) {
			$this->setCarddata ( $card_data );
			try {
				$cardToken = $this->stripeClient->createCardToken ( array (
						'card' => $card_data 
				) );
			} catch ( CardErrorException $exception ) {
				return array (
						'card_added' => false,
						'message' => $exception->getMessage () 
				);
			}
		} else {
			try {
				$cardToken = $this->stripeClient->createCardToken ( array (
						'card' => $this->getCardValues () 
				) );
			} catch ( CardErrorException $exception ) {
				return array (
						'card_added' => false,
						'message' => $exception->getMessage () 
				);
			}
		}
		$this->updateInfo ( $cardToken ['card'] );
		$this->setCardAttribute ( 'card_token', $cardToken ['id'] );
		$args = $this->getCardValues ();
		try {
			$result ['card_added'] = true;
			$result ['response'] = $this->stripeClient->createCard ( array (
					'card' => $args,
					'customer' => $this->customer 
			) );
		} catch ( CardErrorException $exception ) {
			$result ['card_added'] = false;
			$result ['message'] = $exception->getMessage ();
		}
		return $result;
	}
	
	/*
	 * Get card's info
	 * @params: $card_id
	 * @return:
	 */
	public function getCard($customer_id, $card_id) {
		try {
			$result ['exist'] = 1;
			$result ['info'] = $this->stripeClient->getCard ( array (
					'customer' => $customer_id,
					'id' => $card_id 
			) );
		} catch ( NoFoundException $e ) {
			$result ['exist'] = 0;
			$result ['message'] = $e->getMessage ();
		}
		return $result;
	}
	
	/*
	 * Update card's info
	 */
	public function updateCard($card_data) {
		try {
			$this->stripeClient->updateCard ( array (
					'id' => $card_data ['id'],
					'customer' => $card_data ['customer'],
					'address_city' => $card_data ['address_city'],
					'address_line1' => $card_data ['address_line1'],
					'address_line2' => $card_data ['address_line2'],
					'address_state' => $card_data ['address_state'],
					'address_zip' => $card_data ['address_zip'],
					'exp_month' => $card_data ['exp_month'],
					'exp_year' => $card_data ['exp_year'],
					'name' => $card_data ['name'] 
			) );
			return array (
					'status' => 'Success' 
			);
		} catch ( ValidationException $e ) {
			return array (
					'status' => 'Failure',
					'message' => $e->getMessage () 
			);
		}
	}
	
	/*
	 * Remove a card
	 */
	public function deleteCard($customer_id, $card_id) {
		try {
			return $this->stripeClient->deleteCard ( array (
					'customer' => $customer_id,
					'id' => $card_id 
			) );
		} catch ( NoFoundException $e ) {
			return array (
					'deleted' => 0,
					'message' => $e->getMessage () 
			);
		}
	}
	
	/*
	 * Set member variable values
	 * @params: $card_data
	 * @return:
	 * TRUE : All attributes and values are successful set
	 * FALSE : Input params is failed
	 */
	private function setCarddata($card_data) {
		if (is_array ( $card_data )) {
			foreach ( $card_data as $cardAttr => $cardValue )
				$this->{$cardAttr} = $cardValue; // Attributes from array are exactly same name
			return true;
		} else {
			if (is_object ( $card_data )) {
				$this->number = $card_data->number;
				$this->exp_month = $card_data->exp_month;
				$this->exp_year = $card_data->exp_year;
				$this->cvc = $card_data->cvc;
				// $this->type = $card_data->type;
				$this->type = $card_data->brand;
				$this->last4 = $card_data->last4;
				$this->name = (isset ( $card_data->name ) ? $card_data->name : null);
				$this->fingerprint = (isset ( $card_data->finfer_print ) ? $card_data->fingerprint : '');
				$this->customer = (isset ( $card_data->customer ) ? $card_data->customer : null);
				$this->country = (isset ( $card_data->country ) ? $card_data->country : '');
				$this->address_line1 = (isset ( $card_data->address_line1 ) ? $card_data->address_line1 : '');
				$this->address_line2 = (isset ( $card_data->address_line2 ) ? $card_data->address_line2 : '');
				$this->address_city = (isset ( $card_data->address_city ) ? $card_data->address_city : '');
				$this->address_state = (isset ( $card_data->address_state ) ? $card_data->address_state : '');
				$this->address_zip = (isset ( $card_data->address_zip ) ? $card_data->address_zip : '');
				$this->address_country = (isset ( $card_data->address_country ) ? $card_data->address_country : '');
				$this->cvc_check = (isset ( $card_data->cvc_check ) ? $card_data->cvc_check : 0);
				$this->address_line1_check = (isset ( $card_data->address_line1_check ) ? $card_data->address_line1_check : 0);
				$this->address_zip_check = (isset ( $card_data->address_zip_check ) ? $card_data->address_zip_check : 0);
				return true;
			} else
				return false;
		}
	}
	
	/*
	 * Update card information
	 */
	private function updateInfo($card_data) {
		$this->id = $card_data ['id'];
		$this->type = $card_data ['brand'];
		$this->last4 = $card_data ['last4'];
		$this->fingerprint = $card_data ['fingerprint'];
		// $this->type = $card_data->type;
		$this->customer = ! empty ( $card_data ['customer'] ) ? $card_data ['customer'] : $this->customer;
		$this->country = ! empty ( $card_data ['country'] ) ? $card_data ['country'] : $this->country;
		$this->name = ! empty ( $card_data ['name'] ) ? $card_data ['name'] : $this->name;
		$this->address_line1 = ! empty ( $card_data->address_line1 ) ? $card_data->address_line1 : $this->address_line1;
		$this->address_line2 = ! empty ( $card_data->address_line2 ) ? $card_data->address_line2 : $this->address_line2;
		$this->address_city = ! empty ( $card_data->address_city ) ? $card_data->address_city : $this->address_city;
		$this->address_state = ! empty ( $card_data->address_state ) ? $card_data->address_state : $this->address_state;
		$this->address_zip = ! empty ( $card_data->address_zip ) ? $card_data->address_zip : $this->address_zip;
		$this->address_country = ! empty ( $card_data->address_country ) ? $card_data->address_country : $this->address_country;
	}
	
	/*
	 * Set custom attribute
	 * @params: $attribteName
	 * @params: $attributeValue
	 */
	public function setCardAttribute($attributeName, $newValue) {
		$this->{$attributeName} = $newValue;
	}
	
	/*
	 * Get custom attribute
	 */
	public function getCardAttribute($attributeName) {
		return $this->{$attributeName};
	}
}
