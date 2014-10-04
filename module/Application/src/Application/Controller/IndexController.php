<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

// ///////////////////////////////
// Author: John Meah
// Copyright memreas llc 2013
// ///////////////////////////////
namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Session\Container;
use Application\Model;
use Application\Model\UserTable;
use Application\Form;
use Guzzle\Http\Client;
use Application\Model\MemreasConstants;
use Application\memreas\MemreasPayPal;
use Application\memreas\MemreasPayPalTables;
use Application\memreas\MemreasStripe;

class IndexController extends AbstractActionController {
    //protected $url = MemreasConstants::ORIGINAL_URL;
	protected $url = 'http://memreas-dev-ws.localhost/';
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
				// 'cache_me' => true,
				'xml' => $xml 
		) );
		$response = $request->send ();
		return $data = $response->getBody ( true );
	}
	public function ipnListenerAction() {
		error_log ( "Inside ipnListenerAction...." );
		// PayPal related calls...
		$memreasPayPal = new MemreasPayPal ();
		$memreas_paypal_tables = new MemreasPayPalTables ( $this->getServiceLocator () );
		$result = $memreasPayPal->ipnListener ( null, $memreas_paypal_tables, $this->getServiceLocator () );
		
		http_response_code ( 200 );
		exit();
	}
	public function indexAction() {

        if (isset($_POST['action'])){
            $action = $_POST['action'];
            $MemreasStripe = new MemreasStripe($this->getServiceLocator());
            switch ($action){
                case 'listplans':
                    $user_id = $_POST['user_id'];
                    $plans = $MemreasStripe->getCustomerPlans($user_id);
                    if ($plans['status'] == 'Success'){
                        $plans = $plans['plans'];
                        if (!empty($plans))
                            $status = 'Success';
                        else{
                            $status = 'Failure';
                            $message = 'There is no plan at this time';
                        }
                    }else{
                        $status = 'Failure';
                        $message = $plans['message'];
                    }

                    if ($status == 'Success')
                        $result = array('status' => 'Success', 'plans' => $plans);
                    else $result = array('status' => 'Failure', 'message' => $message);
                    break;
                case 'listplansstatic':
                    $static = $_POST['static'];
                    if (!$static){
                        $status = 'Success';
                        $plans = $MemreasStripe->listPlans();
                    }
                    else{
                        $status = 'Success';
                        $plans = $MemreasStripe->listPlans();
                        foreach ($plans as $key => $plan){
                            $totaluser = $MemreasStripe->getTotalPlanUser($plan['id']);
                            $plans[$key]['total_user'] = $totaluser ? $totaluser : 0;
                        }
                    }

                    if (empty($plans)){
                        $status = 'Failure';
                        $message = 'There is no available plan';
                    }

                    if ($status == 'Success')
                        $result = array('status' => 'Success', 'plans' => $plans);
                    else $result = array('status' => 'Failure', 'message' => $message);
                    break;

                case 'getorderhistory':
                    $data = json_decode($_POST['data'], true);

                    $orderHistories = $MemreasStripe->getOrderHistories($data['user_id'], (int)$data['page'], (int)$data['limit']);

                    if ($orderHistories['status'] == "Success"){
                        if ($data['user_id']) $userDetail = $MemreasStripe->getUserById($data['user_id']);
                        else $userDetail = null;

                        $orders = $orderHistories['transactions'];
                        if (!empty($orders))
                            $result = array('status' => 'Success', 'orders' => $orders, 'user' => $userDetail);
                        else $result = array('status' => 'Failure', 'message' => 'No record found');
                    }
                    else $result = array('status' => 'Failure', 'message' => $orderHistories['message']);
                    break;

                case 'getorder':
                    $transaction_id = $_POST['transaction_id'];
                    $Order = $MemreasStripe->getOrder($transaction_id);
                    if (!empty($Order)){
                        $userDetail = $MemreasStripe->getAccountDetailByAccountId($Order->account_id);
                        $result = array('status' => 'Success', 'order' => $Order, 'user' => $userDetail);
                    }
                    else $result = array('status' => 'Failure', 'message' => 'Record is not exist');

                    break;

                case 'getaccountdetail':
                    $user_id = $_POST['user_id'];
                    $result = $MemreasStripe->getCustomer(array('userid' => $user_id));
                    break;

                case 'refund':
                    $data = array(
                        'user_id' => $_POST['user_id'],
                        'amount' => $_POST['amount'],
                        'reason' => $_POST['reason']
                    );
                    $result = $MemreasStripe->refundAmount($data);
                    break;

                case 'listpayees':
                    $page = (int)$_POST['page'];
                    $limit = (int)$_POST['limit'];
                    $result = $MemreasStripe->listMassPayee($page, $limit);
                    break;

                default:
            }

            header ("Content-Type: application/json");
            echo json_encode($result); die();
        }

		/*$testing_data = array(
 							'name' => 'John Meah',
 							'email' => 'demo recipient',
 							'description' => 1
						);
		$MemreasStripe = new MemreasStripe($this->getServiceLocator());
		echo '<pre>'; print_r ($MemreasStripe->createRecipient($testing_data)); die();*/
		
		//$path = $this->security ( "application/index/paypal.phtml" );
		//Edited for Stripe
		echo "Setting path to application/stripe/index.phtml".PHP_EOL;
		$path = $this->security ( "application/stripe/index.phtml" );
		$view = new ViewModel ();
		$view->setTemplate ( $path ); // path to phtml file under view folder
		return $view;
	}
	public function payPalListMassPayeeAction() {
		$path = $this->security ( "application/index/paypal.phtml" );
		
		if (isset ( $_REQUEST ['callback'] )) {
			// Fetch parms
			$callback = $_REQUEST ['callback'];
			$json = $_REQUEST ['json'];
			$jsonArr = json_decode ( $json, true );
			$actionname = $jsonArr ['action'];
			$type = $jsonArr ['type'];
			$message_data = $jsonArr ['json'];
			
			// PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->payPalListMassPayee($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;	
	}
	
    public function paypalPayoutMassPayeesAction() {
error_log("Inside paypalPayoutMassPayeesAction..." . PHP_EOL);		
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
error_log("Inside paypalPayoutMassPayeesAction.json --> $json" . PHP_EOL);		
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->paypalPayoutMassPayees($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;	
	}

    public function payPalAddSellerAction() {
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->payPalAddSeller($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;		  
     }
    public function paypalDecrementValueAction() {
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->paypalDecrementValue($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;		  
     }

     public function paypalAddValueAction() {
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->paypalAddValue($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;			
    }

    public function paypalListCardsAction() {
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->paypalListCards($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;			
    }

     public function paypalDeleteCardsAction() {
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->paypalDeleteCards($message_data, $memreas_paypal_tables, $this->getServiceLocator());
			
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;			
    }

    public function paypalAccountHistoryAction() {			
		
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->paypalAccountHistory($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;			
    }
    
     public function payPalSubscribeAction() {
	    $path = $this->security("application/index/paypal.phtml");

		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->payPalSubscribe($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;			
    }
    
    public function paypalAction() {
    	//		
    	$stripe = new MemreasStripe($this->getServiceLocator());					
		
		//Delete customer
		//echo '<pre>'; print_r ($stripe->deleteCustomer('cus_3yx1W1G08Wj62Q')); die();
		
		//$sample_cus = array('email' => 'customer1@gmail.com',  'description' => 'demo stripe customer');
		//$stripe->setCustomerInfo($sample_cus);
		//$customer = $stripe->createCustomer();
		//$customer_id = $customer['id'];
		//$sample_cus['id'] = $customer_id;			
		//echo '<pre>'; print_r ($customer); echo '</pre>';
		/*$stripe->setCardAttribute('customer', "cus_3yx1W1G08Wj62Q");		
		$sample_data = array(
								'number' => '4242424242424242',
								'exp_month' => '10',
								'exp_year' => '2019',
								'cvc'	=> '111',
								'type' => 'Master'
							);
		$stripe->setCard($sample_data);			
		echo '<pre>'; print_r ($stripe->getCardValues());
		echo '<pre>'; print_r ($stripe->storeCard()); die();*/
		
		//
    	
	    //$path = $this->security("application/index/paypal.phtml");
	    $path = $this->security("application/stripe/index.phtml");
	     
		if (isset($_REQUEST['callback'])) {
			//Fetch parms
			$callback = $_REQUEST['callback'];
			$json = $_REQUEST['json'];
			$jsonArr = json_decode($json, true);
			$actionname = $jsonArr['action'];
			$type = $jsonArr['type'];
			$message_data = $jsonArr['json'];

			//PayPal related calls...
			$memreasPayPal = new MemreasPayPal();
			$memreas_paypal_tables = new MemreasPayPalTables($this->getServiceLocator());
			$result = $memreasPayPal->storeCreditCard($message_data, $memreas_paypal_tables, $this->getServiceLocator());
				
			$json = json_encode($result);
			//Return the ajax call...
			$callback_json = $callback . "(" . $json . ")";
			$output = ob_get_clean();
			header("Content-type: plain/text");
			echo $callback_json;
			//Need to exit here to avoid ZF2 framework view.
			exit;
		} else {
			$view = new ViewModel();
			$view->setTemplate($path); // path to phtml file under view folder
		}

		return $view;			
    }


    public function loginAction() {
		//Fetch the post data
		$request = $this->getRequest();
		$postData = $request->getPost()->toArray();
		$username = $postData ['username'];
		$password = $postData ['password'];

		//Setup the URL and action
		$action = 'login';
		$xml = "<xml><login><username>$username</username><password>$password</password></login></xml>";
		$redirect = 'paypal';
		
		//Guzzle the LoginWeb Service		
		$result = $this->fetchXML($action, $xml);
		$data = simplexml_load_string($result);

		//ZF2 Authenticate
		if ($data->loginresponse->status == 'success') {
error_log("Inside loginAction success for $username");
error_log("Inside loginAction redirect ---> $redirect");
			$this->setSession($username);
            //Redirect here
			//return $this->redirect()->toRoute('index', array('action' => $redirect));
			return $this->redirect()->toRoute('stripe', array('action' => "index"));
		} else {
			return $this->redirect()->toRoute('index', array('action' => "index"));
		}
    }

    public function logoutAction() {
		$this->getSessionStorage()->forgetMe();
        $this->getAuthService()->clearIdentity();
        $session = new Container('user');
        $session->getManager()->destroy(); 
         
        $view = new ViewModel();
		$view->setTemplate('application/index/index.phtml'); // path to phtml file under view folder
		return $view;			
    }

    public function setSession($username) {
		//Fetch the user's data and store it in the session...
   	    $user = $this->getUserTable()->getUserByUsername($username);
        unset($user->password);
       	unset($user->disable_account);
   	    unset($user->create_date);
        unset($user->update_time);
		$session = new Container('user');        
		$session->offsetSet('user_id', $user->user_id);
		$session->offsetSet('username', $username);
        $session->offsetSet('user', json_encode($user));    
    }
     

    public function getUserTable() {
        if (!$this->userTable) {
            $sm = $this->getServiceLocator();
            $this->userTable = $sm->get('Application\Model\UserTable');
        }
        return $this->userTable;
    }

    public function getAuthService() {
        if (!$this->authservice) {
            $this->authservice = $this->getServiceLocator()
                    ->get('AuthService');
        }

        return $this->authservice;
    }

    public function getSessionStorage() {
        if (!$this->storage) {
            $this->storage = $this->getServiceLocator()
                    ->get('Application\Model\MyAuthStorage');
        }

        return $this->storage;
    }

    public function security($path) {
    	//if already login do nothing
		$session = new Container("user");
	    if(!$session->offsetExists('user_id')){
			error_log("user_id not there so logout");
	    	$this->logoutAction();
    	  return "application/index/index.phtml";
	    }
	    echo "Inside security($path);".PHP_EOL;
		return $path;			
        //return $this->redirect()->toRoute('index', array('action' => 'login'));
    }
	
} // end class IndexController
