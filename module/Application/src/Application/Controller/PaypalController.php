<?php

/*
 * To change this template, choose Tools | Templates and open the template in
 * the editor.
 */

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Application\Form\PreapprovalForm;
use Application\Model\Preapproval;
use AdaptivePaymentsService;
use RequestEnvelope;
use PreapprovalRequest;
use PPConnectionException;
use PPConfigurationException;
use PPInvalidCredentialException;
use PPMissingCredentialException;
use RefundRequest;
use Receiver;
use PhoneNumberType;
use ReceiverList;
use FundingConstraint;
use SenderIdentifier;
use PayRequest;
use AddressType;
use RecurringPaymentsProfileDetailsType;
use ActivationDetailsType;
use BillingPeriodDetailsType;
use ScheduleDetailsType;
use BasicAmountType;
use CreateRecurringPaymentsProfileRequestDetailsType;
use CreditCardDetailsType;
use CreateRecurringPaymentsProfileRequestType;
use CreateRecurringPaymentsProfileReq;
use PayPalAPIInterfaceServiceService;
use PaymentDetailsType;
use PersonNameType;
use PayerInfoType;
use DoDirectPaymentRequestDetailsType;
use DoDirectPaymentReq;
use DoDirectPaymentRequestType;
use MassPayRequestType;
use MassPayRequestItemType;
use MassPayReq;

use Application\Model\Account;
use Application\Model\AccountBalances;
use Application\Model\AccountDetail;
use Application\Model\Subscription;
use Application\Model\Transaction;
use Application\Model\TransactionReceiver;

define('PP_CONFIG_PATH', dirname(__FILE__) . "/config/");

class PaypalController extends AbstractActionController {
	private $_DEFAULT_SELECT = "- Select -";
	private $_PAYPAL_REDIRECT_URL = "https://www.sandbox.paypal.com/webscr&cmd=";
	private $_DEVELOPER_PORTAL = "https://developer.paypal.com";
	
	protected $accountTable = NULL;
	protected $accountBalancesTable = NULL;
	protected $accountDetailTable = NULL;
	protected $subscriptionTable = NULL;
	protected $transactionTable = NULL;
	protected $transactionRecieverTable = NULL;
	
	
	public function indexAction() {
		return new ViewModel ();
	}
	
	public function getTransactionTable()
	{
		if (!$this->transactionTable) {
			$sm = $this->getServiceLocator();
			$this->transactionTable = $sm->get('Application\Model\TransactionTable');
		}
		return $this->transactionTable;
	}
	
	public function getAccountTable()
	{
		if (!$this->accountTable) {
			$sm = $this->getServiceLocator();
			$this->accountTable = $sm->get('Application\Model\AccountTable');
		}
		return $this->accountTable;
	}
	
	public function getAccountBalancesTable()
	{
		if (!$this->accountBalancesTable) {
			$sm = $this->getServiceLocator();
			$this->accountBalancesTable = $sm->get('Application\Model\AccountBalancesTable');
		}
		return $this->accountBalancesTable;
	}
	
	public function getAccountDetailTable()
	{
		if (!$this->accountDetailTable) {
			$sm = $this->getServiceLocator();
			$this->accountDetailTable = $sm->get('Application\Model\AccountDetailTable');
		}
		return $this->accountDetailTable;
	}
	
	public function getTransactionReceiverTable()
	{
		if (!$this->transactionRecieverTable) {
			$sm = $this->getServiceLocator();
			$this->transactionRecieverTable = $sm->get('Application\Model\TransactionRecieverTable');
		}
		return $this->transactionRecieverTable;
	}
	
	public function getSubscriptionTable()
	{
		if (!$this->subscriptionTable) {
			$sm = $this->getServiceLocator();
			$this->subscriptionTable = $sm->get('Application\Model\SubscriptionTable');
		}
		return $this->subscriptionTable;
	}
	
