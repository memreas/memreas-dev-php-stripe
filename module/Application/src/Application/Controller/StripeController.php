<?php

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
	
	public function indexAction(){
		$view = new ViewModel();
		$view->setTemplate('application/stripe/index.phtml');
		return $view;
	}
	
	/*
	 * Ajax functions
	 * */
	public function storeCardAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];$json = $_REQUEST['json'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);						
			$message_data = $jsonArr['json'];
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			
			//Prepare card data
			$card_data = array(
				'number' 			=> $message_data['credit_card_number'],
				'exp_month' 		=> $message_data['expiration_month'],
				'exp_year' 			=> $message_data['expiration_year'],
				'cvc'				=> $message_data['cvc'],
				'name' 				=> $message_data['first_name'] . ' ' . $message_data['last_name'],
				'country' 			=> 'US', 									//Change this to dynamic form value
				'address_line1' 	=> $message_data['address_line_1'],
				'address_line2'		=> '',
				'address_city' 		=> $message_data['city'],
				'address_state' 	=> $message_data['state'] ,
				'address_zip' 		=> $message_data['zip_code'],
				'address_country' 	=> 'US',									//Change this to dynamic form value				
			);			
			echo $callback . "(" . json_encode($MemreasStripe->storeCard($card_data)) . ")";
			die();
			
		}	
	}

	public function listCardsAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			echo $callback . "(" . json_encode($MemreasStripe->listCards()) . ")";
			die();
		}
	}

	public function deleteCardsAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			echo $callback . "(" . json_encode($MemreasStripe->deleteCards($jsonArr['json'])) . ")";
			die();
		}
	}
	
	public function addSellerAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			$addSeller = $MemreasStripe->addSeller($jsonArr['json']);
			echo $callback . "(" . json_encode($addSeller) . ")";
			die();
		}
	}
	
	public function addValueAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			$addValue = $MemreasStripe->addValueToAccount($jsonArr['json']);
			echo $callback . "(" . json_encode($addValue) . ")";
			die();
		}
	}
	
	public function decrementAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			$decrement = $MemreasStripe->decrementAmount($jsonArr['json']);
			echo $callback . "(" . json_encode($decrement) . ")";
			die();
		}
	}
	
	public function accounthistoryAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			$accountHistory = $MemreasStripe->AccountHistory($jsonArr['json']);
			echo $callback . "(" . json_encode($accountHistory) . ")";
			die();
		}
	}
	
	public function subscribeAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			$accountHistory = $MemreasStripe->setSubscription($jsonArr['json']);
			echo $callback . "(" . json_encode($accountHistory) . ")";
			die();
		}		
	}	
	
	public function listMassPayeeAction(){
		if (isset($_REQUEST['callback'])){
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$MemreasStripe = new MemreasStripe($this->getServiceLocator());
			$accountHistory = $MemreasStripe->listMassPayee();
			echo $callback . "(" . json_encode($accountHistory) . ")";
			die();
		}
	}
	
	public function testAction(){
		$stripePlan = new StripePlansConfig($this->getServiceLocator());
		$plans = $stripePlan->deletePlan('GOLD');
		echo '<pre>'; print_r ($plans);
		die();
	}
	
}