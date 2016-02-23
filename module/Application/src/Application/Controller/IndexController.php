<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Controller;

use Application\memreas\Mlog;
use Application\Model\MemreasConstants;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController {
	private $aws;
	public function indexAction() {
		$cm = __CLASS__.__METHOD__;
		ob_start ();
		$actionname = isset ( $_REQUEST ["action"] ) ? $_REQUEST ["action"] : '';
		if ($actionname == "clearlog") {
			unlink ( getcwd () . '/php_errors.log' );
			Mlog::addone ( $cm . __LINE__, "Log has been cleared!" );
			echo '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
			// End buffering and flush
			ob_end_clean ();
			exit ();
		} else if ($actionname == "showlog") {
			Mlog::addone ( $cm . __LINE__ . "showlog-->", "called..." );
			$result = '<pre>' . file_get_contents ( getcwd () . '/php_errors.log' );
			echo $result;
			// End buffering and flush
			ob_end_flush ();
			exit ();
		} else if ($actionname == "email") {
			$to = $_REQUEST ["to"];
			Mlog::addone ( $cm . __LINE__.'::$to-->', $to );
			$subject = $_REQUEST ["subject"];
			Mlog::addone ( $cm . __LINE__.'::$subject-->', $subject );
			$content = $_REQUEST ["content"];
			Mlog::addone ( $cm . __LINE__.'::$subject-->', $subject );
			
			Mlog::addone ( $cm . __LINE__,"about to fetchAWS()" );
			$this->aws = MemreasConstants::fetchAWS();
			Mlog::addone ( $cm . __LINE__,"about to fetchAWS()" );
			Mlog::addone ( $cm . __LINE__,"about to sendSeSMail(...)" );
			$this->aws->sendSeSMail ( array (
					$to 
			), $subject, $content );
			Mlog::addone ( $cm . __LINE__,"completed sendSeSMail(...)" );
			
		} else {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
		}
	}
} // end class IndexController
