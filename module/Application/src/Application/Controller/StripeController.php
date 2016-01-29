<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Session\Container;
use Application\Model;
use Application\Model\UserTable;
use Application\Form;
use Guzzle\Http\Client;
use Application\Model\MemreasConstants;
use Application\memreas\AWSMemreasRedisCache;
use Application\memreas\AWSMemreasRedisSessionHandler;
use Application\memreas\MemreasStripe;
use Application\memreas\Mlog;
use Application\memreas\StripePlansConfig;

class StripeController extends AbstractActionController {

	//
	// start session by fetching and starting from REDIS - security check
	//
	public function setupSaveHandler() {
		//start capture
		ob_start();
		
		$this->redis = new AWSMemreasRedisCache ( $this->getServiceLocator () );
		$this->sessHandler = new AWSMemreasRedisSessionHandler ( $this->redis, $this->getServiceLocator () );
		session_set_save_handler ( $this->sessHandler );
	
		//clean the buffer we don't need to send back session data
		ob_end_clean();
		
	}
	public function fetchSession() {
		
		
		$cm = __CLASS__ . __METHOD__;
		/**
		 * Setup save handler and start session
		 */
		$hasSession = false;
		header ( 'Access-Control-Allow-Origin: *' );
		$this->setupSaveHandler ();
		try {
			if (!empty($_REQUEST ['json'])) {
				$json = $_REQUEST ['json'];
				Mlog::addone ( $cm . __LINE__ . '$json', $json );
				$jsonArr = json_decode ( $json, true );
				$memreascookie = $jsonArr ['memreascookie'];
				Mlog::addone ( $cm . __LINE__ . '$memreascookie', $memreascookie );
				// $memreascookieArr = json_decode ( $memreascookie, true );
				// Mlog::addone($cm.__LINE__.'$memreascookieArr[$memreascookie]', $memreascookieArr['memreascookie']);
				$this->sessHandler->startSessionWithMemreasCookie ( $memreascookie );
				$hasSession = true;
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Redis Session found->', $_SESSION );
			} else if (!empty($_REQUEST ['sid'])) {
				$sid = $_REQUEST ['sid'];
				Mlog::addone ( $cm . __LINE__ . '$sid', $sid );
				$this->sessHandler->startSessionWithSID ( $sid );
				$hasSession = true;
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
		if ($this->fetchSession ()) {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
		}
	}
	
	/*
	 * List stripe plan
	 */
	public function listPlanAction() {
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$message_data = $jsonArr ['json'];
				// Mlog::addone ( __CLASS__ . __METHOD__.'::$message_data::', $message_data);
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				echo $callback . "(" . json_encode ( $MemreasStripe->listPlans () ) . ")";
				die ();
			}
		}
	}
	
	/*
	 * Ajax functions
	 */
	public function storeCardAction() {
		if ($this->fetchSession ()) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '::request', $_REQUEST );
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$message_data = $jsonArr ['json'];
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				Mlog::addone ( __CLASS__ . __METHOD__ . '::$message_data', $message_data );
				