	public function preapprovalAction() {
		$errors = array ();
		$exception = array ();
		$response = array ();
		$service = NULL;
		$response = NULL;
		
		$form = new PreapprovalForm ();
		$request = $this->getRequest ();
		if ($request->isPost ()) {
			$objPreapproval = new Preapproval ();
			$form->setInputFilter ( $objPreapproval->getInputFilter () );
			$form->setData ( $request->getPost () );
			
			if ($form->isValid ()) {
				$postData = $form->getData ();
				
				$serverName = $request->getServer ( 'SERVER_NAME' );
				$serverPort = $request->getServer ( 'SERVER_PORT' );
				$url = dirname ( 'http://' . $serverName . ':' . $serverPort . $request->getServer ( 'REQUEST_URI' ) );
				
				$returnUrl = $url . "/preapproval";
				$cancelUrl = $url . "/cancelpreapproval";
				$requestEnvelope = new RequestEnvelope ( "en_US" );
				$preapprovalRequest = new PreapprovalRequest ( $requestEnvelope, $cancelUrl, $postData ['currencyCode'], $returnUrl, $postData ['startingDate'] );
				
				if ($postData ['dateOfMonth'] != null) {
					$preapprovalRequest->dateOfMonth = $postData ['dateOfMonth'];
				}
				if ($postData ['dayOfWeek'] != null && $postData ['dayOfWeek'] != $this->_DEFAULT_SELECT) {
					$preapprovalRequest->dayOfWeek = $postData ['dayOfWeek'];
				}
				if ($postData ['dateOfMonth'] != null) {
					$preapprovalRequest->dateOfMonth = $postData ['dateOfMonth'];
				}
				if ($postData ['endingDate'] != null) {
					$preapprovalRequest->endingDate = $postData ['endingDate'];
				}
				if ($postData ['maxAmountPerPayment'] != null) {
					$preapprovalRequest->maxAmountPerPayment = $postData ['maxAmountPerPayment'];
				}
				if ($postData ['maxNumberOfPayments'] != null) {
					$preapprovalRequest->maxNumberOfPayments = $postData ['maxNumberOfPayments'];
				}
				if ($postData ['maxNumberOfPaymentsPerPeriod'] != null) {
					$preapprovalRequest->maxNumberOfPaymentsPerPeriod = $postData ['maxNumberOfPaymentsPerPeriod'];
				}
				if ($postData ['maxTotalAmountOfAllPayments'] != null) {
					$preapprovalRequest->maxTotalAmountOfAllPayments = $postData ['maxTotalAmountOfAllPayments'];
				}
				if ($postData ['paymentPeriod'] != null && $postData ['paymentPeriod'] != $this->_DEFAULT_SELECT) {
					$preapprovalRequest->paymentPeriod = $postData ['paymentPeriod'];
				}
				if ($postData ['memo'] != null) {
					$preapprovalRequest->memo = $postData ['memo'];
				}
				if ($postData ['ipnNotificationUrl'] != null) {
					$preapprovalRequest->ipnNotificationUrl = $postData ['ipnNotificationUrl'];
				}
				if ($postData ['senderEmail'] != null) {
					$preapprovalRequest->senderEmail = $postData ['senderEmail'];
				}
				if ($postData ['pinType'] != null && $postData ['pinType'] != $this->_DEFAULT_SELECT) {
					$preapprovalRequest->pinType = $postData ['pinType'];
				}
				if ($postData ['feesPayer'] != null) {
					$preapprovalRequest->feesPayer = $postData ['feesPayer'];
				}
				if ($postData ['displayMaxTotalAmount'] != null) {
					$preapprovalRequest->displayMaxTotalAmount = $postData ['displayMaxTotalAmount'];
				}
				
				//Need to store Account, Account_Detail, and 
				
				
echo json_encode ($preapprovalRequest);

echo json_encode ("<P>");
				$service = new AdaptivePaymentsService ();
				try {
					$response = $service->Preapproval ( $preapprovalRequest );

echo json_encode ("<P>");
echo json_encode ($response);

					

					/* db submission*/
					
					if(empty($response->error)){
						
						$now = date('Y-m-d H:i:s');
						$account  = new Account();
						$account->exchangeArray(array(
										'user_id' => '5',
										'account_type' => 'buyer',
										'balance' => '100000',
										'create_time' => $now,
										'update_time' => $now
									));
						
						$account_id =  $this->getAccountTable()->saveAccount($account);

echo json_encode ("<P>");
echo json_encode ($response);

						$accountDetail  = new AccountDetail();
						$accountDetail->exchangeArray(array(
								'account_id'=>$account_id,
								'first_name'=>'Nik',
								'last_name'=>'P',
								'address_line1'=>'Mumbai',
								'address_line2'=>'Mumbai',
								'city'=>'Mumbai',
								'state'=>'Maharastra',
								'zip_code'=>'0',
								'postal_code'=>'410206',
								'paypal_credit_card_reference_id'=>'0',
								));
						
						
						$accountDetailid =  $this->getAccountDetailTable()->saveAccountDetail($accountDetail);
						
						$transaction  = new Transaction();
						$transaction->exchangeArray(array(
									'account_id' => $account_id,
									'transaction_type' =>'preapproval',
									'item_name' =>'test',
									'pass_fail' =>'1',
									'amount' =>'4000',
									'currency' =>$postData ['currencyCode'],	
									'feespayer' =>'0',
									'billing_cycle_length' =>'0',
									'number_of_recurrences'=>'0',
									'start_date' =>$now,
									'billing_period'=>'0',
									'billing_frequency' =>'0',
									'transaction_request' => json_encode ($preapprovalRequest),
									'transaction_response' => json_encode ($response),
									'transaction_sent' =>$now,
									'transaction_receive' =>$now	
								));
						
						$transactionid =  $this->getTransactionTable()->saveTransaction($transaction);
						
						$subscription = new Subscription();
						$subscription->exchangeArray(array(
									 'account_id'=>$account_id,
									 'preapproval_startdate'=>date('Y-m-d H:i:s',strtotime($postData ['startingDate'])),
									 'preapproval_enddate'=>date('Y-m-d H:i:s',strtotime($postData ['endingDate'])),
									 'payment_dte_of_month'=>$postData ['dateOfMonth'],
									 'currency_code'=>$postData ['currencyCode'],
									 'maximum_amount_per_payment'=>$postData ['maxAmountPerPayment'],
									 'maximum_no_of_payments'=>$postData ['maxNumberOfPayments'],
									 'maximum_no_of_payments_per_period'=>$postData ['maxNumberOfPaymentsPerPeriod'],
									 'maximum_total_of_all_payment'=>$postData ['maxTotalAmountOfAllPayments'],
									 'payment_period'=>$postData ['paymentPeriod'],
									 'sender_email'=>$postData ['senderEmail'],
									 'is_pin_required'=>$postData ['pinType'],
									 'fees_payer'=>$postData ['feesPayer'],
									 'fees_date'=>$now,
									 'create_date'=>$now,
									 'update_time'=>$now));
						$subscriptionid =  $this->getSubscriptionTable()->saveSubscription($subscription);
						
						$accountbalances = new AccountBalances();
						$accountbalances->exchangeArray(array(
									'account_id'=>$account_id,
									'transaction_id'=>$transactionid,
									'transaction_type' =>'preapproval',
									'starting_balance'=>'100',
									'amount'=>'-10',
									'ending_balance'=>'90',
									'create_time'=>$now
								));
						
						$accountbalancesid = $this->getAccountBalancesTable()->saveAccountBalances($accountbalances);
						
						//echo '<pre>';print_r($response);exit;
						
					}
					
					
					/**/
					
				} catch ( Exception $ex ) {
					$exception ['class'] = get_class ( $ex );
					$exception ['message'] = $ex->getMessage ();
					if ($ex instanceof PPConnectionException) {
						$exception ['details_message'] = "Error connecting to " . $ex->getUrl ();
					} else if ($ex instanceof PPConfigurationException) {
						$exception ['details_message'] = "Error at $ex->getLine() in $ex->getFile()";
					} else if ($ex instanceof PPInvalidCredentialException || $x instanceof PPMissingCredentialException) {
						$exception ['details_message'] = $ex->errorMessage ();
					}
				
				}
			
			} else {
				$errors = $form->getMessages ();
			}
		
		}
		return array ('form' => $form, 'errors' => $errors, 'exception' => $exception, 'service' => $service, 'response' => $response );
	
	}
	
	public function cancelpreapprovalAction() {
	
	}
	
