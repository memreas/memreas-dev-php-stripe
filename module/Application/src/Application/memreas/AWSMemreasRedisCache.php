<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Application\Model\MemreasConstants;
use Predis\Collection\Iterator;

class AWSMemreasRedisCache {
	private $aws = "";
	public $cache = "";
	private $client = "";
	private $isCacheEnable = MemreasConstants::REDIS_SERVER_USE;
	private $dbAdapter;
	public function __construct($service_locator) {
		if (! $this->isCacheEnable) {
			return;
		}
		
		$this->dbAdapter = $service_locator->get ( 'doctrine.entitymanager.orm_default' );
		
		try {
			$this->cache = new \Predis\Client ( [ 
					'scheme' => 'tcp',
					'host' => MemreasConstants::REDIS_SERVER_ENDPOINT,
					'port' => 6379 
			] );
		} catch ( \Predis\Connection\ConnectionException $ex ) {
			error_log ( "exception ---> " . print_r ( $ex, true ) . PHP_EOL );
		}
	}
	public function setCache($key, $value, $ttl = MemreasConstants::REDIS_CACHE_TTL) {
		if (! $this->isCacheEnable) {
			return false;
		}
		// $result = $this->cache->set ( $key , $value, $ttl );
		$result = $this->cache->executeRaw ( array (
				'SETEX',
				$key,
				$ttl,
				$value 
		) );
		
		// Debug
		if ($result) {
			// error_log('JUST ADDED THIS KEY ----> ' . $key . PHP_EOL);
			// error_log('VALUE ----> ' . $value . PHP_EOL);
		} else {
			// error_log('FAILED TO ADD THIS KEY ----> ' . $key . ' reason code ---> ' . $this->cache->getResultCode(). PHP_EOL);
			error_log ( 'FAILED TO ADD THIS KEY VALUE----> ' . $value . PHP_EOL );
		}
		return $result;
	}
	public function findSet($set, $match) {
		error_log ( "Inside findSet.... set $set match $match" . PHP_EOL );
		// Scan the hash and return 0 or the sub-array
		$result = $this->cache->executeRaw ( array (
				'ZRANGEBYLEX',
				$set,
				"[" . $match,
				"(" . $match . "z" 
		) );
		if ($result != "(empty list or set)") {
			$matches = $result;
		} else {
			$matches = 0;
		}
		// error_log ( "matches------> " . json_encode ( $matches ) . PHP_EOL );
		return $matches;
	}
	public function addSet($set, $key, $val = null) {
		if (is_null ( $val ) && ! $this->cache->executeRaw ( array (
				'ZCARD',
				$set,
				$key 
		) )) {
			return $this->cache->zadd ( "$set", "$key" );
		} else if (! is_null ( $val )) {
			// error_log("addSet $set:$key:$val".PHP_EOL);
			return $this->cache->hset ( "$set", "$key", "$val" );
		} else {
			// do nothing key exists...
			// error_log("addSet $set:$key already exists...".PHP_EOL);
		}
	}
	public function hasSet($set, $hash = false) {
		// Scan the hash and return 0 or the sub-array
		if ($hash) {
			$result = $this->cache->executeRaw ( array (
					'HLEN',
					$set 
			) );
		} else {
			$result = $this->cache->executeRaw ( array (
					'ZCARD',
					$set 
			) );
		}
		// error_log ( "hasSet result set $set ------> " . json_encode ( $result ) . PHP_EOL );
		// Debugging
		// if ($set = "@person") {
		// return 0;
		// }
		
		return $result;
	}
	public function getSet($set) {
		return $this->cache->smembers ( $set, true );
	}
	public function remSet($set) {
		$this->cache->executeRaw ( array (
				'DEL',
				$set 
		) );
	}
	public function remSetKeys($set) {
		$i = 0;
		foreach ( $set as $cacheKey ) {
			$i += $this->cache->del ( $cacheKey );
		}
		return $i;
	}
	public function getCache($key) {
		if (! $this->isCacheEnable) {
			// error_log("isCacheEnable ----> ".$this->isCacheEnable.PHP_EOL);
			return false;
		}
		
		$result = $this->cache->get ( $key );
		/*
		 * if ($result) {
		 * //error_log('JUST FETCHED THIS KEY ----> ' . $key . PHP_EOL);
		 * } else {
		 * error_log('COULD NOT FIND THIS KEY GOING TO DB ----> ' . $key . PHP_EOL);
		 * }
		 */
		
		return $result;
	}
	public function invalidateCache($key) {
		if (! $this->isCacheEnable) {
			return false;
		}
		
		$result = $this->cache->del ( $key );
		// if ($result) {
		// // error_log('JUST DELETED THIS KEY ----> ' . $key . PHP_EOL);
		// } else {
		// error_log ( 'COULD NOT DELETE THIS KEY ----> ' . $key . PHP_EOL );
		// }
	}
	public function invalidateCacheMulti($keys) {
		if (! $this->isCacheEnable) {
			return false;
		}
		
		return $this->cache->deleteMulti ( $keys );
		// $result = $this->cache->deleteMulti ( $keys );
		// if ($result) {
		// // error_log('JUST DELETED THESE KEYS ----> ' . json_encode($keys) . PHP_EOL);
		// } else {
		// error_log ( 'COULD NOT DELETE THES KEYS ----> ' . json_encode ( $keys ) . PHP_EOL );
		// }
		// return $result;
	}
	
