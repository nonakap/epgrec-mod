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
	$tuners        = EXTRA_TUNERS;
	$usable_tuners = (int)$argv[1];

// 衛星波を処理する
if( $usable_tuners != 0 ){
	$smf_type = 'EX';
	$rec_time = EX_EPG_TIME;
	$ch_list  = array( EX_EPG_CHANNEL, CS2,CS8,CS10, CS4,CS6,CS12,CS14,CS16,CS18,CS20,CS22,CS24 );
	$add_time = $settings->rec_switch_time + 2;
	for( $sem_cnt=0; $sem_cnt<$tuners; $sem_cnt++ ){
		$sem_id[$sem_cnt] = sem_get_surely( $sem_cnt+SEM_EX_START );
		if( $sem_id[$sem_cnt] === FALSE )
			exit;
	}
	$shm_id   = shmop_open_surely();
	$sql_base = "WHERE complete = '0' AND type = 'EX'";
	$loop_tim = 10;
	$key      = 0;
	$use_cnt  = 0;
//	$st_time  = time();
	while(1){
		$sql_time   = create_sql_time( $rec_time+$add_time );
		$motion_sql = "' AND title NOT LIKE '%放送%休止%' AND title NOT LIKE '%放送設備%'".$sql_time;
		$rest_sql   = "' AND ( title LIKE '%放送%休止%' OR title LIKE '%放送設備%' )".$sql_time;
		$sql_cmd = $sql_base.create_sql_time( $rec_time + $add_time*2 + $settings->former_time + $loop_tim );
		$sql_chk = $sql_base.' AND starttime > now() AND starttime < addtime( now(), sec_to_time('.( $rec_time+$add_time + PADDING_TIME ).') )';
		if( $use_cnt < $usable_tuners ){
			// 録画重複チェック
			$off_tuners = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
			if( $off_tuners+$use_cnt < $tuners ){
				$revs  = DBRecord::createRecords( RESERVE_TBL, $sql_cmd );
				$lp_st = time();
				do{
					//空チューナー降順探索
					for( $slc_tuner=$tuners-1; $slc_tuner>=0; $slc_tuner-- ){
						for( $cnt=0; $cnt<$off_tuners; $cnt++ ){
							if( $revs[$cnt]->tuner == $slc_tuner )
								continue 2;
						}
						if( sem_acquire( $sem_id[$slc_tuner] ) === TRUE ){
							$shm_name = $slc_tuner + SEM_EX_START;
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
									// 停波確認と受信CH更新
									while(1){
										if( list( $ch_disk, $value ) = each( $ch_list ) ){
											$num = DBRecord::countRecords( PROGRAM_TBL, "WHERE channel LIKE '".$value.$motion_sql);
											if( $num == 0 ){
												$num = DBRecord::countRecords( PROGRAM_TBL, "WHERE channel LIKE '".$value.$rest_sql );
												if( $num == 0 )
													break;		//初回起動
											}else
												break;
										}else{
											shmop_write_surely( $shm_id, $shm_name, 0 );
											break 4;		// 終了
										}
									}

									$cmdline = INSTALL_PATH.'/airwavesSheep.php EX '.$slc_tuner.' '.$value.' '.$rec_time.' '.$ch_disk;
									$cmdline .= ' 0';
									// 除外sid抽出
									$map      = $EX_CHANNEL_MAP;
									$cut_sids = array();
									$cnt      = 0;
									$nc_keys  = array_keys( $map, 'NC' );
									if( $nc_keys !== FALSE ){
										foreach( $nc_keys as $th_ch ){
											$tg_sid           = explode( '_', $th_ch );
											$cut_sids[$cnt++] = (string)$tg_sid[1];
										}
									}
									if( !HIDE_CH_EPG_GET ){
										$cut_sid_cmd = "WHERE skip = '1' AND type = 'EX'";
										$hit         = DBRecord::countRecords( CHANNEL_TBL, $cut_sid_cmd ) + $cnt;
										if( $hit > $cnt ){
											$cuts = DBRecord::createRecords( CHANNEL_TBL, $cut_sid_cmd );
											foreach( $cuts as $cut_ch ){
												if( in_array( (string)$cut_ch->sid, $cut_sids ) === FALSE )
													$cut_sids[$cnt++] = (string)$cut_ch->sid;
											}
										}
									}
									if( $hit > 0 ){
										$cnt      = 0;
										$cmdline .= ' ';
										while(1){
											$cmdline .= $cut_sids[$cnt];
											if( ++$cnt < $hit )
												$cmdline .= ',';
											else
												break;
										}
									}

									$pro[$key] = sheep_release( $cmdline );
									$use_cnt++;
									break 3;
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
		//チューナー空き確認
		$use = 0;
		for( $tune_cnt=0; $tune_cnt<$tuners; $tune_cnt++ )
			if( shmop_read_surely( $shm_id, $tune_cnt+SEM_EX_START ) )
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
		$key = 0;
		do{
			if( $pro[$key] !== FALSE ){
				$st = proc_get_status( $pro[$key] );
				if( $st['running'] == FALSE ){
					proc_close( $pro[$key] );
					array_splice( $pro, $key, 1 );
				}else
					$key++;
			}else
				array_splice( $pro, $key, 1 );
		}while( $key < count($pro) );
		sleep( 1 );
	}
}
	exit();
?>
