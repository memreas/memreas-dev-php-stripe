<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * UserSession
 *
 * @ORM\Table(name="user_session")
 * @ORM\Entity
 */
class UserSession {
	
	/**
	 *
	 * @var string @ORM\Id
	 * @var string @ORM\Column(name="session_id", type="string", length=36, nullable=false)
	 */
	protected $session_id;
	
	/**
	 *
	 * @var string @ORM\Id
	 * @var string @ORM\Column(name="user_id", type="string", length=36, nullable=false)
	 */
	protected $user_id;
	
	/**
	 *
	 * @var string @ORM\Column(name="ipaddress", type="string", length=20, nullable=true)
	 */
	protected $ipaddress;
	
	/**
	 *
	 * @var string @ORM\Column(name="device_id", type="string", length=45, nullable=true)
	 */
	protected $device_id;
	
	/**
	 *
	 * @var string @ORM\Column(name="meta", type="string", length=255, nullable=true)
	 */
	protected $meta;
	
	/**
	 *
	 * @var string @ORM\Column(name="start_time", type="string", length=255, nullable=true)
	 */
	protected $start_time;
	
	/**
	 *
	 * @var string @ORM\Column(name="end_time", type="string", length=255, nullable=true)
	 */
	protected $end_time;
	public function __set($name, $value) {
		$this->$name = $value;
	}
	public function __get($name) {
		return $this->$name;
	}
}