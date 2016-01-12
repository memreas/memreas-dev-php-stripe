<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Aws\S3;
use Aws\CloudFront;
use Aws\S3\Exception\S3Exception;
use Zend\Session\Container;
use Application\Model\MemreasConstants;
use Application\memreas\AWSManagerSender;
use Application\memreas\Mlog;

class MemreasSignedURL {
	protected $message_data;
	protected $memreas_tables;
	protected $service_locator;
	protected $dbAdapter;
	protected $aws;
	protected $s3;
	protected $cloud_front;
	public function __construct() {
		$this->private_key_filename = getcwd () . MemreasConstants::CLOUDFRONT_KEY_FILE;
		$this->key_pair_id = MemreasConstants::CLOUDFRONT_KEY_PAIR_ID;
		$this->expires = time () + MemreasConstants::CLOUDFRONT_EXPIRY_TIME;
		$this->signature_encoded = null;
		$this->policy_encoded = null;
		
		// Fetch aws handle
		$this->aws = MemreasConstants::fetchAWS ();
		
		// Fetch the S3 class
		$this->s3 = $this->aws->createS3 ();
		
		// Fetch the CloudFront class
		$this->cloud_front = $this->aws->createCloudFront ();
	}
	public function fetchSignedURL($path) {
		if ((MemreasConstants::SIGNURLS) && ! empty ( $path ) && ! is_array ( $path )) {
			$this->expires = time () + MemreasConstants::EXPIRES;
			
			$signed_url = $this->get_canned_policy_stream_name ( $path, $this->private_key_filename, $this->key_pair_id, $this->expires );
			
			return $signed_url;
		} else {
			return $path; // path is empty
		}
	}
	
	/*
	 * 5-SEP-2014
	 * JM Change to allow for multiple thumbnails in response
	 * sends back simple json encoded array
	 */
	public function signArrayOfUrls($obj, $host = MemreasConstants::CLOUDFRONT_DOWNLOAD_HOST) {
		if (! empty ( $obj ) && is_array ( $obj )) {
			foreach ( $obj as $url ) {
				if (isset ( $url ) && ! empty ( $url )) {
					$arr [] = $this->fetchSignedURL ( $host . $url );
				}
			}
		} else if (! empty ( $obj )) {
			$arr [] = $this->fetchSignedURL ( $host . $obj ); // this should be string not array
		} else {
			$arr [] = $this->fetchSignedURL ( $host . 'static/profile-pic.jpg' ); // default
		}
		
		$json_arr = json_encode ( $arr );

		return $json_arr;
	}
	public function signHlsUrl($obj) {
		if (! empty ( $obj )) {
			$arr [] = MemreasConstants::CLOUDFRONT_HLSSTREAMING_HOST . $obj;
		} else {
			$arr [] = MemreasConstants::CLOUDFRONT_HLSSTREAMING_HOST . $obj;
		}
		return json_encode ( $arr );
	}
	
	//
	// Nice try but function doesn't work...
	//
	public function createAndSignCustomHLS($media_id, $path) {
		//
		// TODO::Delete existing - not optimal but will work for now...
		//
		$path_parts = pathinfo ( $path );
		$s3path = $path_parts ['dirname'];
		$singedM3u8FilePrefix = "signedM3u8_";
		$s3prefix = $s3path . "/" . $singedM3u8FilePrefix;
		$iterator = $this->s3->getIterator ( 'ListObjects', array (
				'Bucket' => MemreasConstants::S3BUCKET,
				'Prefix' => $s3prefix 
		) );
		
		foreach ( $iterator as $object ) {
			//
			// Check key if it's still valid
			//
			$split_url = explode ( $s3prefix, $object ['Key'] );
			$split_url = explode ( "_", $split_url [1] );
			$timeOfExpiry = $split_url [0];
			
			//
			// if it is return it
			//
			if ($timeOfExpiry < time ()) {
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::Found key returning-->', $object ['Key'] );
				return $object ['Key'];
			}
			
			//
			// else delete and recreate the entry
			//
			$this->s3->deleteObject ( array (
					'Bucket' => MemreasConstants::S3BUCKET,
					'Key' => $object ['Key'] 
			) );
			Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . 'MemreasConstants::S3BUCKET::$object[Key]::deleted::', $object ['Key'] );
		}
		
		//
		// If not fetch the file and store it locally
		//
		$path_url = explode ( "/", $path );
		$fileName = $path_url [count ( $path_url ) - 1];
		$localM3u8Path = tmpfile ();
		$result = 0;
		try {
			$result = $this->s3->getObject ( [ 
					'Bucket' => MemreasConstants::S3BUCKET,
					'Key' => $path 
			] );
		} catch ( S3Exception $s3e ) {
			return 0;
		}
		if ($result) {
			
			//
			// fetch a signed url for the new file name
			//
			$this->expires = time () + MemreasConstants::EXPIRES;
			$signedFileName = $singedM3u8FilePrefix . $this->expires . "_" . $fileName;
			$signedS3M3u8Path = $s3path . '/' . $signedFileName;
			$signedS3M3u8Path = $this->fetchSignedURL ( MemreasConstants::CLOUDFRONT_DOWNLOAD_HOST . $signedS3M3u8Path );
			$queryString = '?' . parse_url ( $signedS3M3u8Path, PHP_URL_QUERY );
			
			//
			// Read the original and write the file
			//
			$data = $result->get ( 'Body' );
			$signedData = str_replace ( ".ts\n", ".ts" . $queryString . "\n", $data );
			$localSignedM3u8Path = tmpfile ();
			
			//
			// Store back to S3
			//
			$result = 0;
			$s3SignedM3u8Path = $s3path . '/' . $signedFileName;
			try {
				$result = $this->s3->putObject ( [ 
						'Bucket' => MemreasConstants::S3BUCKET,
						'Key' => $s3SignedM3u8Path,
						'Body' => $signedData,
						'ContentType' => "application/x-mpegurl",
						'ServerSideEncryption' => 'AES256',
						'StorageClass' => 'REDUCED_REDUNDANCY' 
				] );
			} catch ( S3Exception $s3e ) {
				return 0;
			}
			
			if ($result) {
				Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::uploaded $s3SignedM3u8Path--->', "$s3SignedM3u8Path" );
				return $signedS3M3u8Path;
			}
		}
		
