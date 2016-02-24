<?php

/**
 * Copyright (C) 2015 memreas llc. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */
namespace Application\memreas;

use Application\memreas\Mlog;

class CheckGitPull {
	protected $gitlock = "/var/www/ephemeral0/gitpull.lock";
	protected $github_basedir = "/var/www/memreas-dev-php-stripe/";
	function execOps($op) {
		$outarr = array ();
		$ret = '';
		/**
		 * Exec op and error log results...
		 */
		$output = shell_exec ( $op . ' 2>&1' ) . PHP_EOL;
		return $output;
	}
	public function exec($pull = false) {
		$cm = __CLASS__.__METHOD__;
		Mlog::addone ( $cm , __LINE__);
		$pulled_latest = false;
		$output = '';
		Mlog::addone ( $cm , __LINE__);
		if (file_exists ( $this->gitlock ) && ! $pull) {
			$pulled_latest = true;
		} else if (! file_exists ( $this->gitlock ) || $pull) {
			// Setup SSH agent
		Mlog::addone ( $cm , __LINE__);
			$output = $this->execOps ( 'eval "$(ssh-agent -s)"' );
			
			// Add key
		Mlog::addone ( $cm , __LINE__);
			$output .= $this->execOps ( "ssh-add ~/.ssh/id_rsa" );
			
			// check ssh auth sock
		Mlog::addone ( $cm , __LINE__);
			$output .= $this->execOps ( 'echo "$SSH_AUTH_SOCK"' );
			
			// check github access
		Mlog::addone ( $cm , __LINE__);
			$output .= $this->execOps ( 'ssh -T git@github.com' );
			
			// cd to $github_basedir
		Mlog::addone ( $cm , __LINE__);
			$output .= $this->execOps ( "cd $this->github_basedir" );
			
			// remove composer.phar 10-JAN-2015 - pushing entire folder to avoid merge conflicts
			// $output .= $this->execOps ( "git reset --hard HEAD" );
			
			// git pull
		Mlog::addone ( $cm , __LINE__);
			$output .= $this->execOps ( "git pull" );
			
			// write lock file
		Mlog::addone ( $cm , __LINE__);
			if (file_exists ( $this->gitlock )) {
		Mlog::addone ( $cm , __LINE__);
				$output .= $this->execOps ( "rm " . $this->gitlock );
			}
		Mlog::addone ( $cm , __LINE__);
			$file = fopen ( $this->gitlock, "w" );
		Mlog::addone ( $cm , __LINE__);
			echo fwrite ( $file, $output );
		Mlog::addone ( $cm , __LINE__);
			fclose ( $file );
			
			// set permissions
		Mlog::addone ( $cm , __LINE__);
			$pulled_latest = true;
			
		Mlog::addone ( $cm , __LINE__);
			$output .= "\nCompleted git pull op...";
			
		Mlog::addone ( $cm , __LINE__);
			Mlog::addone ( 'output::', $output );
		}
		Mlog::addone ( $cm , __LINE__);
		return $output;
	}
} // end class MemreasTranscoder