				// Prepare card data - CVC cannot be stored
				$card_data = array (
						'user_id' => $message_data ['user_id'],
						'number' => $message_data ['credit_card_number'],
						'type' => $message_data ['credit_card_type'],
						'exp_month' => $message_data ['expiration_month'],
						'exp_year' => $message_data ['expiration_year'],
						'cvc' => '',
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
				// header('application/json');
				Mlog::addone ( __CLASS__ . __METHOD__ . '::$result', $result );
				echo $callback . "(" . json_encode ( $MemreasStripe->storeCard ( $card_data ) ) . ")";
				die ();
			}
		}
	}
	public function listCardsAction() {
		if ($this->fetchSession ()) {
			Mlog::addone ( __CLASS__ . __METHOD__, "::Enter listCardsAction" );
			if (isset ( $_REQUEST ['callback'] )) {
				Mlog::addone ( __CLASS__ . __METHOD__, "::has Callback" );
				$callback = $_REQUEST ['callback'];
				Mlog::addone ( __CLASS__ . __METHOD__ . '$callback-->', $callback );
				$json = $_REQUEST ['json'];
				Mlog::addone ( __CLASS__ . __METHOD__ . '$json-->', $json );
				$jsonArr = json_decode ( $json, true );
				
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$out = $MemreasStripe->listCards ( $_SESSION ['user_id'] );
				Mlog::addone ( __CLASS__ . __METHOD__ . '$out-->', $callback . "(" . json_encode ( $out ) . ")" );
				echo $callback . "(" . json_encode ( $out ) . ")";
				die ();
			}
		}
	}
	public function viewCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				echo $callback . "(" . json_encode ( $MemreasStripe->listCard ( $jsonArr ['json'] ) ) . ")";
				die ();
			}
		}
	}
	public function updateCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				echo $callback . "(" . json_encode ( $MemreasStripe->saveCard ( $jsonArr ['json'] ) ) . ")";
				die ();
			}
		}
	}
	public function deleteCardsAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				echo $callback . "(" . json_encode ( $MemreasStripe->deleteCards ( $jsonArr ['json'] ) ) . ")";
				die ();
			}
		}
	}
	public function addSellerAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$addSeller = $MemreasStripe->addSeller ( $jsonArr ['json'] );
				echo $callback . "(" . json_encode ( $addSeller ) . ")";
				die ();
			}
		}
	}
	public function addValueAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$addValue = $MemreasStripe->addValueToAccount ( $jsonArr ['json'] );
				echo $callback . "(" . json_encode ( $addValue ) . ")";
				die ();
			}
		}
	}
	public function decrementAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$decrement = $MemreasStripe->decrementAmount ( $jsonArr ['json'] );
				echo $callback . "(" . json_encode ( $decrement ) . ")";
				die ();
			}
		}
	}
	public function accounthistoryAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$accountHistory = $MemreasStripe->AccountHistory ( $jsonArr ['json'] );
				echo $callback . "(" . json_encode ( $accountHistory ) . ")";
				die ();
			}
		}
	}
	public function subscribeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$accountHistory = $MemreasStripe->setSubscription ( $jsonArr ['json'] );
				echo $callback . "(" . json_encode ( $accountHistory ) . ")";
				die ();
			}
		}
	}
	public function listMassPayeeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$accountHistory = $MemreasStripe->listMassPayee ( 1, 10 );
				echo $callback . "(" . json_encode ( $accountHistory ) . ")";
				die ();
			}
		}
	}
	public function getCustomerInfoAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$customer = $MemreasStripe->getCustomer ( $jsonArr ['json'], true );
				Mlog::addone ( __CLASS__ . __METHOD__ . '$customer', $customer );
				echo $callback . "(" . json_encode ( $customer ) . ")";
				die ();
			} else if(isset($_REQUEST ['sid'])) {
				$user_id = $_POST ['user_id'];
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$result = $MemreasStripe->getCustomer ( array (
									'userid' => $user_id 
							), false );
				Mlog::addone ( __CLASS__ . __METHOD__ . '$result', $result );
				echo json_encode ( $result );
				die ();
				
			}
		}
	}
	public function activeCreditAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$token = $_GET ['token'];
			$activeBalance = $MemreasStripe->activePendingBalanceToAccount ( $token );
			echo '<h3 style="text-align: center">' . $activeBalance ['message'] . '</h3>';
			if ($activeBalance ['status'] == 'Success')
				echo '<script type="text/javascript">document.location.href="http://fe.memreas.com";</script>';
			die ();
		}
	}
	public function getUserBalanceAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$customer = $MemreasStripe->getAccountBalance ( $jsonArr ['json'], true );
				echo $callback . "(" . json_encode ( $customer ) . ")";
				die ();
			}
		}
	}
	public function buyMediaAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$result = $MemreasStripe->buyMedia ( $jsonArr ['json'], true );
				echo $callback . "(" . json_encode ( $result ) . ")";
				die ();
			}
		}
	}
	public function checkOwnEventAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			if (isset ( $_REQUEST ['callback'] )) {
				$callback = $_REQUEST ['callback'];
				$json = $_REQUEST ['json'];
				$jsonArr = json_decode ( $json, true );
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				$result = $MemreasStripe->checkOwnEvent ( $jsonArr ['json'], true );
				echo $callback . "(" . json_encode ( $result ) . ")";
				die ();
			}
		}
	}
	public function testAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if ($this->fetchSession ()) {
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$result = $MemreasStripe->MakePayout ( array (
					'account_id' => 'fda56136-c589-43f5-bad3-28ff45d4631a',
					'amount' => 1,
					'description' => 'explain something' 
			) );
			echo '<pre>';
			print_r ( $result );
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