		Mlog::addone ( __CLASS__ . __METHOD__ . __LINE__ . '::failed upload $s3SignedM3u8Path--->', "$s3SignedM3u8Path" );
		return 0;
	}
	public function exec() {
		$data = simplexml_load_string ( $_POST ['xml'] );
		// 0 = not empty, 1 = empty
		$path = trim ( $data->signedurl->path );
		$time = time ();
		
		header ( "Content-type: text/xml" );
		$xml_output = "<?xml version=\"1.0\"  encoding=\"utf-8\" ?>";
		$xml_output .= "<xml>";
		$xml_output .= "<signedurlresponse>";
		
		if (isset ( $path ) && ! empty ( $path )) {
			$signedurl = $this->get_canned_policy_stream_name ( $path, $this->private_key_filename, $this->key_pair_id, $this->expires );
			$xml_output .= "<status>success</status>";
			$xml_output .= "<key_pair_id>$this->key_pair_id</key_pair_id>";
			$xml_output .= "<signature_encoded>$this->signature_encoded</signature_encoded>";
			$xml_output .= "<policy_encoded>$this->policy_encoded</policy_encoded>";
		} else {
			$xml_output .= "<status>failure</status><message>Please checked that you have given all the data required for signedurl.</message>";
		}
		$xml_output .= "</signedurlresponse>";
		$xml_output .= "</xml>";
		echo $xml_output;
		error_log ( "SignedUrl ---> xml_output ----> " . $xml_output . PHP_EOL );
	}
	function rsa_sha1_sign($policy, $private_key_filename) {
		$signature = "";
		
		// load the private key
		$fp = fopen ( $private_key_filename, "r" );
		$priv_key = fread ( $fp, 8192 );
		fclose ( $fp );
		$pkeyid = openssl_get_privatekey ( $priv_key );
		
		// compute signature
		openssl_sign ( $policy, $signature, $pkeyid );
		
		// free the key from memory
		openssl_free_key ( $pkeyid );
		
		return $signature;
	}
	function url_safe_base64_encode($value) {
		$encoded = base64_encode ( $value );
		// replace unsafe characters +, = and / with the safe characters -, _ and ~
		return str_replace ( array (
				'+',
				'=',
				'/' 
		), array (
				'-',
				'_',
				'~' 
		), $encoded );
	}
	function create_stream_name($stream, $policy, $signature, $key_pair_id, $expires) {
		$result = $stream;
		
		$path = ""; // Change made here to fix missing $path variable
		            
		// if the stream already contains query parameters, attach the new query parameters to the end
		            // otherwise, add the query parameters
		$separator = strpos ( $stream, '?' ) == FALSE ? '?' : '&';
		// the presence of an expires time means we're using a canned policy
		if ($expires) {
			$result .= $path . $separator . "Expires=" . $expires . "&Signature=" . $signature . "&Key-Pair-Id=" . $key_pair_id;
		}  // not using a canned policy, include the policy itself in the stream name
else {
			$result .= $path . $separator . "Policy=" . $policy . "&Signature=" . $signature . "&Key-Pair-Id=" . $key_pair_id;
		}
		
		// new lines would break us, so remove them
		return str_replace ( '\n', '', $result );
	}
	function encode_query_params($stream_name) {
		// the adobe flash player has trouble with query parameters being passed into it,
		// so replace the bad characters with their url-encoded forms
		return str_replace ( array (
				'?',
				'=',
				'&' 
		), array (
				'%3F',
				'%3D',
				'%26' 
		), $stream_name );
	}
	function get_canned_policy_stream_name($video_path, $private_key_filename, $key_pair_id, $expires) {
		// this policy is well known by CloudFront, but you still need to sign it, since it contains your parameters
		$canned_policy = '{"Statement":[{"Resource":"' . $video_path . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expires . '}}}]}';
		
		// the policy contains characters that cannot be part of a URL, so we base64 encode it
		$encoded_policy = $this->url_safe_base64_encode ( $canned_policy );
		$this->policy_encoded = $encoded_policy;
		
		// sign the original policy, not the encoded version
		$signature = $this->rsa_sha1_sign ( $canned_policy, $private_key_filename );
		
		// make the signature safe to be included in a url
		$encoded_signature = $this->url_safe_base64_encode ( $signature );
		$this->signature_encoded = $encoded_signature;
		
		// combine the above into a stream name
		$stream_name = $this->create_stream_name ( $video_path, null, $encoded_signature, $key_pair_id, $expires );
		
		// url-encode the query string characters to work around a flash player bug
		// Commented this line there was no need to encode the query params for JW Player
		// return encode_query_params($stream_name);
		
		return $stream_name;
	}
}

?>
