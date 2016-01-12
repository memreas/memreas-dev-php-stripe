<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Application\Model;
use Application\Model\UserTable;
use Application\Form;
use Guzzle\Http\Client;
use Application\Model\MemreasConstants;
use Application\memreas\Mlog;
use Application\memreas\MemreasStripe;

class IndexController extends AbstractActionController {
	// protected $url = MemreasConstants::ORIGINAL_URL;
	protected $url = 'http://memreasdev-wsj.memreas.com/';
	protected $user_id;
	protected $storage;
	protected $authservice;
	protected $userTable;
	protected $eventTable;
	protected $mediaTable;
	protected $eventmediaTable;
	protected $friendmediaTable;
	public function fetchXML($action, $xml) {
		$guzzle = new Client ();
		
		$request = $guzzle->post ( $this->url, null, array (
				'action' => $action,
				'xml' => $xml 
		) );
		$response = $request->send ();
		return $data = $response->getBody ( true );
	}

	public function indexAction() {
		Mlog::addone ( __CLASS__ . __METHOD__, '...' );
		Mlog::addone ( __CLASS__ . __METHOD__ . '$_POST', $_POST );
		Mlog::addone ( __CLASS__ . __METHOD__ . '$_REQUEST', $_REQUEST );
		
		if (! empty ( $_REQUEST ['action'] )) {
			switch ($_REQUEST ['action']) {
				case 'showlog' :
						/*
						 * show log as web page - testing only
						 */
						//ob_start ();
						//http_response_code ( 200 );
						echo '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
					// ob_end_flush ();
					exit ();
					break;
				case 'clearlog' :
					unlink ( getcwd () . '/php_errors.log' );
					error_log ( "Log has been cleared!" );
					exit ();
					break;
				default :
			}
		}
		if (! empty ( $_POST ['action'] )) {
			try {
				Mlog::addone ( __CLASS__ . __METHOD__ . '$_POST[action]', $_POST ['action'] );
				$action = $_POST ['action'];
				$MemreasStripe = new MemreasStripe ( $this->getServiceLocator () );
				switch ($action) {
					/*
					 * Support WS : ListPlans
					 * Description: List plans from Stripe by specify customer id
					 */
					case 'listplans' :
						
						$user_id = $_POST ['user_id'];
						$plans = $MemreasStripe->getCustomerPlans ( $user_id );
						if ($plans ['status'] == 'Success') {
							$plans = $plans ['plans'];
							if (! empty ( $plans ))
								$status = 'Success';
							else {
								$status = 'Failure';
								$message = 'There is no plan at this time';
							}
						} else {
							$status = 'Failure';
							$message = $plans ['message'];
						}
						
						if ($status == 'Success')
							$result = array (
									'status' => 'Success',
									'plans' => $plans 
							);
						else
							$result = array (
									'status' => 'Failure',
									'message' => $message 
							);
						break;
					
					/*
					 * Support WS : ListPlansStatic
					 * Description: List all plans available from Stripe and total of number users
					 * are activated on each
					 */
					case 'listplansstatic' :
						
						$static = $_POST ['static'];
						if (! $static) {
							$status = 'Success';
							$plans = $MemreasStripe->listPlans ();
						} else {
							$status = 'Success';
							$plans = $MemreasStripe->listPlans ();
							foreach ( $plans as $key => $plan ) {
								$totaluser = $MemreasStripe->getTotalPlanUser ( $plan ['id'] );
								$plans [$key] ['total_user'] = $totaluser ? $totaluser : 0;
							}
						}
						
						if (empty ( $plans )) {
							$status = 'Failure';
							$message = 'There is no available plan';
						}
						
						if ($status == 'Success')
							$result = array (
									'status' => 'Success',
									'plans' => $plans 
							);
						else
							$result = array (
									'status' => 'Failure',
									'message' => $message 
							);
						break;
					
					/*
					 * Support WS : GetOrderHistory
					 * Description: Get all order histories or by user if it has been specified
					 */
					case 'getorderhistory' :
						
						$data = json_decode ( $_POST ['data'], true );
						
						$orderHistories = $MemreasStripe->getOrderHistories ( $data ['user_id'], $data ['search_username'], ( int ) $data ['page'], ( int ) $data ['limit'] );
						
						if ($orderHistories ['status'] == "Success") {
							if ($data ['user_id'])
								$userDetail = $MemreasStripe->getUserById ( $data ['user_id'] );
							else
								$userDetail = null;
							
							$orders = $orderHistories ['transactions'];
							if (! empty ( $orders ))
								$result = array (
										'status' => 'Success',
										'orders' => $orders,
										'user' => $userDetail 
								);
							else
								$result = array (
										'status' => 'Failure',
										'message' => 'No record found' 
								);
						} else
							$result = array (
									'status' => 'Failure',
									'message' => $orderHistories ['message'] 
							);
						break;
					
					/*
					 * Support WS : GetOrder
					 * Description: Get order detail by transaction id
					 */
					case 'getorder' :
						
						$transaction_id = $_POST ['transaction_id'];
						$Order = $MemreasStripe->getOrder ( $transaction_id );
						if (! empty ( $Order )) {
							$userDetail = $MemreasStripe->getAccountDetailByAccountId ( $Order->account_id );
							$result = array (
									'status' => 'Success',
									'order' => $Order,
									'user' => $userDetail 
							);
						} else
							$result = array (
									'status' => 'Failure',
									'message' => 'Record is not exist' 
							);
						
						break;
					
					/*
					 * Support WS : GetAccountDetail
					 * Description: Get account detail by user id
					 */
					case 'getaccountdetail' :
						
						$user_id = $_POST ['user_id'];
						$result = $MemreasStripe->getCustomer ( array (
								'userid' => $user_id 
						) );
						break;
					
					/*
					 * Support WS : Refund
					 * Description: Refund amount to user balance from admin
					 */
					case 'refund' :
						
						$data = array (
								'user_id' => $_POST ['user_id'],
								'amount' => $_POST ['amount'],
								'reason' => $_POST ['reason'] 
						);
						$result = $MemreasStripe->refundAmount ( $data );
						break;
					
					/*
					 * Support WS : ListPayees
					 * Description: List all payees with balance is available and larger than than zero
					 */
					case 'listpayees' :
						
						$username = $_POST ['username'];
						$page = ( int ) $_POST ['page'];
						$limit = ( int ) $_POST ['limit'];
						$result = $MemreasStripe->listMassPayee ( $username, $page, $limit );
						break;
					
					/*
					 * Support WS : MakePayout
					 * Description: Send payment to seller stripe account directly based on payee's balance
					 * or amount set by admin
					 */
					case 'makepayout' :
						
						$data = array (
								'account_id' => $_POST ['account_id'],
								'amount' => $_POST ['amount'],
								'description' => $_POST ['description'] 
						);
						$result = $MemreasStripe->MakePayout ( $data );
						break;
					
					/*
					 * Support WS: checkUserType
					 * Description: Checking for user type is buyer/seller from username provide from frontend
					 */
					case 'checkusertype' :
						
						Mlog::addone ( __CLASS__ . __METHOD__ . '$action', $action );
						Mlog::addone ( __CLASS__ . __METHOD__ . '$_POST[username]', $_POST ['username'] );
						$result = $MemreasStripe->checkUserType ( $_POST ['username'] );
						break;
					
					default :
				}
			} catch ( Exception $e ) {
				$result = array (
						'status' => 'Failure',
						'message' => $e->getMessage () 
				);
			}
			
			header ( "Content-Type: application/json" );
			echo json_encode ( $result );
			exit ();
		}
		
		// Edited for Stripe
		echo "Setting path to application/stripe/index.phtml" . PHP_EOL;
		$path = $this->security ( "application/stripe/index.phtml" );
		$view = new ViewModel ();
		$view->setTemplate ( $path ); // path to phtml file under view folder
		return $view;
	}

	public function setSession($username) {
		//
		// Validate user_data against Redis Cache
		//
	}
	public function getUserTable() {
		if (! $this->userTable) {
			$sm = $this->getServiceLocator ();
			$this->userTable = $sm->get ( 'Application\Model\UserTable' );
		}
		return $this->userTable;
	}
	public function getAuthService() {
		if (! $this->authservice) {
			$this->authservice = $this->getServiceLocator ()->get ( 'AuthService' );
		}
		
		return $this->authservice;
	}
	public function getSessionStorage() {
		if (! $this->storage) {
			$this->storage = $this->getServiceLocator ()->get ( 'Application\Model\MyAuthStorage' );
		}
		
		return $this->storage;
	}
} // end class IndexController
