<?php
// ///////////////////////////////
// Author: John Meah
// Copyright memreas llc 2013
// ///////////////////////////////
namespace Application\memreas;

use Zend\Session\Container;

// Rest PayPal API
use PayPal\Api\CreditCard;
use PayPal\Api\CreditCardToken;
use PayPal\Api\Address;
use PayPal\Api\FundingInstrument;
use PayPal\Api\Transaction as PayPal_Transaction;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;

// Merchant PayPal API
use PayPal\CoreComponentTypes\BasicAmountType;
use PayPal\PayPalAPI\MassPayReq;
use PayPal\PayPalAPI\MassPayRequestItemType;
use PayPal\PayPalAPI\MassPayRequestType;
use PayPal\PayPalAPI\CreateRecurringPaymentsProfileReq;
use PayPal\PayPalAPI\CreateRecurringPaymentsProfileRequestType;
use PayPal\PayPalAPI\SetExpressCheckoutReq;
use PayPal\PayPalAPI\SetExpressCheckoutRequestType;
use PayPal\EBLBaseComponents\CreditCardDetailsType;
use PayPal\EBLBaseComponents\RecurringPaymentsProfileDetailsType;
use PayPal\EBLBaseComponents\BillingPeriodDetailsType;
use PayPal\EBLBaseComponents\ScheduleDetailsType;
use PayPal\EBLBaseComponents\CreateRecurringPaymentsProfileRequestDetailsType;
use PayPal\EBLBaseComponents\AddressType;
use PayPal\EBLBaseComponents\SetExpressCheckoutRequestDetailsType;
use PayPal\EBLBaseComponents\PaymentDetailsType;
use PayPal\EBLBaseComponents\BillingAgreementDetailsType;
use PayPal\EBLBaseComponents\SellerDetailsType;
use PayPal\Service\PayPalAPIInterfaceServiceService;

// Core PayPal API
use PayPal\Core\PPConfigManager;
use PayPal\Core\PPLoggingManager;
use PayPal\Exception\PPConnectionException;
use PayPal\Auth\PPSignatureCredential;
use PayPal\Auth\PPTokenAuthorization;
use PayPal\IPN\PPIPNMessage;

define ( 'PP_CONFIG_PATH', dirname ( __FILE__ ) . "/config/" );

// memreas models
use Application\Model\MemreasConstants;
use Application\Model\User;
use Application\Model\Account;
use Application\Model\AccountBalances;
use Application\Model\AccountDetail;
use Application\Model\PaymentMethod;
use Application\Model\Subscription;
use Application\Model\Transaction as Memreas_Transaction;
use Application\Model\TransactionReceiver;
use Application\Model\PayPalConfig;

//For Credit Card Verify
use Zend\Validator\CreditCard as ZendCreditCard;

class MemreasPayPal {

	// Link used for code
	// https://developer.paypal.com/webapps/developer/docs/api/#store-a-credit-card
	protected $user_id;
	protected $username;
	protected $session;
	public function fetchSession() {
		if (! isset ( $this->session )) {
			$this->session = new Container ( 'user' );
			$this->user_id = $this->session->offsetGet ( 'user_id' );
			$this->username = $this->session->offsetGet ( 'username' );
		}
	}
	public function fetchPayPalCredential($service_locator) {
		// Fetch Session
		$this->fetchSession ();

		// Fetch the PayPal credentials
		$token = "";
		$credential = "";
		if (! $this->session->offsetExists ( 'paypal_credential' )) {
			// Fetch an OAuth token...
			$config = $service_locator->get ( 'Config' );
			$client_id = $config ['paypal_constants'] ['CLIENT_ID'];
			$client_secret = $config ['paypal_constants'] ['CLIENT_SECRET'];
			$credential = new OAuthTokenCredential ( $client_id, $client_secret );
			$config = PPConfigManager::getInstance ()->getConfigHashmap ();
			$token = $credential->getAccessToken ( $config );
			$this->session->offsetSet ( 'paypal_credential', $credential );
			$this->session->offsetSet ( 'paypal_token', $token );
		} else {
			$credential = $this->session->offsetGet ( 'paypal_credential' );
		}
		return $credential;
	}
	public function storeCreditCard($message_data, $memreas_paypal_tables, $service_locator) {


		//Check if valid Credit Card
		$ccChecking = new ZendCreditCard();
		if (!$ccChecking->isValid($message_data['credit_card_number'])){
			return array (
					"Status" => "Failure",
					"message" => "Card number is not valid"
			);
		}

		// Fetch Session
		$this->fetchSession ();
		// Fetch PayPal credential
		$credential = $this->fetchPayPalCredential ( $service_locator );
		// Setup an api context for the card
		$api_context = new ApiContext ( $credential );

		// Store the card with PayPal
		$card = new CreditCard ();
		$card->setType ( strtolower ( $message_data ['credit_card_type'] ) );
		$card->setNumber ( $message_data ['credit_card_number'] );
		$card->setExpire_month ( $message_data ['expiration_month'] );
		$card->setExpire_year ( $message_data ['expiration_year'] );
		// $card->setCvv2("012");
		$card->setFirst_name ( $message_data ['first_name'] );
		$card->setLast_name ( $message_data ['last_name'] );
		$card->setPayer_Id ( $this->user_id );

		// Get the Billing Address and associate it with the card
		$billing_address = new Address ();
		$billing_address->setLine1 ( $message_data ['address_line_1'] );
		$billing_address->setLine2 ( $message_data ['address_line_2'] );
		$billing_address->setCity ( $message_data ['city'] );
		$billing_address->setState ( $message_data ['state'] );
		$billing_address->setPostalCode ( $message_data ['zip_code'] );
		$billing_address->setPostal_code ( $message_data ['zip_code'] );
		$billing_address->setCountryCode ( "US" );
		$billing_address->setCountry_code ( "US" );
		$card->setBillingAddress ( $billing_address );
		$card->setBilling_address ( $billing_address );

		// Associate the user with the card
		$card->setPayer_Id ( $this->user_id );

		// Store the data before the call
		// Check for an existing account

		// Fetch the Account
		$row = $memreas_paypal_tables->getAccountTable ()->getAccountByUserId ( $this->user_id );
		if (! $row) {
			// Create an account entry
			$now = date ( 'Y-m-d H:i:s' );
			$account = new Account ();
			$account->exchangeArray ( array (
					'user_id' => $this->user_id,
					'username' => $this->username,
					'account_type' => 'buyer',
					'balance' => 0,
					'create_time' => $now,
					'update_time' => $now
			) );
			$account_id = $memreas_paypal_tables->getAccountTable ()->saveAccount ( $account );
		} else {
			$account_id = $row->account_id;
		}
		error_log ( "Account set..." . PHP_EOL );
		$accountDetail = new AccountDetail ();
		$accountDetail->exchangeArray ( array (
				'account_id' => $account_id,
				'first_name' => $message_data ['first_name'],
				'last_name' => $message_data ['last_name'],
				'address_line_1' => $message_data ['address_line_1'],
				'address_line_2' => $message_data ['address_line_2'],
				'city' => $message_data ['city'],
				'state' => $message_data ['state'],
				'zip_code' => $message_data ['zip_code'],
				'postal_code' => $message_data ['zip_code']
		) );
		$account_detail_id = $memreas_paypal_tables->getAccountDetailTable ()->saveAccountDetail ( $accountDetail );
		error_log ( "Account_Detail set..." . PHP_EOL );

		// Store the transaction that is sent to PayPal
		$now = date ( 'Y-m-d H:i:s' );
		$transaction = new Memreas_Transaction ();

		// Copy the card and obfuscate the card number before storing the transaction
		$obfuscated_card = json_decode ( $card->toJSON (), true );
		$obfuscated_card ['number'] = $this->obfuscateAccountNumber ( $obfuscated_card ['number'] );

		$transaction->exchangeArray ( array (
				'account_id' => $account_id,
				'transaction_type' => 'store_credit_card',
				'transaction_request' => json_encode ( $obfuscated_card ),
				'transaction_sent' => $now
		) );

		$transaction_id = $memreas_paypal_tables->getTransactionTable ()->saveTransaction ( $transaction );
		error_log ( "transaction set..." . PHP_EOL );

		try {
			$card->create ( $api_context );
		} catch ( PPConnectionException $ex ) {
			echo "Exception:" . $ex->getMessage () . PHP_EOL;
			var_dump ( $ex->getData () );
			exit ( 1 );
		}

		// Update the transaction table with the PayPal response
		// $transaction = new Transaction();

		$transaction->exchangeArray ( array (
				'transaction_id' => $transaction_id,
				'pass_fail' => 1,
				'transaction_response' => $card->toJSON (),
				'transaction_receive' => $now
		) );
		$transactionid = $memreas_paypal_tables->getTransactionTable ()->saveTransaction ( $transaction );

		// $accountDetail = new AccountDetail();
		$accountDetail->exchangeArray ( array (
				'account_detail_id' => $account_detail_id,
				'paypal_card_reference_id' => $card->getId ()
		) );
		$account_detail_id = $memreas_paypal_tables->getAccountDetailTable ()->saveAccountDetail ( $accountDetail );

		// Add the new payment method
		$payment_method = new PaymentMethod ();
		$payment_method->exchangeArray ( array (
				'account_id' => $account_id,
				'paypal_card_reference_id' => $card->getId (),
				'card_type' => $card->getType (),
				'obfuscated_card_number' => $card->getNumber (),
				'exp_month' => $card->getExpireMonth (),
				'exp_year' => $card->getExpireYear (),
				'valid_until' => $card->getValidUntil (),
				'create_time' => $now,
				'update_time' => $now
		) );
		$payment_method_id = $memreas_paypal_tables->getPaymentMethodTable ()->savePaymentMethod ( $payment_method );

		// Return a success message:
		$result = array (
				"Status" => "Success",
				"paypal_card_id" => $card->getId (),
				"account_id" => $account_id,
				"account_detail_id" => $account_detail_id,
				"transaction_id" => $transactionid,
				"payment_method_id" => $payment_method_id
		);

		return $result;
	}
	public function paypalAddValue($message_data, $memreas_paypal_tables, $service_locator) {

		// Return Message
		$return_message = array ();

		// Fetch Session
		$this->fetchSession ();

		// Fetch PayPal credential
		$credential = $this->fetchPayPalCredential ( $service_locator );
		// Setup an api context for the card
		$api_context = new ApiContext ( $credential );

		// Get the data from the form
		$paypal_card_reference_id = $message_data ['paypal_card_reference_id'];
		$amount = $message_data ['amount'];

		// Fetch the address
		$account_detail = $memreas_paypal_tables->getAccountDetailTable ()->getAccountDetailByPayPalReferenceId ( $paypal_card_reference_id );
		$credit_card = CreditCard::get ( $paypal_card_reference_id );

		// Must use card token for stored cards.
		$credit_card_token = new CreditCardToken ();
		$credit_card_token->setCreditCardId ( $credit_card->getId () );
		$credit_card_token->setPayerId ( $this->user_id );

		// Set the funding instrument (credit card)
		$fi = new FundingInstrument ();
		$fi->setCreditCardToken ( $credit_card_token );

		// Set the Payer
		$payer = new Payer ();
		$payer->setPayment_method ( 'credit_card' );
		$payer->setFunding_instruments ( array (
				$fi
		) );

		// Set the amount details.
		$details = new Details ();
		$details->setShipping ( '0.00' );
		$details->setSubtotal ( $amount );
		$details->setTax ( '0.00' );
		$details->setFee ( '0.00' );

		// Set the amount.
		$paypal_amount = new Amount ();
		$paypal_amount->setCurrency ( 'USD' );
		$paypal_amount->setTotal ( $amount );
		$paypal_amount->setDetails ( $details );

		$paypal_transaction = new PayPal_Transaction ();
		$paypal_transaction->setAmount ( $paypal_amount );
		$paypal_transaction->setDescription ( 'Adding $amount value to account' );

		$payment = new Payment ();
		$payment->setIntent ( 'sale' );
		$payment->setPayer ( $payer );
		$payment->setTransactions ( array (
				$paypal_transaction
		) );

		// Store the transaction before sending
		$now = date ( 'Y-m-d H:i:s' );
		$memreas_transaction = new Memreas_Transaction ();
		$memreas_transaction->exchangeArray ( array (
				'account_id' => $account_detail->account_id,
				'transaction_type' => 'add_value_to_account',
				'transaction_request' => $payment->toJSON (),
				'transaction_sent' => $now
		) );
		$transaction_id = $memreas_paypal_tables->getTransactionTable ()->saveTransaction ( $memreas_transaction );

		// ///////////////////////
		// PayPal Payment Request
		// ///////////////////////
		try {
			$payment->create ();
		} catch ( PPConnectionException $ex ) {
			$result = array (
					"Status" => "Error",
					"Description" => "PPConnectionException error has occurred"
			);
			return $result;
		}
		// error_log("Inside add value - paymnt ---> " . print_r($payment, true) . PHP_EOL);
		// $paypal_transactions = $payment->getTransactions();
		// error_log("Inside add value - paypal_transaction ---> " . print_r($paypal_transactions, true) . PHP_EOL);

		if (! isset ( $payment )) {
			// Something went wrong ... log the error and die
			$memreas_transaction->exchangeArray ( array (
					'transaction_id' => $transaction_id,
					'pass_fail' => 0,
					'amount' => $amount,
					// 'paypal_txn_id' => $payment->getId(), //--> fetches parent_payment_id
					'currency' => 'USD',
					'transaction_response' => json_encode ( $result ),
					'transaction_receive' => $now
			// Get Payer Id
			// 'paypal_payer_id' => $payment->getPayer()->getPayerInfo()->getPayerId(),
			// 'paypal_payer_email' => $payment->getPayer()->getPayerInfo()->getEmail(),
			// Store new transaction table fields with paypal fields
			// 'paypal_receiver_id' => $payment->getPayee()->payee_info()->getPayeeId();
			// 'paypal_receiver_email' => $payment->getPayee()->payee_info()->getPayeeEmail();
			// 'paypal_receiver_id' => $transaction->paypal_receiver_id,
			// 'paypal_fees' => $transaction->paypal_fees,
			// 'paypal_taxes' => $transaction->paypal_taxes,
			// 'paypal_txn_type' => $transaction->paypal_txn_type,
			// 'paypal_parent_payment_id' => $payment->getId(),
			// 'paypal_txn_id' => $sale->getId(),
			// 'paypal_ipn_track_id' => $transaction->paypal_ipn_track_id,
			// 'paypal_payment_status' => $transaction->paypal_payment_status,
						) );
			$transaction_id = $memreas_paypal_tables->getTransactionTable ()->saveTransaction ( $memreas_transaction );
			die ( "PPConnection error..." );
		}
		// Update the transaction table with the PayPal response
		// $transaction = new Transaction();
		$now = date ( 'Y-m-d H:i:s' );
		$transactions = $payment->getTransactions ();
		foreach ( $transactions as $transaction ) {
			$related_resources = $transaction->getRelatedResources ();
			foreach ( $related_resources as $related_resource ) {
				$sale = $related_resource->getSale ();
				// error_log("SALE ID: ".$sale->getId().PHP_EOL);
			}
		}

		// error_log("RESPONSE::payment->toJSON() -----> ".$payment->toJSON().PHP_EOL);
		$memreas_transaction->exchangeArray ( array (
				'transaction_id' => $transaction_id,
				'pass_fail' => 1,
				'amount' => $amount,
				// 'paypal_txn_id' => $payment->getId(), //--> fetches parent_payment_id
				'currency' => 'USD',
				'transaction_response' => $payment->toJSON (),
				'transaction_receive' => $now,

				// Get Payer Id
				// 'paypal_payer_id' => $payment->getPayer()->getPayerInfo()->getPayerId(),
				// 'paypal_payer_email' => $payment->getPayer()->getPayerInfo()->getEmail(),

				// Store new transaction table fields with paypal fields
				// 'paypal_receiver_id' => $payment->getPayee()->payee_info()->getPayeeId();
				// 'paypal_receiver_email' => $payment->getPayee()->payee_info()->getPayeeEmail();
				// 'paypal_receiver_id' => $transaction->paypal_receiver_id,
				// 'paypal_fees' => $transaction->paypal_fees,
				// 'paypal_taxes' => $transaction->paypal_taxes,
				// 'paypal_txn_type' => $transaction->paypal_txn_type,
				'paypal_parent_payment_id' => $payment->getId (),
				'paypal_txn_id' => $sale->getId ()
		// 'paypal_ipn_track_id' => $transaction->paypal_ipn_track_id,
		// 'paypal_payment_status' => $transaction->paypal_payment_status,
				)

		 );
		$transaction_id = $memreas_paypal_tables->getTransactionTable ()->saveTransaction ( $memreas_transaction );

		// Get the last balance
		$currentAccountBalance = $memreas_paypal_tables->getAccountBalancesTable ()->getAccountBalances ( $account_detail->account_id );
		// If no acount found set the starting balance to zero else use the ending balance.
		$starting_balance = (isset ( $currentAccountBalance )) ? $currentAccountBalance->ending_balance : '0.00';
		$ending_balance = $starting_balance + $amount;

		// Insert the new account balance
		$now = date ( 'Y-m-d H:i:s' );
		$endingAccountBalance = new AccountBalances ();
		$endingAccountBalance->exchangeArray ( array (
				'account_id' => $account_detail->account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "add_value_to_account",
				'starting_balance' => $starting_balance,
				'amount' => $amount,
				'ending_balance' => $ending_balance,
				'create_time' => $now
		) );
		$transaction_id = $memreas_paypal_tables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );

