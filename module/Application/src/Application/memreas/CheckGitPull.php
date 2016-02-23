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
		$pulled_latest = false;
		$output = '';
		if (file_exists ( $this->gitlock ) && ! $pull) {
			$pulled_latest = true;
		} else if (! file_exists ( $this->gitlock ) || $pull) {
			// Setup SSH agent
			$output = $this->execOps ( 'eval "$(ssh-agent -s)"' );
			
			// Add key
			$output .= $this->execOps ( "ssh-add ~/.ssh/id_rsa" );
			
			// check ssh auth sock
			$output .= $this->execOps ( 'echo "$SSH_AUTH_SOCK"' );
			
			// check github access
			$output .= $this->execOps ( 'ssh -T git@github.com' );
			
			// cd to $github_basedir
			$output .= $this->execOps ( "cd $this->github_basedir" );
			
			// remove composer.phar 10-JAN-2015 - pushing entire folder to avoid merge conflicts
			// $output .= $this->execOps ( "git reset --hard HEAD" );
			
			// git pull
			$output .= $this->execOps ( "git pull" );
			
			// write lock file
			if (file_exists ( $this->gitlock )) {
				$output .= $this->execOps ( "rm " . $this->gitlock );
			}
			$file = fopen ( $this->gitlock, "w" );
			echo fwrite ( $file, $output );
			fclose ( $file );
			
			// set permissions
			// $output .= $this->execOps ( "git pull" );
			$pulled_latest = true;
			
			//putenv ( "COMPOSER_HOME=" . $this->github_basedir );
			//$output .= $this->execOps ( "php composer.phar self-update" );
			//$output .= $this->execOps ( "php composer.phar update" );
			$output .= "\nCompleted git pull op...";
			
			Mlog::addone ( 'output::', $output );
		}
		return $output;
	}
} // end class MemreasTranscoder