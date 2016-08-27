#Purpose = ssh or sftp into ec2 instance
#Created on 1-NOV-2014
#Author = John Meah
#Version 1.0

echo -n "Do you want to point to local after push (y\n) > "
read local
echo "You entered $local"

echo -n "Enter the details of your deployment (i.e. 4-FEB-2014 Updating this script.) > "
read comment
echo "You entered $comment"
#set -v verbose #echo on

#copy fe settings to push to git...
cp ./module/Application/src/Application/Model/MemreasConstants.pay.php ./module/Application/src/Application/Model/MemreasConstants.php

#Push to AWS
echo "Committing to git..."
git add .
git commit -m "$comment"
echo "Pushing to github..."
set -v verbose #echo on
git push

if [ "$local" = "y" ]
then
	cp module/Application/src/Application/Model/MemreasConstants.localhost.php module/Application/src/Application/Model/MemreasConstants.php
fi

#eb events -f

#
# curl url to pull latest on backend
#
#curl https://memreasdev-pay.memreas.com/?action=clearlog
#curl https://memreasdev-pay.memreas.com/?action=gitpull
curl http://54.160.204.224:55153/index?action=clearlog
curl http://54.160.204.224:55153/index?action=gitpull