		// Update the account table
		$now = date ( 'Y-m-d H:i:s' );
		$account = $memreas_paypal_tables->getAccountTable ()->getAccount ( $account_detail->account_id );
		$account->exchangeArray ( array (
				'balance' => $ending_balance,
				'update_time' => $now
		) );
		$account_id = $memreas_paypal_tables->getAccountTable ()->saveAccount ( $account );

		// Add to the return message for debugging...
		$return_message [$account->username] ['description-->'] = "Amount added to account for user--> " . $account->username;
		$return_message [$account->username] ['starting_balance'] = $starting_balance;
		$return_message [$account->username] ['amount'] = $amount;
		$return_message [$account->username] ['ending_balance'] = $ending_balance;

		// //////////////////////////////////////////
		// Need to add value to memreas_float here
		// //////////////////////////////////////////
		$account_memreas_float = $memreas_paypal_tables->getAccountTable ()->getAccountByUserName ( MemreasConstants::ACCOUNT_MEMREAS_FLOAT );
		// Fetch the memreas_float account balance
		$current_account_balances_memreas_float = $memreas_paypal_tables->getAccountBalancesTable ()->getAccountBalances ( $account_memreas_float->account_id );

		// Get the last balance
		// $currentAccountBalance = $memreas_paypal_tables->getAccountBalancesTable()->getAccountBalances($account_detail->account_id);
		// If no acount found set the starting balance to zero else use the ending balance.
		$starting_balance = (isset ( $current_account_balances_memreas_float )) ? $current_account_balances_memreas_float->ending_balance : '0.00';
		$ending_balance = $starting_balance + $amount;

		// Insert the new account balance
		$now = date ( 'Y-m-d H:i:s' );
		$endingAccountBalance = new AccountBalances ();
		$endingAccountBalance->exchangeArray ( array (
				'account_id' => $account_memreas_float->account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "add_value_to_account",
				'starting_balance' => $starting_balance,
				'amount' => $amount,
				'ending_balance' => $ending_balance,
				'create_time' => $now
		) );

