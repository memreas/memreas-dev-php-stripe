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
use Application\memreas\MemreasStripe;
use Application\memreas\Mlog;
use Application\memreas\StripePlansConfig;

class StripeController extends AbstractActionController {
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
		$view = new ViewModel ();
		$view->setTemplate ( 'application/stripe/index.phtml' );
		return $view;
	}
	
	/*
	 * List stripe plan
	 */
	public function listPlanAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if (isset ( $_REQUEST ['callback'] )) {
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			$message_data = $jsonArr ['json'];
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			echo $callback . "(" . json_encode ( $MemreasStripe->listPlans () ) . ")";
			die ();
		}
	}
	
	/*
	 * Ajax functions
	 */
	public function storeCardAction() {
		header ( 'Access-Control-Allow-Origin: *' );
		Mlog::addone ( __CLASS__ . __METHOD__ . '::request', $_REQUEST );
		// if (isset($_REQUEST['callback'])){
		// $callback = $_REQUEST['callback'];
		$json = $_REQUEST ['json'];
		$jsonArr = json_decode ( $json, true );
		$message_data = $jsonArr ['json'];
		$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$message_data', $message_data );
		
		// Prepare card data
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
		) // Change this to dynamic form value
;
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$card_data', $card_data );
		// echo $callback . "(" . json_encode($MemreasStripe->storeCard($card_data)) . ")";
		// header('application/json');
		$result = json_encode ( $MemreasStripe->storeCard ( $card_data ) );
		Mlog::addone ( __CLASS__ . __METHOD__ . '::$result', $result );
		echo $result;
		die ();
		// }
	}
	public function listCardsAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if (isset ( $_REQUEST ['callback'] )) {
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			if (isset ( $jsonArr ['json'] ['userid'] ))
				$userid = $jsonArr ['json'] ['userid'];
			else
				$userid = null;
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			echo $callback . "(" . json_encode ( $MemreasStripe->listCards ( $userid ) ) . ")";
			die ();
		}
	}
	public function viewCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if (isset ( $_REQUEST ['callback'] )) {
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			echo $callback . "(" . json_encode ( $MemreasStripe->listCard ( $jsonArr ['json'] ) ) . ")";
			die ();
		}
	}
	public function updateCardAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if (isset ( $_REQUEST ['callback'] )) {
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			echo $callback . "(" . json_encode ( $MemreasStripe->saveCard ( $jsonArr ['json'] ) ) . ")";
			die ();
		}
	}
	public function deleteCardsAction() {
		if (isset ( $_REQUEST ['callback'] )) {
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			echo $callback . "(" . json_encode ( $MemreasStripe->deleteCards ( $jsonArr ['json'] ) ) . ")";
			die ();
		}
	}
	public function addSellerAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function addValueAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function decrementAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function accounthistoryAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function subscribeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function listMassPayeeAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function getCustomerInfoAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		if (isset ( $_REQUEST ['callback'] )) {
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
			$customer = $MemreasStripe->getCustomer ( $jsonArr ['json'], true );
			Mlog::addone ( __CLASS__ . __METHOD__ . '$customer', $customer );
			echo $callback . "(" . json_encode ( $customer ) . ")";
			die ();
		}
	}
	public function activeCreditAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
		$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
		$token = $_GET ['token'];
		$activeBalance = $MemreasStripe->activePendingBalanceToAccount ( $token );
		echo '<h3 style="text-align: center">' . $activeBalance ['message'] . '</h3>';
		if ($activeBalance ['status'] == 'Success')
			echo '<script type="text/javascript">document.location.href="http://fe.memreas.com";</script>';
		die ();
	}
	public function getUserBalanceAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function buyMediaAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function checkOwnEventAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function testAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, $_REQUEST ['json'] );
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
	public function resetDataAction() {
		$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
		$MemreasStripe->memreasStripeTables->getAccountTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getAccountBalancesTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getAccountDetailTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getPaymentMethodTable ()->deleteAll ();
		$MemreasStripe->memreasStripeTables->getTransactionTable ()->deleteAll ();
		die ( 'done' );
	}
}