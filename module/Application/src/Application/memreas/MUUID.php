<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Guzzle\Http\Client;

// https://github.com/ramsey/uuid
use Rhumsaa\Uuid\Uuid;
use Rhumsaa\Uuid\Exception\UnsatisfiedDependencyException;
use Application\Model\MemreasConstants;

class MUUID {
	public static function fetchUUID() {
		return ( string ) Uuid::uuid4 ();
	}
}
?>
