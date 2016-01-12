<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A User.
 *
 * @ORM\Entity
 * @ORM\Table(name="user")
 */
class User {
	protected $inputFilter;
	
	/**
	 *
	 * @var string @ORM\Id
	 *      @ORM\Column(type="string",name="user_id");
	 */
	protected $user_id;
	
	/**
	 *
	 * @var string @ORM\Column(name="username", type="string", length=255, nullable=false)
	 */
	protected $username;
	
	/**
	 *
	 * @var string @ORM\Column(name="password", type="string", length=255, nullable=false)
	 */
	protected $password;
	
	/**
	 *
	 * @var string @ORM\Column(name="role", type="string", length=20, nullable=false)
	 */
	protected $role;
	
	/**
	 *
	 * @var string @ORM\Column(name="user_id", type="string", length=255, nullable=false)
	 *      @ORM\Id
	 *      @ORM\GeneratedValue(strategy="IDENTITY")
	 */
	
	/**
	 *
	 * @var integer @ORM\Column(name="database_id", type="integer", nullable=false)
	 */
	private $database_id = 0;
	
	/**
	 *
	 * @var string @ORM\Column(name="username", type="string", length=255, nullable=false)
	 */
	// private $username;
	
	/**
	 *
	 * @var string @ORM\Column(name="password", type="string", length=255, nullable=false)
	 */
	// private $password;
	//
	/**
	 *
	 * @var string @ORM\Column(name="email_address", type="string", length=255, nullable=false)
	 */
	private $email_address;
	
	/**
	 *
	 * @var string @ORM\Column(name="role", type="string", length=20, nullable=false)
	 */
	// private $role;
	
	/**
	 *
	 * @var boolean @ORM\Column(name="profile_photo", type="boolean", nullable=false)
	 */
	private $profile_photo = 0;
	
	/**
	 *
	 * @var string @ORM\Column(name="facebook_username", type="string", length=255, nullable=false)
	 */
	private $facebook_username = '';
	
	/**
	 *
	 * @var string @ORM\Column(name="twitter_username", type="string", length=255, nullable=false)
	 */
	private $twitter_username = '';
	
	/**
	 *
	 * @var boolean @ORM\Column(name="disable_account", type="boolean", nullable=false)
	 */
	protected $disable_account = 0;
	
	/**
	 *
	 * @var string @ORM\Column(name="forgot_token", type="string", length=255, nullable=false)
	 */
	private $forgot_token = '';
	
	/**
	 *
	 * @var string @ORM\Column(name="create_date", type="string", length=255, nullable=false)
	 */
	private $create_date;
	
	/**
	 *
	 * @var string @ORM\Column(name="update_time", type="string", length=255, nullable=false)
	 */
	private $update_time;
	
	/**
	 *
	 * @var string @ORM\Column(name="invited_by", type="string", length=255, nullable=false)
	 */
	private $invited_by;
	
	/**
	 *
	 * @var string @ORM\Column(name="metadata", type="text", nullable=true)
	 */
	private $metadata;
	public function __set($name, $value) {
		$this->$name = $value;
	}
	public function __get($name) {
		return $this->$name;
	}
}