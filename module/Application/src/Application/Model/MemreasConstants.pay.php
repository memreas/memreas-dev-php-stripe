<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\Model;

class MemreasConstants {
	
	// Redis section ubuntu standalone for redis 3 version
	// const REDIS_SERVER_ENDPOINT = "54.225.187.57";
	const REDIS_SERVER_ENDPOINT = "10.179.214.247";
	const REDIS_SERVER_USE = true;
	const REDIS_SERVER_SESSION_ONLY = true;
	const REDIS_SERVER_PORT = "6379";
	const REDIS_CACHE_TTL = 3600; // 1 hour
	const MEMREASDB = 'memreasdb';
	const MEMREASPAYMENTSDB = 'memreaspaymentstripe';
	const S3BUCKET = "memreasdev";
	const ORIGINAL_URL = "https://memreasdev-pay.memreas.com/";
	const MEDIA_URL = "https://memreasdev-wsj.memreas.com/?action=addmediaevent";
	const MEMREAS_FE = "https://fe.memreas.com";
	const MEMREAS_WS = "https://memreasdev-wsj.memreas.com";
	const MEMREAS_WSPROXYPAY = "https://memreasdev-wsj.memreas.com/index?action=";
	const URL = "/index";
	
	// Key data
	const SECRET_KEY = 'sk_test_AFU42eViRyXQfXVSxCPPh05Q';
	const PUBLIC_KEY = 'pk_test_nvbjKhLYYUe4iuuPE3PC6BZ8';
	
	// Account data
	const ACCOUNT_MEMREAS_FLOAT = "memreas_float";
	const ACCOUNT_MEMREAS_MASTER = "memreas_master";
	const MEMREAS_PROCESSING_FEE = 0.2; // 80 / 20 marketplace
	const PLAN_BILLINGFREQUENCY = "1";
	const PLAN_BILLINGPERIOD = "Month";
	const PLAN_BILLINGCYCLES = "36";
	
	/* Plan config */
	const PLAN_AMOUNT_A = 0;
	const PLAN_ID_A = 'PLAN_A_2GB_MONTHLY';
	const PLAN_DETAILS_A = "plan a: free for 2GB monthly";
	const PLAN_GB_STORAGE_AMOUNT_A = 2;
	const PLAN_AMOUNT_B = 2.95;
	const PLAN_ID_B = 'PLAN_B_10GB_MONTHLY';
	const PLAN_DETAILS_B = "plan b: $2.95 for 10GB monthly";
	const PLAN_GB_STORAGE_AMOUNT_B = 10;
	const PLAN_AMOUNT_C = 4.95;
	const PLAN_ID_C = 'PLAN_C_50GB_MONTHLY';
	const PLAN_DETAILS_C = "plan c: $4.95 for 50GB monthly";
	const PLAN_GB_STORAGE_AMOUNT_C = 50;
	const PLAN_AMOUNT_D = 9.95;
	const PLAN_ID_D = 'PLAN_D_100GB_MONTHLY';
	const PLAN_DETAILS_D = "plan d: $9.95 for 100GB monthly";
	const PLAN_GB_STORAGE_AMOUNT_D = 100;
	const ADMIN_EMAIL = 'admin@memreas.com';
	
	const AWS_APPKEY = 'AKIAJ67QJ5UNPE5QIGKA';
	const AWS_APPSEC = 'D4xqQYQU+fPu4n/cmvMPkDl2JtEXSX47xu+NjjIl';
	public static function fetchAWS() {
		$sharedConfig = [
				'region' => 'us-east-1',
				'version' => 'latest',
				'debug' => 'true',
				'credentials' => [
						'key' => self::AWS_APPKEY,
						'secret' => self::AWS_APPSEC
				]
		];
	
		return new \Aws\Sdk ( $sharedConfig );
	}
	
}