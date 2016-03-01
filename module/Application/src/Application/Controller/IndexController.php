<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Controller;

use Application\memreas\AWSMemreasRedisCache;
use Application\memreas\AWSMemreasRedisSessionHandler;
use Application\memreas\AWSStripeManagerSender;
use Application\memreas\CheckGitPull;
use Application\memreas\MemreasStripe;
use Application\memreas\Mlog;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController {
	private $aws;
	public function setupSaveHandler() {
		try {
			$this->redis = new AWSMemreasRedisCache ( $this->getServiceLocator () );
			$this->sessHandler = new AWSMemreasRedisSessionHandler ( $this->redis, $this->getServiceLocator () );
			session_set_save_handler ( $this->sessHandler );
		} catch ( \Exception $e ) {
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::setupSaveHandler() error->', $e->getMessage () );
		}
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
						Mlog::addone ( $cm , __LINE__ );
			if (! empty ( $_REQUEST ['admin_key'] )) {
						Mlog::addone ( $cm , __LINE__ );
				$username = $this->redis->getCache ( 'admin_key' );
						Mlog::addone ( $cm , __LINE__ );
				$this->sessHandler->startSessionWithUID ( '', $username );
						Mlog::addone ( $cm , __LINE__ );
				$hasSession = true;
						Mlog::addone ( $cm , __LINE__ );
			} else if (! empty ( $_REQUEST ['memreascookie'] )) {
				$sid = $_REQUEST ['memreascookie'];
				$this->sessHandler->startSessionWithMemreasCookie ( $_REQUEST ['memreascookie'] );
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
	public function indexAction() {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm . __LINE__ . '::$_REQUEST', $_REQUEST );
		$action = isset ( $_REQUEST ["action"] ) ? $_REQUEST ["action"] : '';
						Mlog::addone ( $cm , __LINE__ );
						Mlog::addone ( $cm , __LINE__ .$action);
						if ($action == "gitpull") {
			$this->checkGitPull = new CheckGitPull ();
			$this->checkGitPull->exec ();
			$gitpull = true;
			echo $this->checkGitPull->exec ( $gitpull );
			exit ();
		} else if ($action == "clearlog") {
			unlink ( getcwd () . '/php_errors.log' );
			Mlog::addone ( $cm . __LINE__, "Log has been cleared!" );
			echo '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
			// End buffering and flush
			exit ();
		} else if ($action == "showlog") {
			Mlog::addone ( $cm . __LINE__ . "showlog-->", "called..." );
			$result = '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
			echo $result;
			// End buffering and flush
			exit ();
		} else if (! empty ( $action )) {
			
						Mlog::addone ( $cm , __LINE__ );
			$MemreasStripe = new MemreasStripe ( $this->getServiceLocator (), $this->aws );
						Mlog::addone ( $cm , __LINE__ );
			if ($this->fetchSession ()) {
						Mlog::addone ( $cm , __LINE__ );
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
						
						Mlog::addone ( $cm, __LINE__ );
						$data = json_decode ( $_POST ['data'], true );
						Mlog::addone ( $cm, __LINE__ );
						
						Mlog::addone ( $cm, __LINE__ );
						$orderHistories = $MemreasStripe->getOrderHistories ( $data ['user_id'], $data ['search_username'], ( int ) $data ['page'], ( int ) $data ['limit'] );
						Mlog::addone ( $cm, __LINE__ );
						
						Mlog::addone ( $cm, __LINE__ );
						if ($orderHistories ['status'] == "Success") {
							Mlog::addone ( $cm, __LINE__ );
							if ($data ['user_id']) {
								Mlog::addone ( $cm, __LINE__ );
								$userDetail = $MemreasStripe->getUserById ( $data ['user_id'] );
						Mlog::addone ( $cm , __LINE__ );
							} else {
						Mlog::addone ( $cm , __LINE__ );
								$userDetail = null;
							}
							
						Mlog::addone ( $cm , __LINE__ );
							$orders = $orderHistories ['transactions'];
						Mlog::addone ( $cm , __LINE__ );
							if (! empty ( $orders )) {
						Mlog::addone ( $cm , __LINE__ );
								$result = array (
										'status' => 'Success',
										'orders' => $orders,
										'user' => $userDetail 
								);
						Mlog::addone ( $cm , __LINE__ );
							} else {
						Mlog::addone ( $cm , __LINE__ );
								$result = array (
										'status' => 'Failure',
										'message' => 'No record found' 
								);
						Mlog::addone ( $cm , __LINE__ );
							}
						} else {
						Mlog::addone ( $cm , __LINE__ );
							$result = array (
									'status' => 'Failure',
									'message' => $orderHistories ['message'] 
							);
						Mlog::addone ( $cm , __LINE__ );
						}
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
						Mlog::addone ( $cm . __LINE__ . '::getAccountDetail $user_id', $user_id );
						$result = $MemreasStripe->getCustomer ( array (
								'userid' => $user_id 
						), false ); // set to true to retrieve Stripe data
						Mlog::addone ( $cm . __LINE__ . '::getAccountDetail $result', $result );
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
						
						Mlog::addone ( $cm . '$action', $action );
						Mlog::addone ( $cm . '$_POST[username]', $_POST ['username'] );
						$result = $MemreasStripe->checkUserType ( $_POST ['username'] );
						break;
					
					default :
				}
				
				/**
				 * flush response
				 */
				Mlog::addone ( $cm . __LINE__ . '::$result', $result );
				$this->flushResponse ( json_encode ( $result ) );
				die ();
				
			} // end if fetch sesssion
		} else {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
		}
	}
} // end class IndexController
