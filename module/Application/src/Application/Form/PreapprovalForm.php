<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
namespace Application\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class PreapprovalForm extends Form {
	public function __construct() {
		parent::__construct ( 'preapproval' );
		$this->setAttribute ( 'method', 'POST' );
		
		$this->add ( array (
				'name' => 'startingDate',
				'options' => array (
						'label' => 'Preapproval start date' 
				),
				'attributes' => array (
						'type' => 'text',
						'value' => date ( "Y-m-d" ) 
				) 
		) );
		
		$this->add ( array (
				'name' => 'endingDate',
				'options' => array (
						'label' => 'Preapproval end date' 
				),
				'attributes' => array (
						'type' => 'text',
						'value' => date ( "Y-m-d", time () + 864000 ) 
				) 
		) );
		
		$this->add ( array (
				'name' => 'dateOfMonth',
				'options' => array (
						'label' => 'Payment date - Date of month' 
				),
				'attributes' => array (
						'type' => 'text' 
				) 
		) );
		
		$this->add ( array (
				'type' => 'Zend\Form\Element\Select',
				'name' => 'currencyCode',
				'attributes' => array (
						'id' => 'currencyCode',
						'options' => array (
								"USD" => "USD",
								"GBP" => "GBP",
								"EUR" => "EUR",
								"JPY" => "JPY",
								"CAD" => "CAD",
								"AUD" => "AUD" 
						) 
				),
				'options' => array (
						'label' => 'currencyCode' 
				) 
		) );
		
		$this->add ( array (
				'type' => 'Zend\Form\Element\Select',
				'name' => 'dayOfWeek',
				'attributes' => array (
						'id' => 'dayOfWeek',
						'options' => array (
								"NO_DAY_SPECIFIED" => "NO_DAY_SPECIFIED",
								"SUNDAY" => "SUNDAY",
								"MONDAY" => "MONDAY",
								"TUESDAY" => "TUESDAY",
								"WEDNESDAY" => "WEDNESDAY",
								"THURSDAY" => "THURSDAY",
								"FRIDAY" => "FRIDAY",
								"SATURDAY" => "SATURDAY" 
						) 
				),
				'options' => array (
						'label' => 'Payment date - Day of week' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'maxAmountPerPayment',
				'options' => array (
						'label' => 'Maximum amount per payment' 
				),
				'attributes' => array (
						'type' => 'text' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'maxNumberOfPayments',
				'options' => array (
						'label' => 'Maximum number of payments' 
				),
				'attributes' => array (
						'type' => 'text',
						'value' => '10' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'maxNumberOfPaymentsPerPeriod',
				'options' => array (
						'label' => 'Maximum number of payments per period' 
				),
				'attributes' => array (
						'type' => 'text' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'maxTotalAmountOfAllPayments',
				'options' => array (
						'label' => 'Maximum total amount of all payments' 
				),
				'attributes' => array (
						'type' => 'text',
						'value' => '50.0' 
				) 
		) );
		
		$this->add ( array (
				'type' => 'Zend\Form\Element\Select',
				'name' => 'paymentPeriod',
				'attributes' => array (
						'id' => 'paymentPeriod',
						'options' => array (
								"NO_PERIOD_SPECIFIED" => "NO_PERIOD_SPECIFIED",
								"DAILY" => "DAILY",
								"WEEKLY" => "WEEKLY",
								"BIWEEKLY" => "BIWEEKLY",
								"SEMIMONTHLY" => "SEMIMONTHLY",
								"MONTHLY" => "MONTHLY",
								"ANNUALLY" => "ANNUALLY" 
						) 
				),
				'options' => array (
						'label' => 'Payment period' 
				) 
		) );
		
		$this->add ( array (
				'type' => 'Zend\Form\Element\Select',
				'name' => 'displayMaxTotalAmount',
				'attributes' => array (
						'id' => 'displayMaxTotalAmount',
						'options' => array (
								"true" => "True",
								"false" => "False" 
						) 
				),
				'options' => array (
						'label' => 'Display Maximum Total Amount' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'memo',
				'options' => array (
						'label' => 'Memo' 
				),
				'attributes' => array (
						'type' => 'text' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'ipnNotificationUrl',
				'options' => array (
						'label' => 'IPN Notification URL' 
				),
				'attributes' => array (
						'type' => 'text' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'senderEmail',
				'options' => array (
						'label' => 'Sender email' 
				),
				'attributes' => array (
						'type' => 'text' 
				) 
		) );
		
		$this->add ( array (
				'type' => 'Zend\Form\Element\Select',
				'name' => 'pinType',
				'attributes' => array (
						'id' => 'pinType',
						'options' => array (
								"NOT_REQUIRED" => "NOT_REQUIRED",
								"REQUIRED" => "REQUIRED" 
						) 
				),
				'options' => array (
						'label' => 'Is PIN type required' 
				) 
		) );
		
		$this->add ( array (
				'type' => 'Zend\Form\Element\Select',
				'name' => 'feesPayer',
				'attributes' => array (
						'id' => 'feesPayer',
						'options' => array (
								"EACHRECEIVER" => "EACHRECEIVER",
								"PRIMARYRECEIVER" => "PRIMARYRECEIVER",
								"SENDER" => "SENDER",
								"SECONDARYONLY" => "SECONDARYONLY" 
						) 
				),
				'options' => array (
						'label' => 'Fees payer' 
				) 
		) );
		
		$this->add ( array (
				'name' => 'send',
				'attributes' => array (
						'type' => 'submit',
						'value' => 'Submit' 
				) 
		) );
	}
}