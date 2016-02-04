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
use Application\memreas\AWSMemreasRedisCache;
use Application\memreas\AWSMemreasRedisSessionHandler;

class IndexController extends AbstractActionController {
	protected $url = MemreasConstants::MEMREAS_WS . '/';
	protected $user_id;
	protected $storage;
	protected $authservice;
	protected $userTable;
	protected $eventTable;
	protected $mediaTable;
	protected $eventmediaTable;
	protected $friendmediaTable;
	protected $sid;
	protected $memreascookie;
	protected $sessHandler;
	
	//
	// start session by fetching and starting from REDIS - security check
	//
	public function setupSaveHandler() {
		
		$this->redis = new AWSMemreasRedisCache ( $this->getServiceLocator () );
		$this->sessHandler = new AWSMemreasRedisSessionHandler ( $this->redis, $this->getServiceLocator () );
		session_set_save_handler ( $this->sessHandler );
	}
	public function flushResponse($response) {
		
		header('Content-Type: application/json');
		$arr = headers_list();
		Mlog::addone('response headers -->', $arr);
		
		echo $response;
		// clean the buffer we don't need to send back session data
		ob_end_flush (); 
		flush ();
		
	}
	public function fetchSession() {
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
			/** all access through proxy */
			if (! empty ( $_REQUEST ['sid'] )) {
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
	
} // end class IndexController
