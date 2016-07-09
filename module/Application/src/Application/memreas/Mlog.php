<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Application\Model\MemreasConstants;

class Mlog {
	public static $log;
	
	/**
	 * funtion adds to array for name, value, and outputs
	 *
	 * @param unknown $objname
	 *        	- name of var to be error logged
	 * @param unknown $obj
	 *        	- to be error logged
	 * @param unknown $opt
	 *        	- how to format
	 *        	\n - newline
	 *        	p - print_r($obj)
	 *        	j - json_encode($obj)
	 *        	a - separator (--->)
	 */
	public static function addone($objname, $obj, $opt = '\n') {
		if (empty ( $obj )) {
			$obj = 'object is empty';
		} else if ((is_array ( $obj )) || (is_object ( $obj ))) {
			$opt = 'p';
		} else if ($opt != '\n') {
			// do nothing option set...
		}
		
		self::addObj ( $objname . '::', $obj, $opt );
		self::out ();
	}
	
	/**
	 * funtion adds to array
	 *
	 * @param unknown $obj
	 *        	- to be error logged
	 * @param unknown $opt
	 *        	- how to format
	 *        	\n - newline
	 *        	p - print_r($obj)
	 *        	j - json_encode($obj)
	 *        	a - separator (--->)
	 */
	public static function add($obj, $opt = '\n', $out = 0) {
		self::$log [] = array (
				'obj' => $obj,
				'opt' => $opt 
		);
		if ($out) {
			self::out ();
		}
	}
	public static function addObj($objname, $obj, $opt = '\n', $out = 0) {
		self::$log [] = array (
				'objname' => $objname,
				'obj' => $obj,
				'opt' => $opt 
		);
		if ($out) {
			self::out ();
		}
	}
	
	/**
	 * function outs to error_log()
	 *
	 * @param unknown $arr
	 *        	- array to be output
	 */
	public static function out() {
		foreach ( self::$log as $item ) {
			if (! empty ( $item ['objname'] )) {
				$objname = $item ['objname'];
			} else {
				$objname = '';
			}
			$obj = $item ['obj'];
			$opt = $item ['opt'];
			if ($opt == 'j') {
				error_log ( $objname . json_encode ( $obj ) . PHP_EOL );
			} else if ($opt == 'p') {
				error_log ( $objname . print_r ( $obj, true ) . PHP_EOL );
			} else {
				// must be string
				error_log ( $objname . $obj . PHP_EOL );
			}
		} // end for
		self::$log = array ();
	}
}