	/*
	 * Add function to invalidate cache for media
	 */
	public function invalidateMedia($user_id, $event_id = null, $media_id = null) {
		// error_log("Inside invalidateMedia".PHP_EOL);
		// error_log('Inside invalidateMedia $user_id ----> *' . $user_id . '*' . PHP_EOL);
		// error_log('Inside invalidateMedia $event_id ----> *' . $event_id . '*' . PHP_EOL);
		// error_log('Inside invalidateMedia $media_id ----> *' . $media_id . '*' . PHP_EOL);
		// write functions for media
		// - add media event (key is event_id or user_id)
		// - mediainappropriate (key is user id for invalidate)
		// - deletePhoto (key is user id for invalidate)
		// - update media
		// - removeeventmedia
		$cache_keys = array ();
		$event_id = trim ( $event_id );
		if (! empty ( $event_id )) {
			$cache_keys [] = "listallmedia_" . $event_id;
			$cache_keys [] = "geteventdetails_" . $event_id;
		}
		$media_id = trim ( $media_id );
		if (! empty ( $media_id )) {
			$cache_keys [] = "viewmediadetails_" . $media_id;
		}
		$user_id = trim ( $user_id );
		if (! empty ( $user_id )) {
			// countviewevent can return me / friends / public
			$cache_keys [] = "listallmedia_" . $user_id;
			$cache_keys [] = "viewevents_is_my_event_" . $user_id;
			$cache_keys [] = "viewevents_is_friend_event_" . $user_id;
		}
		// Mecached - deleteMulti...
		$result = $this->remSetKeys ( $cache_keys );
		if ($result) {
			$now = date ( 'Y-m-d H:i:s.u' );
			error_log ( 'invalidateCacheMulti JUST DELETED THESE KEYS ----> ' . json_encode ( $cache_keys ) . " time: " . $now . PHP_EOL );
		} else {
			$now = date ( 'Y-m-d H:i:s.u' );
			error_log ( 'invalidateCacheMulti COULD NOT DELETE THES KEYS ----> ' . json_encode ( $cache_keys ) . " time: " . $now . PHP_EOL );
		}
	} // End invalidateMedia
	
	/*
	 * Add function to invalidate cache for events
	 */
	public function invalidateEvents($user_id) {
		error_log ( "Inside invalidateEvents" . PHP_EOL );
		// write functions for media
		// - add event (key is event_id)
		// - removeevent
		if (! empty ( $user_id )) {
			// countviewevent can return me / friends / public
			$cache_keys = array (
					"viewevents_is_my_event_" . $user_id,
					"viewevents_is_friend_event_" . $user_id 
			);
			$this->invalidateCacheMulti ( $cache_keys );
		}
	}
	
	/*
	 * Add function to invalidate cache for comments
	 */
	public function invalidateComments($cache_keys) {
	}
	
	/*
	 * Add function to invalidate cache for event friends
	 */
	public function invalidateEventFriends($event_id, $user_id) {
		// write functions for media
		// - add event friend
		// - remove event friend
		if (! empty ( $event_id )) {
			// countviewevent can return me / friends / public
			$cache_keys = array (
					"geteventpeople_" . $event_id,
					"viewevents_is_my_event_" . $user_id,
					"viewevents_is_friend_event_" . $user_id 
			);
			$this->invalidateCacheMulti ( $cache_keys );
		}
	}
	
	/*
	 * Add function to invalidate cache for friends
	 */
	public function invalidateFriends($user_id) {
		// write functions for media
		// - add friend
		// - remove friend
		if (! empty ( $event_id )) {
			// countviewevent can return me / friends / public
			$cache_keys = array (
					"listmemreasfriends_" . $user_id,
					"getfriends_" . $user_id 
			);
			$this->invalidateCacheMulti ( $cache_keys );
			$this->invalidateEvents ( $user_id );
		}
	}
	
	/*
	 * Add function to invalidate cache for notifications
	 */
	public function invalidateNotifications($user_id) {
		// write functions for groups
		// - list notification (key is user_id)
		if (! empty ( $user_id )) {
			$this->invalidateCache ( "listnotification_" . $user_id );
		}
	}
	
	/*
	 * Add function to invalidate cache for user
	 */
	public function invalidateUser($user_id) {
		// write functions for groups
		// - list group (key is user_id)
		if (! empty ( $user_id )) {
			$this->invalidateCache ( "getuserdetails_" . $user_id );
		}
	}
	
	/*
	 * Add function to invalidate cache for groups
	 */
	public function invalidateGroups($user_id) {
		// write functions for groups
		// - list group (key is user_id)
		if (! empty ( $user_id )) {
			$this->invalidateCache ( "listgroup_" . $user_id );
		}
	}
}

?>
		