#!/usr/bin/php
<?php
$script_path = dirname( __FILE__ );
chdir( $script_path );
include_once( $script_path . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );
include_once( INSTALL_PATH . '/reclib.php' );


function get_logcmd( $src_tm )
{
	return "/bin/grep '".date("M ",$src_tm).sprintf( "% 2s", date("j",$src_tm) ).date(" H:i:",$src_tm).(($src_tm%60)/10)."' /var/log/syslog | /bin/grep Drop";
}

$settings = Settings::factory();

$reserve_id = $argv[1];

try{
	$rrec = new DBRecord( RESERVE_TBL, 'id' , $reserve_id );
	$rev_ds = '予約ID:'.$rrec->id.' '.$rrec->channel_disc.':T'.$rrec->tuner.'-Ch'.$rrec->channel.' '.$rrec->starttime.'『'.$rrec->title.'』';

	if( (int)$rrec->autorec > 0 ){
		$restart_lmt = toTimestamp( $rrec->starttime ) + REC_RETRY_LIMIT;
		if( $restart_lmt<toTimestamp( $rrec->endtime ) && time()<$restart_lmt ){
			// 録画開始に失敗 再予約
			$starttime     = $rrec->starttime;
			$endtime       = $rrec->endtime;
			$channel_id    = $rrec->channel_id;
			$title         = $rrec->title;
			$description   = $rrec->description;
			$category_id   = $rrec->category_id;
			$program_id    = $rrec->program_id;
//			$autorec       = $rrec->autorec;
			$mode          = $rrec->mode;
			$discontinuity = $rrec->discontinuity;
			$priority      = $rrec->priority;
			reclog( $rev_ds.'の録画開始に失敗しました。再予約を試みます。', EPGREC_WARN );
			$rrec->delete();
			try{
				$rval = Reservation::custom(
							$starttime,
							$endtime,
							$channel_id,
							$title,
							$description,
							$category_id,
							$program_id,
							0,
							$mode,
							$discontinuity,
							0,
							$priority
				);
			}
			catch( Exception $e ) {
				exit( "Error:".$e->getMessage() );
			}
			exit();
		}
	}
	$ts_path = INSTALL_PATH .$settings->spool . '/'. $rrec->path;
	if( file_exists( $ts_path ) ) {
		// PT1のログを取得
		$get_time = time();
		usleep(10 * 1000);
		$be_time  = (int)(($get_time-1)/10) * 10;
		$set_time = (int)(($get_time+1)/10) * 10;
		$cmd      = get_logcmd( $be_time );
		$log      = shell_exec( $cmd );
		if( $be_time != $set_time ){
			$cmd  = get_logcmd( $set_time );
			$log .= shell_exec( $cmd );
		}
		if( $log != NULL ){
			if( strpos( $log, 'Drop=00000000:00000000:00000000:00000000' ) === FALSE )
				$syslog = '<br><font color="#ff0000">'.str_replace( "\n", '<br>', htmlspecialchars($log) ).'</font>';
			else
				$syslog = '<br>'.str_replace( "\n", '<br>', htmlspecialchars($log) );
			$rev_ds = htmlspecialchars($rev_ds);
		}else
			$syslog = NULL;
		// 不具合が出る場合は、以下を入れ替えること
//		if( (int)trim(exec("stat -c %s '".$ts_path."'")) )
		if( filesize( $ts_path ) )
		{
			// 予約完了
			if( time() >= toTimestamp($rrec->endtime) ){
				reclog( $rev_ds.'の録画が完了'.$syslog );
			}else{
				if( (int)$rrec->autorec >= 0 )
					reclog( $rev_ds.'の録画が中断されました。ソフトウェアもしくは記憶ストレージ・受信チューナーなどハードウェアに異常があります。'.$syslog, EPGREC_ERROR );
				else{
					reclog( $rev_ds.'の録画が手動中断されました。'.$syslog );
					$rrec->autorec = $rrec->autorec * -1 - 1;
				}
			}
			$rrec->complete = '1';
			$rrec->update();
			if( $settings->mediatomb_update == 1 ) {
				// ちょっと待った方が確実っぽい
				@exec('sync');
				sleep(15);
				$dbh = mysql_connect( $settings->db_host, $settings->db_user, $settings->db_pass );
				if( $dbh !== false ) {
					$sqlstr = "use ".$settings->db_name;
					@mysql_query( $sqlstr );
					// 別にやらなくてもいいが
					$sqlstr = "set NAME utf8";
					@mysql_query( $sqlstr );
					$sqlstr = "update mt_cds_object set metadata='dc:description=".mysql_real_escape_string($rrec->description)."&epgrec:id=".$reserve_id."' where dc_title='".$rrec->path."'";
					@mysql_query( $sqlstr );
					$sqlstr = "update mt_cds_object set dc_title='".mysql_real_escape_string($rrec->title)."(".date("Y/m/d").")' where dc_title='".$rrec->path."'";
					@mysql_query( $sqlstr );
				}
			}
		}else{
			if( disk_free_space( INSTALL_PATH.$settings->spool ) )
				reclog( $rev_ds.'の録画に失敗した模様・録画ファイルサイズが0byteです。'.$syslog, EPGREC_ERROR );
			else
				reclog( $rev_ds.'の録画に失敗した模様・録画ストレージ残容量が0byteです。'.$syslog, EPGREC_ERROR );
			$rrec->delete();
		}
	}
	else {
		// 予約実行失敗
		reclog( $rev_ds.'の録画に失敗した模様・録画ファイルが存在しません。', EPGREC_ERROR );
		$rrec->delete();
	}
}
catch( exception $e ) {
	reclog( 'recomplete:: 予約テーブルのアクセスに失敗した模様('.$e->getMessage().')', EPGREC_ERROR );
	exit( $e->getMessage() );
}
exit();
?>
