<?php  
/////////////////////////////////
// Author: John Meah
// Copyright memreas llc 2013
/////////////////////////////////

namespace Application\Model;
class MemreasConstants {
        const MEMREASDB     	= 'memreasdb';
        const MEMREASPAYMENTSDB	= 'memreaspaymentstripe';
        const S3BUCKET     		= "memreasdev";
        const TOPICARN			= "arn:aws:sns:us-east-1:004184890641:us-east-upload-transcode-worker-int";
        const ORIGINAL_URL		= "https://memreasdev-pay.memreas.com/";
        const MEDIA_URL			= "http://memreasdev-wsu.elasticbeanstalk.com/app/?action=addmediaevent";
        const URL				= "/index";
        const MEMREAS_WS = "https://memreasdev-wsg.memreas.com";

        const ACCOUNT_MEMREAS_FLOAT = "memreasfloat";
        const ACCOUNT_MEMREAS_MASTER = "memreasmaster";
		const PAYPAL_MEMREAS_MASTER_EMAIL = 'johnmeah-facilitator@paypal.com';
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
        
        //const SITEURL			= "http://memreasdev.elasticbeanstalk.com/eventapp_zend2.1";
        const DATA_PATH			= "/data/";
        const MEDIA_PATH		= "/media/";
        const IMAGES_PATH		= "/images/";
        const USERIMAGE_PATH	= "/media/userimage/";
        const FOLDER_PATH		= "/data/media/";
        const FOLDER_AUDIO		= "upload_audio";
        const FOLDER_VIDEO		= "uploadVideo";
        const VIDEO				= "/data/media/uploadVideo";
        const AUDIO				= "/data/media/upload_audio";


        const CLOUDFRONT_STREAMING_HOST		= 'rtmp://sliq2chtodqqky.cloudfront.net/';
        const CLOUDFRONT_DOWNLOAD_HOST		= 'http://d1ckv7o9k6o3x9.cloudfront.net/';
        //const MEMREAS_TRANSCODER_URL		= 'http://memreasbackend.elasticbeanstalk.com/';
        const MEMREAS_TRANSCODER_URL		= 'http://192.168.1.13/memreas-dev-php-backend/app/';
        const MEMREAS_TRANSCODER_TOPIC_ARN	= 'arn:aws:sns:us-east-1:004184890641:us-east-upload-transcode-worker-int';
        const ADMIN_EMAIL ='admin@memreas.com';

}