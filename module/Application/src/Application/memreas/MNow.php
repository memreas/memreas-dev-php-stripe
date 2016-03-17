<?php

/**
 * Copyright (C) 2016 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

class MNow {
	public static function now() {
		return date ( 'Y-m-d H:i:s' );
	}
}
?>
