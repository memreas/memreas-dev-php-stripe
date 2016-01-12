<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Media
 *
 * @ORM\Table(name="media")
 * @ORM\Entity
 */
class Media {
	
	/**
	 *
	 * @var string @ORM\Column(name="media_id", type="string", length=255, nullable=false)
	 *      @ORM\Id
	 *     
	 */
	private $media_id;
	
	/**
	 *
	 * @var string @ORM\Column(name="user_id", type="string", length=255, nullable=false)
	 */
	private $user_id;
	
	/**
	 *
	 * @var string @ORM\Column(name="copyright_id", type="string", length=255, nullable=false)
	 */
	private $copyright_id = '0';
	
	/**
	 *
	 * @var boolean @ORM\Column(name="is_profile_pic", type="boolean", nullable=false)
	 */
	private $is_profile_pic = '0';
	
	/**
	 *
	 * @var string @ORM\Column(name="sync_status", type="integer", nullable=false)
	 */
	private $sync_status = 0;
	
	/**
	 *
	 * @var integer @ORM\Column(name="transcode_status", type="string", length=45, nullable=false)
	 */
	private $transcode_status = 'pending';
	// pending
	// in_progress
	// success
	// failed
	
	/**
	 *
	 * @var string @ORM\Column(name="metadata", type="text", nullable=false)
	 */
	private $metadata;
	
	/**
	 *
	 * @var string @ORM\Column(name="report_flag", type="string", length=1, nullable=false)
	 */
	private $report_flag = '0';
	
	/**
	 *
	 * @var string @ORM\Column(name="create_date", type="string", length=255, nullable=false)
	 */
	private $create_date;
	
	/**
	 *
	 * @var string @ORM\Column(name="update_date", type="string", length=255, nullable=false)
	 */
	private $update_date;
	public function __set($name, $value) {
		$this->$name = $value;
	}
	public function __get($name) {
		return $this->$name;
	}
	public function __construct() {
		$this->events = new \Doctrine\Common\Collections\ArrayCollection ();
	}
}
