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

class User {

    public $user_id,
            $database_id,
            $username,
            $password,
            $email_address,
            $role,
            $profile_photo,
            $facebook_username,
            $twitter_username,
            $disable_account,
            $create_date,
            $update_time;
      protected $inputFilter;   

    

    public function exchangeArray($data) {
        $this->user_id = (isset($data['user_id'])) ? $data['user_id'] : null;
        $this->database_id = (isset($data['database_id'])) ? $data['database_id'] : null;
        $this->username = (isset($data['username'])) ? $data['username'] : null;
        $this->password = (isset($data['password'])) ? $data['password'] : null;
        $this->email_address = (isset($data['email_address'])) ? $data['email_address'] : null;
        $this->role = (isset($data['role'])) ? $data['role'] : null;
        $this->profile_photo = (isset($data['profile_photo'])) ? $data['profile_photo'] : null;
        $this->facebook_username = (isset($data['facebook_username'])) ? $data['facebook_username'] : null;
        $this->twitter_username = (isset($data['twitter_username'])) ? $data['twitter_username'] : null;
        $this->disable_account = (isset($data['disable_account'])) ? $data['disable_account'] : null;
        $this->create_date = (isset($data['create_date'])) ? $data['create_date'] : null;
        $this->update_time = (isset($data['update_time'])) ? $data['update_time'] : null;
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
