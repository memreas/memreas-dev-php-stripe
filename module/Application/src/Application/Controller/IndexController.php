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
	private $aws;
	public function indexAction() {
		ob_start ();
		$actionname = isset ( $_REQUEST ["action"] ) ? $_REQUEST ["action"] : '';
		if ($actionname == "clearlog") {
			unlink ( getcwd () . '/php_errors.log' );
			Mlog::addone ( __CLASS__ . __METHOD . __LINE__, "Log has been cleared!" );
			echo '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
			// End buffering and flush
			ob_end_clean ();
			exit ();
		} else if ($actionname == "showlog") {
			Mlog::addone ( __CLASS__ . __METHOD . __LINE__ . "showlog-->", "called..." );
			$result = '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
			echo $result;
			// End buffering and flush
			ob_end_flush ();
			exit ();
		} else if ($actionname == "email") {
			$to = $_REQUEST ["to"];
			$subject = $_REQUEST ["subject"];
			$content = $_REQUEST ["content"];
			
			$this->aws = MemreasConstants::fetchAWS();
			$this->aws->sendSeSMail ( array (
					$to 
			), $subject, $content );
		} else {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
		}
	}
} // end class IndexController
