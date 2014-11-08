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
use Application\Model\AccountPurchases;
use Application\Model\MemreasConstants;
use Zend\View\Model\ViewModel;
use Application\memreas\AWSManagerSender;
use Application\Model\User;
use Application\Model\Account;
use Application\Model\AccountBalances;
use Application\Model\AccountDetail;
use Application\Model\PaymentMethod;
use Application\Model\Subscription;
use Application\Model\Transaction as Memreas_Transaction;
use Application\Model\TransactionReceiver;
use Application\memreas\StripePlansConfig;
use Aws\Ses\Exception\SesException;
use Guzzle\Http\Client;

/*
 * Include core Module - Libraries
 * */
use Zend\Session\Container;
use ZfrStripe;
use ZfrStripeModule;
use ZfrStripe\Client\StripeClient;
use ZfrStripe\Exception\TransactionErrorException;
use ZfrStripe\Exception\NotFoundException;
use ZfrStripe\Exception\CardErrorException;
use Guzzle\Service\Exception\ValidationException;
use Zend\Validator\CreditCard as ZendCreditCard;
use ZfrStripe\Exception\BadRequestException;
 
 class MemreasStripe extends StripeInstance{

	private $stripeClient;	
	private $stripeInstance;
	public $memreasStripeTables;
	
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

    public $serviceLocator;
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

     public function get($propertyName){
         return $this->{$propertyName};
     }

     public function getCustomer($data, $stripe = false){
         $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($data['userid']);

         //Check if exist account
         if (empty($account))
             return array('status' => 'Failure', 'message' => 'No account related to this user.');

         $accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);

         return array(
                'status' => 'Success',
                'customer' => ($stripe) ? $this->stripeCustomer->getCustomer($accountDetail->stripe_customer_id) : null,
                'account' => $account,
                'accountDetail' => $accountDetail
         );
     }

     public function refundAmount($data){
         $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($data['user_id']);

         //Check if exist account
         if (empty($account))
             return array('status' => 'Failure', 'message' => 'No account related to this user.');

         $now = date('Y-m-d H:i:s');
         $account->reason = $data['reason'];
         $transactionDetail = array(
             'account_id' => $account->account_id,
             'transaction_type' => 'refund_amount',
             'amount' => (int)$data['amount'],
             'currency' => 'USD',
             'transaction_request' => json_encode($account),
             'transaction_response' => json_encode($account),
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
             'transaction_type' => "refund_amount",
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

         return array('status' => 'Success', 'message' => 'Refund completed');
     }

     public function getCustomerPlans($user_id){
         $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);

         //Check if exist account
         if (empty($account))
             return array('status' => 'Failure', 'message' => 'No account related to this user.');

         $accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);
         $customer = $this->stripeCustomer->getCustomer($accountDetail->stripe_customer_id);
         $customerPlans = $customer['info']['subscriptions']['data'];
         return array('status' => 'Success', 'plans' => $customerPlans);
     }

     public function listPlans(){
         return $this->stripePlan->getAllPlans();
     }

     public function getTotalPlanUser($planId){
         $countUserPlan = $this->memreasStripeTables->getSubscriptionTable()->countUser($planId);
         return $countUserPlan;
     }

     public function getOrderHistories($user_id, $page, $limit){
         if ($user_id){
             $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);

             //Check if exist account
             if (empty($account))
                 return array('status' => 'Failure', 'message' => 'No account related to this user.');

            $transactions = $this->memreasStripeTables->getTransactionTable()->getTransactionByAccountId($account->account_id, $page, $limit);
         }
         else $transactions = $this->memreasStripeTables->getTransactionTable()->getAllTransactions($page, $limit);

         $orders = array();
         foreach ($transactions as $transaction) {
             $accountBalance = $this->memreasStripeTables->getAccountBalancesTable()->getAccountBalanceByTransactionId($transaction->transaction_id);
             $orders[] = array(
                 'transaction' => $transaction,
                 'accountBalance' => $accountBalance
             );
         }
         return array(
             'status' => 'Success',
             'transactions' => $orders
         );
     }

     public function getOrder($transaction_id){
        return $this->memreasStripeTables->getTransactionTable()->getTransaction($transaction_id);
     }

     public function getAccountDetailByAccountId($account_id){
         $account = $this->memreasStripeTables->getAccountTable()->getAccount($account_id);
         $userDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);
         return $userDetail;
     }

     public function getUserById($user_id){
         $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);
         $userDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);
         return $userDetail;
     }

     /*
      * Check user type based on user id
      * */
     public function checkUserType($username){
		$user = $this->memreasStripeTables->getUserTable()->getUserByUsername($username);
        if (!$user)
            return array('status' => 'Failure', 'message' => 'No user related to this username');

        //Fetch the account
        $type = array();
         $buyer_amount = 0;
        $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id);
        if (!empty ($account)) {
            $type[] = 'buyer';
            $buyer_amount = $account->balance;
        }

         $seller_amount = 0;
        $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id, 'seller');
         if (!empty ($account)) {
             $type[] = 'seller';
             $seller_amount = $account->balance;
         }

        if (!empty($type))
            return array('status' => 'Success', 'types' => $type, 'buyer_balance' => $buyer_amount, 'seller_balance' => $seller_amount);
        else return array('status' => 'Failure', 'message' => 'You have no any payment method at this time. please try to add card first');
     }

	/*
	 * Override customer's function
	 * */
	 public function addSeller($seller_data){
	 		 		
		//Get memreas user name
		$user_name = $seller_data['user_name'];
		$user = $this->memreasStripeTables->getUserTable()->getUserByUsername($user_name);

         if (!$user)
             return array('status' => 'Failure', 'message' => 'No user related to this username');

		//Get Paypal email address
		$stripe_email_address = $seller_data['stripe_email_address'];			

		//Fetch the Account
		$row = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id, 'seller');
		if (!$row) {

            $bankData = array(
                'country' => 'US',
                'routing_number' => $seller_data['bank_routing'],
                'account_number' => $seller_data['account_number']
            );

			//Create Stripe Recipient data
			$recipientParams = array(
									'name' => $seller_data['first_name'] . ' ' . $seller_data['last_name'],
									'email' => $stripe_email_address,
									'description' => 1,
                                    'bank_account' => $bankData
								);
			$this->stripeRecipient->setRecipientInfo($recipientParams);
			$recipientResponse = $this->stripeRecipient->createRecipient();

            if (array_key_exists('error', $recipientResponse))
                return array('status' => 'Failure', 'message' => $recipientResponse['message']);
			
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
            //If user has an account with type is buyer, register a new seller account
			$account_id = $row->account_id;
			//Return a success message:
			$result = array (
				"status"=>"Failure",
				"account_id"=> $account_id,
				"message"=>"Seller already exists",
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
			'stripe_email_address'=>$seller_data['stripe_email_address']
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
			"status"=>"Success",
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
        if (isset($data['userid']))
            $userid = $data['userid'];
        else $userid = $this->session->offsetGet('user_id');
	 	$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($userid);
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
			$now = date ( 'Y-m-d H:i:s' );
			$transactionDetail = array(
										'account_id' => $account->account_id,
										'transaction_type' => 'add_value_to_account',
										'amount' => (int)$data['amount'],
										'currency' => $currency,
										'transaction_request' => json_encode($transactionRequest),
										'transaction_response' => json_encode($chargeResult),
										'transaction_sent' => $now,
										'transaction_receive' => $now,
                                        'transaction_status' => 'buy_credit_email'
									);	
			$memreasTransaction = new Memreas_Transaction();
			$memreasTransaction->exchangeArray($transactionDetail);
            $transactionId = $this->memreasStripeTables->getTransactionTable()->saveTransaction($memreasTransaction);

            //Send activation email
            $viewModel = new ViewModel (array(
                'username' => $accountDetail->first_name . ' ' . $accountDetail->last_name,
                'active_link' => 'https://memreasdev-pay.memreas.com/stripe/activeCredit?token=' . $transactionId,
                'amount' => ((int)$data['amount'])
            ));
            $viewModel->setTemplate ( 'email/buycredit' );
            $viewRender = $this->serviceLocator->get ( 'ViewRenderer' );

            $html = $viewRender->render ( $viewModel );
            $subject = 'Your purchased credit is now ready';

            if (!isset($aws_manager) || empty ( $aws_manager ))
                $aws_manager = new AWSManagerSender ( $this->serviceLocator );
            try{
                $user = $this->memreasStripeTables->getUserTable()->getUser($userid);
                $aws_manager->sendSeSMail ( array($user->email_address), $subject, $html );
            }catch (SesException $e){}

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
			
			return array('status' => 'Success', 'message' => 'Successfully added $' . $data['amount'] . ' to your account.');
		}
		else return array('status' => 'Failure', 'message' => 'Unable to process payment'); 		
	 }

     public function getAccountBalance($data){
        $user_id = $data['user_id'];
        $user = $this->memreasStripeTables->getUserTable()->getUser($user_id);
        if (!$user)
            return array('status' => 'Failure', 'message' => 'No user related to this username');

         //Fetch the account
         $buyer_amount = 0;
        $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id);
        if (!empty ($account))
            $buyer_amount = $account->balance;

        $seller_amount = 0;
        $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user->user_id, 'seller');
        if (!empty ($account))
            $seller_amount = $account->balance;

        return array('status' => 'Success', 'buyer_balance' => $buyer_amount, 'seller_balance' => $seller_amount);
     }

     public function buyMedia($data){
         $user = $this->memreasStripeTables->getUserTable()->getUser($data['user_id']);
         $amount = $data['amount'];
         $event_id = $data['event_id'];
         $seller_id = $data['seller_id'];
         $duration_from = $data['duration_from'];
         $duration_to = $data['duration_to'];
         if (!$user)
             return array('status' => 'Failure', 'message' => 'No user related to this username');

         /*
          * Start decrease amount from buyer
          * and buyer flow
         */
         $account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ($user->user_id);
         if (!$account)
             return array('status' => 'Failure', 'message' => 'Account does not exist');
         $accountId = $account->account_id;

         $currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances($accountId);

         if (! isset ( $currentAccountBalance )
             || ($currentAccountBalance->ending_balance <= 0)
             || $account->balance <= 0
             || ($currentAccountBalance->ending_balance <= $amount)
             || $account->balance <= $amount) {
             return array (
                 "status" => "Failure",
                 "Description" => "Account not found or does not have sufficient funds."
             );
         }
         $now = date ( 'Y-m-d H:i:s' );
         $MemreasTransaction = new Memreas_Transaction();
         $MemreasTransaction->exchangeArray ( array (
             'account_id' => $accountId,
             'transaction_type' => 'buy_media_spending',
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
             'amount' => "-$amount",
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

         //Update purchase table
         $AccountPurchase = new AccountPurchases();
         $AccountPurchase->exchangeArray(array(
             'account_id' => $account_id,
             'event_id' => $event_id,
             'amount' => $amount,
             'transaction_id' => $transactionId,
             'transaction_type' => 'buy_media_complete',
             'create_time' => $now,
             'start_date' => $duration_from,
             'end_date' => $duration_to
         ));
         $this->memreasStripeTables->getAccountPurchasesTable()->saveAccountPurchase($AccountPurchase);

         /*
          * Starting process for seller flow
          * */
         $seller = $this->memreasStripeTables->getUserTable()->getUser($seller_id);
         $account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ($seller->user_id, 'seller');
         if (!$account)
             return array('status' => 'Failure', 'message' => 'Account does not exist');

         $accountId = $account->account_id;

         $currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances($accountId);

         $now = date ( 'Y-m-d H:i:s' );
         $MemreasTransaction = new Memreas_Transaction();
         $MemreasTransaction->exchangeArray ( array (
             'account_id' => $accountId,
             'transaction_type' => 'sell_media_received',
             'pass_fail' => 1,
             'amount' => "+$amount",
             'currency' => 'USD',
             'transaction_request' => "N/a",
             'transaction_sent' => $now,
             'transaction_response' => "N/a",
             'transaction_receive' => $now
         ) );
         $transactionId = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ($MemreasTransaction);

         //Starting decrement account balance
         $startingBalance = $currentAccountBalance->ending_balance;
         $endingBalance = $startingBalance + $amount;

         // Insert the new account balance
         $now = date ( 'Y-m-d H:i:s' );
         $endingAccountBalance = new AccountBalances ();
         $endingAccountBalance->exchangeArray (array(
             'account_id' => $accountId,
             'transaction_id' => $transactionId,
             'transaction_type' => "increase_value_from_account",
             'starting_balance' => $startingBalance,
             'amount' => "+$amount",
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

         return array('status' => 'Success', 'message' => 'Buying media completed', 'event_id' => $event_id);
     }

     public function checkOwnEvent($data){
         $event_id = $data['event_id'];
         $user_id = $data['user_id'];

         $account = $this->memreasStripeTables->getAccountTable ()->getAccountByUserId ($user_id);
         if (!$account)
            return array('status' => 'Failure', 'event_id' => $event_id);

         $checkBuyMedia = $this->memreasStripeTables->getAccountPurchasesTable()->getAccountPurchase($account->account_id, $event_id);

         if (empty($checkBuyMedia))
             return array('status' => 'Failure', 'event_id' => $event_id);

         return array('status' => 'Success', 'event_id' => $event_id);
     }

     public function activePendingBalanceToAccount($transaction_id){
         $Transaction = $this->memreasStripeTables->getTransactionTable()->getTransaction($transaction_id);

         if (empty($Transaction))
             return array('status' => 'Failure', 'message' => 'No record found');

         if (strpos($Transaction->transaction_status, 'activated'))
             return array('status' => 'Failure', 'message' => 'Expired transaction');

         $now = date ( 'Y-m-d H:i:s' );

         //Update Account Balance
         $currentAccountBalance = $this->memreasStripeTables->getAccountTable ()->getAccount($Transaction->account_id);
         $startingAccountBalance = (isset($currentAccountBalance)) ? $currentAccountBalance->balance : '0.00';

         $endingAccountBalance = $startingAccountBalance + (int)$Transaction->amount;

         $account = $this->memreasStripeTables->getAccountTable()->getAccount($Transaction->account_id);
         $account->exchangeArray(array(
             'balance' => $endingAccountBalance,
             'update_time' => $now
         ));
         $accountId = $this->memreasStripeTables->getAccountTable()->saveAccount($account);

         $Transaction->transaction_status = 'buy_credit_email, buy_credit_verified, buy_credit_activated';
         $metadata = array(
             'IP' => $_SERVER['REMOTE_ADDR'],
             'date' => $now,
             'url' => $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']
         );
         $Transaction->metadata = json_encode($metadata);
         $this->memreasStripeTables->getTransactionTable()->saveTransaction($Transaction);

         return array('status' => 'Success', 'message' => 'Amount has been activated');
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
			$memreasTransaction = new Memreas_Transaction();
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
					'transaction_receive' =>$now
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
         if (isset($card_data['user_id']))
            $user_id = $card_data['user_id'];
         else $user_id = $this->session->offsetGet('user_id');

        $user = $this->memreasStripeTables->getUserTable()->getUser($user_id);
	 	$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);
		if (!$account){
			
			//Create new stripe customer
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
					'user_id' => $user_id,
					'username' => $user->username,
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
                'stripe_email_address'  => $user->email_address,
				'first_name' 			=> $firstName,
				'last_name' 			=> $lastName,
				'address_line_1' 		=> $card_data ['address_line1'],
				'address_line_2' 		=> $card_data ['address_line2'],
				'city' 					=> $card_data ['address_city'],
				'state' 				=> $card_data ['address_state'],
				'zip_code' 				=> $card_data ['address_zip'],
				'postal_code' 			=> $card_data ['address_zip']
		) );
		$account_detail_id = $this->memreasStripeTables->getAccountDetailTable()->saveAccountDetail($accountDetail);

		//Save customer card to Stripe
	 	$stripeCard = $this->stripeCard->storeCard($card_data);
        if (!$stripeCard['card_added'])
            return array('status' => 'Failure', 'message' => $stripeCard['message']);
		$stripeCard = $stripeCard['response'];

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
				"status" => "Success",
				"stripe_card_reference_id" => $stripeCard['id'],
				"account_id" => $account_id,
				"account_detail_id" => $account_detail_id,
				"transaction_id" => $transactionid,
				"payment_method_id" => $payment_method_id
		);

		return $result;
	 }

	public function setSubscription($data){

		if (isset($data['userid']))
            $userid = $data['userid'];
        else $userid = $this->session->offsetGet('user_id');

        if (isset($data['card_id']))
            $card = $data['card_id'];
        else $card = null;

		$checkExistStripePlan = $this->stripePlan->getPlan($data['plan']);
		if (empty($checkExistStripePlan['plan'])) return array('status' => 'Failure', 'message' => 'Subscription plan was not found');
		
		
		$account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($userid);
		if (!$account) return array('status' => 'Failure', 'message' => 'No account related to this user.');
				
		$account_id = $account->account_id;
		$accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account_id);
		
		if (!$accountDetail) return array('status' => 'Failure', 'message' => 'Please update account detail first');
		
		$stripeCustomerId = $accountDetail->stripe_customer_id;

        //Create a charge for this subscription
        $paymentMethod = $this->memreasStripeTables->getPaymentMethodTable()->getPaymentMethodByStripeReferenceId($card);

        if (empty ($paymentMethod))
            return array('status' => 'Failure', 'message' => 'This card not relate to your account');

        //Check if user has actived subscription or not
        $stripeCustomerInfo = $this->stripeCustomer->getCustomer($stripeCustomerId);

        $upgrade = true;
        if ($stripeCustomerInfo['info']['subscriptions']['total_count'] > 0){
            $subscriptions = $stripeCustomerInfo['info']['subscriptions']['data'];
            foreach ($subscriptions as $subscription){

                //User has actived plan
                if ($subscription['plan']['id'] == $data['plan']){
                    return array('status' => 'Failure', 'message' => 'You have actived this plan before.');
                }
            }

            $planLevel = $this->stripePlan->getPlanLevel($data['plan']);
            $customerPlanLevel = $this->stripePlan->getPlanLevel($subscription['plan']['id']);

            //Checking for upgrade plan
            if ($planLevel > $customerPlanLevel){
                $result = $this->stripeCustomer->cancelSubscription($subscriptions[0]['id'], $stripeCustomerId);
            }
            else {

                //Downgrade plan
                $planDetail = $this->stripePlan->getPlanConfig($data['plan']);

                //Get user used detail
                $guzzle = new Client();
                $xml = "<xml><getdiskusage><user_id>{$userid}</user_id></getdiskusage></xml>";
                $request = $guzzle->post(
                    MemreasConstants::MEMREAS_WS,
                    null,
                    array(
                        'action' => 'getdiskusage',
                        //'cache_me' => true,
                        'xml' => $xml,
                        'sid' => '',
                        'user_id' => ''
                    )
                );

                $response = $request->send();
                $data = $response->getBody(true);
                $data = str_replace(array("\r", "\n"), "", $data);
                $data = json_encode(simplexml_load_string($data));
                $plan = json_decode($data);
                $plan = $plan->getdiskusageresponse;
                $dataUsage = str_replace(" GB", "", $plan->total_used);
                if ($dataUsage > $planDetail['storage']){
                    return array('status' => 'Failure', 'message' => 'In order to downgrade your plan you must be within the required usage limits. Please remove media as needed before downgrading your plan');
                }
                else{
                    //Cancel current plan
                    $result = $this->stripeCustomer->cancelSubscription($subscriptions[0]['id'], $stripeCustomerId);
                    $upgrade = false;
                }

            }
        }

        //Charge only if user upgrade or register as a new
        if ($upgrade){
            //Begin going to charge on Stripe
            $cardId = $paymentMethod->stripe_card_reference_id;
            $customerId = $accountDetail->stripe_customer_id;
            $transactionAmount = ((int)$data['amount']) * 100; //Stripe use minimum amount conver for transaction. Eg: input $5
            //convert request to stripe value is 500
            $stripeChargeParams = array(
                'amount' => $transactionAmount,
                'currency' => 'USD',
                'customer' => $customerId,
                'card' => $cardId, //If this card param is null, Stripe will get primary customer card to charge
                'description' => 'Charge for subscription : ' . $data['plan'], //Set description more details later
            );

            if ($transactionAmount > 0)
                $chargeResult = $this->stripeCard->createCharge($stripeChargeParams);
            else $chargeResult = false;
        }
        else $chargeResult = true;

        if ($chargeResult){
            //Check if Charge is successful or not
            if (!$chargeResult['paid'] && $upgrade)
                return array('status' => 'Failure', 'message' => 'Transaction declined! Please check your Stripe account and cards');
        }
		
		//Set stripe subscription
		$subscriptionParams = array(
									'plan' => $data['plan'],
									'customer' => $stripeCustomerId,
									);

        //Set customer card for charging
        if (!empty($card))
            $this->stripeCustomer->setCustomerCardDefault($stripeCustomerId, $card);

		$createSubscribe = $this->stripeCustomer->setSubscription($subscriptionParams);

        if (isset($createSubscribe['id'])){
            $plan = $this->stripePlan->getPlan($data['plan']);
            $viewModel = new ViewModel (array(
                'username' => $accountDetail->first_name . ' ' . $accountDetail->last_name,
                'plan_name' => $plan['plan']['name']
            ));
            $viewModel->setTemplate ( 'email/subscription' );
            $viewRender = $this->serviceLocator->get ( 'ViewRenderer' );

            $html = $viewRender->render ( $viewModel );
            $subject = 'Your subscription plan has been actived';

            if (!isset($aws_manager) || empty ( $aws_manager ))
                $aws_manager = new AWSManagerSender ( $this->serviceLocator );
            try{
                $user = $this->memreasStripeTables->getUserTable()->getUser($userid);
                $aws_manager->sendSeSMail ( array($user->email_address), $subject, $html );
            }catch (SesException $e){}

            $now = date('Y-m-d H:i:s');

            //Save transaction table
            $transaction = new Memreas_Transaction ();

            $transaction->exchangeArray ( array (
                'account_id' => $account_id,
                'transaction_type' => 'buy_subscription',
                'transaction_request' => json_encode ( $paymentMethod ),
                'transaction_sent' => $now
            ) );
            $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );

            $transaction->exchangeArray ( array (
                'transaction_id' => $transaction_id,
                'pass_fail' => 1,
                'transaction_response' => json_encode($createSubscribe),
                'transaction_receive' => $now
            ) );
            $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );


            //Save subscription table
            $memreasSubscription  = new Subscription();
            $memreasSubscription->exchangeArray(array(
                'account_id'=> $account->account_id,
                'currency_code'=> 'USD',
                'plan' => $data['plan'],
                'plan_amount' => $transactionAmount,
                'plan_description' => $plan['plan']['name'],
                'gb_storage_amount' => '',
                'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY,
                'start_date' => $now,
                'end_date' => null,
                'paypal_subscription_profile_id' => $createSubscribe['id'],
                'paypal_subscription_profile_status' => 'Active',
                'create_date' => $now,
                'update_time' => $now
            ));
            $this->memreasStripeTables->getSubscriptionTable()->saveSubscription($memreasSubscription);

            //Update user plan table
            $user = $this->memreasStripeTables->getUserTable()->getUser($userid);
            $metadata = json_decode($user->metadata, true);
            $planDetail = $this->stripePlan->getPlanConfig($data['plan']);
            $metadata['subscription'] = array(
                'plan' => $data['plan'],
                'name' => $planDetail['plan_name'],
                'storage' => $planDetail['storage'],
                'date_active' => $now,
                'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY,
            );
            $user->metadata = json_encode($metadata);
            $this->memreasStripeTables->getUserTable()->saveUser($user);

            return array('status' => 'Success');
        }
        else return array('status' => 'Failure', 'message' => 'Subscription registering failed.');
	}

     public function cancelSubscription($subscriptionId, $customerId){
         $this->stripeCustomer->cancelSubscription($subscriptionId, $customerId);
     }

	public function listMassPayee($username, $page, $limit){
		$MassPees = $this->memreasStripeTables->getAccountTable()->listMassPayee($username, $page, $limit);
		
		$countRow = count($MassPees);
		
		if (!$countRow) return array('status' => 'Failure', 'message' => 'No record found');
		$massPeesArray = array();
		foreach ($MassPees as $MassPee){
			$massPeesArray[] = array(
										'account_id' 	=> $MassPee->account_id,
										'user_id' 		=> $MassPee->user_id,
										'username' 		=> $MassPee->username,
										'account_type' 	=> $MassPee->account_type,
										'balance' 		=> $MassPee->balance,	
									);
		}
		
		return array(
						'status' 	=> 'Success',
						'Numrows'	=> $countRow,
						'accounts'	=> $massPeesArray,
					);
	}

     public function MakePayout($data){
         $account = $this->memreasStripeTables->getAccountTable()->getAccount($data['account_id']);

         if (empty ($account))
             return array('status' => 'Failure', 'message' => 'You have no any payment method at this time. please try to add card first');

         //Check if account has available balance
         if ($account->balance < $data['amount'])
             return array('status' => 'Failure', 'message' => 'Account balance is smaller than amount you requested');

         $accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);

         //Check if stripe customer / recipient is set
         if (empty($accountDetail->stripe_customer_id))
             return array('status' => 'Failure', 'message' => 'No stripe ID related to this account');

         $transferParams = array(
             'amount' => (int)$data['amount'],
             'currency' => 'USD',
             'recipient' => $accountDetail->stripe_customer_id,
             'description' => $data['description']
         );

         try{
            $transferResponse = $this->stripeRecipient->makePayout($transferParams);
         }catch(ZfrStripe\Exception\BadRequestException $e){
             return array('status' => 'Failure', 'message' => $e->getMessage());
         }

         $now = date('Y-m-d H:i:s');
         //Update transaction
         $transaction = new Memreas_Transaction ();

         $transaction->exchangeArray ( array (
             'account_id' => $account->account_id,
             'transaction_type' => 'make_payout_seller',
             'transaction_request' => '',
             'transaction_sent' => $now
         ) );
         $transaction_id = $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );

         $transaction->exchangeArray ( array (
             'transaction_id' => $transaction_id,
             'pass_fail' => 1,
             'transaction_response' => json_encode($transferResponse),
             'transaction_receive' => $now
         ) );
         $this->memreasStripeTables->getTransactionTable ()->saveTransaction ( $transaction );

         //Update Account Balance
         $currentAccountBalance = $this->memreasStripeTables->getAccountBalancesTable ()->getAccountBalances($account->account_id);
         $startingAccountBalance = (isset($currentAccountBalance)) ? $currentAccountBalance->ending_balance : '0.00';
         $endingAccountBalance = $startingAccountBalance - (int)$data['amount'];
         $accountBalance = new AccountBalances ();
         $accountBalance->exchangeArray ( array (
             'account_id' => $account->account_id,
             'transaction_id' => '',
             'transaction_type' => "seller_payout",
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

         return array('status' => 'Success', 'message' => 'Amount has been transferred');
     }

	/*
	 * List card by user_id
	 * */
	 public function listCards($user_id){
        if (empty($user_id))
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

     public function listCard($data){
         if (empty($data['user_id']))
             $user_id = $this->session->offsetGet('user_id');
         else $user_id = $data['user_id'];
         $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);

         //Check if exist account
         if (empty($account))
             return array('status' => 'Failure', 'message' => 'No account related to this user.');

         $paymentMethods = $this->memreasStripeTables->getPaymentMethodTable()->getPaymentMethodsByAccountId($account->account_id);

         //Check if account has payment method
         if (empty($paymentMethods))
             return array('status' => 'Failure', 'message' => 'No record found.');

         $accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);

         if (empty($accountDetail))
             return array('status' => 'Failure', 'message' => 'Data corrupt with this account. Please try add new card first.');

         //Check if this card has exist at Stripe
         $stripeCard = $this->stripeCard->getCard($accountDetail->stripe_customer_id, $data['card_id']);
         if (!$stripeCard['exist'])
             return array('status' => 'Failure', 'message' => 'This card is not belong to you.');
         else
             return array('status' => 'Success', 'card' => $stripeCard['info']);
     }

     public function saveCard($card_data){
         if (empty($card_data['user_id']))
             $user_id = $this->session->offsetGet('user_id');
         else $user_id = $card_data['user_id'];
         $account = $this->memreasStripeTables->getAccountTable()->getAccountByUserId($user_id);

         //Check if exist account
         if (empty($account))
             return array('status' => 'Failure', 'message' => 'No account related to this user.');

         $accountDetail = $this->memreasStripeTables->getAccountDetailTable()->getAccountDetailByAccount($account->account_id);
         $card_data['customer'] = $accountDetail->stripe_customer_id;

         return $this->stripeCard->updateCard($card_data);
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
			$customerObject = new StripeCustomer($this->stripeClient);
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

     public function cancelSubscription($subscriptionId, $customerId){
         return $this->stripeClient->cancelSubscription(array('customer' => $customerId, 'id' => $subscriptionId));
     }

     public function setCustomerCardDefault($customerId, $cardId){
         return $this->stripeClient->updateCustomer(array('id' => $customerId, 'default_card' => $cardId));
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
            try{
                return $customer = $this->stripeClient->createRecipient($data);
                $this->id = $customer['id'];
            }catch (ZfrStripe\Exception\BadRequestException $e){
                return array('error' => true, 'message' => $e->getMessage());
            }
		}
		else{
            try{
                return $customer = $this->stripeClient->createRecipient($this->getRecipientValues());
                $this->id = $customer['id'];
            }catch (ZfrStripe\Exception\BadRequestException $e){
                return array('error' => true, 'message' => $e->getMessage());
            }
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
            $recipientObject = new StripeRecipient($this->stripeClient);
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

      /*
       * Make payout amount to Recipient
       * params : $transferParams
       * */
      public function makePayout($transferParams){
          return $this->stripeClient->createTransfer($transferParams);
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
			$cardObject = new StripeCard($this->stripeClient);
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
            try{
	 		    $cardToken = $this->stripeClient->createCardToken(array('card' => $card_data));
            }catch(CardErrorException $exception){
                return array('card_added' => false, 'message' => $exception->getMessage());
            }
		}			 				
		else{
            try{
			    $cardToken = $this->stripeClient->createCardToken(array('card' => $this->getCardValues()));
            }catch(CardErrorException $exception){
                return array('card_added' => false, 'message' => $exception->getMessage());
            }
        }
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
      * Update card's info
      * */
     public function updateCard($card_data){
         try{
            $this->stripeClient->updateCard(array(
                'id' => $card_data['id'],
                'customer' => $card_data['customer'],
                'address_city' => $card_data['address_city'],
                'address_line1' => $card_data['address_line1'],
                'address_line2' => $card_data['address_line2'],
                'address_state' => $card_data['address_state'],
                'address_zip' => $card_data['address_zip'],
                'exp_month' => $card_data['exp_month'],
                'exp_year' => $card_data['exp_year'],
                'name' => $card_data['name']
            ));
             return array('status' => 'Success');
         }catch (ValidationException $e){
            return array('status' => 'Failure', 'message' => $e->getMessage());
         }
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
