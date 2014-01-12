#!/usr/bin/php
<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/Settings.class.php' );
	include_once( INSTALL_PATH . '/reclib.php' );

	$settings = Settings::factory();

	run_user_regulate();
	$shm_id = shmop_open_surely();
	if( isset( $argv[1] ) ){
		$val = isset( $argv[2] ) ? (int)$argv[2] : (int)0;
		shmop_write_surely( $shm_id, (int)$argv[1], $val );
	}else{
		for( $tuner=0; $tuner<$settings->gr_tuners;$tuner++ ){
			shmop_write_surely( $shm_id, SEM_GR_START+$tuner, 0 );
		}
		for( $tuner=0; $tuner<$settings->bs_tuners;$tuner++ ){
			shmop_write_surely( $shm_id, SEM_ST_START+$tuner, 0 );
		}
		for( $tuner=0; $tuner<EXTRA_TUNERS;$tuner++ ){
			shmop_write_surely( $shm_id, SEM_EX_START+$tuner, 0 );
		}
		for( $tuner=0; $tuner<20;$tuner++ ){
			shmop_write_surely( $shm_id, SEM_REALVIEW+$tuner, 0 );
		}
	}
	shmop_close( $shm_id );
	exit();
?>
