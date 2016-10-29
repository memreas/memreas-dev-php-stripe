#Purpose -> check merge conflicts.
#Created on 22-OCT-2016
#Author = John Meah
#Version 1.0


echo -n "Checking for files with merge conflicts - see output below..."

git diff --name-only --diff-filter=U

