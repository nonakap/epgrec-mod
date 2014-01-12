#!/usr/bin/php
<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . '/config.php');
  include_once( INSTALL_PATH . '/DBRecord.class.php' );
  include_once( INSTALL_PATH . '/Settings.class.php' );
  include_once( INSTALL_PATH . '/Reservation.class.php' );
  include_once( INSTALL_PATH . '/storeProgram.inc.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

function rec_start( $cmd ) {
	$descspec = array(
					0 => array( 'file','/dev/null','r' ),
					1 => array( 'file','/dev/null','w' ),
					2 => array( 'file','/dev/null','w' ),
	);
	$pro = proc_open( $cmd, $descspec, $pipes );
	if( is_resource( $pro ) )
		return $pro;
	return false;
}

// 録画開始前EPG更新に定期EPG更新が重ならないようにする。
function scout_wait()
{
	$sql_cmd = "WHERE complete = '0' AND starttime > now() AND starttime < addtime( now(), '00:03:00' )";
	while(1){
		$num = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
		if( $num ){
			$revs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd." ORDER BY starttime DESC" );
			$sleep_next = toTimestamp( $revs[0]->starttime );
			sleep( $sleep_next-time() );
		}else
			return;
	}
}

function sig_handler()
{
	global	$shm_name,$temp_xml,$temp_ts;

	// シャットダウンの処理
	if( isset( $shm_name ) ){
		//テンポラリーファイル削除
		if( isset( $temp_ts ) && file_exists( $temp_ts ) )
			@unlink( $temp_ts );
		if( isset( $temp_xml ) && file_exists( $temp_xml ) )
			@unlink( $temp_xml );
		//共有メモリー変数初期化
		$shm_id = shmop_open_surely();
		if( shmop_read_surely( $shm_id, $shm_name ) ){
			shmop_write_surely( $shm_id, $shm_name, 0 );
		}
		shmop_close( $shm_id );
	}
	exit;
}

	// シグナルハンドラを設定
	declare( ticks = 1 );
	pcntl_signal( SIGTERM, "sig_handler" );

	$settings = Settings::factory();
	$type     = $argv[1];	//GR/BS/CS/EX
	$tuner    = $argv[2];
	$value    = $argv[3];	//ch
	$rec_time = $argv[4];
	$ch_disk  = $argv[5];
	$slp_time = isset( $argv[6] ) ? (int)$argv[6] : 0;
	$cut_sids = isset( $argv[7] ) ? $argv[7] : "";

	$shm_nm   = array( 'GR' => SEM_GR_START, 'BS' => SEM_ST_START, 'EX' => SEM_EX_START );
	$smf_type = $type=='CS' ? 'BS' : $type;
	$dmp_type = $type=='GR' ? $ch_disk : '/'.($type=='EX' ? 'CS' : $type);
	$temp_xml = $settings->temp_xml.'_'.$type.$value;
	$temp_ts  = $settings->temp_data.'_'.$type.$value;

	//EPG受信
	sleep( $settings->rec_switch_time+1 );
	if( ( $type!=='EX' && ( ( $tuner<TUNER_UNIT1 && RECPT1_EPG_PATCH ) || ( $tuner>=TUNER_UNIT1 && $OTHER_TUNERS_CHARA["$smf_type"][$tuner-TUNER_UNIT1]['epgTs'] ) ) ) ||
		( $type==='EX' && $EX_TUNERS_CHARA[$tuner]['epgTs'] ) )
		$cmd_ts = 'SID=epg ';
	$cmd_ts .= 'CHANNEL='.$value.' DURATION='.$rec_time.' TYPE='.$type.' TUNER_UNIT='.TUNER_UNIT1.' TUNER='.$tuner.' MODE=0 OUTPUT='.$temp_ts.' '.DO_RECORD . ' >/dev/null 2>&1';
	$pro    = rec_start( $cmd_ts );
	if( $pro !== FALSE ){
		// プライオリティ低に
		pcntl_setpriority(20);
		$start_wt = 6;					//チューナーの差をこれで吸収
		$wait_lp  = (int)$rec_time;
		$wait_tm  = $wait_lp - $start_wt;
		$wait_lp += $start_wt;
		$wait_cnt = 0;
		while(1){
			$st = proc_get_status( $pro );
			if( $st['running'] == FALSE ){
				proc_close( $pro );
				break;
			}else
				if( $wait_cnt < $start_wt )
					sleep( 1 );
				else
					if( $wait_cnt < $wait_lp ){
						sleep( $wait_tm );
						$wait_tm = 1;
					}else{
						//タイムアウト
						$ps_output = shell_exec( PS_CMD );
						$rarr = explode( "\n", $ps_output );
						for( $do_cnt=0; $do_cnt<count($rarr); $do_cnt++ ){
							if( strpos( $rarr[$do_cnt], DO_RECORD ) !== FALSE ){
								$ps = ps_tok( $rarr[$do_cnt] );
								if( $ps->ppid == $st['pid'] ){
									$do_pid = $ps->pid;
									for( $cc=0; $cc<count($rarr); $cc++ ){
										$ps = ps_tok( $rarr[$cc] );
										if( $ps->ppid == $do_pid ){
											posix_kill( $ps->pid, 9 );		//録画コマンド停止
											break 2;
										}
									}
								}
							}
						}
						reclog( 'EPG受信失敗:録画コマンドがスタックしてる可能性があります', EPGREC_WARN );
						break;
					}
			$wait_cnt++;
		}
	}else
		reclog( 'EPG受信失敗:録画コマンドに異常がある可能性があります', EPGREC_WARN );

	//チューナー占有解除
	$shm_id   = shmop_open_surely();
	$shm_name = $shm_nm[$smf_type] + $tuner;
	$sem_id   = sem_get_surely( $shm_name );
	while( sem_acquire( $sem_id ) === FALSE )
		sleep( 1 );
	shmop_write_surely( $shm_id, $shm_name, 0 );
	while( sem_release( $sem_id ) === FALSE )
		usleep( 100 );

	if( file_exists( $temp_ts ) && filesize( $temp_ts ) ){
		scout_wait();
		while(1){
			$sem_id = sem_get_surely( SEM_EPGDUMP );
			if( $sem_id !== FALSE ){
				while(1){
					if( sem_acquire( $sem_id ) === TRUE ){
						//xml抽出
						$cmd_xml = $settings->epgdump.' '.$dmp_type.' '.$temp_ts.' '.$temp_xml;
						if( $type!='GR' && $cut_sids!="" )
							$cmd_xml .= ' -cut '.$cut_sids;
						exec( $cmd_xml );
						@unlink( $temp_ts );
						while( sem_release( $sem_id ) === FALSE )
							usleep( 100 );
						break 2;
					}
					sleep(1);
				}
			}
			sleep(1);
		}
		if( file_exists( $temp_xml ) ){
			if( $slp_time )
				sleep( $slp_time );
			scout_wait();
			while(1){
				$sem_id = sem_get_surely( SEM_EPGSTORE );
				if( $sem_id !== FALSE ){
					while(1){
						if( sem_acquire( $sem_id ) === TRUE ){
							//EPG更新
							if( storeProgram( $type, $temp_xml ) != -1 )
								@unlink( $temp_xml );
							else
								reclog( $cmd_ts, EPGREC_WARN );
							while( sem_release( $sem_id ) === FALSE )
								usleep( 100 );
							break 2;
						}
						sleep(1);
					}
				}
				sleep(1);
			}
		}else
			reclog( 'EPG受信失敗:xmlファイル"'.$temp_xml.'"がありません(放送間帯でないなら問題ありません)', EPGREC_WARN );
	}else{
		reclog( 'EPG受信失敗:TSファイル"'.$temp_ts.'"がありません(放送間帯でないなら問題ありません)', EPGREC_WARN );
		reclog( $cmd_ts, EPGREC_WARN );
		if( $tuner < TUNER_UNIT1 )
			shmop_write_surely( $shm_id, SEM_REBOOT, 1 );
	}
	shmop_close( $shm_id );
	exit();
?>