		$transaction_id = $memreas_paypal_tables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );

		// Update the account table
		$now = date ( 'Y-m-d H:i:s' );
		$account_memreas_float->exchangeArray ( array (
				'balance' => $ending_balance,
				'update_time' => $now
		) );
		$account_id = $memreas_paypal_tables->getAccountTable ()->saveAccount ( $account_memreas_float );

		// Add to the return message for debugging...
		$return_message [$account_memreas_float->username] ['description-->'] = "Amount added to account for user--> " . $account_memreas_float->username;
		$return_message [$account_memreas_float->username] ['starting_balance'] = $starting_balance;
		$return_message [$account_memreas_float->username] ['amount'] = $amount;
		$return_message [$account_memreas_float->username] ['ending_balance'] = $ending_balance;

		// Return a success message:
		$result = array (
				"Status" => "Success",
				"Description" => $return_message
		// "starting_balance"=>$starting_balance,
		// "amount"=>$amount,
		// "ending_balance"=>$ending_balance,
				);
		return $result;
	}

	public function paypalDecrementValue($message_data, $memreas_paypal_tables, $service_locator) {

		// Return Message
		$return_message = "";

		// Fetch Session
		$this->fetchSession ();
		// Get the data from the form
		$seller = $message_data ['seller'];
		$memreas_master = $message_data ['memreas_master'];
		$amount = $message_data ['amount'];

		try {
			// /////////////////////////////////////////////
			// Start a transaction because this is internal
			// /////////////////////////////////////////////
			$dbAdapter = $service_locator->get ( MemreasConstants::MEMREASDB );
			$connection = $dbAdapter->getDriver ()->getConnection ();
			$connection->beginTransaction ();

			// ////////////////////////
			// Fetch the Buyer Account
			// ////////////////////////
			$account = $memreas_paypal_tables->getAccountTable ()->getAccountByUserId ( $this->user_id );
			if (! $account) {
				error_log ( "Inside decrementValue getAccountByUserId failed ---> Rolling back" . PHP_EOL );
				$connection->rollback ();
				$result = array (
						"Status" => "Error",
						"Description" => "Could not find account"
				);
				return $result;
			}
			$account_id = $account->account_id;

			error_log ( "Fetched buyer account ----> " . $this->username . PHP_EOL );

			// Decrement the user's account - they must have a positive balance.
			// Fetch Account_Balances
			$currentAccountBalance = $memreas_paypal_tables->getAccountBalancesTable ()->getAccountBalances ( $account_id );

			// If no acount found thrown an error ... buyer must have a positive balance
			/*
			 * TODO: Add in logic to check for positive balance.
			 */
			if (! isset ( $currentAccountBalance ) || ($currentAccountBalance->ending_balance <= 0)) {
				error_log ( "Inside decrementValue getAccountBalances failed ---> Rolling back" . PHP_EOL );
				$connection->rollback ();
				$result = array (
						"Status" => "Error",
						"Description" => "Account not found or does not have sufficient funds."
				);
				return $result;
			}

			// Log the transaction
			$now = date ( 'Y-m-d H:i:s' );
			$memreas_transaction = new Memreas_Transaction ();
			$memreas_transaction->exchangeArray ( array (
					'account_id' => $account_id,
					'transaction_type' => 'decrement_value_from_account',
					'pass_fail' => 1,
					'amount' => "-$amount",
					'currency' => 'USD',
					'transaction_request' => "N/a",
					'transaction_sent' => $now,
					'transaction_response' => "N/a",
					'transaction_receive' => $now
			) );
			$transaction_id = $memreas_paypal_tables->getTransactionTable ()->saveTransaction ( $memreas_transaction );

			// Decrement the account
			$starting_balance = $currentAccountBalance->ending_balance;
			$ending_balance = $starting_balance - $amount;

			// Insert the new account balance
			$now = date ( 'Y-m-d H:i:s' );
			$endingAccountBalance = new AccountBalances ();
			$endingAccountBalance->exchangeArray ( array (
					'account_id' => $account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "decrement_value_from_account",
					'starting_balance' => $starting_balance,
					'amount' => - $amount,
					'ending_balance' => $ending_balance,
					'create_time' => $now
			) );
			$transaction_id = $memreas_paypal_tables->getAccountBalancesTable ()->saveAccountBalances ( $endingAccountBalance );

			// Update the account table
			$now = date ( 'Y-m-d H:i:s' );
			$account = $memreas_paypal_tables->getAccountTable ()->getAccount ( $account_id );
			$account->exchangeArray ( array (
					'balance' => $ending_balance,
					'update_time' => $now
			) );
			$account_id = $memreas_paypal_tables->getAccountTable ()->saveAccount ( $account );

			// Add to the return message for debugging...
			$return_message [$account->username] ['description-->'] = "Amount added to account for user--> " . $account->username;
			$return_message [$account->username] ['starting_balance'] = $starting_balance;
			$return_message [$account->username] ['amount'] = - $amount;
			$return_message [$account->username] ['ending_balance'] = $ending_balance;

			// ////////////////////////
			// Fetch the Seller Account
			// ////////////////////////
			$seller_user = $memreas_paypal_tables->getUserTable ()->getUserByUsername ( $seller );
			$seller_user_id = $seller_user->user_id;
			$seller_account = $memreas_paypal_tables->getAccountTable ()->getAccountByUserId ( $seller_user_id );
			if (! $seller_account) {
				error_log ( "Inside decrementValue Seller getAccountByUserId failed ---> Rolling back" . PHP_EOL );
				$connection->rollback ();
				$result = array (
						"Status" => "Error",
						"Description" => "Could not find seller account"
				);
				return $result;
			}
			$seller_account_id = $seller_account->account_id;

			error_log ( "Fetched seller account ----> " . $seller_user->username . PHP_EOL );

			// Increment the seller's account by 80% of the purchase
			// Fetch Account_Balances
			$currentAccountBalance = $memreas_paypal_tables->getAccountBalancesTable ()->getAccountBalances ( $seller_account_id );

			// Log the transaction
			$now = date ( 'Y-m-d H:i:s' );
			$memreas_transaction = new Memreas_Transaction ();
			$seller_amount = $amount * 0.8;
			$memreas_master_amount = $amount - $seller_amount;

			$memreas_transaction->exchangeArray ( array (
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
			$transaction_id = $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

			//Increment the account
			//If no acount found set the starting balance to zero else use the ending balance.
			$starting_balance = (isset($currentAccountBalance)) ? $currentAccountBalance->ending_balance : '0.00';
			$ending_balance = $starting_balance + $seller_amount;

			//Insert the new account balance
			$now = date('Y-m-d H:i:s');
			$endingAccountBalance = new AccountBalances();
			$endingAccountBalance->exchangeArray(array(
				'account_id' => $seller_account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "increment_value_to_account",
				'starting_balance' => $starting_balance,
				'amount' => "$seller_amount",
				'ending_balance' => $ending_balance,
				'create_time' => $now,
				));
			$transaction_id = $memreas_paypal_tables->getAccountBalancesTable()->saveAccountBalances($endingAccountBalance);

			//Update the account table
			$now = date('Y-m-d H:i:s');
			$account = $memreas_paypal_tables->getAccountTable()->getAccount($seller_account_id);
			$account->exchangeArray(array(
				'balance' => $ending_balance,
				'update_time' => $now,
				));
			$account_id = $memreas_paypal_tables->getAccountTable()->saveAccount($account);

			//Add to the return message for debugging...
			$return_message[$seller_user->username]['description-->'] = "Amount added to account for user--> ".$seller_user->username;
			$return_message[$seller_user->username]['starting_balance'] = $starting_balance;
			$return_message[$seller_user->username]['amount'] = $seller_amount;
			$return_message[$seller_user->username]['ending_balance'] = $ending_balance;

			//////////////////////////////////
			//Fetch the memreasmaster account
			//////////////////////////////////
			$memreas_master_user = $memreas_paypal_tables->getUserTable()->getUserByUsername(MemreasConstants::ACCOUNT_MEMREAS_MASTER);
			$memreas_master_user_id = $memreas_master_user->user_id;
			$memreas_master_account = $memreas_paypal_tables->getAccountTable()->getAccountByUserId($memreas_master_user_id);
			if (!$memreas_master_account) {
error_log("Inside decrementValue memreas_master_account getAccountByUserId failed ---> Rolling back" . PHP_EOL);
				$connection->rollback();
				$result = array ( "Status"=>"Error", "Description"=>"Could not find memreas_master account", );
				return $result;
			}
			$memreas_master_account_id = $memreas_master_account->account_id;

	error_log("Fetched memreas_master account ----> " . $memreas_master_user->username . PHP_EOL);

			//Increment the memreas_master account by 20% of the purchase
			//Fetch Account_Balances
			$currentAccountBalance = $memreas_paypal_tables->getAccountBalancesTable()->getAccountBalances($memreas_master_account_id);
			//Log the transaction
			$now = date('Y-m-d H:i:s');
			$memreas_transaction  = new Memreas_Transaction;

			$memreas_transaction->exchangeArray(array(
					'account_id'=>$memreas_master_account_id,
					'transaction_type' =>'increment_value_to_account',
					'pass_fail' => 1,
					'amount' => $memreas_master_amount,
					'currency' => 'USD',
					'transaction_request' => "N/a",
					'transaction_sent' =>$now,
					'transaction_response' => "N/a",
					'transaction_receive' =>$now,
			));
			$transaction_id = $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

			//Increment the account
			//If no acount found set the starting balance to zero else use the ending balance.
			$starting_balance = (isset($currentAccountBalance)) ? $currentAccountBalance->ending_balance : '0.00';
			$ending_balance = $starting_balance + $memreas_master_amount;

			//Insert the new account balance
			$now = date('Y-m-d H:i:s');
			$endingAccountBalance = new AccountBalances();
			$endingAccountBalance->exchangeArray(array(
				'account_id' => $memreas_master_account_id,
				'transaction_id' => $transaction_id,
				'transaction_type' => "increment_value_to_account",
				'starting_balance' => $starting_balance,
				'amount' => "$memreas_master_amount",
				'ending_balance' => $ending_balance,
				'create_time' => $now,
				));
			$transaction_id = $memreas_paypal_tables->getAccountBalancesTable()->saveAccountBalances($endingAccountBalance);

			//Update the account table
			$now = date('Y-m-d H:i:s');
			$account = $memreas_paypal_tables->getAccountTable()->getAccount($memreas_master_account_id);
			$account->exchangeArray(array(
				'balance' => $ending_balance,
				'update_time' => $now,
				));
			$account_id = $memreas_paypal_tables->getAccountTable()->saveAccount($account);

			//Add to the return message for debugging...
			$return_message[$memreas_master_user->username]['description-->'] = "Amount added to account for user--> ".$memreas_master_user->username;
			$return_message[$memreas_master_user->username]['starting_balance'] = $starting_balance;
			$return_message[$memreas_master_user->username]['amount'] = $memreas_master_amount;
			$return_message[$memreas_master_user->username]['ending_balance'] = $ending_balance;

			//////////////////////////////////
			//Fetch the memreasfloat account
			//////////////////////////////////
			$memreas_float_user = $memreas_paypal_tables->getUserTable()->getUserByUsername(MemreasConstants::ACCOUNT_MEMREAS_FLOAT);
			$memreas_float_user_id = $memreas_float_user->user_id;
			$memreas_float_account = $memreas_paypal_tables->getAccountTable()->getAccountByUserId($memreas_float_user_id);
			if (!$memreas_float_account) {
error_log("Inside decrementValue memreas_float_account getAccountByUserId failed ---> Rolling back" . PHP_EOL);
				$connection->rollback();
				$result = array ( "Status"=>"Error", "Description"=>"Could not find memreas_float account", );
				return $result;
			}
			$memreas_float_account_id = $memreas_float_account->account_id;

error_log("Fetched memreas_float account ----> " . $memreas_float_user->username . PHP_EOL);

			//Decrement the memreas_float account by 100% of the purchase
			//Fetch Account_Balances
			$currentAccountBalance = $memreas_paypal_tables->getAccountBalancesTable()->getAccountBalances($memreas_float_account_id);
			//Log the transaction
			$now = date('Y-m-d H:i:s');
			$memreas_transaction  = new Memreas_Transaction;

			$memreas_transaction->exchangeArray(array(
					'account_id'=>$memreas_float_account_id,
					'transaction_type' =>'decrement_value_to_account',
					'pass_fail' => 1,
					'amount' => "-$amount",
					'currency' => 'USD',
					'transaction_request' => "N/a",
					'transaction_sent' =>$now,
					'transaction_response' => "N/a",
					'transaction_receive' =>$now,
			));
			$transaction_id = $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

			//Decrement the account - account being decremented shouldn't be zero...
			//If no acount found set the starting balance to zero else use the ending balance.
			$starting_balance = (isset($currentAccountBalance)) ? $currentAccountBalance->ending_balance : '0.00';
			$ending_balance = $starting_balance - $amount;

			//Insert the new account balance
			$now = date('Y-m-d H:i:s');
			$endingAccountBalance = new AccountBalances();
			$endingAccountBalance->exchangeArray(array(
					'account_id' => $memreas_float_account_id,
					'transaction_id' => $transaction_id,
					'transaction_type' => "decrement_value_to_account",
					'starting_balance' => $starting_balance,
					'amount' => -$amount,
					'ending_balance' => $ending_balance,
					'create_time' => $now,
			));
			$transaction_id = $memreas_paypal_tables->getAccountBalancesTable()->saveAccountBalances($endingAccountBalance);

			//Update the account table
			$now = date('Y-m-d H:i:s');
			$account = $memreas_paypal_tables->getAccountTable()->getAccount($memreas_float_account_id);
			$account->exchangeArray(array(
					'balance' => $ending_balance,
					'update_time' => $now,
			));
			$account_id = $memreas_paypal_tables->getAccountTable()->saveAccount($account);

			///////////////////////////////////////////////
			// Commit the transaction ... chaching :)
			///////////////////////////////////////////////
			error_log("Inside decrementValue Committing the Transaction!" . PHP_EOL);
			$connection->commit();

			//Add to the return message for debugging...
			$return_message[$memreas_float_user->username]['description-->'] = "Amount added to account for user--> ".$memreas_float_user->username;
			$return_message[$memreas_float_user->username]['starting_balance'] = $starting_balance;
			$return_message[$memreas_float_user->username]['amount'] = -$amount;
			$return_message[$memreas_float_user->username]['ending_balance'] = $ending_balance;

			//Return a success message:
			$result = array (
				"Status"=>"Success",
				"Description"=>$return_message
				);
			return $result;
		} catch (\Exception $e) {
			if ($connection instanceof \Zend\Db\Adapter\Driver\ConnectionInterface) {
error_log("Inside decrementValue Exception ---> Rolling back" . PHP_EOL);
				$connection->rollback();
			}
		}

	}

	public function paypalDeleteCards($message_data, $memreas_paypal_tables, $service_locator) {

		//Fetch Session
		$this->fetchSession();

		//Fetch PayPal credential
		$credential = $this->fetchPayPalCredential($service_locator);
		//Setup an api context for the card
		$api_context = new ApiContext($credential);

		//Delete the card at PayPal and update the database
		$arr = array();
		foreach($message_data as $card) {
			$creditCard = CreditCard::get($card, $api_context);
			try {
				$creditCard->delete($api_context);
				$arr[] = "$card";
				//$row = $memreas_paypal_tables->getPaymentMethodTable()->getPaymentMethodByPayPalReferenceId($card);
				//Delete Payment Method Table
				$memreas_paypal_tables->getPaymentMethodTable()->deletePaymentMethodByPayPalCardReferenceId($card);
				//Delete Account Detail Table (associated billing address)
				$memreas_paypal_tables->getAccountDetailTable()->deleteAccountDetailByPayPalCardReferenceId($card);
			} catch (\PPConnectionException $ex) {
	  			$result = array (
					"Status"=>"Error",
					"Description"=>$ex->getMessage()
				);
			  return $result;
			}
		}

		$result = array (
			"Status"=>"Success",
			"Description"=>"Deleted the following cards at PayPal",
			"Deleted_Cards"=>$arr,
			);

		return $result;
	}

	public function paypalListCards($message_data, $memreas_paypal_tables, $service_locator) {

		//Fetch Session
		$this->fetchSession();

		//Fetch the user_id
	    if($this->session->offsetExists('user_id')){

			$this->user_id = $this->session->offsetGet('user_id');
			//Fetch the users's list of cards from the database
			$payment_method = new PaymentMethod();

			$rowset = $memreas_paypal_tables->getPaymentMethodTable()->getPaymentMethodsByUserId($this->user_id);
			//$rowset = $memreas_paypal_tables->getPaymentMethodTable()->getPaymentMethodByUserId($account_id);
			$rowCount = count($rowset);
			$payment_methods = array();
			foreach ($rowset as $row) {

				//Payment Method Details
				$payment_method_result = array();
				$payment_method_result['payment_method_id'] = $row['payment_method_id'];
				$payment_method_result['account_id'] = $row['account_id'];
				$payment_method_result['user_id'] = $row['user_id'];
				$payment_method_result['paypal_card_reference_id'] = $row['paypal_card_reference_id'];
				$payment_method_result['card_type'] = $row['card_type'];
				$payment_method_result['obfuscated_card_number'] = $row['obfuscated_card_number'];
				$payment_method_result['exp_month'] = $row['exp_month'];
				$payment_method_result['valid_until'] = $row['valid_until'];

				//Address Details
				$account_detail = $memreas_paypal_tables->getAccountDetailTable()->getAccountDetailByPayPalReferenceId($row['paypal_card_reference_id']);
				$payment_method_result['first_name'] = $account_detail->first_name;
				$payment_method_result['last_name'] = $account_detail->last_name;
				$payment_method_result['address_line_1'] = $account_detail->address_line_1;
				$payment_method_result['address_line_2'] = $account_detail->address_line_2;
				$payment_method_result['city'] = $account_detail->city;
				$payment_method_result['state'] = $account_detail->state;
				$payment_method_result['zip_code'] = $account_detail->zip_code;

				$payment_methods[] = $payment_method_result;
			}

 			$str="";
 			$status="Error";
			if ($rowCount > 0) {
			    $str = "found $rowCount rows";
			    $status = "Success";
			} else {
			    $str = 'no rows matched the query';
			}

			//Return a success message:
			$result = array (
				"Status"=>$status,
				"NumRows"=>"$str",
				"payment_methods"=>$payment_methods,
				);
			return $result;
		}
		//Return an error message:
		$result = array (
			"Status"=>"Error",
			"Description"=>"$user_id not found"
			);
		return $result;
	}

	public function payPalAddSeller($message_data, $memreas_paypal_tables, $service_locator) {
		//Fetch Session
		$this->fetchSession();
		//Get memreas user name
		$user_name = $message_data['user_name'];
		$user = $memreas_paypal_tables->getUserTable()->getUserByUsername($user_name);
		//Get Paypal email address
		$paypal_email_address = $message_data['paypal_email_address'];
		//Get the Billing Address and associate it with the card
		$billing_address = new Address();
		$billing_address->setLine1($message_data['address_line_1']);
		$billing_address->setLine2($message_data['address_line_2']);
		$billing_address->setCity($message_data['city']);
		$billing_address->setState($message_data['state']);
		$billing_address->setPostalCode($message_data['zip_code']);
		$billing_address->setPostal_code($message_data['zip_code']);
		$billing_address->setCountryCode("US");
		$billing_address->setCountry_code("US");

		//Fetch the Account
		$row = $memreas_paypal_tables->getAccountTable()->getAccountByUserId($user->user_id);
		if (!$row) {
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
			$account_id =  $memreas_paypal_tables->getAccountTable()->saveAccount($account);
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
			'first_name'=>$message_data['first_name'],
			'last_name'=>$message_data['last_name'],
			'address_line_1'=>$message_data['address_line_1'],
			'address_line_2'=>$message_data['address_line_2'],
			'city'=>$message_data['city'],
			'state'=>$message_data['state'],
			'zip_code'=>$message_data['zip_code'],
			'postal_code'=>$message_data['zip_code'],
			'paypal_email_address'=>$message_data['paypal_email_address'],
			));
		$account_detail_id = $memreas_paypal_tables->getAccountDetailTable()->saveAccountDetail($accountDetail);

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
		$transaction_id =  $memreas_paypal_tables->getTransactionTable()->saveTransaction($transaction);

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
		$account_balances_id =  $memreas_paypal_tables->getAccountBalancesTable()->saveAccountBalances($account_balances);

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

	public function payPalListMassPayee($message_data, $memreas_paypal_tables, $service_locator) {

		//Fetch Session
		//$this->fetchSession();

		//Fetch the list of seller's due payment
		$rowset = $memreas_paypal_tables->getAccountTable()->listMassPayee();

		$account_mass_payees = array();
		$rowCount = count($rowset);
		foreach ($rowset as $row) {
				$account_mass_payee = array();
				$account_mass_payee['account_id'] = $row->account_id;
				$account_mass_payee['user_id'] = $row->user_id;
				$account_mass_payee['username'] = $row->username;
				$account_mass_payee['account_type'] = $row->account_type;
				$account_mass_payee['balance'] = $row->balance;
				$account_mass_payees[] = $account_mass_payee;
		}

		$str="";
		$status="Error";
		if ($rowCount > 0) {
			$str = "found $rowCount rows";
			$status = "Success";

			//Return a success message:
			$result = array (
				"Status"=>$status,
				"NumRows"=>"$str",
				"accounts"=>$account_mass_payees,
				);
		} else {
			$str = 'no rows matched the query';
			//Return an error message:
			$result = array (
				"Status"=>"Error",
				"Description"=>$str
				);
		}
		return $result;
	}

	public function paypalPayoutMassPayees($message_data, $memreas_paypal_tables, $service_locator) {
		//Fetch PayPal credential
		$credential = $this->fetchPayPalCredential($service_locator);

		//setup the mass pay
		$massPayRequest = new MassPayRequestType();
		$massPayRequest->MassPayItem = array();

		//Setup vars and loop through post
		$payouts = array();
		$account_mass_payees = array();
		$rowCount = count($message_data);
		$memreas_masspay_txn_id = uniqid("memreas",true);
//error_log("memreas_masspay_txn_id ---->$memreas_masspay_txn_id".PHP_EOL);
		foreach($message_data as $account_id) {
			//Fetch the account data
			$account = $memreas_paypal_tables->getAccountTable()->getAccount($account_id);
			$account_detail = $memreas_paypal_tables->getAccountDetailTable()->getAccountDetailByAccount($account_id);
			try {
				$account_mass_payee = array();
				$account_mass_payee['account_id'] = $account->account_id;
				$account_mass_payee['user_id'] = $account->user_id;
				$account_mass_payee['username'] = $account->username;
				$account_mass_payee['account_type'] = $account->account_type;
				$account_mass_payee['balance'] = $account->balance;
				$account_mass_payee['paypal_email_address'] = $account_detail->paypal_email_address;
				$account_mass_payee['paypal_email_address'] = $account_detail->paypal_email_address;
				$account_mass_payees[] = $account_mass_payee;

error_log("account_mass_payee['account_id'] ----> " . $account_mass_payee['account_id'] . PHP_EOL);
error_log("account_mass_payee['user_id'] ----> " . $account_mass_payee['user_id'] . PHP_EOL);
error_log("account_mass_payee['username'] ----> " . $account_mass_payee['username'] . PHP_EOL);
error_log("account_mass_payee['account_type'] ----> " . $account_mass_payee['account_type'] . PHP_EOL);
error_log("account_mass_payee['balance'] ----> " . $account_mass_payee['balance'] . PHP_EOL);
error_log("account_mass_payee['paypal_email_address'] ----> " . $account_mass_payee['paypal_email_address'] . PHP_EOL);
				//Record the transaction
				//TODO

				//Create a mass pay item for this account
				$masspayItem = new MassPayRequestItemType();
				$masspayItem->Amount = new BasicAmountType("USD", $account_mass_payee['balance']);
				$masspayItem->ReceiverEmail = $account_mass_payee['paypal_email_address'];
				$masspayItem->UniqueId = $memreas_masspay_txn_id;
				$massPayRequest->MassPayItem[] = $masspayItem;
//error_log("massPayItem added for ----> " . $account_mass_payee['paypal_email_address'] . PHP_EOL);
			} catch (\PPConnectionException $ex) {
	  			$result = array (
					"Status"=>"Error",
					"Description"=>$ex->getMessage()
				);
			  return $result;
			}
		}

		//Send out the group as a batch...
		$massPayReq = new MassPayReq();
		$massPayReq->MassPayRequest = $massPayRequest;
		$paypalService = new PayPalAPIInterfaceServiceService(PayPalConfig::getAcctAndConfig());
//error_log("PayPalConfig::getAcctAndConfig() ----> " . print_r(PayPalConfig::getAcctAndConfig(),true) . PHP_EOL);

		//Store the transaction before sending
		$now = date('Y-m-d H:i:s');
		$memreas_transaction  = new Memreas_Transaction;
		$memreas_transaction->exchangeArray(array(
				'account_id'=>$account_detail->account_id,
				'transaction_type' =>'masspay_payout',
				'transaction_request' => json_encode($massPayReq),
				'paypal_txn_id' => $memreas_masspay_txn_id,
				'transaction_sent' =>$now
		));
		$transaction_id =  $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

		/////////////////////////
		// PayPal Payment Request
		/////////////////////////
		try {
				$massPayResponse = $paypalService->MassPay($massPayReq);
		} catch (Exception $ex) {
			//Update the transaction table with error
			$now = date('Y-m-d H:i:s');
			$memreas_transaction->exchangeArray(array(
					'transaction_id' => $transaction_id,
					'pass_fail' => 0,
					'amount' => -1, //mass pay amount n/a
					'currency' => 'USD',
					'transaction_response' => $ex->getMessage(),
					'transaction_receive' =>$now
			));
			$transaction_id = $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

			$result = array (
				"Status"=>"Error",
				"Description"=>$ex->getMessage()
			);
		  return $result;
		}

		//Update the transaction table with the PayPal response
		//$transaction  = new Transaction();
		$now = date('Y-m-d H:i:s');
		$memreas_transaction->exchangeArray(array(
				'transaction_id' => $transaction_id,
				'pass_fail' => 1,
				'amount' => -1, //mass pay amount n/a
				'currency' => 'USD',
				'paypal_parent_payment_id' => $massPayResponse->CorrelationID,
				'transaction_response' => json_encode($massPayResponse),
				'transaction_receive' => $now
		));
error_log("About to print Mass Pay resonse.-----> ".json_encode($massPayResponse).PHP_EOL);
		$transaction_id = $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

		$result = array (
			"Status"=>"Success",
			"Description"=>"Payouts Data",
			"Payouts"=> $payouts,
			"massPayResponse"=> $massPayResponse,
			);

		return $result;
	}

	public function ipnListener($message_data, $memreas_paypal_tables, $service_locator) {
error_log("Inside ipnListener....");

		// CONFIG: Enable debug mode. This means we'll log requests into 'ipn.log' in the same directory.
		// Especially useful if you encounter network errors or other intermittent problems with IPN (validation).
		// Set this to 0 once you go live or don't require logging.
		define("DEBUG", 0);

		// Set to 0 once you're ready to go live
		define("USE_SANDBOX", 1);
		//define("LOG_FILE", "./ipn.log");
		define("LOG_FILE", "./php_errors.log");


		// Read POST data
		// reading posted data directly from $_POST causes serialization
		// issues with array data in POST. Reading raw POST data from input stream instead.
		$raw_post_data = file_get_contents('php://input');
		$raw_post_array = explode('&', $raw_post_data);
		$myPost = array();
		foreach ($raw_post_array as $keyval) {
			$keyval = explode ('=', $keyval);
			if (count($keyval) == 2) {
				$myPost[$keyval[0]] = urldecode($keyval[1]);
			}
		}

		// read the post from PayPal system and add 'cmd'
		$req = 'cmd=_notify-validate';
		if(function_exists('get_magic_quotes_gpc')) {
			$get_magic_quotes_exists = true;
		}
		foreach ($myPost as $key => $value) {
			if($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) {
				$value = urlencode(stripslashes($value));
			} else {
				$value = urlencode($value);
			}
			$req .= "&$key=$value";
		}

		// Post IPN data back to PayPal to validate the IPN data is genuine
		// Without this step anyone can fake IPN data

		if(USE_SANDBOX == true) {
			$paypal_url = "https://www.sandbox.paypal.com/cgi-bin/webscr";
		} else {
			$paypal_url = "https://www.paypal.com/cgi-bin/webscr";
		}

		$ch = curl_init($paypal_url);
		if ($ch == FALSE) {
			return FALSE;
		}

		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);

		if(DEBUG == true) {
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
		}

		// CONFIG: Optional proxy configuration
		//curl_setopt($ch, CURLOPT_PROXY, $proxy);
		//curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1);

		// Set TCP timeout to 30 seconds
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

		// CONFIG: Please download 'cacert.pem' from "http://curl.haxx.se/docs/caextract.html" and set the directory path
		// of the certificate as shown below. Ensure the file is readable by the webserver.
		// This is mandatory for some environments.

		//$cert = __DIR__ . "./cacert.pem";
		//curl_setopt($ch, CURLOPT_CAINFO, $cert);

		$res = curl_exec($ch);
		if (curl_errno($ch) != 0) // cURL error
		{
			if(DEBUG == true) {
				error_log(date('[Y-m-d H:i e] '). "Can't connect to PayPal to validate IPN message: " . curl_error($ch) . PHP_EOL, 3, LOG_FILE);
			}
			curl_close($ch);
			exit;

		} else {
			// Log the entire HTTP response if debug is switched on.
			if(DEBUG == true) {
				error_log(date('[Y-m-d H:i e] '). "HTTP request of validation request:". curl_getinfo($ch, CURLINFO_HEADER_OUT) ." for IPN payload: $req" . PHP_EOL, 3, LOG_FILE);
				error_log(date('[Y-m-d H:i e] '). "HTTP response of validation request: $res" . PHP_EOL, 3, LOG_FILE);

				// Split response headers and payload
				list($headers, $res) = explode("\r\n\r\n", $res, 2);
			}
			curl_close($ch);
		}

		// Inspect IPN validation result and act accordingly

		if (strcmp ($res, "VERIFIED") == 0) {
			// check whether the payment_status is Completed
			// check that txn_id has not been previously processed
			// check that receiver_email is your PayPal email
			// check that payment_amount/payment_currency are correct
			// process payment and mark item as paid.
//error_log("Inside ipnListener VERIFIED" . PHP_EOL);
//error_log("Inside ipnListener txn_type----> ".$_POST['txn_type'].PHP_EOL);

			//Acknowledge the IPN message
			ob_start();
			http_response_code(200);
			ob_end_flush(); 	// Strange behaviour, will not work
			flush();            // Unless both are called !
			//Now process the message...

			/*
			 * Sale Section
			 */
			if ($_POST['txn_type'] == 'web_accept') {
error_log("Inside ipnListener web_accept" . PHP_EOL);
				$transaction_type = "add_value_to_account_ipn";
				// assign posted variables to local variables
				//$item_name = (isset($_POST['item_name'])) ?  $_POST['item_name'] : "N/a";
				//$item_number = (isset($_POST['item_number'])) ?  $_POST['item_number'] : "N/a";
				$payer_id = (isset($_POST['payer_id'])) ?  $_POST['payer_id'] : "N/a";
				$payer_email = (isset($_POST['payer_email'])) ?  $_POST['payer_email'] : "N/a";
				$receiver_id = (isset($_POST['receiver_id'])) ?  $_POST['receiver_id'] : "N/a";
				$receiver_email = (isset($_POST['receiver_email'])) ?  $_POST['receiver_email'] : "N/a";

				$payment_status = (isset($_POST['payment_status'])) ?  $_POST['payment_status'] : "N/a";
				$payment_amount = (isset($_POST['mc_gross'])) ?  $_POST['mc_gross'] : '0';
				$payment_currency = (isset($_POST['mc_currency'])) ?  $_POST['mc_currency'] : "USD";
				$payment_tax = (isset($_POST['tax'])) ?  $_POST['tax'] : '0';
				$payment_fee = (isset($_POST['mc_fee'])) ?  $_POST['mc_fee'] : '0';

				$txn_type = (isset($_POST['txn_type'])) ?  $_POST['txn_type'] : "N/a";
				$txn_id = (isset($_POST['txn_id'])) ?  $_POST['txn_id'] : "N/a";
				$ipn_track_id = (isset($_POST['ipn_track_id'])) ?  $_POST['ipn_track_id'] : "N/a";

				//Fetch the original transaction
				$rowset = $memreas_paypal_tables->getTransactionTable()->getTransactionByPayPalTxnId($txn_id);
				if (!$rowset) {
					/*
					 * TODO - Need to throw exception here but store entry?
					 */
error_log("Inside ipnListener web_accept - didn't find row...".PHP_EOL);
				} else {
					$row = $rowset->current();
					$account_id = $row->account_id;
				}

				//Store the transaction received
				$now = date('Y-m-d H:i:s');
				$memreas_transaction  = new Memreas_Transaction;
				$memreas_transaction->exchangeArray(array(
						//'account_id'=>$account_detail->account_id,
						'account_id'=>$account_id,
						'transaction_type' =>'add_value_to_account_ipn',
						//'pass_fail' =>'1',
						//'amount' => '-1',
						//'currency' => 'USD',
						'transaction_request' => 'ipn_received',
						'transaction_response' => json_encode($_POST),
						'transaction_sent' => $now,
						'transaction_receive' => $now,

						//Get payer and receiver emails
						//'paypal_item_name' => $item_name,
						//'paypal_item_number' => $item_number,
						'paypal_payer_id' => $payer_id,
						'paypal_payer_email' => $payer_email,
						'paypal_receiver_id' => $receiver_id,
						'paypal_receiver_email' => $receiver_email,

						'paypal_payment_status' => $payment_status,
						'paypal_payment_amount' => $payment_amount,
						'paypal_payment_currency' => $payment_currency,
						'paypal_payment_fee' => $payment_fee,
						'paypal_paypal_tax' => $payment_tax,

						'paypal_txn_type' => $txn_type,
						'paypal_txn_id' => $txn_id,
						'paypal_ipn_track_id' => $ipn_track_id,
				));
				$transaction_id =  $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);
error_log("Inside ipnListener stored transaction table $transaction_id" . PHP_EOL);
			}
			/*
			 * MassPay Section
			 */
			else if ($_POST['txn_type'] == 'masspay') {
error_log("Inside ipnListener masspay" . PHP_EOL);

				//Process all masspay entries
				$i = 0;
				while (++$i):
					$str = "masspay_txn_id_".$i;
					if (isset($_POST[$str])) {
							$transaction_type = "masspay_payout_ipn";
							$txn_type = (isset($_POST['txn_type'])) ?  $_POST['txn_type'] : "N/a";
							$txn_id = (isset($_POST['masspay_txn_id_'.$i])) ?  $_POST['masspay_txn_id_'.$i] : "N/a";
							$ipn_track_id = (isset($_POST['ipn_track_id'])) ?  $_POST['ipn_track_id'] : "N/a";

							$payment_status = (isset($_POST['payment_status'])) ?  $_POST['payment_status'] : "N/a";
							$payment_amount = (isset($_POST['mc_gross_'.$i])) ?  $_POST['mc_gross_'.$i] : '0';
							$payment_currency = (isset($_POST['mc_currency_'.$i])) ?  $_POST['mc_currency_'.$i] : "USD";
							$payment_tax = (isset($_POST['tax'])) ?  $_POST['tax'] : '0';  //not in response
							$payment_fee = (isset($_POST['mc_fee_'.$i])) ?  $_POST['mc_fee_'.$i] : '0';

							$payer_id = (isset($_POST['payer_id'])) ?  $_POST['payer_id'] : "N/a";
							$payer_email = (isset($_POST['payer_email'])) ?  $_POST['payer_email'] : "N/a";
							$receiver_id = (isset($_POST['receiver_id'])) ?  $_POST['receiver_id'] : "N/a";
							$receiver_email = (isset($_POST['receiver_email_'.$i])) ?  $_POST['receiver_email_'.$i] : "N/a";

							//Fetch the original transaction
							$rowset = $memreas_paypal_tables->getTransactionTable()->getTransactionByPayPalTxnId($_POST['unique_id_'.$i]);
							if (!$rowset) {
								//Need to throw exception here but store entry?
error_log("Inside ipnListener masspay - didn't find row...".PHP_EOL);
							} else {
								//error_log("Inside ipnListener web_accept - found row ----> ".print_r($row).PHP_EOL);
								$row = $rowset->current();
								$account_id = $row->account_id;
								$correlation_id = $row->paypal_parent_payment_id;
error_log("Inside ipnListener masspay - found row account_id---->".$account_id.PHP_EOL);
error_log("Inside ipnListener masspay - found row paypal_parent_payment_id/correlation_id---->".$correlation_id.PHP_EOL);
error_log("Inside ipnListener masspay - found row paypal_txn_id---->".$txn_id.PHP_EOL);
error_log("Inside ipnListener masspay - found row paypal_ipn_track_id---->".$ipn_track_id.PHP_EOL);
							}

							//Store the transaction received
							$now = date('Y-m-d H:i:s');
							$memreas_transaction  = new Memreas_Transaction;
							$memreas_transaction->exchangeArray(array(
									'account_id'=>$account_id,
									'transaction_type' => $transaction_type,
									//'pass_fail' =>'1',
									//'amount' => '-1',
									//'currency' => 'USD',
									'transaction_request' => 'ipn_received',
									'transaction_response' => json_encode($_POST),
									'transaction_sent' => $now,
									'transaction_receive' => $now,

									//Get payer and receiver emails
									//'paypal_item_name' => $item_name,
									//'paypal_item_number' => $item_number,
									'paypal_payer_id' => $payer_id,
									'paypal_payer_email' => $payer_email,
									'paypal_receiver_id' => $receiver_id,
									'paypal_receiver_email' => $receiver_email,

									'paypal_payment_status' => $payment_status,
									'paypal_payment_amount' => $payment_amount,
									'paypal_payment_currency' => $payment_currency,
									'paypal_payment_fee' => $payment_fee,
									'paypal_paypal_tax' => $payment_tax,

									'paypal_txn_type' => $txn_type,
									'paypal_parent_payment_id' => $correlation_id,
									'paypal_txn_id' => $txn_id,
									'paypal_ipn_track_id' => $ipn_track_id,
							));
							$transaction_id =  $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);
error_log("Inside ipnListener stored transaction table" . PHP_EOL);
					} else {
							break;
					}
				endwhile;
error_log("Inside ipnListener masspay - past while loop..." . PHP_EOL);
			}else if ($_POST['txn_type'] == 'recurring_payment_profile_created') {
			/*
			 * MassPay Section
			 */


error_log("Inside ipnListener masspay" . PHP_EOL);
			} else {
				$transaction_type = $_POST['txn_type'];
			}

			if(DEBUG == true) {
				error_log(date('[Y-m-d H:i e] '). "Verified IPN: $req ". PHP_EOL, 3, LOG_FILE);
				error_log("Inside ipnListener - Inspect IPN validation result POST ---> " . print_r($_POST, true) . PHP_EOL);
			}
		} else if (strcmp ($res, "INVALID") == 0) {
			// log for manual investigation
			// Add business logic here which deals with invalid IPN messages
			if(DEBUG == true) {
				error_log(date('[Y-m-d H:i e] '). "Invalid IPN: $req" . PHP_EOL, 3, LOG_FILE);
			}
		}

