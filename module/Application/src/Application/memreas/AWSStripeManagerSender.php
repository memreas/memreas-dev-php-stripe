<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Application\Model\MemreasConstants;
use Guzzle\Log\MonologLogAdapter;
use Guzzle\Plugin\Log\LogPlugin;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class AWSStripeManagerSender {
	//private $aws = null;
	private $ses = null;
	public function __construct() {
		try {
			/*
			// Create a log channel
			$log = new Logger('aws');
			$log->pushHandler(new StreamHandler('/ses_logger.log', Logger::DEBUG));
			
			// Create a log adapter for Monolog
			$logger = new MonologLogAdapter($log);
			
			// Create the LogPlugin
			$logPlugin = new LogPlugin($logger);
			
			// Create an Amazon S3 client
			//$s3Client = S3Client::factory();
			
			// Add the LogPlugin to the client
			//$s3Client->addSubscriber($logPlugin);
				
			
			
			
			Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
			$this->ses = new \Aws\Ses\SesClient([
					'version'	=> 'latest',
					'region'      => 'us-east-1',
					'credentials' => [
							'key'    => MemreasConstants::AWS_APPKEY,
							'secret' => MemreasConstants::AWS_APPSEC
					]
			]);
			Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
			*/
				
			// Fetch aws handle
			//Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
			$this->aws = MemreasConstants::fetchAWS ();
			$this->ses = $this->aws->createSes ();
			Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
			//$this->ses->addSubscriber($logPlugin);
			Mlog::addone ( __CLASS__ . __METHOD__ , __LINE__ );
		} catch ( Exception $e ) {
			Mlog::addone ( __CLASS__ . __METHOD__ . '::$e->getMessage()--->', $e->getMessage () );
		}
	}
	public function sendSeSMail($to_array, $subject, $text_or_html) {
		$from = MemreasConstants::ADMIN_EMAIL;
		$client = $this->ses;
		
		$result = $client->sendEmail ( array (
				// Source is required
				'Source' => $from,
				// Destination is required
				'Destination' => array (
						'ToAddresses' => $to_array,
						'CcAddresses' => array (),
						'BccAddresses' => array () 
				),
				// Message is required
				'Message' => array (
						// Subject is required
						'Subject' => array (
								// Data is required
								'Data' => $subject 
						),
						// 'Charset' => 'iso-8859-1'
						// Body is required
						'Body' => array (
								'Text' => array (
										// Data is required
										'Data' => $text_or_html,
										'Charset' => 'iso-8859-1' 
								),
								'Html' => array (
										// Data is required
										'Data' => $text_or_html,
										'Charset' => 'iso-8859-1' 
								) 
						) 
				),
				'ReplyToAddresses' => array (
						$from 
				),
				'ReturnPath' => $from 
		) );
	}
}

?>


