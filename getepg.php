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
  
  // 後方互換性
  if( ! defined( "BS_EPG_CHANNEL" )  ) define( "BS_EPG_CHANNEL",  "211"  );
  if( ! defined( "CS1_EPG_CHANNEL" ) ) define( "CS1_EPG_CHANNEL", "CS8"  );
  if( ! defined( "CS2_EPG_CHANNEL" ) ) define( "CS2_EPG_CHANNEL", "CS24" );

  function check_file( $file ) {
	// ファイルがないなら無問題
	if( ! file_exists( $file ) ) return true;

	// 1時間以上前のファイルなら削除してやり直す
	if( (time() - filemtime( $file )) > 3600 ) {
		@unlink( $file );
		return true;
	}

	return false;
  }

  // 並列化が可能か執念深く調べる
  $use_para = false;
  $use_para = (function_exists( "pcntl_fork" ) && function_exists( "posix_setsid" ) && function_exists( "pcntl_signal" ) && function_exists("pcntl_setpriority"));
  if( ! $use_para ) {
	reclog("getepg:: 並列実行が行えないPHP環境です" );
  }
  else {
	reclog("getepg:: 並列実行を使用します" );
  }
  
  $settings = Settings::factory();

  $temp_xml_bs  = $settings->temp_xml."_bs";
  $temp_xml_cs1 = $settings->temp_xml."_cs1";
  $temp_xml_cs2 = $settings->temp_xml."_cs2";
  $temp_xml_gr  = $settings->temp_xml."_gr";

  if( file_exists( $settings->temp_data ) ) @unlink( $settings->temp_data );

  garbageClean();			//  不要プログラム削除

	$shm_id = shmop_open_surely();
  // BSを処理する
  if( $settings->bs_tuners != 0 ) {
	// 録画重複チェック
	$num = DBRecord::countRecords(  RESERVE_TBL, "WHERE complete = '0' AND (type = 'BS' OR type = 'CS') AND endtime > now() AND starttime < addtime( now(), '00:03:05')" );
	if($num < $settings->bs_tuners && check_file($temp_xml_bs)) {
		$revs = DBRecord::createRecords(  RESERVE_TBL, "WHERE complete = '0' AND (type = 'BS' OR type = 'CS') AND endtime > now() AND starttime < addtime( now(), '00:03:05')" );
		for( $slc_tuner=$settings->bs_tuners-1; $slc_tuner>=0; $slc_tuner-- ){
			for( $cnt=0; $cnt<$num; $cnt++ ){
				if( $revs[$cnt]->tuner == $slc_tuner )
					continue 2;
			}
			break;
		}
	 	$cmdline = "CHANNEL=".BS_EPG_CHANNEL." DURATION=180 TYPE=BS TUNER_UNIT=".TUNER_UNIT1." TUNER=".$slc_tuner." MODE=0 OUTPUT=".$settings->temp_data." ".DO_RECORD . " >/dev/null 2>&1";
  		exec( $cmdline );
  		$cmdline = $settings->epgdump." /BS ".$settings->temp_data." ".$temp_xml_bs;
  		exec( $cmdline );
  		if( $use_para ) {
			$cmdline = INSTALL_PATH."/storeProgram.php BS ".$temp_xml_bs." >/dev/null 2>&1 &";
			exec( $cmdline );
		}
		else {
			storeProgram( "BS", $temp_xml_bs );
			if( file_exists( $temp_xml_bs ) ) @unlink( $temp_xml_bs );
		}
  		if( file_exists( $settings->temp_data ) ) @unlink( $settings->temp_data );
	}

	// CS
	if ($settings->cs_rec_flg != 0) {
		$num = DBRecord::countRecords(  RESERVE_TBL, "WHERE complete = '0' AND (type = 'BS' OR type = 'CS') AND endtime > now() AND starttime < addtime( now(), '00:03:05')" );
		if($num < $settings->bs_tuners && check_file($temp_xml_cs1)) {
			$revs = DBRecord::createRecords(  RESERVE_TBL, "WHERE complete = '0' AND (type = 'BS' OR type = 'CS') AND endtime > now() AND starttime < addtime( now(), '00:03:05')" );
			for( $slc_tuner=$settings->bs_tuners-1; $slc_tuner>=0; $slc_tuner-- ){
				for( $cnt=0; $cnt<$num; $cnt++ ){
					if( $revs[$cnt]->tuner == $slc_tuner )
						continue 2;
				}
				break;
			}
			$cmdline = "CHANNEL=".CS1_EPG_CHANNEL." DURATION=120 TYPE=CS TUNER_UNIT=".TUNER_UNIT1." TUNER=".$slc_tuner." MODE=0 OUTPUT=".$settings->temp_data." ".DO_RECORD . " >/dev/null 2>&1";
			exec( $cmdline );
			$cmdline = $settings->epgdump." /CS ".$settings->temp_data." ".$temp_xml_cs1;
			exec( $cmdline );
			if( $use_para ) {
				$cmdline = INSTALL_PATH."/storeProgram.php CS ".$temp_xml_cs1." >/dev/null 2>&1 &";
				exec( $cmdline );
			}
			else {
				storeProgram( "CS", $temp_xml_cs1 );
				if( file_exists( $temp_xml_cs1 ) ) @unlink( $temp_xml_cs1 );
			}
			if( file_exists( $settings->temp_data ) ) @unlink( $settings->temp_data );
		}
		$num = DBRecord::countRecords(  RESERVE_TBL, "WHERE complete = '0' AND (type = 'BS' OR type = 'CS') AND endtime > now() AND starttime < addtime( now(), '00:03:05')" );
		if( ($num < $settings->bs_tuners) && check_file($temp_xml_cs2) ) {
			$revs = DBRecord::createRecords(  RESERVE_TBL, "WHERE complete = '0' AND (type = 'BS' OR type = 'CS') AND endtime > now() AND starttime < addtime( now(), '00:03:05')" );
			for( $slc_tuner=$settings->bs_tuners-1; $slc_tuner>=0; $slc_tuner-- ){
				for( $cnt=0; $cnt<$num; $cnt++ ){
					if( $revs[$cnt]->tuner == $slc_tuner )
						continue 2;
				}
				break;
			}
			$cmdline = "CHANNEL=".CS2_EPG_CHANNEL." DURATION=120 TYPE=CS TUNER_UNIT=".TUNER_UNIT1." TUNER=".$slc_tuner." MODE=0 OUTPUT=".$settings->temp_data." ".DO_RECORD . " >/dev/null 2>&1";
			exec( $cmdline );
			$cmdline = $settings->epgdump." /CS ".$settings->temp_data." ".$temp_xml_cs2;
			exec( $cmdline );
			if( $use_para ) {
				$cmdline = INSTALL_PATH."/storeProgram.php CS ".$temp_xml_cs2." >/dev/null 2>&1 &";
				exec( $cmdline );
			}
			else {
				storeProgram( "CS", $temp_xml_cs2 );
				if( file_exists( $temp_xml_cs2 ) ) @unlink( $temp_xml_cs2 );
			}
			if( file_exists( $settings->temp_data ) ) @unlink( $settings->temp_data );
	  	}
  	}
  }

  // 地上波を処理する
  if( $settings->gr_tuners != 0 ) {
	foreach( $GR_CHANNEL_MAP as $key=>$value ){
		// 録画重複チェック
		$num = DBRecord::countRecords(  RESERVE_TBL, "WHERE complete = '0' AND type = 'GR' AND endtime > now() AND starttime < addtime( now(), '00:03:10')" );
		if($num < $settings->gr_tuners && check_file($temp_xml_gr.$value."")) {
			//空チューナー降順探索 思案中
			$revs = DBRecord::createRecords(  RESERVE_TBL, "WHERE complete = '0' AND type = 'GR' AND endtime > now() AND starttime < addtime( now(), '00:03:10')" );
			for( $slc_tuner=$settings->gr_tuners-1; $slc_tuner>=0; $slc_tuner-- ){
				for( $cnt=0; $cnt<$num; $cnt++ ){
					if( $revs[$cnt]->tuner == $slc_tuner )
						continue 2;
				}
				break;
			}
			$cmdline = "CHANNEL=".$value." DURATION=60 TYPE=GR TUNER_UNIT=".TUNER_UNIT1." TUNER=".$slc_tuner." MODE=0 OUTPUT=".$settings->temp_data." ".DO_RECORD . " >/dev/null 2>&1";
			exec( $cmdline );
			$cmdline = $settings->epgdump." ".$key." ".$settings->temp_data." ".$temp_xml_gr.$value."";
			exec( $cmdline );
			if( $use_para ) {
				$cmdline = INSTALL_PATH."/storeProgram.php GR ".$temp_xml_gr.$value." >/dev/null 2>&1 &";
				exec( $cmdline );
			}
			else {
				storeProgram( "GR", $temp_xml_gr.$value );
				if( file_exists( $temp_xml_gr.$value ) ) @unlink( $temp_xml_gr.$value );
			}
			if( file_exists( $settings->temp_data ) ) @unlink( $settings->temp_data );
  		}
  	}
  }
  // 並列ならこれで終わりにする
  if( $use_para ) exit();

  doKeywordReservation( '*', $shm_id );	// キーワード予約
  shmop_close( $shm_id );
  exit();
?>
