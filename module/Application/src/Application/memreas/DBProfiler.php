<?php

namespace Application\memreas;

use Zend\ServiceManager\ServiceManager;

class DBProfiler {
	public function fetchProfiler($sl, $dbname) {
		error_log ( "Inside DBProfiler..." );
		$profiler = $sl->get ( $dbname )->getProfiler ();
		// $profiler->setEnabled(true);
		error_log ( "Inside DBProfiler profiler ---> " . print_r ( $profiler, true ) . PHP_EOL );
		// $queryProfiles = $profiler->getQueryProfiles();
		
		// foreach($queryProfiles as $key=>$row)
		// {
		// error_log(print_r($row->toArray(), true));
		// }
	}
}
?>
