<?php
/*
 * Memreas Stripe Paygate 
 * Coder: Tran IT
 * Date: 05.05.2014
 * Referer document: https://stripe.com/docs/api
 * */
 
namespace Application\memreas;

/*
 * Pre-include App Model
 * * */
use Application\Model\MemreasConstants;
use Application\Model\User;
use Application\Model\Account;
use Application\Model\AccountBalances;
use Application\Model\AccountDetail;
use Application\Model\PaymentMethod;
use Application\Model\Subscription;
use Application\Model\Transaction as Memreas_Transaction;
use Application\Model\TransactionReceiver;
use Application\memreas\StripePlansConfig;


/*
 * Include core Module - Libraries
 * */
use Zend\Session\Container;
use ZfrStripe;
use ZfrStripeModule;
use ZfrStripe\Client\StripeClient;
use ZfrStripe\Exception\TransactionErrorException;
use ZfrStripe\Exception\NotFoundException;
use Zend\Validator\CreditCard as ZendCreditCard;
 
 class MemreasStripe extends StripeInstance{
 	
	private $serviceLocator;
	private $stripeClient;	
	private $stripeInstance;
	protected $memreasStripeTables;
	
	protected $clientSecret;
	protected $clientPublic;
	
	protected $user_id;		
	
	public function __construct($serviceLocator){		
		$this->serviceLocator = $serviceLocator;
		$this->retreiveStripeKey();
		$this->stripeClient = new StripeClient($this->clientSecret);
		$this->memreasStripeTables = new MemreasStripeTables($serviceLocator);
		$this->stripeInstance = parent::__construct($this->stripeClient, $this->memreasStripeTables);		
		
		$session = new Container('user');
		$this->user_id = $session->offsetGet('user_id'); 							
	}
	
	/*
	 * Retreive Stripe account configuration SECRET and PUBLIC key
	 * Refer file : Application/Config/Autoload/Local.php
	 * */
	private function retreiveStripeKey(){
		$stripeConfig = $this->serviceLocator->get('Config');		
		$this->clientSecret = $stripeConfig['stripe_constants']['SECRET_KEY'];		
		$this->clientPublic = $stripeConfig['stripe_constants']['PUBLIC_KEY'];
	}				
	
 }

