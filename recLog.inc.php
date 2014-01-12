<?php

define( 'E_INFO' , 0 );
define( 'EPGREC_WARN', 1 );
define( 'EPGREC_ERROR', 2 );
define( 'EPGREC_DEBUG', 3 );


function reclog( $message , $level = E_INFO ) {
	
	try {
		$log = new DBRecord( LOG_TBL );
		
		$log->logtime = date('Y-m-d H:i:s');
		$log->level = $level;
		$log->message = strpos( $message, '<br>' )===FALSE && strpos( $message, '</a>' )===FALSE ? htmlspecialchars($message) : $message;
		$log->update();
	}
	catch( Exception $e ) {
		// 
	}
}

?>
