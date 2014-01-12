#!/usr/bin/php
<?php
	$script_path = dirname( __FILE__ );
	chdir( $script_path );
	include_once( $script_path . '/config.php');
	include_once( INSTALL_PATH . '/DBRecord.class.php' );
	include_once( INSTALL_PATH . '/Settings.class.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

function sheep_release( $cmd ) {
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

function create_sql_time( $tmp_time ) {
	global	$settings;

	return ' AND endtime > subtime( now(), sec_to_time('.($settings->extra_time+2).') ) AND starttime < addtime( now(), sec_to_time('.$tmp_time.') )';
}

	$settings      = Settings::factory();
	$tuners        = (int)$settings->gr_tuners;
	$usable_tuners = (int)$argv[1];

// 地上波を処理する
if( $usable_tuners != 0 ){
	$rec_time   = FIRST_REC;
	$base_time  = $rec_time + $settings->rec_switch_time + 2;
	$sql_time   = create_sql_time( $base_time );
	$motion_sql = "_%' AND title NOT LIKE '%放送%休止%' AND title NOT LIKE '%放送設備%'".$sql_time;
	$rest_sql   = "_%' AND ( title LIKE '%放送%休止%' OR title LIKE '%放送設備%' )".$sql_time;
	if( !( list( $ch_disk, $value ) = each( $GR_CHANNEL_MAP ) ) )
		exit();
	for( $sem_cnt=0; $sem_cnt<$tuners; $sem_cnt++ ){
		$sem_id[$sem_cnt] = sem_get_surely( $sem_cnt+SEM_GR_START );
		if( $sem_id[$sem_cnt] === FALSE )
			exit;
	}
	$shm_id   = shmop_open_surely();
	$loop_tim = 10;
	$sql_cmd  = "WHERE complete = '0' AND type = 'GR'".create_sql_time( $base_time + $settings->rec_switch_time + $settings->former_time + $loop_tim + 2 );
	$sql_chk  = "WHERE complete = '0' AND type = 'GR'".' AND starttime > now() AND starttime < addtime( now(), sec_to_time('.( $base_time + PADDING_TIME ).') )';
	$use_cnt  = 0;
	$release_cnt = 0;
	while(1){
		if( $use_cnt < $usable_tuners ){
			// 録画重複チェック
			$off_tuners = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
			if( $off_tuners+$use_cnt < $tuners ){
				$revs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd );
				$lp_st = time();
				do{
					//空チューナー降順探索
					for( $slc_tuner=$tuners-1; $slc_tuner>=0; $slc_tuner-- ){
						for( $cnt=0; $cnt<$off_tuners; $cnt++ ){
							if( $revs[$cnt]->tuner == $slc_tuner )
								continue 2;
						}
						if( sem_acquire( $sem_id[$slc_tuner] ) === TRUE ){
							$shm_name = $slc_tuner + SEM_GR_START;
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

								if( DBRecord::countRecords( RESERVE_TBL, $sql_chk ) > 0 ){
									$rr     = DBRecord::createRecords( RESERVE_TBL, $sql_chk );
									$motion = TRUE;
									if( $slc_tuner < TUNER_UNIT1 ){
										foreach( $rr as $rev ){
											if( $rev->tuner < TUNER_UNIT1 ){
												$motion = FALSE;
												break;
											}
										}
									}else{
										foreach( $rr as $rev ){
											if( $rev->tuner >= TUNER_UNIT1 ){
												$motion = FALSE;
												break;
											}
										}
									}
								}else
									$motion = TRUE;

								if( $motion ){
									// 停波再確認と受信CH更新
									while(1){
										$num = DBRecord::countRecords( PROGRAM_TBL, "WHERE channel_disc LIKE '".$ch_disk.$motion_sql);
										if( $num == 0 ){
											$num = DBRecord::countRecords( PROGRAM_TBL, "WHERE channel_disc LIKE '".$ch_disk.$rest_sql );
											if( $num == 0 )
												break;		//初回起動
										}else
											break;
										if( !( list( $ch_disk, $value ) = each( $GR_CHANNEL_MAP ) ) ){
											shmop_write_surely( $shm_id, $shm_name, 0 );
											break 4;		// 終了
										}
									}

									$cmdline = INSTALL_PATH.'/airwavesSheep.php GR '.$slc_tuner.' '.$value.' '.$rec_time.' '.$ch_disk;
									$pro[$release_cnt++] = sheep_release( $cmdline );
									$use_cnt++;

									// 受信CH更新
									while(1){
										if( list( $ch_disk, $value ) = each( $GR_CHANNEL_MAP ) ){
											$num = DBRecord::countRecords( PROGRAM_TBL, "WHERE channel_disc LIKE '".$ch_disk.$motion_sql);
											if( $num == 0 ){
												$num = DBRecord::countRecords( PROGRAM_TBL, "WHERE channel_disc LIKE '".$ch_disk.$rest_sql );
												if( $num == 0 )
													continue 4;		//初回起動
												else
													continue;		//停波チャンネルのスキップ
											}else
												continue 4;
										}else
											break 4;		// 終了
									}
								}else
									shmop_write_surely( $shm_id, $shm_name, 0 );
							}else
								//占有失敗
								while( sem_release( $sem_id[$slc_tuner] ) === FALSE )
									usleep( 100 );
						}
					}
					sleep(1);
				}while( time()-$lp_st < $loop_tim );
				//時間切れ
			}else{
				//空チューナー無し
				//先行録画が同ChならそこからEPGを貰うようにしたい
				if( $off_tuners >= $tuners )
					break;
			}
		}
		//EPG受信チューナー数確認
		$use = 0;
		for( $tune_cnt=0; $tune_cnt<$tuners; $tune_cnt++ )
			if( shmop_read_surely( $shm_id, $tune_cnt+SEM_GR_START ) )
				$use++;
		if( $use_cnt > $use )
			$use_cnt = $use;
		else
			sleep(1);
	}
	shmop_close( $shm_id );
GATHER_SHEEPS:
	//全子プロセス(EPG受信・更新)終了待ち
	while( count($pro) ){
		$cnt = 0;
		do{
			if( $pro[$cnt] !== FALSE ){
				$st = proc_get_status( $pro[$cnt] );
				if( $st['running'] == FALSE ){
					proc_close( $pro[$cnt] );
					array_splice( $pro, $cnt, 1 );
				}else
					$cnt++;
			}else
				array_splice( $pro, $cnt, 1 );
		}while( $cnt < count($pro) );
		sleep( 1 );
	}
}
	exit();
?>
