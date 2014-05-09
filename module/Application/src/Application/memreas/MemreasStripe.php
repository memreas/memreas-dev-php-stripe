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
	private $stripeCard;
	
	protected $session;
	protected $memreasStripeTables;	
	
 	public function __construct($stripeClient, $memreasStripeTables){
 		$this->stripeCustomer = new StripeCustomer($stripeClient);
		$this->stripeCard = new StripeCard($stripeClient);		
		$this->memreasStripeTables = $memreasStripeTables;
		$this->session = new Container('user');		
 	}
		
	/*
	 * Collect Customer's functions
	 * Description on each function can be found on detail classes
	 * */
	public function setCustomerInfo($data){
		return $this->stripeCustomer->setCustomerInfo($data);
	}
	public function getCustomerValues(){
		return $this->stripeCustomer->getCustomerValues();
	}
	public function createCustomer($data = null){
		return $this->stripeCustomer->createCustomer($data);
	}
	public function getCustomer($customer_id){
		return $this->stripeCustomer->getCustomer($customer_id);
	}
	public function getCustomers(){
		return $this->stripeCustomer->getCustomers();
	}	
	public function updateCustomer($data){
		return $this->stripeCustomer->updateCustomer($data);
	}
	public function deleteCustomer($customer_id){
		return $this->stripeCustomer->deleteCustomer($customer_id);
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
	  public function getCard($card_id){
	  	try{
	  		$result['exist'] = 1;
			$result['info'] = $this->stripeClient->getCard(array('id' => $card_id));
	  	}catch(NoFoundException $e){
	  		$result['exist'] = 0;
			$result['message'] = $e->getMessage();
	  	}		
		return $result;
	  }
	  
	  /*
	   * Remove a card
	   * */
	   public function deleteCard($card_id){
	   	try{
	   		return $this->stripeClient->deleteCard(array('id' => $card_id));			
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
