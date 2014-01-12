#!/usr/bin/php
<?php
	$script_path = dirname( __FILE__ );
	chdir( $script_path );
	include_once( $script_path . '/config.php');
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/DBRecord.class.php' );
	include_once( INSTALL_PATH . '/Keyword.class.php' );
	include_once( INSTALL_PATH . '/Settings.class.php' );
	include_once( INSTALL_PATH . '/storeProgram.inc.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

function dog_release( $cmd ){
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

function stop_process( $kill_pid ){
	while(1){
		if( posix_kill( $kill_pid, 15 ) )
			return;
		else{
			$errno = posix_get_last_error();
			if( $errno == ESRCH )
				return;
		}
		//
	}
}

function cleanup( $rarr, $cmd ){
	foreach( $rarr as $ra ){
		if( strpos( $ra, $cmd ) !== FALSE ){
			$ps       = ps_tok( $ra );
			$kill_pid = (int)$ps->pid;
			stop_process( $kill_pid );
		}
	}
}

	$shepherd_st = time();
	$settings = Settings::factory();
	$GR_tuners = (int)$settings->gr_tuners;
	$BS_tuners = (int)$settings->bs_tuners;
	$CS_flag   = $settings->cs_rec_flg==0 ? FALSE : TRUE;

	run_user_regulate();

	garbageClean();			//  不要プログラム削除

/* 別口で対応
	// 定期EPG更新に録画開始前EPG更新が重ならないようにする。
	$sql_cmd = "WHERE complete = '0' AND starttime > now() AND starttime < addtime( now(), '00:13:00' )";
	while(1){
		$num = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
		if( $num ){
			$revs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd.' ORDER BY starttime DESC' );
			$sleep_next = toTimestamp( $revs[0]->starttime );
			if( $sleep_next < $shepherd_st+2*60*60-(10+1)*60 )
				sleep( $sleep_next-time() );
			else
				exit();
		}else
			break;
	}
*/

	$ps_output = shell_exec( PS_CMD.' 2>/dev/null' );
	$rarr      = explode( "\n", $ps_output );
	$my_pid    = posix_getpid();
	$kill_flg  = FALSE;
	for( $cc=0; $cc<count($rarr); $cc++ ){
		if( strpos( $rarr[$cc], 'shepherd.php' ) !== FALSE ){
			$ps = ps_tok( $rarr[$cc] );
			if( $my_pid == (int)$ps->pid ){
				$per_pid = (int)$ps->ppid;
				foreach( $rarr as $ra ){
					if( strpos( $ra, 'shepherd.php' ) !== FALSE ){
						$ps = ps_tok( $ra );
						$kill_pid = (int)$ps->pid;
						if( $kill_pid!=$my_pid && $kill_pid!=$per_pid ){
							stop_process( $kill_pid );
							$kill_flg = TRUE;
						}
					}
				}
				cleanup( $rarr, 'collie.php' );
				cleanup( $rarr, 'sheepdog.php' );
				cleanup( $rarr, 'airwavesSheep.php' );
				if( $kill_flg )
					reclog( '前回の定期EPG更新が終了していなかったので中断させました。', EPGREC_WARN );
				break;
			}
		}
	}

	// PT2 fault flag clear
	$shm_id = shmop_open_surely();
	shmop_write_surely( $shm_id, SEM_REBOOT, 0 );

	// EPG受信本数制御
	// 面倒なので手抜き テンポラリ容量は十分確保しましょう(^_^)
	$tmpdrive_size = disk_free_space( '/tmp' );
	$GR_num = count( $GR_CHANNEL_MAP );
	if( $BS_tuners > 0 ){
		if( !$CS_flag ){
			$bs_max = 1;
//			$bs_tim = array( 0, 220 + 15 + 90 );	// BS only
			$bs_tim = array( 0, 220 + 15 + 30 );	// BS only
		}else{
			$bs_max = $BS_tuners>=3 ? 3 : $BS_tuners;
			$bs_tim = array( 0, 750, 510, 330 );	// XML取り込み２並列
//			$bs_tim = array( 0, 590, 530, 530 );	// XML取り込み２並列
//			$bs_tim = array( 0, 550, 430, 430 );	// XML取り込み直列
		}
	}
	if( RECPT1_EPG_PATCH && TUNER_UNIT1>0 ){
		$gr_pt1 = $GR_tuners<TUNER_UNIT1? $GR_tuners : TUNER_UNIT1;
		$bs_pt1 = $BS_tuners<TUNER_UNIT1? $BS_tuners : TUNER_UNIT1;
	}else{
		$gr_pt1 = 0;
		$bs_pt1 = 0;
	}
	for( $tuner=0; $tuner<$GR_tuners-TUNER_UNIT1; $tuner++ ){
		if( $OTHER_TUNERS_CHARA['GR'][$tuner]['epgTs'] )
			$gr_pt1++;
	}
	for( $tuner=0; $tuner<$BS_tuners-TUNER_UNIT1; $tuner++ ){
		if( $OTHER_TUNERS_CHARA['BS'][$tuner]['epgTs'] )
			$bs_pt1++;
	}
	$gr_oth = $GR_tuners - $gr_pt1;
	$bs_oth = $BS_tuners - $bs_pt1;
	if( $gr_pt1>0 || $bs_pt1>0 ){
		if( $gr_oth && $tmpdrive_size<=(GR_OTH_EPG_SIZE+GR_XML_SIZE) ){
			reclog( 'shepherd.php::テンポラリー容量が不十分なためEPG更新が出来ません。空き容量を確保してください。', EPGREC_ERR );
			exit();
		}
		if( $bs_oth && $tmpdrive_size<=(BS_OTH_EPG_SIZE+BS_XML_SIZE) ){
			reclog( 'shepherd.php::テンポラリー容量が不十分なためBS/CSのEPG更新が出来ません。空き容量を確保してください。', EPGREC_ERR );
			$bs_pt1 = 0;
			$bs_oth = 0;
		}

		$gr_use = 0;
		if( $gr_oth ){
			$gr_work_size = GR_OTH_EPG_SIZE + GR_XML_SIZE;
			if( $gr_work_size < $tmpdrive_size ){
				while( $GR_tuners > ++$gr_use ){
					if( $gr_oth > $gr_use ){
						if( $gr_work_size+GR_OTH_EPG_SIZE < $tmpdrive_size ){
							$gr_work_size += GR_OTH_EPG_SIZE;
						}else{
							if( $gr_work_size+GR_OTH_EPG_SIZE == $tmpdrive_size ){
								$gr_work_size += GR_OTH_EPG_SIZE;
								$gr_use++;
							}
							goto GR_ESP;
						}
					}else
						break;
				}
			}else{
				if( $gr_work_size > $tmpdrive_size )
					$gr_work_size = 0;
				else
					$gr_use = 1;
				goto GR_ESP;
			}
		}else
			$gr_work_size = 0;
		if( $gr_pt1 && $GR_tuners>$gr_use ){
			if( $gr_oth == 0 ){
				$gr_work_size = GR_PT1_EPG_SIZE + GR_XML_SIZE;
				if( $gr_work_size >= $tmpdrive_size ){
					if( $gr_work_size > $tmpdrive_size )
						$gr_work_size = 0;
					else
						$gr_use = 1;
					goto GR_ESP;
				}else
					$gr_use = 1;
			}
			while( $GR_tuners > $gr_use ){
				if( $gr_work_size+GR_PT1_EPG_SIZE < $tmpdrive_size ){
					$gr_work_size += GR_PT1_EPG_SIZE;
					$gr_use++;
				}else{
					if( $gr_work_size+GR_PT1_EPG_SIZE == $tmpdrive_size ){
						$gr_work_size += GR_PT1_EPG_SIZE;
						$gr_use++;
					}
					break;
				}
			}
		}
GR_ESP:
		$bs_use = 0;
		if( $bs_oth ){
			$st_work_size = BS_OTH_EPG_SIZE + BS_XML_SIZE;
			if( $st_work_size < $tmpdrive_size ){
				while( $bs_max > ++$bs_use ){
					if( $bs_oth > $bs_use ){
						if( $st_work_size+CS_OTH_EPG_SIZE < $tmpdrive_size ){
							$st_work_size += CS_OTH_EPG_SIZE;
						}else{
							if( $st_work_size+CS_OTH_EPG_SIZE == $tmpdrive_size ){
								$st_work_size += CS_OTH_EPG_SIZE;
								$bs_use++;
							}
							goto ST_ESP;
						}
					}else
						break;
				}
			}else{
				if( $st_work_size > $tmpdrive_size )
					$st_work_size = 0;
				else
					$bs_use = 1;
				goto ST_ESP;
			}
		}else
			$st_work_size = 0;
		if( $bs_pt1 && $bs_max>$bs_use ){
			if( $bs_oth == 0 ){
				$st_work_size = BS_PT1_EPG_SIZE + BS_XML_SIZE;
				if( $st_work_size >= $tmpdrive_size ){
					if( $st_work_size > $tmpdrive_size )
						$st_work_size = 0;
					else
						$bs_use = 1;
					goto ST_ESP;
				}else
					$bs_use = 1;
			}
			while( $bs_max > $bs_use ){
				if( $st_work_size+CS_PT1_EPG_SIZE < $tmpdrive_size ){
					$st_work_size += CS_PT1_EPG_SIZE;
					$bs_use++;
				}else{
					if( $st_work_size+CS_PT1_EPG_SIZE == $tmpdrive_size ){
						$st_work_size += CS_PT1_EPG_SIZE;
						$bs_use++;
					}
					break;
				}
			}
		}
ST_ESP:
		$gr_bs_sepa = $gr_work_size+$st_work_size <= $tmpdrive_size ? FALSE : TRUE;
	}else{
		$tune_cnts = (int)( $tmpdrive_size / GR_OTH_EPG_SIZE );
		if( $tune_cnts == 0 ){
			reclog( 'shepherd.php::テンポラリー容量が不十分なためEPG更新が出来ません。空き容量を確保してください。', EPGREC_ERR );
			exit();
		}
		// XML取り込みは、BS 2.5分(atomD525) CS 1分(仮定)を想定
		$gr_rec_tm = FIRST_REC + $settings->rec_switch_time + 1;
		$gr_bs_sepa = FALSE;
		if( $BS_tuners > 0 ){
			if( $tune_cnts < 3 ){
				$gr_use = $GR_tuners>$tune_cnts ? $tune_cnts : $GR_tuners;
				$bs_use = 0;
				reclog( 'shepherd.php::テンポラリー容量が不十分なため衛星波のEPG更新が出来ません。空き容量を確保してください。', EPGREC_ERR );
			}else{
				if( $tune_cnts == 3 ){
					$gr_bs_sepa = TRUE;
					$gr_use = $GR_tuners>=3 ? 3 : $GR_tuners;
					$bs_use = 1;
					reclog( 'shepherd.php::テンポラリー容量が不十分なため地上波･衛星波並列受信が出来ません。空き容量を確保してください。', EPGREC_WARN );
				}else{
					$bs_tmp = array( 0, 3, 4, 6 );
					if( $GR_tuners > 0 ){
						if( $bs_tmp[$bs_max]+$GR_tuners > $tune_cnts ){
							$minimam =11 * 60;
							$bs_use  = $bs_max;
							for( $bs_stk=$bs_max; $bs_stk>0; $bs_stk-- )
								if( $tune_cnts > $bs_tmp[$bs_stk] ){
									$temp = abs( $bs_tim[$bs_stk] - (int)ceil( $GR_num / ($tune_cnts-$bs_tmp[$bs_stk]) )*$gr_rec_tm );
									if( $minimam >= $temp ){
										$minimam = $temp;
										$bs_use  = $bs_stk;
									}
								}
							$gr_use = $tune_cnts - $bs_tmp[$bs_use];
							//所要時間算出
							$gr_times = (int)ceil( $GR_num / $gr_use ) * $gr_rec_tm;
							$para_tm  = $gr_times<$bs_tim[$bs_use] ? $bs_tim[$bs_use] : $gr_times;
							//セパレート･モード時の所要時間算出
							$gr_use_sepa = $GR_tuners>$tune_cnts ? $tune_cnts : $GR_tuners;
							$gr_times    = (int)ceil( $GR_num / $gr_use_sepa ) * $gr_rec_tm;
							for( $bs_use_sepa=$bs_max; $bs_use_sepa>0; $bs_use_sepa-- )
								if( $bs_tmp[$bs_use_sepa] <= $tune_cnts )
									break;
							$sepa_tm = $gr_times + $bs_tim[$bs_use_sepa];
							//地上波･衛星波 分離判定
							if( $sepa_tm < $para_tm ){
								$gr_bs_sepa = TRUE;
								$gr_use = $gr_use_sepa;
								$bs_use = $bs_use_sepa;
							}
						}else{
							$gr_use = $GR_tuners;
							$bs_use = $bs_max;
						}
					}else{
						$gr_use = 0;
						for( $bs_use=$bs_max; $bs_use>0; $bs_use-- )
							if( $bs_tmp[$bs_use] <= $tune_cnts )
								break;
					}
				}
			}
		}else{
			$gr_use = $GR_tuners>$tune_cnts ? $tune_cnts : $GR_tuners;
			$bs_use = 0;
		}
	}
	// スカパー！プレミアム
	$ex_use = EXTRA_TUNERS;

	// BS/CSを処理する
	if( $bs_use > 0 ){
		$proST = dog_release( INSTALL_PATH.'/collie.php '.$bs_use );
		if( $gr_bs_sepa ){
			//セパレート･モード時のウェイト
			sleep( $bs_tim[$bs_use]+10 );
			$ST_tm = 0;
		}else
			//初期スリープ時間設定
			$ST_tm = $bs_tim[$bs_use] - 120;		// 設定により変動が多いので
	}else{
		$proST = FALSE;
		$ST_tm = 0;
	}
	// スカパー！プレミアム
	if( $ex_use > 0 ){
		$proET = dog_release( INSTALL_PATH.'/greatpyrenees.php '.$ex_use );
		if( $gr_bs_sepa ){
			//セパレート･モード時のウェイト
			sleep( EX_EPG_TIME+10 );
			$EX_tm = 0;
		}else
			//初期スリープ時間設定
			$EX_tm = EX_EPG_TIME - 120;		// 設定により変動が多いので
	}else{
		$proEX = FALSE;
		$EX_tm = 0;
	}
	// 地上波を処理する
	if( $gr_use > 0 ){
		$proGR    = dog_release( INSTALL_PATH.'/sheepdog.php '.$gr_use );
		$sleep_tm = (int)ceil( $GR_num / $gr_use ) * FIRST_REC;
	}else{
		$proGR    = FALSE;
		$sleep_tm = 0;
	}
	// 初期スリープ時間設定
	if( $sleep_tm < $ST_tm )
		$sleep_tm = $ST_tm;
	if( $sleep_tm < $EX_tm )
		$sleep_tm = $EX_tm;

	// EPG更新待ち
	$wtd_tm = $sleep_tm;
	while( $proST !== FALSE || $proGR !== FALSE || $proEX !== FALSE ){
		sleep( $sleep_tm );
		$sleep_tm = 1;
		if( $proST !== FALSE ){
			$st = proc_get_status( $proST );
			if( $st['running'] == FALSE ){
				proc_close( $proST );
				$proST = FALSE;
			}
		}
		if( $proEX !== FALSE ){
			$st = proc_get_status( $proEX );
			if( $st['running'] == FALSE ){
				proc_close( $proEX );
				$proEX = FALSE;
			}
		}
		if( $proGR !== FALSE ){
			$st = proc_get_status( $proGR );
			if( $st['running'] == FALSE ){
				proc_close( $proGR );
				$proGR = FALSE;
			}
		}
		// タイムアウト(1H)
		if( $wtd_tm++ >= 60*60 ){
			$ps_output = shell_exec( PS_CMD.' 2>/dev/null' );
			$rarr      = explode( "\n", $ps_output );
			cleanup( $rarr, 'airwavesSheep.php' );
			if( $proST !== FALSE ){
				cleanup( $rarr, 'collie.php' );
				while( $st['running'] );
				proc_close( $proST );
			}
			if( $proEX !== FALSE ){
				cleanup( $rarr, 'greatpyrenees.php' );
				while( $st['running'] );
				proc_close( $proEX );
			}
			if( $proGR !== FALSE ){
				cleanup( $rarr, 'sheepdog.php' );
				while( $st['running'] );
				proc_close( $proGR );
			}
			shmop_write_surely( $shm_id, SEM_REBOOT, 1 );
			break;
		}
	}
	reclog( 'EPG更新完了('.transTime(time()-$shepherd_st,TRUE).')' );
	// キーワード予約
	doKeywordReservation( '*', $shm_id );

	// PT2が不安定な場合、リブートする
	if( PT1_REBOOT ){
		$smph = shmop_read_surely( $shm_id, SEM_REBOOT );
		if( $smph == 1 ){
			$search_core = time();
			while(1){
				// 5分以内に予約がなければリブート(変更する場合は3分より大きくすること)
				$sql_cmd = "WHERE complete = '0' AND endtime > '".toDatetime($search_core)."' AND starttime < '".toDatetime($search_core+5*60)."'";
				$num = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
				if( $num == 0 ){
					$sleep_tm     = $search_core - time();
					if( $sleep_tm < 0 )
						$sleep_tm = 0;
					reclog( REBOOT_COMMENT.toDatetime($search_core+$settings->extra_time+10), EPGREC_WARN );
					// 10は、録画完了後のDB書き込み待ち
					sleep( $sleep_tm+$settings->extra_time+10 );		//使用中でない事が確認できる方法がないかな･･･
					system( REBOOT_CMD );
					break;
				}else{
					$revs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd.' ORDER BY endtime DESC' );
					$search_core = toTimestamp( $revs[0]->endtime );
					if( $search_core-$shepherd_st >= (2*60-5)*60 )
						break;
				}
			}
		}
	}
	shmop_close( $shm_id );
	exit();
?>
