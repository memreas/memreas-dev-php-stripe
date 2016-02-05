#!/bin/bash
#Purpose = ssh or sftp into ec2 instance
#Created on 4-APR-2014
#Author = John Meah
#Version 1.0
set -v verbose #echo on

cp module/Application/src/Application/Model/MemreasConstants.localhost.php module/Application/src/Application/Model/MemreasConstants.php