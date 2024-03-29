<?php

namespace Application\Model;

/**
 * Copyright (C) 2015 memreas llc.
 * - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
class MemreasConstants {
	
	// Redis section ubuntu standalone for redis 3 version
	const REDIS_SERVER_ENDPOINT = "10.0.0.231";
	const REDIS_SERVER_USE = true;
	const REDIS_SERVER_SESSION_ONLY = true;
	const REDIS_SERVER_PORT = "6379";
	const REDIS_CACHE_TTL = 3600; // 1 hour
	const MEMREASDB = 'memreasdb';
	const MEMREASPAYMENTSDB = 'memreaspaymentstripe';
	const S3BUCKET = "memreasdev";
	const ORIGINAL_URL = "http://127.0.0.1:55153/";
	const MEDIA_URL = "http://127.0.0.1:55152/?action=addmediaevent";
	const MEMREAS_FE = "http://127.0.0.1:55151";
	const MEMREAS_WS = "http://127.0.0.1:55152";
	const MEMREAS_WSPROXYPAY = "http://http://127.0.0.1:55152/index?action=";
	//const ORIGINAL_URL = "https://memreasdev-pay.memreas.com/";
	//const MEDIA_URL = "https://memreasdev-wsa.memreas.com/?action=addmediaevent";
	//const MEMREAS_FE = "https://fe.memreas.com";
	//const MEMREAS_WS = "https://memreasdev-wsa.memreas.com";
	//const MEMREAS_WSPROXYPAY = "https://memreasdev-wsa.memreas.com/index?action=";
	const URL = "/index";
	const COPYRIGHT = '&copy; memreas, llc. all rights reserved.';
	
	// Key data
	const SECRET_KEY = 'sk_test_wGuOAxPnnNVn6n9J7oBWVZ0h';
	const PUBLIC_KEY = 'pk_test_zCmszvnYzWu8VjueyHpmcAJZ';
	
	// Account data
	const ACCOUNT_MEMREAS_FLOAT = "memreas_float";
	const ACCOUNT_MEMREAS_FEES = "memreas_fees";
	const ACCOUNT_MEMREAS_PAYER = "memreas_payer";
	const ACCOUNT_MEMREAS_MASTER = "memreas_master";
	const ACCOUNT_MEMREAS_PLATFORM = "memreas_platform";
	const ACCOUNT_TYPE_BUYER = "buyer";
	const ACCOUNT_TYPE_TRACKER = "tracker";
	const ACCOUNT_TYPE_SELLER = "seller";
	const MEMREAS_PROCESSING_FEE = 0.25; // 75/25% marketplace
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
	
	// S3_AccessUser
	const AWS_APPKEY = 'AKIAIXDH3Q23AP4KPZRA';
	const AWS_APPSEC = 'TM+p8s9hlJwnKECCEKSVVhEOBjVhTWDG4ijIhJdk';
	const SES_SMTP_KEY = 'AKIAJZNY5YHUCYUP2U4A';
	const SES_SMTP_SECRET = 'Ashp1eCvbvKuL5uTpw/TSipW/O7CkewRoTl/N+NXUAGr';
	// Listpay Payout
	const LIST_MASS_PAYEE_INTERVAL = 1; // Get records for older 30 days
	public static function fetchAWS() {
		$sharedConfig = [ 
				'region' => 'us-east-1',
				'version' => 'latest',
				'credentials' => [ 
						'key' => self::AWS_APPKEY,
						'secret' => self::AWS_APPSEC 
				] 
		];
		
		return new \Aws\Sdk ( $sharedConfig );
	}
}