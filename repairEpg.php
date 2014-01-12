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
define( 'TIME_LIMIT', 1.5*60*60 );

	$settings = Settings::factory();

function search_getepg()
{
	$ps_output = shell_exec( PS_CMD );
	$rarr = explode( "\n", $ps_output );
	$catch_cmd = INSTALL_PATH.'/getepg.php';
	for( $cc=0; $cc<count($rarr); $cc++ ){
		if( strpos( $rarr[$cc], $catch_cmd ) !== FALSE )
			return TRUE;
	}
	return FALSE;
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


if( search_getepg() === FALSE ){
	$channel_id = $argv[1];
	$rev = new DBRecord( CHANNEL_TBL, "id", $channel_id );
	$type     = $rev->type;		//GR/BS/CS
	$value    = $rev->channel;
	$sid      = $rev->sid;
	$st_tm    = (int)$argv[2];
	$ed_tm    = (int)$argv[3];
	$ch_disc  = $type==='GR' ? strtok( $rev->channel_disc, '_' ) : '/'.$type;
	$rec_tm   = FIRST_REC;
	$pid      = posix_getpid();
	$temp_xml = $settings->temp_xml.$type.'_'.$pid;
	$temp_ts  = $settings->temp_data.'_'.$type.'_'.$pid;

	if( $type === 'GR' ){
		$smf_type = 'GR';
		$sql_type = "type = 'GR'";
		$smf_key  = SEM_GR_START;
		$tuners   = $settings->gr_tuners;
	}else
	if( $type === 'EX' ){
		$smf_type = 'EX';
		$sql_type = "type = 'EX'";
		$smf_key  = SEM_EX_START;
		$tuners   = EXTRA_TUNERS;
	}else{
		$smf_type = 'BS';
		$sql_type = "(type = 'BS' OR type = 'CS')";
		$smf_key  = SEM_ST_START;
		$tuners   = $settings->bs_tuners;
	}
	$epg_tm  = $rec_tm + $settings->rec_switch_time + $settings->former_time + 2;
	$sql_use = "WHERE complete = '0' AND ".$sql_type.' AND endtime > subtime( now(), sec_to_time('.($settings->extra_time+2).') ) AND starttime < addtime( now(), sec_to_time('.$epg_tm.') )';
	$sql_cmd = "WHERE channel_id = '".$channel_id."'";
	if( DBRecord::countRecords( RESERVE_TBL, $sql_cmd." AND starttime > now() AND starttime <= '".toDatetime( $ed_tm )."' AND complete = '0'" ) )
		exit();
	$sql_cmd .= ' AND starttime > now() AND starttime <= addtime( now(), sec_to_time('.( $epg_tm + PADDING_TIME ).') )';
	for( $sem_cnt=0; $sem_cnt<$tuners; $sem_cnt++ ){
		$sem_id[$sem_cnt] = sem_get_surely( $sem_cnt+$smf_key );
		if( $sem_id[$sem_cnt] === FALSE )
			exit;
	}
	$shm_id = shmop_open_surely();
	$sem_dump = sem_get_surely( SEM_EPGDUMP );
	if( $sem_dump === FALSE )
		exit;
	$sem_store = sem_get_surely( SEM_EPGSTORE );
	if( $sem_store === FALSE )
		exit;
	// 何時のタイミングから始めるかは要調節
	$stat = 0;
	$start_tm = $now_tm = time();
	if( $st_tm > $start_tm+TIME_LIMIT )
		$st_tm = $start_tm + TIME_LIMIT;
	while(1){
		if( $now_tm < $st_tm ){
			$sp_tm = $st_tm - $now_tm;
			if( $sp_tm < 5 * 60 ){
				$sp_tm = 5 * 60;
				$stat  = 1;
			}else
				if( $sp_tm > 10 * 60 )
					$sp_tm = 10 * 60;
		}else{
			if( $now_tm-$st_tm < 15*60 ){
				$sp_tm = 5 * 60;
				$stat++;
			}else
				break;
		}
		sleep( $sp_tm );
		if( DBRecord::countRecords( RESERVE_TBL, $sql_cmd ) )
			break;
		if( DBRecord::countRecords( PROGRAM_TBL, $sql_cmd." AND ( title LIKE '%放送%休止%' OR title LIKE '%放送設備%' )" ) )
			break;

		$off_tuners = DBRecord::countRecords( RESERVE_TBL, $sql_use );
		if( $off_tuners < $tuners ){
			//空チューナー降順探索
			$revs = DBRecord::createRecords( RESERVE_TBL, $sql_use );
			for( $slc_tuner=$tuners-1; $slc_tuner>=0; $slc_tuner-- ){
				for( $cnt=0; $cnt<$off_tuners; $cnt++ ){
					if( $revs[$cnt]->tuner == $slc_tuner )
						continue 2;
				}
				if( sem_acquire( $sem_id[$slc_tuner] ) === TRUE ){
					$shm_name = $smf_key + $slc_tuner;
					$smph     = shmop_read_surely( $shm_id, $shm_name );
					if( $smph==2 && $tuners-$off_tuners==1 ){
						// リアルタイム視聴停止
						$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
						posix_kill( $real_view, 9 );		// 録画コマンド停止
						$smph = 0;
						shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo clear
					}
					if( $smph == 0 ){
						shmop_write_surely( $shm_id, $shm_name, 1 );
						while( sem_release( $sem_id[$slc_tuner] ) === FALSE )
							usleep( 100 );
						sleep( (int)$settings->rec_switch_time );
reclog( 'repairEPG::rec strat['.$type.':'.$value.':'.$sid.']'.toDatetime(time()), EPGREC_DEBUG );
						if( ( $type!=='EX' && ( ( $slc_tuner<TUNER_UNIT1 && RECPT1_EPG_PATCH ) || ( $slc_tuner>=TUNER_UNIT1 && $OTHER_TUNERS_CHARA["$smf_type"][$slc_tuner-TUNER_UNIT1]['epgTs'] ) ) )
							|| ( $type==='EX' && $EX_TUNERS_CHARA[$slc_tuner]['epgTs'] ) )
							$cmdline = 'SID=epg ';
						else
							$cmdline = "";
						$cmdline .= 'CHANNEL='.$value.' DURATION='.$rec_tm.' TYPE='.$type.' TUNER_UNIT='.TUNER_UNIT1.' TUNER='.$slc_tuner.' MODE=0 OUTPUT='.$temp_ts.' '.DO_RECORD.' >/dev/null 2>&1';
						exec( $cmdline );
						//チューナー占有解除
						while( sem_acquire( $sem_id[$slc_tuner] ) === FALSE )
							usleep( 100 );
						shmop_write_surely( $shm_id, $shm_name, 0 );
						while( sem_release( $sem_id[$slc_tuner] ) === FALSE )
							usleep( 100 );
						//
						if( file_exists( $temp_ts ) ){
							$cmdline = $settings->epgdump.' '.$ch_disc.' '.$temp_ts.' '.$temp_xml;
							if( $type !== 'GR' )
								$cmdline .= ' -sid '.$sid;
							while(1){
								if( sem_acquire( $sem_dump ) === TRUE ){
									exec( $cmdline );
									while( sem_release( $sem_dump ) === FALSE )
										usleep( 100 );
									@unlink( $temp_ts );
									break;
								}
								usleep(100 * 1000);
							}
							if( file_exists( $temp_xml ) ){
								while(1){
									if( sem_acquire( $sem_store ) === TRUE ){
										$ch_id = storeProgram( $type, $temp_xml );
										@unlink( $temp_xml );
										if( $ch_id !== -1 )
											doKeywordReservation( $type, $shm_id );	// キーワード予約
										while( sem_release( $sem_store ) === FALSE )
											usleep( 100 );
										if( is_string( $ch_id ) ){
											$next_st = (int)$ch_id;
											if( $next_st > $start_tm+TIME_LIMIT )
												$next_st = $start_tm + TIME_LIMIT;
											if( $st_tm != $next_st ){
												$st_tm = $next_st;
												$stat  = 0;
											}
											break 2;	// 継続
										}else{
											if( $ch_id == 0 ){
												if( $stat >= 2 )
													break 3;	// 終了
												else
													break 2;	// 継続
											}else
												break 2;	// 継続
										}
									}
									usleep(100 * 1000);
								}
							}
						}
						break;
					}
					//占有失敗
					while( sem_release( $sem_id[$slc_tuner] ) === FALSE )
						usleep( 100 );
				}
			}
		}else{
			//空チューナー無し
			//先行録画が同ChならそこからEPGを貰うようにしたい
			//また取れない場合もあるので録画冒頭でEID自家判定するしかない?
		}
		$now_tm = time();
	}
	shmop_close( $shm_id );
}
	exit();
?>
