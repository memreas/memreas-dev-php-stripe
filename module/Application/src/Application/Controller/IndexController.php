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
			
			if (! empty ( $_REQUEST ['admin_key'] )) {
				
				$username = $this->redis->getCache ( 'admin_key' );
				
				$this->sessHandler->startSessionWithUID ( '', $username );
				
				$hasSession = true;
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
		// If session is valid on ws we can proceed
		//
		return $hasSession;
	}
	public function indexAction() {
		$cm = __CLASS__ . __METHOD__;
		Mlog::addone ( $cm . __LINE__ . '::$_REQUEST', $_REQUEST );
		$action = isset ( $_REQUEST ["action"] ) ? $_REQUEST ["action"] : '';
		
		Mlog::addone ( $cm, __LINE__ . $action );
		if ($action == "gitpull") {
			$this->checkGitPull = new CheckGitPull ();
			echo $this->checkGitPull->exec ( true );
			exit ();
		} else if ($action == "clearlog") {
			//`cat /dev/null > getcwd () . '/php_errors.log'`
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
		} else {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
		}
	}
} // end class IndexController