/*
		$ipnMessage = new PPIPNMessage(null, PayPalConfig::getAcctAndConfig());
		foreach($ipnMessage->getRawData() as $key => $value) {
			error_log("IPN: $key => $value");
		}

		if($ipnMessage->validate()) {
			error_log("Success: Got valid IPN data");
		} else {
			error_log("Error: Got invalid IPN data");
		}
*/
	}

	public function paypalSubscribe($message_data, $memreas_paypal_tables, $service_locator) {
error_log("Inside paypalSubscribe....".PHP_EOL);
error_log("Inside paypalSubscribe message_data ....".print_r($message_data,true).PHP_EOL);
		//Return Message
		$return_message = array();

		//Fetch Session
		$this->fetchSession();

		//Fetch PayPal credential
		$credential = $this->fetchPayPalCredential($service_locator);

		//Setup an api context for the card
		$api_context = new ApiContext($credential);

		//Get the data from the form
		//$paypal_card_reference_id = $message_data['paypal_card_reference_id'];
		$subscribe_first_name = $message_data['subscribe_first_name'];
		$subscribe_last_name = $message_data['subscribe_last_name'];
		$subscribe_address_line_1 = $message_data['subscribe_address_line_1'];
		$subscribe_address_line_2 = $message_data['subscribe_address_line_2'];
		$subscribe_city = $message_data['subscribe_city'];
		$subscribe_state = $message_data['subscribe_state'];
		$subscribe_zip_code = $message_data['subscribe_zip_code'];
		$subscribe_credit_card_type = $message_data['subscribe_credit_card_type'];
		$subscribe_credit_card_number = $message_data['subscribe_credit_card_number'];
		$subscribe_expiration_month = $message_data['subscribe_expiration_month'];
		$subscribe_expiration_year = $message_data['subscribe_expiration_year'];
		$plan = $message_data['plan'];

		//Set the amount
		switch ($plan) {
			case "A":
				$plan_amount = MemreasConstants::PLAN_AMOUNT_A;
				$plan_details = MemreasConstants::PLAN_DETAILS_A;
				$plan_gb_storage_amount = MemreasConstants::PLAN_GB_STORAGE_AMOUNT_A;
				break;
			case "B":
				$plan_amount = MemreasConstants::PLAN_AMOUNT_B;
				$plan_details = MemreasConstants::PLAN_DETAILS_B;
				$plan_gb_storage_amount = MemreasConstants::PLAN_GB_STORAGE_AMOUNT_B;
				break;
			case "C":
				$plan_amount = MemreasConstants::PLAN_AMOUNT_C;
				$plan_details = MemreasConstants::PLAN_DETAILS_C;
				$plan_gb_storage_amount = MemreasConstants::PLAN_GB_STORAGE_AMOUNT_C;
				break;
			case "D":
				$plan_amount = MemreasConstants::PLAN_AMOUNT_D;
				$plan_details = MemreasConstants::PLAN_DETAILS_D;
				$plan_gb_storage_amount = MemreasConstants::PLAN_GB_STORAGE_AMOUNT_D;
				break;
		}


		//Fetch any other data we need
		$account = $memreas_paypal_tables->getAccountTable()->getAccountByUserId($this->user_id);
		//$account_detail = $memreas_paypal_tables->getAccountDetailTable()->getAccountDetailByPayPalReferenceId($paypal_card_reference_id);

		//Log the inputs
		$return_message['account_id'] = $account->account_id;
		//$return_message['account_detail_id'] = $account_detail->account_detail_id;
		//$return_message['paypal_card_reference_id'] = $paypal_card_reference_id;
		$return_message['subscribe_first_name'] = $subscribe_first_name;
		$return_message['subscribe_last_name'] = $subscribe_last_name;
		$return_message['subscribe_address_line_1'] = $subscribe_address_line_1;
		$return_message['subscribe_address_line_2'] = $subscribe_address_line_2;
		$return_message['subscribe_city'] = $subscribe_city;
		$return_message['subscribe_state'] = $subscribe_state;
		$return_message['subscribe_zip_code'] = $subscribe_zip_code;
		$return_message['subscribe_credit_card_type'] = $subscribe_credit_card_type;
		$return_message['subscribe_credit_card_number'] = $subscribe_credit_card_number;
		$return_message['subscribe_expiration_month'] = $subscribe_expiration_month;
		$return_message['subscribe_expiration_year'] = $subscribe_expiration_year;
		$return_message['plan'] = $plan;
		$return_message['plan_amount'] = $plan_amount;
		$return_message['plan_desciption'] = $plan_details;
error_log("return_message start...".print_r($return_message,true).PHP_EOL);

		/*
		 * SetExpressCheckout Section
		 */

		$logger = new PPLoggingManager('SetExpressCheckout');

		// ## SetExpressCheckoutReq
		$setExpressCheckoutRequestDetails = new SetExpressCheckoutRequestDetailsType();

		//Setup billing agreement
		$billingAgreementDetailsType = new BillingAgreementDetailsType();
		$billingAgreementDetailsType->BillingType = "RecurringPayments";
		$billingAgreementDetailsType->BillingAgreementDescription=$plan_details;
		$setExpressCheckoutRequestDetails->BillingAgreementDetails = $billingAgreementDetailsType;

		// URL to which the buyer's browser is returned after choosing to pay
		// with PayPal. For digital goods, you must add JavaScript to this page
		// to close the in-context experience.
		// `Note:
		// PayPal recommends that the value be the final review page on which
		// the buyer confirms the order and payment or billing agreement.`
/*
 * TODO: Fix Return URL
 */
		$setExpressCheckoutRequestDetails->ReturnURL = "http://memreasdev-paypal.elasticbeanstalk.com/ipnListener?cmd=return";

		// URL to which the buyer is returned if the buyer does not approve the
		// use of PayPal to pay you. For digital goods, you must add JavaScript
		// to this page to close the in-context experience.
		// `Note:
		// PayPal recommends that the value be the original page on which the
		// buyer chose to pay with PayPal or establish a billing agreement.`
/*
 * TODO: Fix Cancel URL
 */
		$setExpressCheckoutRequestDetails->CancelURL = "http://memreasdev-paypal.elasticbeanstalk.com/ipnListener?cmd=cancel";

		// ### Payment Information
		// list of information about the payment
		$paymentDetailsArray = array();

		// information about the first payment
        $paymentDetails1 = new PaymentDetailsType();

		// Total cost of the transaction to the buyer. If shipping cost and tax
		// charges are known, include them in this value. If not, this value
		// should be the current sub-total of the order.
		//
		// If the transaction includes one or more one-time purchases, this field must be equal to
		// the sum of the purchases. Set this field to 0 if the transaction does
		// not include a one-time purchase such as when you set up a billing
		// agreement for a recurring payment that is not immediately charged.
		// When the field is set to 0, purchase-specific fields are ignored.
		//
		// * `Currency Code` - You must set the currencyID attribute to one of the
		// 3-character currency codes for any of the supported PayPal
		// currencies.
		// * `Amount`
        $orderTotal1 = new BasicAmountType("USD",$plan_amount);
		$paymentDetails1->OrderTotal = $orderTotal1;

		// How you want to obtain payment. When implementing parallel payments,
		// this field is required and must be set to `Order`. When implementing
		// digital goods, this field is required and must be set to `Sale`. If the
		// transaction does not include a one-time purchase, this field is
		// ignored. It is one of the following values:
		//
		// * `Sale` - This is a final sale for which you are requesting payment
		// (default).
		// * `Authorization` - This payment is a basic authorization subject to
		// settlement with PayPal Authorization and Capture.
		// * `Order` - This payment is an order authorization subject to
		// settlement with PayPal Authorization and Capture.
		// `Note:
		// You cannot set this field to Sale in SetExpressCheckout request and
		// then change the value to Authorization or Order in the
		// DoExpressCheckoutPayment request. If you set the field to
		// Authorization or Order in SetExpressCheckout, you may set the field
		// to Sale.`
		$paymentDetails1->PaymentAction = "Sale";

		// Unique identifier for the merchant. For parallel payments, this field
		// is required and must contain the Payer Id or the email address of the
		// merchant.
		$sellerDetails1 = new SellerDetailsType();
		$sellerDetails1->PayPalAccountID = MemreasConstants::PAYPAL_MEMREAS_MASTER_EMAIL;
		$paymentDetails1->SellerDetails = $sellerDetails1;

		// A unique identifier of the specific payment request, which is
		// required for parallel payments.
		$paymentDetails1->PaymentRequestID = "PaymentRequest1";

		// `Address` to which the order is shipped, which takes mandatory params:
		//
		// * `Street Name`
		// * `City`
		// * `State`
		// * `Country`
		// * `Postal Code`

		$shipToAddress1 = new AddressType();
		$shipToAddress1->Street1 = $subscribe_address_line_1;
		$shipToAddress1->CityName = $subscribe_city;
		$shipToAddress1->StateOrProvince = $subscribe_state;
		$shipToAddress1->PostalCode = $subscribe_zip_code;
		$shipToAddress1->Country = "US";

		// Your URL for receiving Instant Payment Notification (IPN) about this transaction. If you do not specify this value in the request, the notification URL from your Merchant Profile is used, if one exists.
/*
 * TODO: Test with ipnListener
 */
		$paymentDetails1->NotifyURL = "http://memreasdev-paypal.elasticbeanstalk.com/index/ipnListener";

		$paymentDetails1->ShipToAddress = $shipToAddress1;
		$paymentDetailsArray[0] = $paymentDetails1;

		$setExpressCheckoutRequestDetails->PaymentDetails = $paymentDetailsArray;
		$setExpressCheckoutReq = new SetExpressCheckoutReq();
		$setExpressCheckoutRequest = new SetExpressCheckoutRequestType($setExpressCheckoutRequestDetails);
		$setExpressCheckoutReq->SetExpressCheckoutRequest = $setExpressCheckoutRequest;

error_log("About to call setexpresscheckout...".PHP_EOL);
		// ## Creating service wrapper object
		// Creating service wrapper object to make API call and loading
		// configuration file for your credentials and endpoint
		$service = new PayPalAPIInterfaceServiceService(PayPalConfig::getAcctAndConfig());

		try {
			// ## Making API call
			// Invoke the appropriate method corresponding to API in service
			// wrapper object
			$response = $service->SetExpressCheckout($setExpressCheckoutReq);
error_log("setexpresscheckout response ----> ".print_r($response,true).PHP_EOL);

		} catch (Exception $ex) {
			$logger->error("Error Message : " + $ex->getMessage());
		}

		// ## Accessing response parameters
		// You can access the response parameters using variables in
		// response object as shown below
		// ### Success values
		if ($response->Ack == "Success") {

			// ### Redirecting to PayPal for authorization
			// Once you get the "Success" response, needs to authorise the
			// transaction by making buyer to login into PayPal. For that,
			// need to construct redirect url using EC token from response.
			// For example,
			// `redirectURL="https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=". $response->Token();`

			// Express Checkout Token
			//$logger->log("EC Token:" . $response->Token);
			error_log("EC Token: ".$response->Token.PHP_EOL);

		}
		// ### Error Values
		// Access error values from error list using getter methods
		else {
			$logger->error("API Error Message : ". $response->Errors[0]->LongMessage);
			exit;
		}
error_log("SetExpressCheckout response ---> ".print_r($response,true).PHP_EOL);
//error_log("Finished setexpresscheckout...".PHP_EOL);

		/*
		 * CreatRecurringPaymentProfile section
		 */

		$logger = new PPLoggingManager('CreateRecurringPaymentProfile');

		//Setup the paypal vars..
		//https://github.com/paypal/codesamples-php/blob/master/Merchant/sample/code/CreateRecurringPaymentProfile.php

		//Fetch the card data
		//$card = CreditCard::get($paypal_card_reference_id, $api_context);
		$currency_code = "USD";
		$shippingAddress = new AddressType();
		$shippingAddress->Name = $subscribe_first_name.' '.$subscribe_last_name;
		$shippingAddress->Street1 = $subscribe_address_line_1;
		$shippingAddress->Street2 = $subscribe_address_line_2;
		$shippingAddress->CityName = $subscribe_city;
		$shippingAddress->StateOrProvince = $subscribe_state;
		$shippingAddress->PostalCode = $subscribe_zip_code;
		$shippingAddress->Country = "US";

		$now = date('Y-m-d H:i:s');
		$RPProfileDetails = new RecurringPaymentsProfileDetailsType();
		$RPProfileDetails->SubscriberName = $shippingAddress->Name;
		$RPProfileDetails->BillingStartDate = $now;
		$RPProfileDetails->SubscriberShippingAddress  = $shippingAddress;
		//$activationDetails = new ActivationDetailsType();
		//$activationDetails->InitialAmount = new BasicAmountType($currency_code, $_REQUEST['initialAmount']);
		//$activationDetails->FailedInitialAmountAction = $_REQUEST['failedInitialAmountAction'];
		$paymentBillingPeriod =  new BillingPeriodDetailsType();
        $paymentBillingPeriod->BillingFrequency = MemreasConstants::PLAN_BILLINGFREQUENCY;
        $paymentBillingPeriod->BillingPeriod = MemreasConstants::PLAN_BILLINGPERIOD;
        $paymentBillingPeriod->TotalBillingCycles = MemreasConstants::PLAN_BILLINGCYCLES;
		$paymentBillingPeriod->Amount = new BasicAmountType($currency_code, $plan_amount);
		$paymentBillingPeriod->ShippingAmount = new BasicAmountType($currency_code, "0");
/*
 * TODO: Follow up on taxes
 */
		$paymentBillingPeriod->TaxAmount = new BasicAmountType($currency_code, "0");

		$scheduleDetails = new ScheduleDetailsType();
		$scheduleDetails->Description = $plan_details;
		//$scheduleDetails->ActivationDetails = $activationDetails;
		$scheduleDetails->PaymentPeriod = $paymentBillingPeriod;
		$scheduleDetails->MaxFailedPayments =  "2";
		$scheduleDetails->AutoBillOutstandingAmount = true;

		$createRPProfileRequestDetail = new CreateRecurringPaymentsProfileRequestDetailsType();
		$creditCard = new CreditCardDetailsType();
		$creditCard->CreditCardType = $subscribe_credit_card_type;
		$creditCard->CreditCardNumber = $subscribe_credit_card_number;
		$creditCard->CardOwner = $subscribe_first_name.' '.$subscribe_last_name;
		$creditCard->ExpMonth = $subscribe_expiration_month;
		$creditCard->ExpYear = $subscribe_expiration_year;
		$createRPProfileRequestDetail->CreditCard = $creditCard;

		$createRPProfileRequestDetail->ScheduleDetails = $scheduleDetails;
		$createRPProfileRequestDetail->RecurringPaymentsProfileDetails = $RPProfileDetails;
		$createRPProfileRequestDetail->Token = $response->Token;
		$createRPProfileRequest = new CreateRecurringPaymentsProfileRequestType();
		$createRPProfileRequest->CreateRecurringPaymentsProfileRequestDetails = $createRPProfileRequestDetail;

		$createRPProfileReq =  new CreateRecurringPaymentsProfileReq();
		$createRPProfileReq->CreateRecurringPaymentsProfileRequest = $createRPProfileRequest;

		/*
		 *  ## Creating service wrapper object
		Creating service wrapper object to make API call and loading
		Configuration::getAcctAndConfig() returns array that contains credential and config parameters
		*/
		$paypalService = new PayPalAPIInterfaceServiceService(PayPalConfig::getAcctAndConfig());
		try {

			//Store the transaction before sending
			$now = date('Y-m-d H:i:s');
			//Scrub the credit card data
			$request = str_replace($subscribe_credit_card_number, $this->obfuscateAccountNumber($subscribe_credit_card_number), json_encode($createRPProfileReq));
			$memreas_transaction  = new Memreas_Transaction;
			$memreas_transaction->exchangeArray(array(
					'account_id'=>$account->account_id,
					'transaction_type' =>'subscribe',
					'transaction_request' => $request,
					'transaction_sent' =>$now
			));
			$transaction_id =  $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

			/* wrap API method calls on the service object with a try catch */
			$response = $paypalService->CreateRecurringPaymentsProfile($createRPProfileReq);

error_log("response->correlation_id ---> ".$response->CorrelationID.PHP_EOL);
error_log("response ---> ".print_r($response,true).PHP_EOL);

			/*
			 * Log the transaction post-call to PayPal here
			*/
			$now = date('Y-m-d H:i:s');
			$memreas_transaction->exchangeArray(array(
					'transaction_id' => $transaction_id,
					'pass_fail' => 1,
					'amount' => $plan_amount,
					//'paypal_txn_id' => $payment->getId(), //--> fetches parent_payment_id
					'currency' => 'USD',
					'paypal_correlation_id' => $response->CorrelationID,
					'transaction_response' => json_encode($response),
					'transaction_receive' =>$now,
			));
			$transaction_id = $memreas_paypal_tables->getTransactionTable()->saveTransaction($memreas_transaction);

			//Add entry for account detail
			$accountDetail = new AccountDetail ();
			$accountDetail->exchangeArray ( array (
					'account_id' => $account->account_id,
					'first_name' => $subscribe_first_name,
					'last_name' => $subscribe_last_name,
					'address_line_1' => $subscribe_address_line_1,
					'address_line_2' => $subscribe_address_line_2,
					'city' => $subscribe_city,
					'state' => $subscribe_state,
					'zip_code' => $subscribe_zip_code,
					'postal_code' => $subscribe_zip_code
			) );
			$account_detail_id = $memreas_paypal_tables->getAccountDetailTable ()->saveAccountDetail ( $accountDetail );
error_log ( "Subscribe Account_Detail set..." . PHP_EOL );

			// Add the new payment method
			$payment_method = new PaymentMethod ();
			$payment_method->exchangeArray ( array (
					'account_id' => $account->account_id,
					//'paypal_card_reference_id' => $card->getId (), //subscribe card not stored in vault but with subscription.
					'card_type' => $subscribe_credit_card_type,
					'obfuscated_card_number' => $this->obfuscateAccountNumber($subscribe_credit_card_number),
					'exp_month' => $subscribe_expiration_month,
					'exp_year' => $subscribe_expiration_year,
					'valid_until' => $subscribe_expiration_month.'/'.$subscribe_expiration_year,
					'create_time' => $now,
					'update_time' => $now
			) );
			$payment_method_id = $memreas_paypal_tables->getPaymentMethodTable ()->savePaymentMethod ( $payment_method );
error_log("Subscribe - Just stored payment_method table:)".PHP_EOL);

			//Log the subscription
			//Store the transaction before sending
			$now = date('Y-m-d H:i:s');
			$memreas_subscription  = new Subscription();
			$memreas_subscription->exchangeArray(array(
					'account_id'=> $account->account_id,
					'currency_code'=>$currency_code,
					'plan' => $plan,
					'plan_amount' => $plan_amount,
					'plan_description' => $plan_details,
					'gb_storage_amount' => $plan_gb_storage_amount,
					'billing_frequency' => MemreasConstants::PLAN_BILLINGFREQUENCY,
					'start_date' => $now,
					'end_date' => null,
					'paypal_subscription_profile_id' => $response->CreateRecurringPaymentsProfileResponseDetails->ProfileID,
					'paypal_subscription_profile_status' => $response->CreateRecurringPaymentsProfileResponseDetails->ProfileStatus,
					'create_date' => $now,
					'update_time' => $now
			));
			$subscription_id =  $memreas_paypal_tables->getSubscriptionTable()->saveSubscription($memreas_subscription);
error_log("Subscribe - Just stored subscription table:)".PHP_EOL);

/*
 * TODO - Store the payment method and account detail entries
*/


		}catch(Exception $ex){
			$logger->error("Error Message : ". $ex->getMessage());
			$result = array (
					"Status"=>"Error",
					"Description"=>"Error Message : ". $ex->getMessage(),
			);
			return $result;
		}

		//Return a success message:
		$result = array (
				"Status"=>"Success",
				"Description"=>$return_message
		);
		return $result;

/*
		if(isset($createRPProfileResponse)) {
			echo "<table>";
			echo "<tr><td>Ack :</td><td><div id='Ack'>$createRPProfileResponse->Ack</div> </td></tr>";
			echo "<tr><td>ProfileID :</td><td><div id='ProfileID'>".$createRPProfileResponse->CreateRecurringPaymentsProfileResponseDetails->ProfileID ."</div> </td></tr>";
			echo "</table>";

			echo "<pre>";
			print_r($createRPProfileResponse);
			echo "</pre>";
			exit;
		}
*/

		//Return a success message:
		$result = array (
				"Status"=>"Success",
				"Description"=>$return_message
				);
		return $result;


	}

	public function payPalAccountHistory($message_data, $memreas_paypal_tables, $service_locator) {
		//Fetch Session
		$this->fetchSession();

		//Fetch the user_name and id
		$user_name = $message_data['user_name'];
		$user = $memreas_paypal_tables->getUserTable()->getUserByUsername($user_name);

		//Fetch the user_id
	    if(isset($this->user_id)){

			//Fetch the Account
			$account = $memreas_paypal_tables->getAccountTable()->getAccountByUserId($user->user_id);
			if (!$account) {
				$result = array ( "Status"=>"Error", "Description"=>"Could not find account", );
				return $result;
			}
			$account_id = $account->account_id;

			//Fetch the transactions
			$transactions =  $memreas_paypal_tables->getTransactionTable()->getTransactionByAccountId($account_id);

			//Debug...
			$transactions_arr = array();
			foreach ($transactions as $row) {
				$row_arr = array();
     		   //echo $row->my_column . PHP_EOL;
     		   $row_arr["transaction_id"] = $row->transaction_id;
     		   $row_arr["account_id"] = $row->account_id;
     		   $row_arr["transaction_type"] = $row->transaction_type;
     		   $row_arr["pass_fail"] = $row->pass_fail;
     		   $row_arr["amount"] = $row->amount;
     		   $row_arr["currency"] = $row->currency;
     		   $row_arr["transaction_request"] = $row->transaction_request;
     		   $row_arr["transaction_response"] = $row->transaction_response;
     		   $row_arr["transaction_sent"] = $row->transaction_sent;
     		   $row_arr["transaction_receive"] = $row->transaction_receive;
     		   $transactions_arr[] = $row_arr;
			}

			//Return a success message:
			$result = array (
				"Status"=>"Success",
				"account"=>$account,
				"transactions"=>$transactions_arr,
				);
			return $result;
		}

		//Return an error message:
		$result = array (
			"Status"=>"Error",
			"Description"=>"$user_id not found"
			);
		return $result;
	}

	public function obfuscateAccountNumber($num) {
		$num = (string) preg_replace("/[^A-Za-z0-9]/", "", $num); // Remove all non alphanumeric characters

		return str_pad(substr($num, -4), strlen($num), "*", STR_PAD_LEFT);
	}

} // end class
?>
