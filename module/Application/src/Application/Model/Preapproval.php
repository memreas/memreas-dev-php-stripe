<?php
namespace Application\Model;
use Zend\InputFilter\Factory as InputFactory;
use Zend\InputFilter\InputFilter;                 
use Zend\InputFilter\InputFilterAwareInterface;   
use Zend\InputFilter\InputFilterInterface;

class Preapproval implements InputFilterAwareInterface
{
	public $startingDate;
	public $endingDate;
	public $dateOfMonth;
	public $currencyCode;
	public $dayOfWeek;
	public $maxAmountPerPayment;
	public $maxNumberOfPayments;
	public $maxNumberOfPaymentsPerPeriod;
	public $maxTotalAmountOfAllPayments;
	public $paymentPeriod;
	public $displayMaxTotalAmount;
	public $memo;
	public $ipnNotificationUrl;
	public $senderEmail;
	public $pinType;
	public $feesPayer;

	protected $inputFilter;   
	
	public function exchangeArray($data)
	{
		$this->startingDate     = (isset($data['startingDate'])) ? $data['startingDate']     : null;
		$this->endingDate 		= (isset($data['endingDate'])) ? $data['endingDate'] : null;
		$this->dateOfMonth  	= (isset($data['dateOfMonth']))  ? $data['dateOfMonth']  : null;
		$this->currencyCode     = (isset($data['currencyCode'])) ? $data['currencyCode']     : null;
		$this->maxAmountPerPayment 	= (isset($data['maxAmountPerPayment'])) ? $data['maxAmountPerPayment'] : null;
		$this->maxNumberOfPayments  	= (isset($data['maxNumberOfPayments']))  ? $data['maxNumberOfPayments']  : null;
		$this->maxNumberOfPaymentsPerPeriod  	= (isset($data['maxNumberOfPaymentsPerPeriod']))  ? $data['maxNumberOfPaymentsPerPeriod']  : null;
		$this->maxTotalAmountOfAllPayments  	= (isset($data['maxTotalAmountOfAllPayments']))  ? $data['maxTotalAmountOfAllPayments']  : null;
		$this->paymentPeriod  	= (isset($data['paymentPeriod']))  ? $data['paymentPeriod']  : null;
		$this->displayMaxTotalAmount 	= (isset($data['displayMaxTotalAmount']))  ? $data['displayMaxTotalAmount']  : null;
		$this->memo 	= (isset($data['memo']))  ? $data['memo']  : null;
		$this->ipnNotificationUrl 	= (isset($data['ipnNotificationUrl']))  ? $data['ipnNotificationUrl']  : null;
		$this->senderEmail 	= (isset($data['senderEmail']))  ? $data['senderEmail']  : null;
		$this->pinType 	= (isset($data['pinType']))  ? $data['pinType']  : null;
		$this->feesPayer 	= (isset($data['feesPayer']))  ? $data['feesPayer']  : null;
	}
	

	// Add content to this method:
    public function setInputFilter(InputFilterInterface $inputFilter)
    {
        throw new \Exception("Not used");
    }

	 public function getInputFilter()
    {
        if (!$this->inputFilter) {
            $inputFilter = new InputFilter();
            $factory     = new InputFactory();

            $inputFilter->add($factory->createInput(array(
                'name'     => 'startingDate',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'endingDate',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'dateOfMonth',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'currencyCode',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'maxAmountPerPayment',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'maxNumberOfPayments',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'maxNumberOfPaymentsPerPeriod',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'maxTotalAmountOfAllPayments',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'paymentPeriod',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'displayMaxTotalAmount',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'memo',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'ipnNotificationUrl',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'senderEmail',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'pinType',
                'required' => true
            )));

			$inputFilter->add($factory->createInput(array(
                'name'     => 'feesPayer',
                'required' => true
            )));

            $this->inputFilter = $inputFilter;
        }

        return $this->inputFilter;
    }



}