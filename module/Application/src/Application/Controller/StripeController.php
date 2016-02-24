<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Controller;

use Application\memreas\AWSMemreasRedisCache;
use Application\memreas\AWSMemreasRedisSessionHandler;
use Application\memreas\MemreasStripe;
use Application\memreas\Mlog;
use Application\Model\MemreasConstants;
use Guzzle\Http\Client;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class StripeController extends AbstractActionController {
	
	private $redis;
	private $sessHandler;
	//
	// start session by fetching and starting from REDIS - security check
	//
	public function setupSaveHandler() {
		$this->redis = new AWSMemreasRedisCache ( $this->getServiceLocator () );
		$this->sessHandler = new AWSMemreasRedisSessionHandler ( $this->redis, $this->getServiceLocator () );
		session_set_save_handler ( $this->sessHandler );
	}
	public function flushResponse($response) {
		header ( 'Content-Type: application/json' );
		// $arr = headers_list ();
		// Mlog::addone ( 'response headers -->', $arr );
		Mlog::addone ( 'response-->', $response );
		echo $response;
		// clean the buffer we don't need to send back session data
		ob_end_flush ();
		flush ();
	}
	public function fetchSession() {
		// Mlog::addone ( __CLASS__ . __METHOD__ . '::$_SERVER-->', $_SERVER );
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$_REQUEST-->', $_REQUEST );
		$cm = __CLASS__ . __METHOD__;
		// start capture
		ob_start ();
		/**
		 * Setup save handler and start session
		 */
		$hasSession = false;
		header ( 'Access-Control-Allow-Origin: *' );
		$this->setupSaveHandler ();
		try {
			if (! empty ( $_REQUEST ['admin_key'] )) {
				$username = $this->redis->getCache('admin_key');
				$this->sessHandler->startSessionWithUID('', $username );
				$hasSession = true;
			} else if (! empty ( $_REQUEST ['memreascookie'] )) {
				$sid = $_REQUEST ['memreascookie'];
				$this->sessHandler->startSessionWithMemreasCookie($_REQUEST ['memreascookie'] );
				$hasSession = true;
			} else if (! empty ( $_REQUEST ['sid'] )) {
				$sid = $_REQUEST ['sid'];
				Mlog::addone ( $cm . __LINE__ . '$sid', $sid );
				$this->sessHandler->startSessionWithSID ( $sid );
				$hasSession = true;
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session found->', $_SESSION );
			} else if (! empty ( $_REQUEST ['json'] )) {
				$json = $_REQUEST ['json'];
				Mlog::addone ( $cm . __LINE__ . '$json', $json );
				$jsonArr = json_decode ( $json, true );
				$memreascookie = $jsonArr ['memreascookie'];
				Mlog::addone ( $cm . __LINE__ . '$memreascookie', $memreascookie );
				$this->sessHandler->startSessionWithMemreasCookie ( $memreascookie );
				$hasSession = true;
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session found->', $_SESSION );
			}
		} catch ( \Exception $e ) {
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session lookup error->', $e->getMessage () );
		}
		
		//
		// If session is valid on wsj we can proceed
		//
		return $hasSession;
	}
	public function fetchXML($action, $xml) {
		$guzzle = new Client ();
		
		$request = $guzzle->post ( $this->url, null, array (
				'action' => $action,
				// 'cache_me' => true,
				'xml' => $xml 
		) );
		$response = $request->send ();
		return $data = $response->getBody ( true );
	}
	public function indexAction() {
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$_SERVER-->', $_SERVER );
		if ($this->fetchSession ()) {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
		}
	}
	
	/*
	 * Stripe webhook receiver
	 */
	public function webHookReceiverAction() {
		/**
		 * -
		 * Session is not required for webhooks
		 */
		$cm = __CLASS__ . __METHOD__;
		if (stripos ( $_SERVER ['HTTP_USER_AGENT'], 'stripe' ) !== false) {
			
			\Stripe\Stripe::setApiKey ( MemreasConstants::SECRET_KEY );
			
			// Retrieve the request's body and parse it as JSON
			$input = @file_get_contents ( "php://input" );
			Mlog::addone ( $cm . __LINE__, 'webHookReceiver() received php://input' );
			$event_json = json_decode ( $input );
			Mlog::addone ( $cm . __LINE__ . '::$event_json::', $event_json );
			
			// Do something with $event_json
			http_response_code ( 200 ); // PHP 5.4 or greater
			Mlog::addone ( $cm . __LINE__, 'exit webHookReceiver()' );
		}
		
		/*
		 * Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
		 *
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'enter' );
		 * // Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$_SERVER', $_SERVER );
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$_SERVER [HTTP_USER_AGENT]', $_SERVER ['HTTP_USER_AGENT'] );
		 * if (stripos ( $_SERVER ['HTTP_USER_AGENT'], 'stripe' ) !== false) {
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'inside if...' );
		 * // $url = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
		 * // Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__.'referrer host ---->', $url );
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'about to create new MemreasStripe ( $this->getServiceLocator () )' );
		 * $MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'initialized' );
		 * $MemreasStripe->webHookReceiver ();
		 * }
		 */
		die ();
	}
	
	/*
	 * List stripe plan
	 */
	public function listPlanAction() {
		if ($this->fetchSession ()) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '$_REQUEST', $_REQUEST );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$json::', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$message_data::', $message_data );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->listPlans () ) );
			die ();
		}
	}
	
	/*
	 * Ajax functions
	 */
	public function storeCardAction() {
		if ($this->fetchSession ()) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '::request', $_REQUEST );
			$json = $_REQUEST ['json'];
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$message_data', $message_data );
			
			// Prepare card data - CVC cannot be stored
			$card_data = array (
					'user_id' => $message_data ['user_id'],
					'number' => $message_data ['credit_card_number'],
					'type' => $message_data ['credit_card_type'],
					'exp_month' => $message_data ['expiration_month'],
					'exp_year' => $message_data ['expiration_year'],
					'cvc' => $message_data ['cvc'],
					'name' => $message_data ['first_name'] . ' ' . $message_data ['last_name'],
					'country' => 'US', // Change this to dynamic form value
					'address_line1' => $message_data ['address_line_1'],
					'address_line2' => $message_data ['address_line_2'],
					'address_city' => $message_data ['city'],
					'address_state' => $message_data ['state'],
					'address_zip' => $message_data ['zip_code'],
					'address_country' => 'US' 
			); // Change this to dynamic form value
			
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$card_data', $card_data );
			// echo $callback . "(" . json_encode($MemreasStripe->storeCard($card_data)) . ")";
			$this->flushResponse ( json_encode ( $MemreasStripe->storeCard ( $card_data ) ) );
			die ();
		}
	}
	public function listCardsAction() {
		if ($this->fetchSession ()) {
			Mlog::addone ( __CLASS__ . __METHOD__, "::Enter listCardsAction" );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->listCards ( $_SESSION ['user_id'] ) ) );
			die ();
		}
	}
	public function viewCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->listCard ( $message_data ) ) );
			die ();
		}
	}
	public function updateCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->saveCard ( $message_data ) ) );
			die ();
		}
	}
	public function deleteCardsAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->deleteCards ( $message_data ) ) );
			die ();
		}
	}
	public function addSellerAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->addSeller ( $message_data ) ) );
			die ();
		}
	}
	public function addValueAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->addValueToAccount ( $message_data ) ) );
			die ();
		}
	}
	public function decrementAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->decrementAccount ( $message_data ) ) );
			die ();
		}
	}
	public function accounthistoryAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->AccountHistory ( $message_data ) ) );
			die ();
		}
	}
	public function subscribeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->setSubscription ( $message_data ) ) );
			die ();
		}
	}
	public function listMassPayeeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$message_data-->', $message_data );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			Mlog::addone ( __CLASS__ . __METHOD__, 'About to enter listMassPayee...' );
			$this->flushResponse ( json_encode ( $MemreasStripe->listMassPayee () ) );
			die ();
		}
	}
	public function getCustomerInfoAction() {
		if ($this->fetchSession ()) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$customer = $MemreasStripe->getCustomer ( array (
					'userid' => $_SESSION['user_id'] 
			), true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$customer', $customer );
			$this->flushResponse ( json_encode ( $customer ) );
			die ();
		}
	}
	public function activeCreditAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if (isset ( $_REQUEST ['token'] )) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$token = $_REQUEST ['token'];
			$activeBalance = $MemreasStripe->activePendingBalanceToAccount ( $token );
			// echo '<h3 style="text-align: center">' . $activeBalance ['message'] . '</h3>';
			if ($activeBalance ['status'] == 'Success') {
				$this->flushResponse ( '<script type="text/javascript">document.location.href="' . MemreasConstants::MEMREAS_FE . '/?credits_activated=1";</script>' );
			} else if ($activeBalance ['status'] == 'activated') {
				$this->flushResponse ( '<h3 style="text-align: center">' . $activeBalance ['message'] . '</h3><script type="text/javascript">document.location.href="' . MemreasConstants::MEMREAS_FE . '/?credits_already_activated=1";</script>' );
			} else if ($activeBalance ['status'] == 'Failure') {
				$this->flushResponse ( '<h3 style="text-align: center">An error has occurred for token:: ' . $token . ' Please email ' . MemreasConstants::ADMIN_EMAIL . ' with your token information.</h3>' );
			}
			die ();
		}
	}
	public function getUserBalanceAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->getAccountBalance ( message_data, true ) ) );
			die ();
		}
	}
	public function buyMediaAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->buyMedia ( $message_data, true ) ) );
			die ();
		}
	}
	public function checkOwnEventAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->checkOwnEvent ( message_data, true ) ) );
			die ();
		}
	}
	public function payeePayoutAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$this->flushResponse ( json_encode ( $MemreasStripe->MakePayout ( $message_data ) ) );
			die ();
		}
	}
	public function resetDataAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
		$MemreasStripe->memreasStripeTables->getAccountTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getAccountBalancesTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getAccountDetailTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getPaymentMethodTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getTransactionTable ()->deleteAll ();
		die ( 'done' );
	}
}