/*
 * Mail Class
 * */
 class StripeInstance{
 	
	private $stripeCustomer;
	private $stripeRecipient;
	private $stripeCard;
	private $stripePlan;
	
	protected $session;
	protected $memreasStripeTables;	
	
 	public function __construct($stripeClient, $memreasStripeTables){
 		$this->stripeCustomer = new StripeCustomer($stripeClient);
		$this->stripeRecipient = new StripeRecipient($stripeClient);
		$this->stripeCard = new StripeCard($stripeClient);		
		$this->stripePlan = new StripePlansConfig($stripeClient);
		$this->memreasStripeTables = $memreasStripeTables;
		$this->session = new Container('user');		
 	}
		
	/*
	 * Override customer's function
	 * */
	 public function addSeller($seller_data){
	 		 		
		//Get memreas user name
		$user_name = $seller_data['user_name'];
		$user = $this->memreasStripeTables->getUserTable()->getUserByUsername($user_name);
		//Get Paypal email address
		$stripe_email_address = $seller_data['stripe_email_address'];			

		//Fetch the Account
		$row = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id);
		if (!$row) {
			//Create Stripe Recipient data
			$recipientParams = array(
									'name' => $seller_data['first_name'] . ' ' . $seller_data['last_name'],
									'email' => $stripe_email_address,
									'description' => 1,
								);
			$this->stripeRecipient->setRecipientInfo($recipientParams);
			$recipientResponse = $this->stripeRecipient->createRecipient();						
			
			//Create an account entry
			$now = date('Y-m-d H:i:s');
			$account  = new Account();
			$account->exchangeArray(array(
				'user_id' => $user->user_id,
				'username' => $user->username,
				'account_type' => 'seller',
				'balance' => 0,
				'create_time' => $now,
				'update_time' => $now
			));
			$account_id =  $this->memreasStripeTables->getAccountTable()->saveAccount($account);
		} else {
			$account_id = $row->account_id;
			//Return a success message:
			$result = array (
				"Status"=>"Failure",
				"account_id"=> $account_id,
				"Error"=>"Seller already exists",
			);
			return $result;
		}

		$accountDetail  = new AccountDetail();
		$accountDetail->exchangeArray(array(
			'account_id'=>$account_id,
			'first_name'=>$seller_data['first_name'],
			'last_name'=>$seller_data['last_name'],
			'stripe_customer_id' => $recipientResponse['id'],
			'address_line_1'=>$seller_data['address_line_1'],
			'address_line_2'=>$seller_data['address_line_2'],
			'city'=>$seller_data['city'],
			'state'=>$seller_data['state'],
			'zip_code'=>$seller_data['zip_code'],
			'postal_code'=>$seller_data['zip_code'],
			'stripe_email_address'=>$seller_data['stripe_email_address'],
			));
		$account_detail_id = $this->memreasStripeTables->getAccountDetailTable()->saveAccountDetail($accountDetail);

		//Store the transaction that is sent to PayPal
		$now = date('Y-m-d H:i:s');
		$transaction  = new Memreas_Transaction();
		$transaction->exchangeArray(array(
				'account_id'=>$account_id,
				'transaction_type' =>'add_seller',
				'transaction_request' => "N/a",
				'pass_fail' => 1,
				'transaction_sent' =>$now,
				'transaction_receive' =>$now
		));
		$transaction_id =  $this->memreasStripeTables->getTransactionTable()->saveTransaction($transaction);

		//Insert account balances as needed
		$account_balances  = new AccountBalances();
		$account_balances->exchangeArray(array(
			'account_id' => $account_id,
			'transaction_id' => $transaction_id,
			'transaction_type' => 'add seller',
			'starting_balance' => 0,
			'amount' => 0,
			'ending_balance' => 0,
			'create_time' => $now
		));
		$account_balances_id =  $this->memreasStripeTables->getAccountBalancesTable()->saveAccountBalances($account_balances);

		//Return a success message:
		$result = array (
			"Status"=>"Success",
			"account_id"=> $account_id,
			"account_detail_id"=>$account_detail_id,
			"transaction_id"=>$transaction_id,
			"account_balances_id"=>$account_balances_id,
		);

		return $result;
	 }

	/*
	 * Add value to account
	 * */
	 public function addValueToAccount($data){
	 	$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($this->session->offsetGet('user_id'));
		$currency = 'USD';
		if (empty ($account)) 
			return array('status' => 'Failure', 'message' => 'You have no account at this time. Please add card first.');
		
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id); 
		
		if (empty ($accountDetail))
			return array('status' => 'Failure', 'message' => 'There is no data with your account.');
		
		//Check if this account has this card or not		
		$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable()->getPaymentMethodByStripeReferenceId($data['stripe_card_reference_id']);
		
		if (empty ($paymentMethod))
			return array('status' => 'Failure', 'message' => 'This card not relate to your account');
		
		//Begin going to charge on Stripe
		$cardToken = $paymentMethod->stripe_card_token;
		$cardId = $paymentMethod->stripe_card_reference_id;
		$customerId = $accountDetail->stripe_customer_id;
		$transactionAmount = ((int)$data['amount']) * 100; //Stripe use minimum amount conver for transaction. Eg: input $5  
															//convert request to stripe value is 500 
		$stripeChargeParams = array(
										'amount' => $transactionAmount,
										'currency' => $currency,
										'customer' => $customerId,
										'card' => $cardId, //If this card param is null, Stripe will get primary customer card to charge
										'description' => 'Add value to account', //Set description more details later
									);		
		$chargeResult = $this->stripeCard->createCharge($stripeChargeParams);
		if ($chargeResult){
			//Check if Charge is successful or not
			if (!$chargeResult['paid'])
				return array('status' => 'Failure', 'Transaction declined! Please check your Stripe account and cards');
			
			//Begin storing transaction to DB
			$transactionRequest = $stripeChargeParams;
			$transactionRequest['account_id'] = $account->account_id;
			$transactionRequest['stripe_details'] = array(
														'stripeCustomer' => $customerId,
														'stripeCardToken' => $cardToken,
														'stripeCardId' => $cardId,
														);
			$now = $now = date ( 'Y-m-d H:i:s' );		 
			$transactionDetail = array(
										'account_id' => $account->account_id,
										'transaction_type' => 'add_value_to_account',
										'amount' => (int)$data['amount'],
										'currency' => $currency,
										'transaction_request' => json_encode($transactionRequest),
										'transaction_response' => json_encode($chargeResult),
										'transaction_sent' => $now,
										'transaction_receive' => $now
									);	
			$memreasTransaction = new Memreas_Transaction();
			$memreasTransaction->exchangeArray($transactionDetail);
			$transactionId = $this->memreasStripeTables->getTransactionTable()->saveTransaction($memreasTransaction);
			
			//Update Account Balance
			$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances($account->account_id);
			$startingAccountBalance = (isset($currentAccountBalance)) ? $currentAccountBalance->ending_balance : '0.00';
			$endingAccountBalance = $startingAccountBalance + (int)$data['amount'];
			
			$accountBalance = new AccountBalances ();
			$accountBalance->exchangeArray ( array (
					'account_id' => $account->account_id,
					'transaction_id' => $transactionId,
					'transaction_type' => "add_value_to_account",
					'starting_balance' => $startingAccountBalance,
					'amount' => (int)$data['amount'],
					'ending_balance' => $endingAccountBalance,
					'create_time' => $now
			));
			$balanceId = $this->memreasStripeTables->getAccountBalancesTable()->saveAccountBalances($accountBalance);
			
			//Update account table
			$account = $this->memreasStripeTables->getAccountTable()->getAccount($account->account_id);
			$account->exchangeArray(array(
										'balance' => $endingAccountBalance,
										'update_time' => $now
									));
			$accountId = $this->memreasStripeTables->getAccountTable()->saveAccount($account);
			
			return array('status' => 'Success', 'message' => 'Successfully added $' . $data['amount'] . ' to your account.');
		}
		else return array('status' => 'Failure', 'message' => 'Unable to process payment'); 		
	 }

	public function decrementAmount($data){
		
		$seller = $data ['seller'];
		$memreas_master = $data['memreas_master'];
		$amount = $data ['amount'];
		
		$account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ($this->session->offsetGet('user_id'));
		if (!$account)
			return array('status' => 'Failure', 'message' => 'Account does not exist');
		$accountId = $account->account_id;
		
		$currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances($accountId);
		
		if (! isset ( $currentAccountBalance ) || ($currentAccountBalance->ending_balance <= 0)) {								
				return array (
						"status" => "Failure",
						"Description" => "Account not found or does not have sufficient funds."
				);				
		}
		$now = date ( 'Y-m-d H:i:s' );
		$MemreasTransaction = new Memreas_Transaction();
		$MemreasTransaction->exchangeArray ( array (
					'account_id' => $accountId,
					'transaction_type' => 'decrement_value_from_account',
					'pass_fail' => 1,
					'amount' => "-$amount",
					'currency' => 'USD',
					'transaction_request' => "N/a",
					'transaction_sent' => $now,
					'transaction_response' => "N/a",
					'transaction_receive' => $now
			) );
		$transactionId = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ($MemreasTransaction);
		
		//Starting decrement account balance
		$startingBalance = $currentAccountBalance->ending_balance;
		$endingBalance = $startingBalance - $amount;

		// Insert the new account balance
		$now = date ( 'Y-m-d H:i:s' );
		$endingAccountBalance = new AccountBalances ();
		$endingAccountBalance->exchangeArray (array(
				'account_id' => $accountId,
				'transaction_id' => $transactionId,
				'transaction_type' => "decrement_value_from_account",
				'starting_balance' => $startingBalance,
				'amount' => - $amount,
				'ending_balance' => $endingBalance,
				'create_time' => $now
		));
		$accountBalanceId = $this->memreasStripeTables->getAccountBalancesTable ()->saveAccountBalances ($endingAccountBalance);
		
		// Update the account table
		$now = date ( 'Y-m-d H:i:s' );
		$account = $this->memreasStripeTables->getAccountTable()->getAccount($accountId);
		$account->exchangeArray (array(
				'balance' => $endingBalance,
				'update_time' => $now
		) );
		$account_id = $this->memreasStripeTables->getAccountTable()->saveAccount ($account);
		
		$accountResult = array(
								'status' => 'Success', 
								'message' => 'Transaction completed',
								'starting_balance' => $startingBalance,
								'amount' => $amount,
								'ending_balance' => $endingBalance
								);	
		
		//For seller account
		$sellerUser = $this->memreasStripeTables->getUserTable()->getUserByUsername($seller);
		$seller_user_id = $sellerUser->user_id;
		$seller_account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($seller_user_id);
		
		if (!$seller_account){
			$sellerResult = array(
									'status' => 'Failure',
									'message' => 'Transaction failed! Seller does not exist',									
								);
		}
		else{
			$seller_account_id = $seller_account->account_id;
			$currentSellerBalance = $this->memreasStripeTables->getAccountBalancesTable()->getAccountBalances ($seller_account_id);

			// Log the transaction
			$now = date ( 'Y-m-d H:i:s' );
			$MemreasTransaction = new Memreas_Transaction();
			$seller_amount = $amount * 0.8;
			$memreas_master_amount = $amount - $seller_amount;

			$memreasTransaction->exchangeArray ( array (
					'account_id'=>$seller_account_id,
					'transaction_type' =>'increment_value_to_account',
					'pass_fail' => 1,
					'amount' => $seller_amount,
					'currency' => 'USD',
					'transaction_request' => "N/a",
					'transaction_sent' =>$now,
					'transaction_response' => "N/a",
					'transaction_receive' =>$now,
			));
			
			$transactionId = $this->memreasStripeTables->getTransactionTable()->saveTransaction($memreasTransaction);
			
			$startingBalance = (isset($currentSellerBalance)) ? $currentSellerBalance->ending_balance : '0.00';
			$endingBalance = $startingBalance + $seller_amount;

			//Insert the new account balance
			$now = date('Y-m-d H:i:s');
			$endingSellerBalance = new AccountBalances();
			$endingSellerBalance->exchangeArray(array(
				'account_id' => $seller_account_id,
				'transaction_id' => $transactionId,
				'transaction_type' => "increment_value_to_account",
				'starting_balance' => $startingBalance,
				'amount' => "$seller_amount",
				'ending_balance' => $endingBalance,
				'create_time' => $now,
				));
			$sellerBalanceId = $this->memreasStripeTables->getAccountBalancesTable()->saveAccountBalances($endingSellerBalance);
			
			//Update the account table
			$now = date('Y-m-d H:i:s');
			$seller = $this->memreasStripeTables->getAccountTable()->getAccount($seller_account_id);
			$seller->exchangeArray(array(
				'balance' => $endingBalance,
				'update_time' => $now,
				));
			$seller_account_id = $this->memreasStripeTables->getAccountTable()->saveAccount($seller);
			
			$sellerResult = array(
									'status' => 'Success',
									'message' => 'Transaction completed.',
									'starting_balance' => $startingBalance,
									'amount' => $amount,		
									'ending_balance' => $endingBalance
								);
			
		}
		return array(
						'account' => $accountResult,
						'seller' => $sellerResult,
					);
	
	}

	public function AccountHistory($data){
		$userName = $data['user_name'];
		$user = $this->memreasStripeTables->getUserTable()->getUserByUsername($userName);
		if(!$user)
			return array('status' => 'Failure', 'message' => 'User is not found');
		$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id);
		if (!$account)
			return array('status' => 'Failure', 'message' => 'Account associate to this user does not exist');
		$accountId = $account->account_id;
		$Transactions = $this->memreasStripeTables->getTransactionTable()->getTransactionByAccountId($accountId);
		$TransactionsArray = array();
		if ($Transactions){
			foreach ($Transactions as $Transaction){
				$row = array();
				$row["transaction_id"] = $Transaction->transaction_id;
				$row["account_id"] = $Transaction->account_id;
				$row["transaction_type"] = $Transaction->transaction_type;
				$row["pass_fail"] = $Transaction->pass_fail;
				$row["amount"] = $Transaction->amount;
				$row["currency"] = $Transaction->currency;
				$row["transaction_request"] = $Transaction->transaction_request;
				$row["transaction_response"] = $Transaction->transaction_response;
				$row["transaction_sent"] = $Transaction->transaction_sent;
				$row["transaction_receive"] = $Transaction->transaction_receive;
				$TransactionsArray[] = $row;
			}
		}
		return array(
						'status' 		=> 'Success',
						'account' 		=> $account,
						'transactions' 	=> $TransactionsArray
					);
	}

	/*
	 * Override stripe recipient function
	 * */
	 public function createRecipient($data){
	 	$this->stripeRecipient->setRecipientInfo($data);
		return $this->stripeRecipient->createRecipient();
	 }
	
		
	/*
	 * Override card's function
	 * */	 
	 
	 public function storeCard($card_data = null){
	 	
		//Check if valid Credit Card
		$ccChecking = new ZendCreditCard();
		if (!$ccChecking->isValid($card_data['number'])){
			return array (
					"Status" => "Failure",
					"message" => "Card number is not valid"
			);
		}
				
	 	$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($this->session->offsetGet('user_id'));
		if (!$account){
			
			//Create new stripe customer
			$user = $this->memreasStripeTables->getUserTable()->getUser($this->session->offsetGet('user_id'));			
			$userStripeParams = array(
							'email' => $user->email_address,
							'description' => 'Stripe account accociated with memreas user id : ' . $user->user_id, 
							);
										
			$this->stripeCustomer->setCustomerInfo($userStripeParams);
			$stripeUser = $this->stripeCustomer->createCustomer();
								
			$stripeCusId = $stripeUser['response']['id'];				
			
			//Create a new account if this account has no existed
			$now = date ( 'Y-m-d H:i:s' );
			$account = new Account ();
			$account->exchangeArray ( array (
					'user_id' => $this->session->offsetGet('user_id'),
					'username' => $this->session->offsetGet('username'),
					'account_type' => 'buyer',
					'balance' => 0,
					'create_time' => $now,
					'update_time' => $now
			));
			$account_id = $this->memreasStripeTables->getAccountTable ()->saveAccount ( $account );
		}
		else {
			$account_id = $account->account_id;
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account_id);
			$stripeCusId = $accountDetail->stripe_customer_id;
		}
		
		//Update customer id in card
		$this->stripeCard->setCardAttribute('customer', $stripeCusId);
		
		//Update account detail information
		$accountDetail = new AccountDetail ();
		$fullname = explode(' ', $card_data['name']);
		$firstName = $fullname[0];
		$lastName = $fullname[1];
		$accountDetail->exchangeArray ( array (
				'account_id' 			=> $account_id,
				'stripe_customer_id' 	=> $stripeCusId,
				'first_name' 			=> $firstName,
				'last_name' 			=> $lastName,
				'address_line_1' 		=> $card_data ['address_line1'],
				'address_line_2' 		=> $card_data ['address_line2'],
				'city' 					=> $card_data ['address_city'],
				'state' 				=> $card_data ['address_state'],
				'zip_code' 				=> $card_data ['address_zip'],
				'postal_code' 			=> $card_data ['address_zip'],
		) );
		$account_detail_id = $this->memreasStripeTables->getAccountDetailTable()->saveAccountDetail($accountDetail);
		
		$now = date ( 'Y-m-d H:i:s' );
		$transaction = new Memreas_Transaction ();

		// Copy the card and obfuscate the card number before storing the transaction
		$obfuscated_card = json_decode ( json_encode($card_data), true );
		$obfuscated_card ['number'] = $this->obfuscateAccountNumber ( $obfuscated_card ['number'] );
		
		$transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'store_credit_card',
				'transaction_request' => json_encode ( $obfuscated_card ),
				'transaction_sent' => $now
		) );
		$transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		 
		//Save customer card to Stripe 
	 	$stripeCard = $this->stripeCard->storeCard($card_data);
		$stripeCard = $stripeCard['response'];		
		
		$transaction->exchangeArray ( array (
				'transaction_id' => $transaction_id,
				'pass_fail' => 1,
				'transaction_response' => json_encode($stripeCard),
				'transaction_receive' => $now
		) );
		$transactionid = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );
		
		// Add the new payment method
		$payment_method = new PaymentMethod ();		
		$payment_method->exchangeArray ( array (
				'account_id' => $account_id,
				'stripe_card_reference_id' => $stripeCard['id'],
				'stripe_card_token' => $this->stripeCard->getCardAttribute('card_token'),
				'card_type' => $stripeCard['type'],
				'obfuscated_card_number' => $obfuscated_card ['number'],
				'exp_month' => $stripeCard['exp_month'],
				'exp_year' => $stripeCard['exp_year'],
				'valid_until' => $stripeCard['exp_month'] . '/' . $stripeCard['exp_year'],
				'create_time' => $now,
				'update_time' => $now
		) );
		$payment_method_id = $this->memreasStripeTables->getPaymentMethodTable ()->savePaymentMethod ( $payment_method );
		
		// Return a success message:
		$result = array (
				"Status" => "Success",
				"stripe_card_reference_id" => $stripeCard['id'],
				"account_id" => $account_id,
				"account_detail_id" => $account_detail_id,
				"transaction_id" => $transactionid,
				"payment_method_id" => $payment_method_id
		);

		return $result;						
	 }

	public function setSubscription($data){		
		
		$checkExistStripePlan = $this->stripePlan->getPlan($data['plan']);
		if (empty($checkExistStripePlan['plan'])) return array('status' => 'Failure', 'message' => 'Subscription plan was not found');
		
		
		$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($this->session->offsetGet('user_id'));
		if (!$account) return array('status' => 'Failure', 'message' => 'No account related to this user.');
				
		$account_id = $account->account_id;
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account_id);
		
		if (!$accountDetail) return array('status' => 'Failure', 'message' => 'Please update account detail first');
		
		$stripeCustomerId = $accountDetail->stripe_customer_id;
		
		//Set stripe subscription
		$subscriptionParams = array(
									'plan' => $data['plan'],
									'customer' => $stripeCustomerId,
									);
		$createSubscribe = $this->stripeCustomer->setSubscription($subscriptionParams);
		
		return $createSubscribe;		
		
	}

	/*
	 * List card by user_id
	 * */
	 public function listCards(){
	 	$user_id = $this->session->offsetGet('user_id');
		$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);
		
		//Check if exist account
		if (empty($account))
			return array('status' => 'Failure', 'message' => 'No account related to this user.');
			
		$paymentMethods = $this->memreasStripeTables->getPaymentMethodTable()->getPaymentMethodsByAccountId($account->account_id);
		
		//Check if account has payment method
		if (empty($paymentMethods))
			return array('status' => 'Failure', 'message' => 'No record found.');
		
		//Fetching results
		$listPayments = array();
		$index = 0;
		foreach ($paymentMethods as $paymentMethod){
			$accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($paymentMethod['account_id']);
			
			if (empty($accountDetail))
				return array('status' => 'Failure', 'message' => 'Data corrupt with this account. Please try add new card first.');
			
			//Check if this card has exist at Stripe
			$stripeCard = $this->stripeCard->getCard($accountDetail->stripe_customer_id, $paymentMethod['stripe_card_reference_id']);
			if (!$stripeCard['exist']){
				$listPayments[$index]['stripe_card'] = 'Failure';
				$listPayments[$index]['stripe_card_respone'] = $stripeCard['message'];
			} 										
			else{
				$listPayments[$index]['stripe_card'] = 'Success';
				$listPayments[$index]['stripe_card_response'] = $stripeCard['info'];
			}
			
			//Payment Method Details					
			$listPayments[$index]['payment_method_id'] = $paymentMethod['payment_method_id'];
			$listPayments[$index]['account_id'] = $paymentMethod['account_id'];
			$listPayments[$index]['user_id'] = $user_id;
			$listPayments[$index]['stripe_card_reference_id'] = $paymentMethod['stripe_card_reference_id'];
			$listPayments[$index]['card_type'] = $paymentMethod['card_type'];
			$listPayments[$index]['obfuscated_card_number'] = $paymentMethod['obfuscated_card_number'];
			$listPayments[$index]['exp_month'] = $paymentMethod['exp_month'];
			$listPayments[$index]['valid_until'] = $paymentMethod['valid_until'];

			//Address Details			
			$listPayments[$index]['first_name'] = $accountDetail->first_name;
			$listPayments[$index]['last_name'] = $accountDetail->last_name;
			$listPayments[$index]['address_line_1'] = $accountDetail->address_line_1;
			$listPayments[$index]['address_line_2'] = $accountDetail->address_line_2;
			$listPayments[$index]['city'] = $accountDetail->city;
			$listPayments[$index]['state'] = $accountDetail->state;
			$listPayments[$index]['zip_code'] = $accountDetail->zip_code;
			++$index;
		}

		return array(
					'status' => 'Success',
					'NumRows' => count($listPayments),
					'payment_methods' => $listPayments,
				);
	 }

	/*
	 * Delete cards
	 * */
	 public function DeleteCards($card_data){
	 	if (empty($card_data))
		 return array('status' => 'Failure', 'message' => 'No card input');
		
		foreach ($card_data as $card){						
			if (!empty($card)){
				$paymentMethod = $this->memreasStripeTables->getPaymentMethodTable()->getPaymentMethodByStripeReferenceId($card);				
				$accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($paymentMethod->account_id);
				$stripeCustomerId = $accountDetail->stripe_customer_id;
				$deleteCardDB = $this->memreasStripeTables->getPaymentMethodTable()->deletePaymentMethodByStripeCardReferenceId($card);
				if ($deleteCardDB){
					//Remove this card from Stripe
					$deleteStripeCard = $this->stripeCard->deleteCard($stripeCustomerId, $card);
					if (!$deleteStripeCard['deleted'])
						return array('status' => 'Failure', 'message' => $deleteStripeCard['message']);
				}
				else return array('status' => 'Failure', 'message' => 'Error while deleting card from DB.');				
			}
		}
		return array('status' => 'Success', 'message' => 'Cards have been deleted.');
	 }

	/*
	 * Encode card number
	 * */
	 private function obfuscateAccountNumber($num) {
		$num = (string) preg_replace("/[^A-Za-z0-9]/", "", $num); // Remove all non alphanumeric characters
		return str_pad(substr($num, -4), strlen($num), "*", STR_PAD_LEFT);
	 }
	 		 
 }

