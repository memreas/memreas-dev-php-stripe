<?php

    /**
     * Description of Users
     * @author shivani
     */

    namespace Application\Model;
    use Zend\InputFilter\Factory as InputFactory;
    use Zend\InputFilter\InputFilter;
    use Zend\InputFilter\InputFilterAwareInterface;
    use Zend\InputFilter\InputFilterInterface;

    class Refunds {

        public $refund_id,
            $transaction_id,
            $amount,
            $reason,
            $created;
        protected $inputFilter;



        public function exchangeArray($data) {
            $this->refund_id = (isset($data['refund_id'])) ? $data['refund_id'] : null;
            $this->transaction_id = (isset($data['transaction_id'])) ? $data['transaction_id'] : null;
            $this->amount = (isset($data['amount'])) ? $data['amount'] : null;
            $this->reason = (isset($data['reason'])) ? $data['reason'] : null;
            $this->created = (isset($data['created'])) ? $data['created'] : null;
        }
        // Add content to these methods:
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
                    'name'     => 'user_id',
                    'required' => false,
                    'filters'  => array(
                        array('name' => 'StripTags'),
                    ),
                )));

                $inputFilter->add($factory->createInput(array(
                    'name'     => 'username',
                    'required' => true,
                    'filters'  => array(
                        array('name' => 'StripTags'),
                        array('name' => 'StringTrim'),
                    ),
                    'validators' => array(
                        array(
                            'name'    => 'StringLength',
                            'options' => array(
                                'encoding' => 'UTF-8',
                                'min'      => 2,
                                'max'      => 100,
                            ),
                        ),
                    ),
                )));

                $inputFilter->add($factory->createInput(array(
                    'name'     => 'password',
                    'required' => true,
                    'filters'  => array(
                        array('name' => 'StripTags'),
                        array('name' => 'StringTrim'),
                    ),
                    'validators' => array(
                        array(
                            'name'    => 'StringLength',
                            'options' => array(
                                'encoding' => 'UTF-8',
                                'min'      => 4,
                                'max'      => 100,
                            ),
                        ),
                    ),
                )));

                $this->inputFilter = $inputFilter;
            }

            return $this->inputFilter;
        }
    }

?>
