<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class MemreasStringsStripe {
	
	//set locale
	public static $locale = '_EN';
	
	//en section
	const TEST = 'TEST';
	
    static function getString($string) {
        return constant('self::'. $string . self::$locale);
    }
}