	public function chainedpaymentAction() {
		$postData = array ();
		$errors = array ();
		$exception = array ();
		$response = array ();
		$service = NULL;
		$response = NULL;
		
		$request = $this->getRequest ();
		if ($request->isPost ()) {
			$postData = $request->getPost ()->toArray ();
			$olddate =  date('Y-m-d H:i:s');
			if (isset ( $postData ['receiverEmail'] )) {
				$receiver = array ();
				for($i = 0; $i < count ( $postData ['receiverEmail'] ); $i ++) {
					$receiver [$i] = new Receiver ();
					$receiver [$i]->email = $postData ['receiverEmail'] [$i];
					$receiver [$i]->amount = $postData ['receiverAmount'] [$i];
					$receiver [$i]->primary = $postData ['primaryReceiver'] [$i];
					
					if ($postData ['invoiceId'] [$i] != "") {
						$receiver [$i]->invoiceId = $postData ['invoiceId'] [$i];
					}
					if ($postData ['paymentType'] [$i] != "" && $postData ['paymentType'] [$i] != $this->_DEFAULT_SELECT) {
						$receiver [$i]->paymentType = $postData ['paymentType'] [$i];
					}
					if ($postData ['paymentSubType'] [$i] != "") {
						$receiver [$i]->paymentSubType = $postData ['paymentSubType'] [$i];
					}
					if ($postData ['phoneCountry'] [$i] != "" && $postData ['phoneNumber'] [$i]) {
						$receiver [$i]->phone = new PhoneNumberType ( $postData ['phoneCountry'] [$i], $postData ['phoneNumber'] [$i] );
						if ($postData ['phoneExtn'] [$i] != "") {
							$receiver [$i]->phone->extension = $postData ['phoneExtn'] [$i];
						}
					}
				}
				$receiverList = new ReceiverList ( $receiver );
			}
			
			$payRequest = new PayRequest ( new RequestEnvelope ( "en_US" ), $postData ['actionType'], $postData ['cancelUrl'], $postData ['currencyCode'], $receiverList, $postData ['returnUrl'] );
			// Add optional params
			if ($postData ["feesPayer"] != "") {
				$payRequest->feesPayer = $postData ["feesPayer"];
			}
			if ($postData ["preapprovalKey"] != "") {
				$payRequest->preapprovalKey = $postData ["preapprovalKey"];
			}
			if ($postData ['ipnNotificationUrl'] != "") {
				$payRequest->ipnNotificationUrl = $postData ['ipnNotificationUrl'];
			}
			if ($postData ["memo"] != "") {
				$payRequest->memo = $postData ["memo"];
			}
			if ($postData ["pin"] != "") {
				$payRequest->pin = $postData ["pin"];
			}
			if ($postData ['preapprovalKey'] != "") {
				$payRequest->preapprovalKey = $postData ["preapprovalKey"];
			}
			if ($postData ['reverseAllParallelPaymentsOnError'] != "") {
				$payRequest->reverseAllParallelPaymentsOnError = $postData ["reverseAllParallelPaymentsOnError"];
			}
			if ($postData ['senderEmail'] != "") {
				$payRequest->senderEmail = $postData ["senderEmail"];
			}
			if ($postData ['trackingId'] != "") {
				$payRequest->trackingId = $postData ["trackingId"];
			}
			if ($postData ['fundingConstraint'] != "" && $postData ['fundingConstraint'] != $this->_DEFAULT_SELECT) {
				$payRequest->fundingConstraint = new FundingConstraint ();
				$payRequest->fundingConstraint->allowedFundingType = new FundingTypeList ();
				$payRequest->fundingConstraint->allowedFundingType->fundingTypeInfo = array ();
				$payRequest->fundingConstraint->allowedFundingType->fundingTypeInfo [] = new FundingTypeInfo ( $postData ["fundingConstraint"] );
			}
			
			if ($postData ['emailIdentifier'] != "" || $postData ['senderCountryCode'] != "" || $postData ['senderPhoneNumber'] != "" || $postData ['senderExtension'] != "" || $postData ['useCredentials'] != "") {
				$payRequest->sender = new SenderIdentifier ();
				if ($postData ['emailIdentifier'] != "") {
					$payRequest->sender->email = $postData ["emailIdentifier"];
				}
				if ($postData ['senderCountryCode'] != "" || $postData ['senderPhoneNumber'] != "" || $postData ['senderExtension'] != "") {
					$payRequest->sender->phone = new PhoneNumberType ();
					if ($postData ['senderCountryCode'] != "") {
						$payRequest->sender->phone->countryCode = $postData ["senderCountryCode"];
					}
					if ($postData ['senderPhoneNumber'] != "") {
						$payRequest->sender->phone->phoneNumber = $postData ["senderPhoneNumber"];
					}
					if ($postData ['senderExtension'] != "") {
						$payRequest->sender->phone->extension = $postData ["senderExtension"];
					}
				}
				if ($postData ['useCredentials'] != "") {
					$payRequest->sender->useCredentials = $postData ["useCredentials"];
				}
			}
			$service = new AdaptivePaymentsService ();
			try {
				$response = $service->Pay ( $payRequest );
				
				//db
				if(empty($response->error)){
				
					$now = date('Y-m-d H:i:s');
					$account  = new Account();
					$account->exchangeArray(array(
							'user_id' => '5',
							'account_type' => 'buyer',
							'balance' => '100000',
							'create_time' => $now,
							'update_time' => $now
					));
				
					$account_id =  $this->getAccountTable()->saveAccount($account);
				
					$accountDetail  = new AccountDetail();
					$accountDetail->exchangeArray(array(
							'account_id'=>$account_id,
							'first_name'=>'Nik',
							'last_name'=>'P',
							'address_line1'=>'Mumbai',
							'address_line2'=>'Mumbai',
							'city'=>'Mumbai',
							'state'=>'Maharastra',
							'zip_code'=>'0',
							'postal_code'=>'410206',
							'paypal_credit_card_reference_id'=>'0',
					));
				
				
					$accountDetailid =  $this->getAccountDetailTable()->saveAccountDetail($accountDetail);
				
					$transaction  = new Transaction();
					$transaction->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_type' =>'chained_payment',
							'item_name' =>'Chained Payment',
							'pass_fail' =>'1',
							'amount' =>'4000',
							'currency' =>$postData ['currencyCode'],
							'feespayer' =>$postData ['feesPayer'],
							'billing_cycle_length' =>'0',
							'number_of_recurrences'=>'0',
							'start_date' =>$now,
							'billing_period'=>'0',
							'billing_frequency' =>'0',
							'transaction_request' =>json_encode($payRequest),
							'transaction_response' =>json_encode($response),
							'transaction_sent' =>$olddate,
							'transaction_receive' =>$now
					));
				
					$transactionid =  $this->getTransactionTable()->saveTransaction($transaction);
					/*
					$subscription = new Subscription();
					$subscription->exchangeArray(array(
							'account_id'=>$account_id,
							'preapproval_startdate'=>date('Y-m-d H:i:s',strtotime($postData ['startingDate'])),
							'preapproval_enddate'=>date('Y-m-d H:i:s',strtotime($postData ['endingDate'])),
							'payment_dte_of_month'=>$postData ['dateOfMonth'],
							'currency_code'=>$postData ['currencyCode'],
							'maximum_amount_per_payment'=>$postData ['maxAmountPerPayment'],
							'maximum_no_of_payments'=>$postData ['maxNumberOfPayments'],
							'maximum_no_of_payments_per_period'=>$postData ['maxNumberOfPaymentsPerPeriod'],
							'maximum_total_of_all_payment'=>$postData ['maxTotalAmountOfAllPayments'],
							'payment_period'=>$postData ['paymentPeriod'],
							'sender_email'=>$postData ['senderEmail'],
							'is_pin_required'=>$postData ['pinType'],
							'fees_payer'=>$postData ['feesPayer'],
							'fees_date'=>$now,
							'create_date'=>$now,
							'update_time'=>$now));
					$subscriptionid =  $this->getSubscriptionTable()->saveSubscription($subscription);*/
				
					$accountbalances = new AccountBalances();
					$accountbalances->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_id'=>$transactionid,
							'transaction_type' =>'chained_payment',
							'starting_balance'=>'100',
							'amount'=>'100',
							'ending_balance'=>'10',
							'create_time'=>$now
					));
				
