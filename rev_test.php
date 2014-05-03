#!/usr/bin/php
<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/DBRecord.class.php' );
  include_once( INSTALL_PATH . '/Reservation.class.php' );
  include_once( INSTALL_PATH . '/Keyword.class.php' );
  include_once( INSTALL_PATH . '/Settings.class.php' );
  include_once( INSTALL_PATH . '/storeProgram.inc.php' );
  include_once( INSTALL_PATH . '/recLog.inc.php' );

	run_user_regulate();
	if( isset( $argv[1] ) && !strncasecmp( $argv[1], '-R', 2 ) ){
		$precs = DBRecord::createRecords( RESERVE_TBL, 'WHERE complete=0' );
		foreach( $precs as $reserve ){
			try {
				Reservation::cancel( $reserve->id );
				usleep( 100 );		// あんまり時間を空けないのもどう?
			}
			catch( Exception $e ){
				// 無視
			}
		}
		if( !strcasecmp( $argv[1], '-RR' ) )
			exit();
	}
	$shm_id = shmop_open_surely();
  doKeywordReservation( '*', $shm_id );	// キーワード予約
  shmop_close( $shm_id );
  exit();
?>
