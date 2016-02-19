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
	
	public function indexAction() {
			$view = new ViewModel ();
			$view->setTemplate ( 'application/error/500.phtml' );
			return $view;
	}
	
} // end class IndexController
