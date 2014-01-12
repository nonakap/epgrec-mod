<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );

if( isset($_GET['program_id']) ){
	$program_id = $_GET['program_id'];
	if( $program_id ){
		try {
			$rec = new DBRecord(PROGRAM_TBL, 'id', $program_id );
			$rec->autorec = (boolean)$rec->autorec ? 0:1;
			$rec->key_id  = 0;
			$rec->update();
		}
		catch( Exception $e ) {
			// 無視
		}
	}
}
exit();
?>