/*
 * Inherit Main Stripe Class
 * Process all requests under customer
 * */
 class StripeCustomer{
 	
	private $stripeClient;
	
 	/*
	 * Define member variables
	 * */	
	 private $id; 			//Customer's id
	 private $email; 		//Customer's email
	 private $description; //Customer's description
	 
	 public function __construct($stripeClient){
	 	$this->stripeClient = $stripeClient;
	 }
	 
	 /*
	  * Set customer's info
	  * @params: $data
	  * @return: TRUE if data is set - FAIL if data set failed
	  * */
	 public function setCustomerInfo($data){
	 	if (is_array($data)){
	 		$this->email = $data['email'];
			$this->description = $data['description'];
			return true;
	 	}
		else{
			if (is_object($data)){
				$this->email = $data->email;
				$this->description = $data->description;
				return true;
			}
			else return false;
		}
	 }
	 
	 /*
	  * Create a new customer account
	  * @params: $data - If $data is not null, this will use this data to create instead of class's members
	  * @return: result in JSON
	  * */
	 public function createCustomer($data = null){
	 	if ($data){
			$customer = $this->stripeClient->createCustomer(array('customer' => $data));
			$this->id = $customer['id'];			
		}
		else{
			$customer = $this->stripeClient->createCustomer(array('customer' => $this->getCustomerValues()));
			$this->id = $customer['id'];
		}
		return $this->updateCustomer(array('id' => $this->id, 'email' => $this->email, 'description' => $this->description));
	 }
	 
	 /*
	  * Get Customer's information
	  * @params: customer id
	  * @return: result in JSON
	  * */
	  public function getCustomer($customer_id){
	  	try{
	  		$customer['exist'] = true;	
	  		$customer['info'] = $this->stripeClient->getCustomer(array('id' => $customer_id));
		}catch(NotFoundException $e){
			$customer['exist'] = false;
			$customer['message'] = $e->getMessage();
		}
		return $customer;
	  }
	 
	 /*
	  * Get Customers - list of all customers
	  * @params: null
	  * @return: Result in objects
	  * */
	  public function getCustomers(){
	  	return $this->stripeClient->getCustomers();
	  }
	  
	  /*
	   * Update Customer's information
	   * @params: $data - Contain new customer updates
	   * @Contain: account_balance, card(can be a token) - refer to storeCard function
	   * 			coupon, default_card(card id will be used for default), description,
	   * 			email, metadata
	   * */
	   public function updateCustomer($data){
	   		try{
	   			$result['updated'] = true;				
				$result['response'] = $this->stripeClient->updateCustomer($data); //$data should contain id
	   		}catch(NotFoundException $e){
	   			$result['updated'] = false;
				$result['message'] = $e->getMessage();				
	   		}		
			return $result;
	   }
	   
	   /*
	    * Delete customer
	    * @params: $customer_id
	    * */
	    public function deleteCustomer($customer_id){
	    	try{
	    		return $this->stripeClient->deleteCustomer(array('id' => $customer_id));
			}catch(NotFoundException $e){
				return array('deleted' => 0, 'message' => $e->getMessage());
			}
	    }
	 
	 /*
	  * Retrieve current customer instance
	  * @params: $outputFormat : 1 - ARRAY, 0 - OBJECT
	  * */
	  public function getCustomerValues($outputFormat = 1){
	  	if ($outputFormat)
			return array('email' => $this->email, 'description' => $this->description);
		else{
			$customerObject->email = $this->email;
			$customerObject->description = $this->description;
			return $customerObject;
		}
	  }

	/*
	 * Set customer subscription
	 * @params: $data
	 * */
	 public function setSubscription($data){
	 	return $this->stripeClient->createSubscription($data);
	 }
 }
 
 /*
  * Inherit Main Stripe Class
  * Process all requests under Recipient
  * */
  class StripeRecipient{
  	
	private $stripeClient;
	
	/*
	 * Defone member variables
	 * */
	 private $id;					//Recipient id
	 private $name;					//Recipient name
	 private $type = 'individual';	//individual or corporation
	 private $tax_id = null;			//The recipient's tax ID
	 private $bank_account = array();	//Bank account
	 private $email;				//Email	
	 private $description = '';			//Description
	 private $metadata = array();		//Meta data, optional value
	 
	 public function __construct($stripeClient){
	 	$this->stripeClient = $stripeClient;
	 }
	 
	 /*
	  * Set recipient's info
	  * @params: $data
	  * @return: TRUE if data is set - FAIL if data set failed
	  * */
	 public function setRecipientInfo($data){
	 	if (is_array($data)){
	 		foreach ($data as $key => $value)
				$this->{$key} = $value;
			return true;
	 	}
		else{
			if (is_object($data)){
				$this->name = $data->name;
				$this->type = isset($data->type) ? $data->type : $this->type;
				$this->tax_id = isset($data->tax_id) ? $data->tax_id : $this->tax_id;
				$this->bank_account = isset($data->bank_account) ? $data->bank_account : $this->bank_account;
				$this->email = $data->email;
				$this->description = $data->description;
				$this->metadata = isset($data->metadata) ? $data->metadata : $this->metadata;
				return true;
			}
			else return false;
		}
	 }	
	 
	 /*
	  * Create a new recipient account
	  * @params: $data - If $data is not null, this will use this data to create instead of class's members
	  * @return: result in JSON
	  * */
	 public function createRecipient($data = null){	 	
	 	if ($data){
			return $customer = $this->stripeClient->createRecipient($data);
			$this->id = $customer['id'];			
		}
		else{
			return $customer = $this->stripeClient->createRecipient($this->getRecipientValues());
			$this->id = $customer['id'];
		}
		return $this->updateCustomer(array('id' => $this->id, 'email' => $this->email, 'description' => $this->description));
	 }
	 
	 /*
	  * Retrieve current recipient instance
	  * @params: $outputFormat : 1 - ARRAY, 0 - OBJECT
	  * */
	  public function getRecipientValues($outputFormat = 1){
	  	if ($outputFormat){
			$recipient = array(
					'id' => $this->id,
					'name' => $this->name,
					'type' => $this->type,					
					'bank_account' => $this->bank_account,
					'email' => $this->email, 
					'description' => $this->description,
					'metadata' => $this->metadata,
					);
			if (!empty($this->tax_id))
				$recipient['tax_id'] = $this->tax_id;
			return $recipient;
		}
		else{
			$recipientObject->id = $this->id;
			$recipientObject->name = $this->name;
			$recipientObject->type = $this->type;
			if (!empty($this->tax_id))
				$recipientObject->tax_id = $this->tax_id;
			$recipientObject->bank_account = $this->bank_account;			
			$recipientObject->email = $this->email;
			$recipientObject->description = $this->description;
			$recipientObject->metadata = $this->metadata;
			return $recipientObject;
		}
	  }
  }
 
 /*
  * Inherit Main Stripe Class
  * Proccess all requests under credit card 
  * */
 class StripeCard{
 	
	private $stripeClient; 	
	
	/*
	 * Define member variables
	 * */
	private $id;								//Card id - will get when generate card token	
 	private $number; 							//Credit card number
	private $exp_month;							//Credit card expires month
	private $exp_year;							//Credit card expires year
	private $cvc;								//Credit card secret digits
	private $type;								//Credit card type
	private $last4;								//Last 4 digits
	private $object 				= 'card'; 	//Object is card
	private $fingerprint 			= '';		//Stripe card finger print
	private $customer 				= null;		//Stripe card's customer
	private $name					= null;		//Name of card's holder
	private $country 				= '';		//Country code. Eg : US, UK,...
	private $address_line1 			= '';		//Billing address no.1
	private $address_line2 			= ''; 		//Billing address no.2
	private $address_city 			= '';		//Address city	
	private $address_state 			= ''; 		//Address state
	private $address_zip 			= '';		//Address zip
	private $address_country 		= '';		//Address country
	private $cvc_check 				= 0;		//Card verification credential
	private $address_line1_check 	= 0;		//Check credit card address no.1
	private $address_zip_check 		= 0;		//Check credit card zipcode
	
	protected $card_token;
	
	public function __construct($stripeClient){
	 	$this->stripeClient = $stripeClient;			
	}	
	
	/*
	 * Charge value
	 * */
	 public function createCharge($data){
	 	return $this->stripeClient->createCharge($data);
	 }
	
	/*
	 * Construct card class
	 * @params : $card - an Array or Object contain StripeCard member variables
	 * */
	public function setCard($card){
		if ($card && (is_array($card) || is_object($card))){
			if (!$this->setCarddata($card))
				throw new Exception("Credit card set values failed! Please check back credit card's data.", 1);													
		}
	}	
	
	/*
	 * Return current credit card instance
	 * @params : 1 - Array, 0 - Object 
	 * 	
	 * */
	public function getCardValues($outputFormat = 1){
		if ($outputFormat)
			return array(
							'id' 					=> $this->id,
							'number' 				=> $this->number,
							'exp_month' 			=> $this->exp_month,
							'exp_year' 				=> $this->exp_year,
							'cvc'					=> $this->cvc,
							'type'					=> $this->type,
							'last4'					=> $this->last4,
							'name'					=> $this->name,
							'object'				=> $this->object,
							'fingerprint' 			=> $this->fingerprint,			
							'customer' 				=> $this->customer,				
							'country' 				=> $this->country,				
							'address_line1' 		=> $this->address_line1,			
							'address_line2' 		=> $this->address_line2, 		
							'address_city' 			=> $this->address_city,				
							'address_state' 		=> $this->address_state, 		
							'address_zip' 			=> $this->address_zip,			
							'address_country' 		=> $this->address_country,		
							'cvc_check' 			=> $this->cvc_check,				
							'address_line1_check' 	=> $this->address_line1_check,	
							'address_zip_check' 	=> $this->address_zip_check,
						);
		else{
			$cardObject->id		 				= $this->id;
			$cardObject->number 				= $this->number;
			$cardObject->exp_month 				= $this->exp_month;
			$cardObject->exp_year 				= $this->exp_year;
			$cardObject->cvc					= $this->cvc;
			$cardObject->type					= $this->type;
			$cardObject->last4					= $this->last4;
			$cardObject->name					= $this->name;
			$cardObject->object					= $this->object;
			$cardObject->fingerprint 			= $this->fingerprint;			
			$cardObject->customer 				= $this->customer;				
			$cardObject->country 				= $this->country;				
			$cardObject->address_line1			= $this->address_line1;		
			$cardObject->address_line2			= $this->address_line2; 		
			$cardObject->address_city 			= $this->address_city;				
			$cardObject->address_state 			= $this->address_state; 		
			$cardObject->address_zip 			= $this->address_zip;			
			$cardObject->address_country 		= $this->address_country;		
			$cardObject->cvc_check				= $this->cvc_check;				
			$cardObject->address_line1_check	= $this->address_line1_check;	
			$cardObject->address_zip_check 		= $this->address_zip_check;
			return $cardObject;
		}
	}

	/*
	 * Register / Create a new card
	 * @params: $card_data - predefined credit card's data to be stored
	 * 			You can pass data when init class or directly input without data init
	 * @return: JSON Object result	 
	 * */
	 public function storeCard($card_data = null){	 	
	 	if ($card_data){
	 		$this->setCarddata($card_data);
	 		$cardToken = $this->stripeClient->createCardToken(array('card' => $card_data));
		}			 				
		else 			
			$cardToken = $this->stripeClient->createCardToken(array('card' => $this->getCardValues()));		
		$this->updateInfo($cardToken['card']);	
		$this->setCardAttribute('card_token', $cardToken['id']);						
		$args = $this->getCardValues();					
		try{
			$result['card_added'] = true;
			$result['response'] = $this->stripeClient->createCard(array('card' => $args, 'customer' => $this->customer));
		}catch(CardErrorException $exception){
			$result['card_added'] = false;
			$result['message'] = $exception->getMessage();
		}		 	
		return $result;
	 }
	 
	 /*
	  * Get card's info
	  * @params: $card_id
	  * @return:
	  * */
	  public function getCard($customer_id, $card_id){
	  	try{
	  		$result['exist'] = 1;
			$result['info'] = $this->stripeClient->getCard(array('customer' => $customer_id, 'id' => $card_id));
	  	}catch(NoFoundException $e){
	  		$result['exist'] = 0;
			$result['message'] = $e->getMessage();
	  	}		
		return $result;
	  }
	  
	  /*
	   * Remove a card
	   * */
	   public function deleteCard($customer_id, $card_id){
	   	try{
	   		return $this->stripeClient->deleteCard(array('customer' => $customer_id, 'id' => $card_id));			
	   	}catch(NoFoundException $e){
	   		return array('deleted' => 0, 'message' => $e->getMessage());
	   	}
	   }
	
	
	/*
	 * Set member variable values
	 * @params: $card_data	 
	 * @return:
	 * 		TRUE	: All attributes and values are successful set
	 * 		FALSE 	: Input params is failed 
	 * */
	 private function setCarddata($card_data){
	 	if (is_array($card_data)){
	 		foreach ($card_data as $cardAttr => $cardValue)
	 			$this->{$cardAttr} = $cardValue; //Attributes from array are exactly same name	 		
			return true;
	 	}	
		else {
			if (is_object($card_data)){
				$this->number 				= $card_data->number;
				$this->exp_month 			= $card_data->exp_month;
				$this->exp_year				= $card_data->exp_year;
				$this->cvc					= $card_data->cvc;
				$this->type					= $card_data->type;				
				$this->name					= (isset($card_data->name) ? $card_data->name : null);
				$this->fingerprint			= (isset($card_data->finfer_print) ? $card_data->fingerprint : '');
				$this->customer				= (isset($card_data->customer) ? $card_data->customer : null);
				$this->country				= (isset($card_data->country) ? $card_data->country : '');
				$this->address_line1		= (isset($card_data->address_line1) ? $card_data->address_line1 : '');
				$this->address_line2		= (isset($card_data->address_line2) ? $card_data->address_line2 : '');
				$this->address_city			= (isset($card_data->address_city) ? $card_data->address_city : '');
				$this->address_state		= (isset($card_data->address_state) ? $card_data->address_state : '');
				$this->address_zip			= (isset($card_data->address_zip) ? $card_data->address_zip : '');
				$this->address_country		= (isset($card_data->address_country) ? $card_data->address_country : '');
				$this->cvc_check			= (isset($card_data->cvc_check) ? $card_data->cvc_check : 0);
				$this->address_line1_check	= (isset($card_data->address_line1_check) ? $card_data->address_line1_check : 0);
				$this->address_zip_check	= (isset($card_data->address_zip_check) ? $card_data->address_zip_check : 0);
				return true;	
			}	
			else return false;
		}
	 }

	/*
	 * Update card information
	 * */
	 private function updateInfo($card_data){
	 	$this->id 				= $card_data['id'];		
	 	$this->type 			= $card_data['type'];
		$this->fingerprint 		= $card_data['fingerprint'];
		$this->last4			= $card_data['last4'];
		$this->customer 		= !empty($card_data['customer']) ? $card_data['customer'] : $this->customer;
		$this->country 			= !empty($card_data['country']) ? $card_data['country'] : $this->country;
		$this->name 			= !empty($card_data['name']) ? $card_data['name'] : $this->name;
		$this->address_line1	= !empty($card_data->address_line1) ? $card_data->address_line1 : $this->address_line1;
		$this->address_line2	= !empty($card_data->address_line2) ? $card_data->address_line2 : $this->address_line2;
		$this->address_city		= !empty($card_data->address_city) ? $card_data->address_city : $this->address_city;
		$this->address_state	= !empty($card_data->address_state) ? $card_data->address_state : $this->address_state;
		$this->address_zip		= !empty($card_data->address_zip) ? $card_data->address_zip : $this->address_city;
		$this->address_country	= !empty($card_data->address_country) ? $card_data->address_country : $this->address_country;
	}

	/*
	 * Set custom attribute
	 * @params: $attribteName
	 * @params: $attributeValue	 
	 * */
	 public function setCardAttribute($attributeName, $newValue){
	 	$this->{$attributeName} = $newValue;
	 }
	 
	 /*
	  * Get custom attribute
	  * */
	  public function getCardAttribute($attributeName){
	  	return $this->{$attributeName};
	  }
 }
