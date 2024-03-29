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
use Application\memreas\AWSStripeManagerSender;
use Application\memreas\Mlog;
use Application\Model\MemreasConstants;
use Guzzle\Http\Client;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class StripeController extends AbstractActionController {
	private $redis;
	private $sessHandler;
	private $aws;
	//
	// start session by fetching and starting from REDIS - security check
	//
	public function setupSaveHandler() {
		try {
			$this->redis = new AWSMemreasRedisCache ( $this->getServiceLocator () );
			$this->sessHandler = new AWSMemreasRedisSessionHandler ( $this->redis, $this->getServiceLocator () );
			session_set_save_handler ( $this->sessHandler );
		} catch ( \Exception $e ) {
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::setupSaveHandler() error->', $e->getMessage () );
		}
	}
	public function flushResponse($response, $content_type= "appplication/json") {
		//header ( "Content-Type: $content_type" );
		// $arr = headers_list ();
		// Mlog::addone ( 'response headers -->', $arr );
		Mlog::addone ( __CLASS__.__METHOD__.__LINE__.'response-->', $response );
		echo $response;
		// clean the buffer we don't need to send back session data
		ob_end_flush ();
		flush ();
	}
	public function fetchSession($json) {
		$message_data = json_decode ( $json, true );
		// Mlog::addone ( __CLASS__ . __METHOD__ . '::$_SERVER-->', $_SERVER );
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$_REQUEST-->', $_REQUEST );
		// start capture
		ob_start ();
		/**
		 * setup aws here since this is always called
		 */
		$this->aws = new AWSStripeManagerSender ();
		$cm = __CLASS__ . __METHOD__;
		/**
		 * Setup save handler and start session
		 */
		$hasSession = false;
		header ( 'Access-Control-Allow-Origin: *' );
		$this->setupSaveHandler ();
		try {
			if (! empty ( $_REQUEST ['admin_key'] )) {
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::admin_key-->', $_REQUEST ['admin_key'] );
				$redis_admin_key = $this->redis->getCache ( 'admin_key' );
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$this->redis->getCache(admin_key)-->', $this->redis->getCache ( 'admin_key' ) );
				$hasSession = false;
				if ($redis_admin_key == $_REQUEST ['admin_key']) {
					// $username = $this->redis->getCache ( 'admin_key' );
					if (! empty ( $message_data ['username'] )) {
						$this->sessHandler->startSessionWithUID ( '', $message_data ['username'] );
						$hasSession = true;
					} else if (! empty ( $message_data ['user_name'] )) {
						$this->sessHandler->startSessionWithUID ( '', $message_data ['user_name'] );
						$hasSession = true;
					} else if (! empty ( $message_data ['user_id'] )) {
						$this->sessHandler->startSessionWithUID ( '', $message_data ['user_id'] );
						$hasSession = true;
					}
					Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$_SESSION-->', $_SESSION );
				}
			} else if (! empty ( $_REQUEST ['memreascookie'] )) {
				$sid = $_REQUEST ['memreascookie'];
				$this->sessHandler->startSessionWithMemreasCookie ( $_REQUEST ['memreascookie'] );
				$hasSession = true;
			} else if (! empty ( $_REQUEST ['sid'] )) {
				$sid = $_REQUEST ['sid'];
				// Mlog::addone ( $cm . __LINE__ . '$sid', $sid );
				$this->sessHandler->startSessionWithSID ( $sid );
				$hasSession = true;
				// Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session found->', $_SESSION );
			} else if (! empty ( $_REQUEST ['json'] )) {
				$json = $_REQUEST ['json'];
				// Mlog::addone ( $cm . __LINE__ . '$json', $json );
				$jsonArr = json_decode ( $json, true );
				$memreascookie = $jsonArr ['memreascookie'];
				// Mlog::addone ( $cm . __LINE__ . '$memreascookie', $memreascookie );
				$this->sessHandler->startSessionWithMemreasCookie ( $memreascookie );
				$hasSession = true;
				// Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session found->', $_SESSION );
			}
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::$_SESSION-->', $_SESSION );
		} catch ( \Exception $e ) {
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session lookup error->', $e->getMessage () );
		}
		
		//
		// If session is valid on ws we can proceed
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
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
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
		Mlog::addone ( $cm . __LINE__ . '::$_REQUEST::', $_REQUEST );
		if (stripos ( $_SERVER ['HTTP_USER_AGENT'], 'stripe' ) !== false) {
			
			\Stripe\Stripe::setApiKey ( MemreasConstants::SECRET_KEY );
			
			// Retrieve the request's body and parse it as JSON
			$input = @file_get_contents ( "php://input" );
			Mlog::addone ( $cm . __LINE__, 'webHookReceiver() received php://input' );
			$eventArr = json_decode ( $input, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'initialized' );
			$MemreasStripe->webHookReceiver ($eventArr);
				
			Mlog::addone ( $cm . __LINE__ . '::$event_json::', $eventArr );
			
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
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'about to create new MemreasStripe ( $this->getServiceLocator (), $this->aws )' );
		 * $MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
		 * Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__, 'initialized' );
		 * $MemreasStripe->webHookReceiver ();
		 * }
		 */
		die ();
	}
	
	/*
	 * Create Customer Action
	 */
	public function createCustomerAction() {
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '$_REQUEST', $_REQUEST );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$json::', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$message_data::', $message_data );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->createCustomer ( $message_data ) ) );
			die ();
		}
	}
	
	/*
	 * List stripe plan
	 */
	public function listPlanAction() {
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '$_REQUEST', $_REQUEST );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$json::', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$message_data::', $message_data );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->listPlans () ) );
			die ();
		}
	}
	
	/*
	 * Ajax functions
	 */
	public function storeCardAction() {
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '::request', $_REQUEST );
			$json = $_REQUEST ['json'];
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
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
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			Mlog::addone ( __CLASS__ . __METHOD__, "::Enter listCardsAction" );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->listCards ( $_SESSION ['user_id'] ) ) );
			die ();
		}
	}
	public function viewCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->listCard ( $message_data ) ) );
			die ();
		}
	}
	public function updateCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->saveCard ( $message_data ) ) );
			die ();
		}
	}
	public function deleteCardsAction() {
		Mlog::addone ( __CLASS__ . __METHOD__.__LINE__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			Mlog::addone ( __CLASS__ . __METHOD__.__LINE__, 'past fetchSession' );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__.__LINE__, '...' );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			Mlog::addone ( __CLASS__ . __METHOD__.__LINE__, '...' );
			$response = json_encode ( $MemreasStripe->deleteCards ( $message_data ) );
			Mlog::addone ( __CLASS__ . __METHOD__.__LINE__, '...' );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$response-->', $response);
			$this->flushResponse ( $response );
			die ();
		}
	}
	public function addSellerAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->addSeller ( $message_data ) ) );
			die ();
		}
	}
	public function addValueAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->addValueToAccount ( $message_data ) ) );
			die ();
		}
	}
	public function decrementAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->decrementAccount ( $message_data ) ) );
			die ();
		}
	}
	public function accounthistoryAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->AccountHistory ( $message_data ) ) );
			die ();
		}
	}
	public function subscribeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->setSubscription ( $message_data ) ) );
			die ();
		}
	}
	public function getCustomerInfoAction() {
		Mlog::addone(__CLASS__.__METHOD__.__LINE__.'$_REQUEST [json]--->', $_REQUEST ['json']);
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$customer = $MemreasStripe->getCustomer ( array (
					'user_id' => $_SESSION ['user_id']
			), true );
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::json::$customer', json_encode ( $customer ) );
			$this->flushResponse ( json_encode ( $customer ) );
			die ();
		}
	}
	public function activeCreditAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST );
		if (isset ( $_REQUEST ['token'] )) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$token = $_REQUEST ['token'];
			$cid = $_REQUEST ['cid'];
			$activeBalance = $MemreasStripe->activePendingBalanceToAccount ( $token, $cid );
			// echo '<h3 style="text-align: center">' . $activeBalance ['message'] . '</h3>';
			if ($activeBalance ['status'] == 'Success') {
				//send back text with redirect
				$content_type = 'application/text';
				$redirect = 'Location: ' . MemreasConstants::MEMREAS_FE . '/?credits_activated=1';
				$this->flushResponse ($redirect, $content_type);
			} else if ($activeBalance ['status'] == 'activated') {
				//send back text with redirect
				$content_type = 'application/text';
				$redirect = 'Location: ' . MemreasConstants::MEMREAS_FE . '/?credits_already_activated=1';
				$this->flushResponse ($redirect, $content_type);
			} else if ($activeBalance ['status'] == 'Failure') {
				$html = 'An error has occurred for token:: ' . $token . ' Please email ' . MemreasConstants::ADMIN_EMAIL . ' with your token information.';
				$content_type = 'application/text';
				$this->flushResponse ('$html', $header);
			}
			die ();
		}
	}
	public function getUserBalanceAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->getAccountBalance ( $message_data, true ) ) );
			die ();
		}
	}
	public function buyMediaAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->buyMedia ( $message_data, true ) ) );
			die ();
		}
	}
	public function checkOwnEventAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->checkOwnEvent ( $message_data, true ) ) );
			die ();
		}
	}
	public function listMassPayeeAction() {
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			/*
			 * if (empty ( $_REQUEST ['admin_key'] )) {
			 * // Only admins allowed
			 * die ();
			 * }
			 */
			Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
			$json = $_REQUEST ['json'];
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->listMassPayee ( $message_data ) ) );
			die ();
		}
	}
	public function getOrderHistoryAction() {
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$message_data-->', $message_data );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$orderHistories = $MemreasStripe->getOrderHistories ( $message_data ['user_id'], $message_data ['search_username'], ( int ) $message_data ['page'], ( int ) $message_data ['limit'] );
			if ($orderHistories ['status'] == "Success") {
				if ($message_data ['user_id']) {
					
					$userDetail = $MemreasStripe->getUserById ( $message_data ['user_id'] );
				} else {
					
					$userDetail = null;
				}
				
				$orders = $orderHistories ['transactions'];
				
				if (! empty ( $orders )) {
					
					$result = array (
							'status' => 'Success',
							'orders' => $orders,
							'user' => $userDetail 
					);
				} else {
					
					$result = array (
							'status' => 'Failure',
							'message' => 'No record found' 
					);
				}
			} else {
				
				$result = array (
						'status' => 'Failure',
						'message' => $orderHistories ['message'] 
				);
			}
			$this->flushResponse ( json_encode ( $result ) );
			die ();
		}
	}
	public function payeePayoutAction() {
		/*
		 * if (empty ( $_REQUEST ['admin_key'] )) {
		 * // Only admins allowed
		 * die ();
		 * }
		 */
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$message_data-->', $message_data );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->MakePayout ( $message_data ) ) );
			die ();
		}
	}
	public function refundAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ( $_REQUEST ['json'] )) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$json = $_REQUEST ['json'];
			Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
			$message_data = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
			$this->flushResponse ( json_encode ( $MemreasStripe->refundAmount ( $message_data ) ) );
			die ();
		}
	}
	public function resetDataAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
		$MemreasStripe->memreasStripeTables->getAccountTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getAccountBalancesTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getAccountDetailTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getPaymentMethodTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getTransactionTable ()->deleteAll ();
		die ( 'done' );
	}
}