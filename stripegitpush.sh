#Purpose = ssh or sftp into ec2 instance
#Created on 1-NOV-2014
#Author = John Meah
#Version 1.0

echo -n "Enter the details of your deployment (i.e. 4-FEB-2014 Updating this script.) > "
read comment
echo "You entered $comment"
#set -v verbose #echo on

#copy fe settings to push to git...
echo cp ./module/Application/src/Application/Model/MemreasConstants.pay.php ./module/Application/src/Application/Model/MemreasConstants.php

#Push to AWS
echo "Committing to git..."
git add .
git commit -m "$comment"
echo "Pushing to github..."
set -v verbose #echo on
git push

echo cp module/Application/src/Application/Model/MemreasConstants.localhost.php module/Application/src/Application/Model/MemreasConstants.php

#eb events -f


#
# curl url to pull latest on backend
#
#
# curl url to pull latest on backend
#
curl https://memreasdev-pay.memreas.com/?action=clearlog
curl https://memreasdev-pay.memreas.com/?action=gitpull