					$accountbalancesid = $this->getAccountBalancesTable()->saveAccountBalances($accountbalances);
				
					//echo '<pre>';print_r($response);exit;
				
				}
				//db
				
			} catch ( Exception $ex ) {
				$exception ['class'] = get_class ( $ex );
				$exception ['message'] = $ex->getMessage ();
				if ($ex instanceof PPConnectionException) {
					$exception ['details_message'] = "Error connecting to " . $ex->getUrl ();
				} else if ($ex instanceof PPConfigurationException) {
					$exception ['details_message'] = "Error at $ex->getLine() in $ex->getFile()";
				} else if ($ex instanceof PPInvalidCredentialException || $x instanceof PPMissingCredentialException) {
					$exception ['details_message'] = $ex->errorMessage ();
				}
			}
		}
		return array ('errors' => $errors, 'exception' => $exception, 'service' => $service, 'response' => $response );
	}
	
	public function refundAction() {
		$postData = array ();
		$errors = array ();
		$exception = array ();
		$response = array ();
		$service = NULL;
		$response = NULL;
		
		$request = $this->getRequest ();
		if ($request->isPost ()) {
			$refundRequest = new RefundRequest ( new RequestEnvelope ( "en_US" ) );
			$postData = $request->getPost ()->toArray ();
			$olddate =  date('Y-m-d H:i:s');
			if (isset ( $postData ['receiverEmail'] )) {
				$receiver = array ();
				for($i = 0; $i < count ( $postData ['receiverEmail'] ); $i ++) {
					$receiver [$i] = new Receiver ();
					$receiver [$i]->email = $postData ['receiverEmail'] [$i];
					$receiver [$i]->amount = $postData ['receiverAmount'] [$i];
					$receiver [$i]->primary = $postData ['primaryReceiver'] [$i];
					
					if ($postData ['invoiceId'] [$i] != "") {
						$receiver [$i]->invoiceId = $postData ['invoiceId'] [$i];
					}
					if ($postData ['paymentType'] [$i] != "" && $postData ['paymentType'] [$i] != $this->_DEFAULT_SELECT) {
						$receiver [$i]->paymentType = $_POST ['paymentType'] [$i];
					}
					if ($postData ['paymentSubType'] [$i] != "") {
						$receiver [$i]->paymentSubType = $postData ['paymentSubType'] [$i];
					}
					if ($postData ['phoneCountry'] [$i] != "" && $postData ['phoneNumber'] [$i]) {
						$receiver [$i]->phone = new PhoneNumberType ( $postData ['phoneCountry'] [$i], $postData ['phoneNumber'] [$i] );
						if ($postData ['phoneExtn'] [$i] != "") {
							$receiver [$i]->phone->extension = $postData ['phoneExtn'] [$i];
						}
					}
				}
				$receiverList = new ReceiverList ( $receiver );
			}
			if ($postData ['currencyCode'] != "") {
				$refundRequest->currencyCode = $postData ["currencyCode"];
			}
			if ($postData ['payKey'] != "") {
				$refundRequest->payKey = $postData ["payKey"];
			}
			if ($postData ['transactionId'] != "") {
				$refundRequest->transactionId = $postData ["transactionId"];
			}
			if ($postData ['trackingId'] != "") {
				$refundRequest->trackingId = $postData ["trackingId"];
			}
			if (isset ( $postData ['receiverEmail'] )) {
				$receiver = array ();
				for($i = 0; $i < count ( $postData ['receiverEmail'] ); $i ++) {
					$receiver [$i] = new Receiver ();
					$receiver [$i]->email = $postData ['receiverEmail'] [$i];
					$receiver [$i]->amount = $postData ['receiverAmount'] [$i];
					$receiver [$i]->primary = $postData ['primaryReceiver'] [$i];
					
					if ($postData ['invoiceId'] [$i] != "") {
						$receiver [$i]->invoiceId = $postData ['invoiceId'] [$i];
					}
					if ($postData ['paymentType'] [$i] != "" && $postData ['paymentType'] [$i] != $this->_DEFAULT_SELECT) {
						$receiver [$i]->paymentType = $postData ['paymentType'] [$i];
					}
					if ($postData ['paymentSubType'] [$i] != "") {
						$receiver [$i]->paymentSubType = $postData ['paymentSubType'] [$i];
					}
					if ($postData ['phoneCountry'] [$i] != "" && $postData ['phoneNumber'] [$i]) {
						$receiver [$i]->phone = new PhoneNumberType ( $postData ['phoneCountry'] [$i], $postData ['phoneNumber'] [$i] );
						if ($postData ['phoneExtn'] [$i] != "") {
							$receiver [$i]->phone->extension = $postData ['phoneExtn'] [$i];
						}
					}
				}
				$refundRequest->receiverList = new ReceiverList ( $receiver );
			}
			
			$service = new AdaptivePaymentsService ();
			try {
				$response = $service->Refund ( $refundRequest );
				
				//db
				if(empty($response->error)){
				
					$now = date('Y-m-d H:i:s');
					$account  = new Account();
					$account->exchangeArray(array(
							'user_id' => '5',
							'account_type' => 'buyer',
							'balance' => '100000',
							'create_time' => $now,
							'update_time' => $now
					));
				
					$account_id =  $this->getAccountTable()->saveAccount($account);
				
					$accountDetail  = new AccountDetail();
					$accountDetail->exchangeArray(array(
							'account_id'=>$account_id,
							'first_name'=>'Nik',
							'last_name'=>'P',
							'address_line1'=>'Mumbai',
							'address_line2'=>'Mumbai',
							'city'=>'Mumbai',
							'state'=>'Maharastra',
							'zip_code'=>'0',
							'postal_code'=>'410206',
							'paypal_credit_card_reference_id'=>'0',
					));
				
				
					$accountDetailid =  $this->getAccountDetailTable()->saveAccountDetail($accountDetail);
				
					$transaction  = new Transaction();
					$transaction->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_type' =>'refund',
							'item_name' =>'refund',
							'pass_fail' =>'1',
							'amount' =>'4000',
							'currency' =>$postData ['currencyCode'],
							'feespayer' =>"0",
							'billing_cycle_length' =>'0',
							'number_of_recurrences'=>'0',
							'start_date' =>$now,
							'billing_period'=>'0',
							'billing_frequency' =>'0',
							'transaction_request' =>'0',
							'transaction_response' =>'0',
							'transaction_sent' =>$olddate,
							'transaction_receive' =>$now
					));
				
					$transactionid =  $this->getTransactionTable()->saveTransaction($transaction);
					/*
					 $subscription = new Subscription();
					$subscription->exchangeArray(array(
							'account_id'=>$account_id,
							'preapproval_startdate'=>date('Y-m-d H:i:s',strtotime($postData ['startingDate'])),
							'preapproval_enddate'=>date('Y-m-d H:i:s',strtotime($postData ['endingDate'])),
							'payment_dte_of_month'=>$postData ['dateOfMonth'],
							'currency_code'=>$postData ['currencyCode'],
							'maximum_amount_per_payment'=>$postData ['maxAmountPerPayment'],
							'maximum_no_of_payments'=>$postData ['maxNumberOfPayments'],
							'maximum_no_of_payments_per_period'=>$postData ['maxNumberOfPaymentsPerPeriod'],
							'maximum_total_of_all_payment'=>$postData ['maxTotalAmountOfAllPayments'],
							'payment_period'=>$postData ['paymentPeriod'],
							'sender_email'=>$postData ['senderEmail'],
							'is_pin_required'=>$postData ['pinType'],
							'fees_payer'=>$postData ['feesPayer'],
							'fees_date'=>$now,
							'create_date'=>$now,
							'update_time'=>$now));
					$subscriptionid =  $this->getSubscriptionTable()->saveSubscription($subscription);*/
				
					$accountbalances = new AccountBalances();
					$accountbalances->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_id'=>$transactionid,
							'transaction_type' =>'chained_payment',
							'starting_balance'=>'100',
							'amount'=>'100',
							'ending_balance'=>'10',
							'create_time'=>$now
					));
				
					$accountbalancesid = $this->getAccountBalancesTable()->saveAccountBalances($accountbalances);
				
					//echo '<pre>';print_r($response);exit;
				
				}
				//db
			} catch ( Exception $ex ) {
				$exception ['class'] = get_class ( $ex );
				$exception ['message'] = $ex->getMessage ();
				if ($ex instanceof PPConnectionException) {
					$exception ['details_message'] = "Error connecting to " . $ex->getUrl ();
				} else if ($ex instanceof PPConfigurationException) {
					$exception ['details_message'] = "Error at $ex->getLine() in $ex->getFile()";
				} else if ($ex instanceof PPInvalidCredentialException || $x instanceof PPMissingCredentialException) {
					$exception ['details_message'] = $ex->errorMessage ();
				}
			}
		}
		
		return array ('errors' => $errors, 'exception' => $exception, 'service' => $service, 'response' => $response );
	}
	
	public function ccrecurringAction() {
		
		$currencyCode = "USD";
		$exception = array ();
		$createRPProfileResponse = NULL;
		$paypalService = NULL;
		$request = $this->getRequest ();
		if ($request->isPost ()) {
			$olddate =  date('Y-m-d H:i:s');
			$postData = $request->getPost ()->toArray ();
			$shippingAddress = new AddressType ();
			$shippingAddress->Name = $postData ['shippingName'];
			$shippingAddress->Street1 = $postData ['shippingStreet1'];
			$shippingAddress->Street2 = $postData ['shippingStreet2'];
			$shippingAddress->CityName = $postData ['shippingCity'];
			$shippingAddress->StateOrProvince = $postData ['shippingState'];
			$shippingAddress->PostalCode = $postData ['shippingPostalCode'];
			$shippingAddress->Country = $postData ['shippingCountry'];
			$shippingAddress->Phone = $postData ['shippingPhone'];
			
			$RPProfileDetails = new RecurringPaymentsProfileDetailsType ();
			$RPProfileDetails->SubscriberName = $postData ['subscriberName'];
			$RPProfileDetails->BillingStartDate = $postData ['billingStartDate'];
			$RPProfileDetails->SubscriberShippingAddress = $shippingAddress;
			
			$activationDetails = new ActivationDetailsType ();
			$activationDetails->InitialAmount = new BasicAmountType ( $currencyCode, $postData ['initialAmount'] );
			$activationDetails->FailedInitialAmountAction = $postData ['failedInitialAmountAction'];
			
			$paymentBillingPeriod = new BillingPeriodDetailsType ();
			$paymentBillingPeriod->BillingFrequency = $postData ['billingFrequency'];
			$paymentBillingPeriod->BillingPeriod = $postData ['billingPeriod'];
			$paymentBillingPeriod->TotalBillingCycles = $postData ['totalBillingCycles'];
			$paymentBillingPeriod->Amount = new BasicAmountType ( $currencyCode, $postData ['paymentAmount'] );
			$paymentBillingPeriod->ShippingAmount = new BasicAmountType ( $currencyCode, $postData ['paymentShippingAmount'] );
			$paymentBillingPeriod->TaxAmount = new BasicAmountType ( $currencyCode, $postData ['paymentTaxAmount'] );
			
			$scheduleDetails = new ScheduleDetailsType ();
			$scheduleDetails->Description = $postData ['profileDescription'];
			$scheduleDetails->ActivationDetails = $activationDetails;
			
			if ($postData ['trialBillingFrequency'] != "" && $postData ['trialAmount'] != "") {
				$trialBillingPeriod = new BillingPeriodDetailsType ();
				$trialBillingPeriod->BillingFrequency = $postData ['trialBillingFrequency'];
				$trialBillingPeriod->BillingPeriod = $postData ['trialBillingPeriod'];
				$trialBillingPeriod->TotalBillingCycles = $postData ['trialBillingCycles'];
				$trialBillingPeriod->Amount = new BasicAmountType ( $currencyCode, $postData ['trialAmount'] );
				$trialBillingPeriod->ShippingAmount = new BasicAmountType ( $currencyCode, $postData ['trialShippingAmount'] );
				$trialBillingPeriod->TaxAmount = new BasicAmountType ( $currencyCode, $postData ['trialTaxAmount'] );
				$scheduleDetails->TrialPeriod = $trialBillingPeriod;
			}
			
			$scheduleDetails->PaymentPeriod = $paymentBillingPeriod;
			if ($postData ['maxFailedPayments'] != "") {
				$scheduleDetails->MaxFailedPayments = $postData ['maxFailedPayments'];
			}
			if ($postData ['autoBillOutstandingAmount'] != "") {
				$scheduleDetails->AutoBillOutstandingAmount = $postData ['autoBillOutstandingAmount'];
			}
			
			$createRPProfileRequestDetail = new CreateRecurringPaymentsProfileRequestDetailsType ();
			$creditCard = new CreditCardDetailsType ();
			$creditCard->CreditCardNumber = $postData ['creditCardNumber'];
			$creditCard->CreditCardType = $postData ['creditCardType'];
			$creditCard->CVV2 = $postData ['cvv'];
			$creditCard->ExpMonth = $postData ['expMonth'];
			$creditCard->ExpYear = $postData ['expYear'];
			$createRPProfileRequestDetail->CreditCard = $creditCard;
			
			$createRPProfileRequestDetail->ScheduleDetails = $scheduleDetails;
			$createRPProfileRequestDetail->RecurringPaymentsProfileDetails = $RPProfileDetails;
			$createRPProfileRequest = new CreateRecurringPaymentsProfileRequestType ();
			$createRPProfileRequest->CreateRecurringPaymentsProfileRequestDetails = $createRPProfileRequestDetail;
			
			$createRPProfileReq = new CreateRecurringPaymentsProfileReq ();
			$createRPProfileReq->CreateRecurringPaymentsProfileRequest = $createRPProfileRequest;
			
			$paypalService = new PayPalAPIInterfaceServiceService ();
			
			try {
				/*
				 * wrap API method calls on the service object with a try catch
				 */
				$createRPProfileResponse = $paypalService->CreateRecurringPaymentsProfile ( $createRPProfileReq );
				
				//db
				if(empty($createRPProfileResponse->error)){
				
					$now = date('Y-m-d H:i:s');
					$account  = new Account();
					$account->exchangeArray(array(
							'user_id' => '5',
							'account_type' => 'buyer',
							'balance' => '100000',
							'create_time' => $now,
							'update_time' => $now
					));
				
					$account_id =  $this->getAccountTable()->saveAccount($account);
				
					$accountDetail  = new AccountDetail();
					$accountDetail->exchangeArray(array(
							'account_id'=>$account_id,
							'first_name'=>'Nik',
							'last_name'=>'P',
							'address_line1'=>'Mumbai',
							'address_line2'=>'Mumbai',
							'city'=>'Mumbai',
							'state'=>'Maharastra',
							'zip_code'=>'0',
							'postal_code'=>'410206',
							'paypal_credit_card_reference_id'=>'0',
					));
				
				
					$accountDetailid =  $this->getAccountDetailTable()->saveAccountDetail($accountDetail);
				
					$transaction  = new Transaction();
					$transaction->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_type' =>'refund',
							'item_name' =>'refund',
							'pass_fail' =>'1',
							'amount' =>'4000',
							'currency' =>$postData ['currency'],
							'feespayer' =>"0",
							'billing_cycle_length' =>'0',
							'number_of_recurrences'=>'0',
							'start_date' =>$now,
							'billing_period'=>'0',
							'billing_frequency' =>'0',
							'transaction_request' =>'0',
							'transaction_response' =>'0',
							'transaction_sent' =>$olddate,
							'transaction_receive' =>$now
					));
				
					$transactionid =  $this->getTransactionTable()->saveTransaction($transaction);
					/*
					 $subscription = new Subscription();
					$subscription->exchangeArray(array(
							'account_id'=>$account_id,
							'preapproval_startdate'=>date('Y-m-d H:i:s',strtotime($postData ['startingDate'])),
							'preapproval_enddate'=>date('Y-m-d H:i:s',strtotime($postData ['endingDate'])),
							'payment_dte_of_month'=>$postData ['dateOfMonth'],
							'currency_code'=>$postData ['currencyCode'],
							'maximum_amount_per_payment'=>$postData ['maxAmountPerPayment'],
							'maximum_no_of_payments'=>$postData ['maxNumberOfPayments'],
							'maximum_no_of_payments_per_period'=>$postData ['maxNumberOfPaymentsPerPeriod'],
							'maximum_total_of_all_payment'=>$postData ['maxTotalAmountOfAllPayments'],
							'payment_period'=>$postData ['paymentPeriod'],
							'sender_email'=>$postData ['senderEmail'],
							'is_pin_required'=>$postData ['pinType'],
							'fees_payer'=>$postData ['feesPayer'],
							'fees_date'=>$now,
							'create_date'=>$now,
							'update_time'=>$now));
					$subscriptionid =  $this->getSubscriptionTable()->saveSubscription($subscription);*/
				
					$accountbalances = new AccountBalances();
					$accountbalances->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_id'=>$transactionid,
							'transaction_type' =>'chained_payment',
							'starting_balance'=>'100',
							'amount'=>'100',
							'ending_balance'=>'10',
							'create_time'=>$now
					));
				
					$accountbalancesid = $this->getAccountBalancesTable()->saveAccountBalances($accountbalances);
				
					//echo '<pre>';print_r($response);exit;
				
				}
				//db
			} catch ( Exception $ex ) {
				if (isset ( $ex )) {
					
					$exception ['ex_message'] = $ex->getMessage ();
					$exception ['ex_type'] = get_class ( $ex );
					
					if ($ex instanceof PPConnectionException) {
						$exception ['ex_detailed_message'] = "Error connecting to " . $ex->getUrl ();
					} else if ($ex instanceof PPMissingCredentialException || $ex instanceof PPInvalidCredentialException) {
						$exception ['ex_detailed_message'] = $ex->errorMessage ();
					} else if ($ex instanceof PPConfigurationException) {
						$exception ['ex_detailed_message'] = "Invalid configuration. Please check your configuration file";
					}
				}
			}
		
		}
		
		return array ('exception' => $exception, 'paypalService' => $paypalService, 'createRPProfileResponse' => $createRPProfileResponse );
	}

	public function directpaymentAction() {
		
		$doDirectPaymentResponse = NULL;
		$request = $this->getRequest ();
		if ($request->isPost ()) {
			$olddate =  date('Y-m-d H:i:s');
			$postData = $request->getPost ()->toArray ();
			$firstName = $postData['firstName'];
			$lastName = $postData['lastName'];

			$address = new AddressType();
			$address->Name = "$firstName $lastName";
			$address->Street1 = $postData['address1'];
			$address->Street2 = $postData['address2'];
			$address->CityName = $postData['city'];
			$address->StateOrProvince = $postData['state'];
			$address->PostalCode = $postData['zip'];
			$address->Country = $postData['country'];
			$address->Phone = $postData['phone'];

			$paymentDetails = new PaymentDetailsType();
			$paymentDetails->ShipToAddress = $address;
			$paymentDetails->OrderTotal = new BasicAmountType('USD', $postData['amount']);
			if(isset($_REQUEST['notifyURL']))
			{
				$paymentDetails->NotifyURL = $postData['notifyURL'];
			}
			

			$personName = new PersonNameType();
			$personName->FirstName = $firstName;
			$personName->LastName = $lastName;

			$payer = new PayerInfoType();
			$payer->PayerName = $personName;
			$payer->Address = $address;
			$payer->PayerCountry = $postData['country'];

			$cardDetails = new CreditCardDetailsType();
			$cardDetails->CreditCardNumber = $postData['creditCardNumber'];
			$cardDetails->CreditCardType = $postData['creditCardType'];
			$cardDetails->ExpMonth = $postData['expDateMonth'];
			$cardDetails->ExpYear = $postData['expDateYear'];
			$cardDetails->CVV2 = $postData['cvv2Number'];
			$cardDetails->CardOwner = $payer;

			$ddReqDetails = new DoDirectPaymentRequestDetailsType();
			$ddReqDetails->CreditCard = $cardDetails;
			$ddReqDetails->PaymentDetails = $paymentDetails;

			$doDirectPaymentReq = new DoDirectPaymentReq();
			$doDirectPaymentReq->DoDirectPaymentRequest = new DoDirectPaymentRequestType($ddReqDetails);
			$paypalService = new PayPalAPIInterfaceServiceService();
			try {
				/* wrap API method calls on the service object with a try catch */
				$doDirectPaymentResponse = $paypalService->DoDirectPayment($doDirectPaymentReq);
				
				//db
				if(empty($doDirectPaymentResponse->error)){
				
					$now = date('Y-m-d H:i:s');
					$account  = new Account();
					$account->exchangeArray(array(
							'user_id' => '5',
							'account_type' => 'buyer',
							'balance' => '100000',
							'create_time' => $now,
							'update_time' => $now
					));
				
					$account_id =  $this->getAccountTable()->saveAccount($account);
				
					$accountDetail  = new AccountDetail();
					$accountDetail->exchangeArray(array(
							'account_id'=>$account_id,
							'first_name'=>$firstName,
							'last_name'=>$lastName,
							'address_line1'=> $postData['address1'],
							'address_line2'=> $postData['address2'],
							'city'=>$postData['city'],
							'state'=>$postData['state'],
							'zip_code'=>$postData['zip'],
							'postal_code'=>$postData['zip'],
							'paypal_credit_card_reference_id'=>'0',
					));
				
				
					$accountDetailid =  $this->getAccountDetailTable()->saveAccountDetail($accountDetail);
				
					$transaction  = new Transaction();
					$transaction->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_type' =>'single_purchase',
							'item_name' =>'Load Account',
							'pass_fail' =>'1',
							'amount' =>$postData['amount'],
							'currency' =>'USD',
							'feespayer' =>"0",
							'billing_cycle_length' =>'0',
							'number_of_recurrences'=>'0',
							'start_date' =>$now,
							'billing_period'=>'0',
							'billing_frequency' =>'0',
							'transaction_request' =>'0',
							'transaction_response' =>'0',
							'transaction_sent' =>$olddate,
							'transaction_receive' =>$now
					));
				
					$transactionid =  $this->getTransactionTable()->saveTransaction($transaction);
					/*
					 $subscription = new Subscription();
					$subscription->exchangeArray(array(
							'account_id'=>$account_id,
							'preapproval_startdate'=>date('Y-m-d H:i:s',strtotime($postData ['startingDate'])),
							'preapproval_enddate'=>date('Y-m-d H:i:s',strtotime($postData ['endingDate'])),
							'payment_dte_of_month'=>$postData ['dateOfMonth'],
							'currency_code'=>$postData ['currencyCode'],
							'maximum_amount_per_payment'=>$postData ['maxAmountPerPayment'],
							'maximum_no_of_payments'=>$postData ['maxNumberOfPayments'],
							'maximum_no_of_payments_per_period'=>$postData ['maxNumberOfPaymentsPerPeriod'],
							'maximum_total_of_all_payment'=>$postData ['maxTotalAmountOfAllPayments'],
							'payment_period'=>$postData ['paymentPeriod'],
							'sender_email'=>$postData ['senderEmail'],
							'is_pin_required'=>$postData ['pinType'],
							'fees_payer'=>$postData ['feesPayer'],
							'fees_date'=>$now,
							'create_date'=>$now,
							'update_time'=>$now));
					$subscriptionid =  $this->getSubscriptionTable()->saveSubscription($subscription);*/
				
					$accountbalances = new AccountBalances();
					$accountbalances->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_id'=>$transactionid,
							'transaction_type' =>'chained_payment',
							'starting_balance'=>'100',
							'amount'=>'100',
							'ending_balance'=>'10',
							'create_time'=>$now
					));
				
					$accountbalancesid = $this->getAccountBalancesTable()->saveAccountBalances($accountbalances);
				
					//echo '<pre>';print_r($response);exit;
				
				}
				//db

			} catch (Exception $ex) {
				$ex_message = "";
				$ex_detailed_message = "";
				$ex_type = "Unknown";

				if(isset($ex)) {

					$ex_message = $ex->getMessage();
					$ex_type = get_class($ex);

					if($ex instanceof PPConnectionException) {
						$ex_detailed_message = "Error connecting to " . $ex->getUrl();
					} else if($ex instanceof PPMissingCredentialException || $ex instanceof PPInvalidCredentialException) {
						$ex_detailed_message = $ex->errorMessage();
					} else if($ex instanceof PPConfigurationException) {
						$ex_detailed_message = "Invalid configuration. Please check your configuration file";
					}
				}
			}

			return array ( 'doDirectPaymentResponse' => $doDirectPaymentResponse );
		}
	}
	
	public function masspayAction() {
		$request = $this->getRequest ();
		$massPayResponse = '';
		if ($request->isPost ()) {
			$olddate = date('Y-m-d H:i:s');
			$massPayRequest = new MassPayRequestType();
			$massPayRequest->MassPayItem = array();
			$postData = $request->getPost ()->toArray ();
			for($i=0; $i<count($postData['mail']); $i++) {
				$masspayItem = new MassPayRequestItemType();
				$masspayItem->Amount = new BasicAmountType($postData['currencyCode'][$i], $postData['amount'][$i]);
				if($postData['receiverInfoCode'] == 'EmailAddress') {
					$masspayItem->ReceiverEmail = $postData['mail'][$i];
				} elseif ($postData['receiverInfoCode'] == 'UserID') {
					$masspayItem->ReceiverID = $postData['id'][$i];
				} elseif ($postData['receiverInfoCode'] == 'PhoneNumber') {
					$masspayItem->ReceiverPhone = $postData['phone'][$i];
				}
				$massPayRequest->MassPayItem[] = $masspayItem;
			}
			$massPayReq = new MassPayReq();
			$massPayReq->MassPayRequest = $massPayRequest;
			
			$paypalService = new PayPalAPIInterfaceServiceService();
			
			// required in third party permissioning
			if(($postData['accessToken']!= null) && ($postData['tokenSecret'] != null)) {
				$paypalService->setAccessToken($postData['accessToken']);
				$paypalService->setTokenSecret($postData['tokenSecret']);
			}
			
			try {
				/* wrap API method calls on the service object with a try catch */
				$massPayResponse = $paypalService->MassPay($massPayReq);
				
				
				//db
				if(empty($massPayResponse->error)){
				
					$now = date('Y-m-d H:i:s');
					$account  = new Account();
					$account->exchangeArray(array(
							'user_id' => '5',
							'account_type' => 'buyer',
							'balance' => '100000',
							'create_time' => $now,
							'update_time' => $now
					));
				
					$account_id =  $this->getAccountTable()->saveAccount($account);
				
					$accountDetail  = new AccountDetail();
					$accountDetail->exchangeArray(array(
							'account_id'=>$account_id,
							'first_name'=>'Nik',
							'last_name'=>'P',
							'address_line1'=>'Mumbai',
							'address_line2'=>'Mumbai',
							'city'=>'Mumbai',
							'state'=>'Maharastra',
							'zip_code'=>'0',
							'postal_code'=>'410206',
							'paypal_credit_card_reference_id'=>'0',
					));
				
				
					$accountDetailid =  $this->getAccountDetailTable()->saveAccountDetail($accountDetail);
				
					$transaction  = new Transaction();
					$transaction->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_type' =>'refund',
							'item_name' =>'refund',
							'pass_fail' =>'1',
							'amount' =>'4000',
							'currency' =>$postData ['currencyCode'],
							'feespayer' =>"0",
							'billing_cycle_length' =>'0',
							'number_of_recurrences'=>'0',
							'start_date' =>$now,
							'billing_period'=>'0',
							'billing_frequency' =>'0',
							'transaction_request' =>'0',
							'transaction_response' =>'0',
							'transaction_sent' =>$olddate,
							'transaction_receive' =>$now
					));
				
					$transactionid =  $this->getTransactionTable()->saveTransaction($transaction);
					/*
					 $subscription = new Subscription();
					$subscription->exchangeArray(array(
							'account_id'=>$account_id,
							'preapproval_startdate'=>date('Y-m-d H:i:s',strtotime($postData ['startingDate'])),
							'preapproval_enddate'=>date('Y-m-d H:i:s',strtotime($postData ['endingDate'])),
							'payment_dte_of_month'=>$postData ['dateOfMonth'],
							'currency_code'=>$postData ['currencyCode'],
							'maximum_amount_per_payment'=>$postData ['maxAmountPerPayment'],
							'maximum_no_of_payments'=>$postData ['maxNumberOfPayments'],
							'maximum_no_of_payments_per_period'=>$postData ['maxNumberOfPaymentsPerPeriod'],
							'maximum_total_of_all_payment'=>$postData ['maxTotalAmountOfAllPayments'],
							'payment_period'=>$postData ['paymentPeriod'],
							'sender_email'=>$postData ['senderEmail'],
							'is_pin_required'=>$postData ['pinType'],
							'fees_payer'=>$postData ['feesPayer'],
							'fees_date'=>$now,
							'create_date'=>$now,
							'update_time'=>$now));
					$subscriptionid =  $this->getSubscriptionTable()->saveSubscription($subscription);*/
				
					$accountbalances = new AccountBalances();
					$accountbalances->exchangeArray(array(
							'account_id' =>$account_id,
							'transaction_id'=>$transactionid,
							'transaction_type' =>'chained_payment',
							'starting_balance'=>'100',
							'amount'=>'100',
							'ending_balance'=>'10',
							'create_time'=>$now
					));
				
					$accountbalancesid = $this->getAccountBalancesTable()->saveAccountBalances($accountbalances);
				
					//echo '<pre>';print_r($response);exit;
				
				}
				//db
			} catch (Exception $ex) {
				$ex_message = "";
				$ex_detailed_message = "";
				$ex_type = "Unknown";
				
				if(isset($ex)) {
				
					$ex_message = $ex->getMessage();
					$ex_type = get_class($ex);
				
					if($ex instanceof PPConnectionException) {
						$ex_detailed_message = "Error connecting to " . $ex->getUrl();
					} else if($ex instanceof PPMissingCredentialException || $ex instanceof PPInvalidCredentialException) {
						$ex_detailed_message = $ex->errorMessage();
					} else if($ex instanceof PPConfigurationException) {
						$ex_detailed_message = "Invalid configuration. Please check your configuration file";
					}
				}
			}
			
			return array ( 'massPayResponse' => $massPayResponse );
		}
		
	}
}
