#!/usr/bin/php
<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');

  $type = $argv[1];	// BS CS GR
  $file = $argv[2];	// XMLファイル
  
  // SIGTERMシグナル
  function handler( $signo = 0 ) {
	global $file;
	if( file_exists( $file ) ) {
		@unlink( $file );
	}
	exit();
  }
  
  // デーモン化
  function daemon() {
	if( pcntl_fork() != 0 )
		exit();
	posix_setsid();
	if( pcntl_fork() != 0 )
		exit;
	pcntl_signal(SIGTERM, "handler");
  }
  
  // デーモン化
  daemon();
  // プライオリティ低に
  pcntl_setpriority(20);
  
  include_once( INSTALL_PATH . '/DBRecord.class.php' );
  include_once( INSTALL_PATH . '/Reservation.class.php' );
  include_once( INSTALL_PATH . '/Keyword.class.php' );
  include_once( INSTALL_PATH . '/Settings.class.php' );
  include_once( INSTALL_PATH . '/storeProgram.inc.php' );
  
  $settings = Settings::factory();
  
  if( file_exists( $file ) ) {
	$shm_id = shmop_open_surely();
	$ch_id = storeProgram( $type, $file );
	@unlink( $file );
//  garbageClean();			//  不要プログラム削除
	if( $ch_id != -1 ){
		doKeywordReservation( $type, $shm_id );	// キーワード予約
	}
	shmop_close( $shm_id );
  }
?>