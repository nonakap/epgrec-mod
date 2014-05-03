<?php
include_once( INSTALL_PATH . '/Keyword.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );

define( 'CERTAINTY', 0 );
define( 'START_TIME_UNCERTAINTY', 1 );
define( 'DURATION_UNCERTAINTY', 2 );
define( 'NEXT_EVENT_UNCERTAINTY', 4 );
define( 'EXTENDING_TIME', 60 );

function garbageClean() {
	// 不要なプログラムの削除
	// 2日以上前のプログラムを消す
	$arr = array();
	$arr = DBRecord::createRecords( PROGRAM_TBL, 'WHERE endtime < subdate( now(), 2 )' );
	foreach( $arr as $val ) $val->delete();
	
	// 8日以上先のデータがあれば消す
	$arr = array();
	$arr = DBRecord::createRecords( PROGRAM_TBL, 'WHERE starttime  > adddate( now(), 8 )' );
	foreach( $arr as $val ) $val->delete();

	// 8日以上前のログを消す
	$arr = array();
	$arr = DBRecord::createRecords( LOG_TBL, 'WHERE logtime < subdate( now(), 8 )' );
	foreach( $arr as $val ) $val->delete();
}

function doKeywordReservation( $wave_type = '*', $shm_id ) {
/*	// 4PROGRAM_TBLのoptimaize
	if( $wave_type==='*' && date('wH')==='100' ){
		global $settings;
		mysql_query( 'ALTER TABLE '.$settings->tbl_prefix.PROGRAM_TBL.' ENGINE MyISAM' );
	}
*/
	// キーワード自動録画予約
	$arr = array();
	$arr = Keyword::createKeywords( 'ORDER BY priority DESC, id ASC' );
	//キーワードを優先度で降順ソート
	$sem_key = sem_get_surely( SEM_KW_START );
	if( count( $arr ) ){
		//キーワード予約
		foreach( $arr as $val ) {
			if( (boolean)$val->kw_enable ){
				switch( $wave_type ){
					case 'GR':
						if( !(boolean)$val->typeGR )
							continue;
						break;
					case 'BS':
					case 'CS':
						if( !(boolean)$val->typeBS && !(boolean)$val->typeCS )
							continue;
						break;
					case 'EX':
						if( !(boolean)$val->typeEX )
							continue;
						break;
//					case '*':
//						break;
				}
				try {
					$val->reservation( $wave_type, $shm_id, $sem_key );
				}
				catch( Exception $e ) {
					// 無視
				}
			}
		}
	}
}

function storeProgram( $type, $xmlfile ) {
	global $BS_CHANNEL_MAP, $GR_CHANNEL_MAP, $CS_CHANNEL_MAP, $EX_CHANNEL_MAP;
	global $settings;
	global $shm_id;

	$ed_tm_sft = (int)$settings->former_time + (int)$settings->rec_switch_time;
	$key_stk = array();
	$key_cnt = 0;
	// チャンネルマップファイルの準備
	$map = array();
	if( $type == 'BS' ) $map = $BS_CHANNEL_MAP;
	else if( $type == 'GR') $map = $GR_CHANNEL_MAP;
	else if( $type == 'CS') $map = $CS_CHANNEL_MAP;
	else if( $type == 'EX') $map = $EX_CHANNEL_MAP;
	
	// serialize file read
	$params = file( $xmlfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if( $params === false ) {
		reclog( 'EPG更新:: 正常な'.$xmlfile.'が作成されなかった模様(放送間帯でないなら問題ありません)', EPGREC_WARN );
//		$new_name = $xmlfile.'.'.toDatetime(time());
//		rename( $xmlfile, $new_name );
		return -1;	// EPGデータが読み取れないなら何もしない
	}
	// channel抽出
	$chs_para = unserialize( array_shift($params) );
	if( $chs_para === FALSE ){
		reclog( 'EPG更新:: 正常な'.$xmlfile.'が作成されなかった模様(TS中にSDTが無い)', EPGREC_WARN );
//		$new_name = $xmlfile.'.'.toDatetime(time());
//		rename( $xmlfile, $new_name );
		return -1;	// EPGデータが読み取れないなら何もしない
	}
	$map_chg = FALSE;
	if( $type !== 'GR' ){
		$f_nm  = INSTALL_PATH.'/settings/'.strtolower($type).'_channel.php';
		$st_ch = file( $f_nm, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	}
	foreach( $chs_para as $ch ){
		// 衛星波でもchが変更されるrecpt1のバグ(BSがCSに　CSでdrop発生が起因?)が確認された為、フェイルセーフを追加(手抜き)
		if( ( $type==='BS' && $ch['on']!=4 ) || ( $type==='CS' && $ch['on']==4 ) ){
			reclog( 'EPG更新::指定放送波に対してオリジナルネットワークIDが違います。('.$type.' on='.$ch['on'].' '.$xmlfile.')', EPGREC_ERROR );
//			$new_name = $xmlfile.'.'.toDatetime(time());
//			rename( $xmlfile, $new_name );
			return -1;	//信頼できないデータなので終了
		}
		$disc = $ch['id'];
		$mono_disc = $type==='GR' ? strtok( $disc, '_' ) : $disc;
		if( !array_key_exists( "$mono_disc", $map ) ){		// GRは、ここで排除
			// BS/CS新規チャンネル・ファイル自動登録 論理チャンネル(sid)変更も含む
			if( $type === 'BS' )
				$map["$disc"] = $BS_CHANNEL_MAP["$disc"] = 'BS'.$ch['node'].'_'.$ch['slot'];	// 'BS' + node + slot
			else
				$map["$disc"] = $CS_CHANNEL_MAP["$disc"] = $type.$ch['node'];
			$wt_str[0] = "\t\"".$disc."\" =>\t\"".$map["$disc"]."\",\t// ".$map["$disc"]."\t".$ch['sv'].",\t// ".$ch['display-name'];

			$wt_str[2] = array_pop( $st_ch );
			$wt_str[1] = array_pop( $st_ch );
			array_push( $st_ch, $wt_str[0], $wt_str[1], $wt_str[2] );
			$map_chg = TRUE;
			reclog( 'EPG更新::新規チャンネルを追加登録しました。('.$wt_str[0].' )', EPGREC_WARN );
		}
		try{
			// チャンネルデータを探す
			$num = DBRecord::countRecords( CHANNEL_TBL , 'WHERE channel_disc=\''.$disc.'\'' );
			if( $num == 0 ){
				if( $type === 'GR' ){
					// 地デジ・サブチャンネル対応でのチェック漏れ対策
					$num = DBRecord::countRecords( CHANNEL_TBL , 'WHERE channel_disc NOT LIKE \''.$mono_disc.'_%\' AND type=\'GR\' AND name=\''.$ch['display-name'].'\'' );
					if( $num ){
						reclog( 'EPG更新::既に同じチャンネル名が登録されています。('.$disc.' '.$rec->name.' -> '.$ch['display-name'].' '.$xmlfile.')', EPGREC_ERROR );
//						$new_name = $xmlfile.'.'.toDatetime(time());
//						rename( $xmlfile, $new_name );
						return -1;	//信頼できないデータなので終了
					}
				}
				// DBにチャンネルデータがないなら新規作成
				if( $map["$mono_disc"] !== 'NC' ){
					$rec = new DBRecord( CHANNEL_TBL );
					$rec->type         = $type;
					if( $type === 'GR' ){
						$rec->channel = $map["$mono_disc"];
					}else{
						$tmp_ch = $type==='BS'? 'BS'.$ch['node'].'_'.$ch['slot'] : $type.$ch['node'];
						if( strcmp( $map["$disc"], $tmp_ch ) ){
							reclog( 'EPG更新::'.$mono_disc.'('.$ch['display-name'].')の物理チャンネル番号が更新されました。('.$map["$mono_disc"].' -> '.$tmp_ch.' '.$xmlfile.')', EPGREC_WARN );
							$key_point         = array_search( $disc, array_keys( $map ) ) + 3;
							$st_ch[$key_point] = "\t\"".$disc."\" =>\t\"".$tmp_ch."\",\t// ".$tmp_ch."\t".$ch['sv'].",\t// ".$ch['display-name'];
							$map_chg           = TRUE;
						}	// 新規追加チャンネルは、上で追加済み
						$rec->channel = $tmp_ch;
					}

					$rec->channel_disc = $disc;
					$rec->name         = $ch['display-name'];
					$rec->sid          = $ch['sv'];
					$rec->update();
				}
			}else{
				$rec = new DBRecord(CHANNEL_TBL, 'channel_disc', $disc );
				if( $type!=='GR' && $map["$mono_disc"]==='NC' && !(boolean)$rec->skip ){
					$del_id = $rec->id;
					// 予約キャンセル
					try{
						$revs = DBRecord::createRecords( RESERVE_TBL, 'WHERE channel_id='.$del_id );
						foreach( $revs as $rev )
							Reservation::cancel( $rev->id );
					}catch( Exception $e ){
					}
					// EPG削除
					try{
						$prgs = DBRecord::createRecords( PROGRAM_TBL, 'WHERE channel_id='.$del_id );
						foreach( $prgs as $prg )
							$prg->delete();
					}catch( Exception $e ){
					}
//					$rec->delete();
					$rec->skip = FALSE;
					// キーワードは一覧側で対策済み
					continue;
				}
				if( strcmp( $rec->name, $ch['display-name'] ) ){
					switch( $type ){
						case 'GR':
							$mono_disc .= '_%';
							break;
						case 'BS':
							$mono_disc[5] = '%';
							break;
					}
					$num = DBRecord::countRecords( CHANNEL_TBL , 'WHERE channel_disc NOT LIKE \''.$mono_disc.'\' AND type=\''.$type.'\' AND name=\''.$ch['display-name'].'\'' );
					if( $num == 0 ){
						reclog( 'EPG更新::チャンネル名が更新されました。('.$disc.' '.$rec->name.' -> '.$ch['display-name'].' '.$xmlfile.')', EPGREC_WARN );
						$rec->name = $ch['display-name'];
					}else{
						reclog( 'EPG更新::既に同じチャンネル名が登録されています。('.$disc.' '.$rec->name.' -> '.$ch['display-name'].' '.$xmlfile.')', EPGREC_ERROR );
//						$new_name = $xmlfile.'.'.toDatetime(time());
//						rename( $xmlfile, $new_name );
						return -1;	//信頼できないデータなので終了
					}
				}
				// BS/CSのチャンネル番号変更
				if( $type !== 'GR' ){
					$tmp_ch = $type==='BS'? 'BS'.$ch['node'].'_'.$ch['slot'] : $type.$ch['node'];

					if( strcmp( $rec->channel, $tmp_ch ) ){
						reclog( 'EPG更新::'.$disc.'('.$ch['display-name'].')の物理チャンネル番号が更新されました。('.$rec->channel.' -> '.$tmp_ch.' '.$xmlfile.')', EPGREC_WARN );
						$rec->channel = $tmp_ch;
						$rec->update();
						$key_point         = array_search( $disc, array_keys( $map ) ) + 3;
						$st_ch[$key_point] = "\t\"".$disc."\" =>\t\"".$tmp_ch."\",\t// ".$tmp_ch."\t".$ch['sv'].",\t// ".$ch['display-name'];
						$map_chg           = TRUE;
						if( !((boolean)$rec->skip)  ){
							// 既予約の物理チャンネル番号を変更
							$revs = DBRecord::createRecords( RESERVE_TBL, 'WHERE complete=0 AND channel_id='.$rec->id );
							foreach( $revs as $rev ){
								if( (int)$rev->autorec ){
									// 自動キーワードはキャンセルのみ
									$key_stk[$key_cnt++] = (int)$rev->autorec;
									Reservation::cancel( $rev->id );
								}else{
									// 手動予約
									$pre_id = $rev->id;
									$st_tm  = $rev->starttime;
									$ed_tm  = !$rev->shortened ? $rev->endtime : toDatetime( toTimestamp( $rev->endtime )+$ed_tm_sft );
									$ch_id  = (int)$rev->channel_id;
									$title  = $rev->title;
									$desc   = $rev->description;
									$cat_id = (int)$rev->category_id;
									$prg_id = (int)$rev->program_id;
									$rs_md  = (int)$rev->mode;
									$discon = (int)$rev->discontinuity;
									$rs_dt  = (int)$rev->dirty;
									$prior  = (int)$rev->priority;
									Reservation::cancel( $pre_id );
									$rval = Reservation::custom( $st_tm, $ed_tm, $ch_id, $title, $desc, $cat_id, $prg_id, 0, $rs_md, $discon, $rs_dt, $prior );
									// 手動予約のトラコン設定の予約ID修正
									list( , , $rec_id, ) = explode( ':', $rval );
									$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$pre_id );
									foreach( $tran_ex as $tran_set ){
										$tran_set->type_no = $rec_id;
										$tran_set->update();
									}
								}
							}
							unset( $rev );
						}
					}
				}else
					$rec->update();
			}
		}catch( Exception $e ){
			reclog( 'EPG更新::DBの接続またはチャンネルテーブルの書き込みに失敗', EPGREC_ERROR );
		}
	}
	unset( $ch );
	if( $map_chg ){
		// xx_channel.php更新
		$fp = fopen( $f_nm, 'w' );
		foreach( $st_ch as $ch_str )
			fwrite( $fp, $ch_str."\n" );
		fclose( $fp );
	}
	// channel 終了

	$single_ch = strncmp( $xmlfile, $settings->temp_xml.'_', strlen( $settings->temp_xml )+1 ) ? TRUE : FALSE;
	$smf_type  = $type==='CS' ? 'BS' : $type;
	$first_epg = TRUE;
	$pro_obj   = new DBRecord( PROGRAM_TBL );
	while( count($params) ){
		// 取得
		$ch_para = unserialize( array_shift($params) );
		if( $ch_para === FALSE ){
			reclog( 'EPG更新:: 正常な'.$xmlfile.'が作成されなかった模様(TS中にSDTまたはEITが無い)', EPGREC_WARN );
//			$new_name = $xmlfile.'.'.toDatetime(time());
//			rename( $xmlfile, $new_name );
			return -1;	// XMLが読み取れないなら何もしない
		}else{
			$channel_disc = $ch_para['disc'];
			$pf_lmt       = $ch_para['pf_cnt'];
			$ev_lmt       = $ch_para['sch_cnt'];
		}
		// チャンネル スキップ
		if( $pf_lmt==0 && $ev_lmt==0 )
			continue;	// EPGデータ無し
		$ch_skip = FALSE;
		if( $type!=='GR' && ( ! array_key_exists( "$channel_disc", $map ) || $map["$channel_disc"]==='NC' ) ){
			// チャンネルマップに存在しないチャンネル(DB登録後に削除された場合も含む)
			$ch_skip = TRUE;
		}else{
			try{
				$channel_rec = new DBRecord( CHANNEL_TBL, 'channel_disc', $channel_disc );
				$skip_ch     = (boolean)$channel_rec->skip;
				if( $type!=='GR' && $skip_ch && !HIDE_CH_EPG_GET ){
					// 受信しないチャンネル
					$ch_skip = TRUE;
				}
			}catch( Exception $e ){
				// チャンネルマップに未登録なチャンネル
				$ch_skip = TRUE;
				reclog( 'EPG更新::チャンネルレコード '.$channel_disc.' が発見できない', EPGREC_ERROR );
			}
		}
		if($ch_skip ){
			if( $ch_para['pf_cnt'] )
				array_shift( $params );
			if( $ch_para['sch_cnt'] )
				array_shift( $params );
			continue;
		}
		$channel_id = (int)$channel_rec->id;
		// pf unserialize
		if( $pf_lmt ){
			while(1){
				if( count($params) ){
					$event_pf = unserialize( array_shift($params) );
					if( $event_pf !== FALSE )
						break;
				}
				reclog( 'EPG更新:: 正常な'.$xmlfile.'が作成されなかった模様('.$channel_disc.'のpf読み取りに失敗しました。)', EPGREC_WARN );
				return -1;	// XMLが読み取れないなら何もしない
			}
		}

		// EIT[schedule]数取得 ない場合はEIT[pf]以降3h分をDBから補完
		if( $ev_lmt == 0 ){
			$sch_obtain = FALSE;

			$after_4H = time() + 4*60*60;
			switch( $event_pf[$pf_lmt-1]['status'] ){
				case CERTAINTY:
					$pf_endtm = toTimestamp( $event_pf[$pf_lmt-1]['endtime'] );
					$epg_end  = $after_4H<$pf_endtm ? $pf_endtm+4*60*60 : $after_4H;
					break;
				case DURATION_UNCERTAINTY:
					$pf_endtm = toTimestamp( $event_pf[$pf_lmt-1]['starttime'] );
					$epg_end  = $after_4H<$pf_endtm+1*60*60 ? $pf_endtm+4*60*60 : $after_4H;
					break;
				default:
					$epg_end  = $after_4H + 2*60*60;
					break;
			}
			$records = $pro_obj->fetch_array( 'channel_id', $channel_id,
								'starttime<\''.toDatetime($epg_end).'\' AND endtime>subtime( \''.$event_pf[0]['starttime'].'\', \'03:00:00\' ) ORDER BY starttime ASC' );
			$tmp_lmt = count( $records );
			if( $tmp_lmt ){
				$pf_trim = 0;
				for( $cnt=0; $cnt<$tmp_lmt; $cnt++ ){
					switch( $pf_trim ){
						case 0:
							// 冒頭の余分な部分をpf1つ前を残して切り捨て
							if( strcmp( $records[$cnt]['starttime'], $event_pf[0]['starttime'] ) < 0 )
								continue;
							else{
								$pf_trim = 1;
								if( $cnt > 0 ){
									$event_sch[$ev_lmt]['starttime']    = $records[$cnt-1]['starttime'];
									$event_sch[$ev_lmt]['endtime']      = $records[$cnt-1]['endtime'];
									$event_sch[$ev_lmt]['channel_disc'] = $channel_disc;
									$event_sch[$ev_lmt]['title']        = $records[$cnt-1]['title'];
									$event_sch[$ev_lmt]['desc']         = $records[$cnt-1]['description'];
									$event_sch[$ev_lmt]['eid']          = (int)$records[$cnt-1]['eid'];
									$event_sch[$ev_lmt]['category']     = (int)$records[$cnt-1]['category_id'];
									$event_sch[$ev_lmt]['genre2']       = (int)$records[$cnt-1]['genre2'];
									$event_sch[$ev_lmt]['genre3']       = (int)$records[$cnt-1]['genre3'];
									$event_sch[$ev_lmt]['sub_genre']    = (int)$records[$cnt-1]['sub_genre'];
									$event_sch[$ev_lmt]['sub_genre2']   = (int)$records[$cnt-1]['sub_genre2'];
									$event_sch[$ev_lmt]['sub_genre3']   = (int)$records[$cnt-1]['sub_genre3'];
									$event_sch[$ev_lmt]['video_type']   = (int)$records[$cnt-1]['video_type'];
									$event_sch[$ev_lmt]['audio_type']   = (int)$records[$cnt-1]['audio_type'];
									$event_sch[$ev_lmt]['multi_type']   = (int)$records[$cnt-1]['multi_type'];
									$ev_lmt++;
								}
							}
						case 1:
							$event_sch[$ev_lmt]['starttime']    = $records[$cnt]['starttime'];
							$event_sch[$ev_lmt]['endtime']      = $records[$cnt]['endtime'];
							$event_sch[$ev_lmt]['channel_disc'] = $channel_disc;
							$event_sch[$ev_lmt]['title']        = $records[$cnt]['title'];
							$event_sch[$ev_lmt]['desc']         = $records[$cnt]['description'];
							$event_sch[$ev_lmt]['eid']          = (int)$records[$cnt]['eid'];
							$event_sch[$ev_lmt]['category']     = (int)$records[$cnt]['category_id'];
							$event_sch[$ev_lmt]['genre2']       = (int)$records[$cnt]['genre2'];
							$event_sch[$ev_lmt]['genre3']       = (int)$records[$cnt]['genre3'];
							$event_sch[$ev_lmt]['sub_genre']    = (int)$records[$cnt]['sub_genre'];
							$event_sch[$ev_lmt]['sub_genre2']   = (int)$records[$cnt]['sub_genre2'];
							$event_sch[$ev_lmt]['sub_genre3']   = (int)$records[$cnt]['sub_genre3'];
							$event_sch[$ev_lmt]['video_type']   = (int)$records[$cnt]['video_type'];
							$event_sch[$ev_lmt]['audio_type']   = (int)$records[$cnt]['audio_type'];
							$event_sch[$ev_lmt]['multi_type']   = (int)$records[$cnt]['multi_type'];
							$ev_lmt++;
							break;
					}
				}
			}

			// EIT[schedule]上のEIT[pf]位置取得
			$tmp_cnt = 0;
			$cnt     = 0;
			do{
				$ev_cnt = $tmp_cnt;
				while(1){
					if( $ev_cnt < $ev_lmt ){
						if( $event_sch[$ev_cnt]['eid'] == $event_pf[$cnt]['eid'] ){
							$event_pf[$cnt]['sch_pnt'] = $ev_cnt++;
							$tmp_cnt                   = $ev_cnt;
							if( ++$cnt >= $pf_lmt )
								break 2;
						}else
							$ev_cnt++;
					}else{
						$event_pf[$cnt]['sch_pnt'] = -1;
						break;
					}
				}
			}while( ++$cnt < $pf_lmt );
		}else{
			$sch_obtain = TRUE;

			// sch unserialize
			while(1){
				if( count($params) ){
					$event_sch = unserialize( array_shift($params) );
					if( $event_sch !== FALSE )
						break;
				}
				reclog( 'EPG更新:: 正常な'.$xmlfile.'が作成されなかった模様('.$channel_disc.'のsch読み取りに失敗しました。)', EPGREC_WARN );
				return -1;	// XMLが読み取れないなら何もしない
			}

			if( $pf_lmt > 0 ){
				$ev_cnt  = 0;
				$sch_add = 0;
				// 抜けschをDBより補完
				for( $pf_cnt=0; $pf_cnt<$pf_lmt; $pf_cnt++ ){
					if( $event_pf[$pf_cnt]['sch_pnt'] < 0 ){
						// 抜けschをDBより補完
						$sch = $pro_obj->fetch_array( 'channel_id', $channel_id, 'eid='.$event_pf[$pf_cnt]['eid'] );
						if( count( $sch ) ){
							// 複数の場合を未考慮(EPG更新にバグがあるかな･･･)

							if( $pf_cnt == 0 ){
								//EIT[pf]より前が無いEIT[sch]をDBより１つ補完
								$sch_in = $pro_obj->fetch_array( 'channel_id', $channel_id,
												'starttime<\''.$sch[0]['starttime'].'\' AND endtime>=\''.$sch[0]['starttime'].'\' ORDER BY endtime DESC' );
								if( count( $sch_in ) ){
									//番組挿入 複数の場合を未考慮
									$event_add[$ev_cnt]['starttime']    = $sch_in[0]['starttime'];
									$event_add[$ev_cnt]['endtime']      = $sch_in[0]['endtime'];
									$event_add[$ev_cnt]['channel_disc'] = $channel_disc;
									$event_add[$ev_cnt]['title']        = $sch_in[0]['title'];
									$event_add[$ev_cnt]['desc']         = $sch_in[0]['description'];
									$event_add[$ev_cnt]['eid']          = (int)$sch_in[0]['eid'];
									$event_add[$ev_cnt]['category']     = (int)$sch_in[0]['category_id'];
									$event_add[$ev_cnt]['genre2']       = (int)$sch_in[0]['genre2'];
									$event_add[$ev_cnt]['genre3']       = (int)$sch_in[0]['genre3'];
									$event_add[$ev_cnt]['sub_genre']    = (int)$sch_in[0]['sub_genre'];
									$event_add[$ev_cnt]['sub_genre2']   = (int)$sch_in[0]['sub_genre2'];
									$event_add[$ev_cnt]['sub_genre3']   = (int)$sch_in[0]['sub_genre3'];
									$event_add[$ev_cnt]['video_type']   = (int)$sch_in[0]['video_type'];
									$event_add[$ev_cnt]['audio_type']   = (int)$sch_in[0]['audio_type'];
									$event_add[$ev_cnt]['multi_type']   = (int)$sch_in[0]['multi_type'];
									$ev_cnt++;
									$sch_add++;
								}
							}
							//番組挿入
							$event_add[$ev_cnt]['starttime']    = $sch[0]['starttime'];
							$event_add[$ev_cnt]['endtime']      = $sch[0]['endtime'];
							$event_add[$ev_cnt]['channel_disc'] = $channel_disc;
							$event_add[$ev_cnt]['title']        = $sch[0]['title'];
							$event_add[$ev_cnt]['desc']         = $sch[0]['description'];
							$event_add[$ev_cnt]['eid']          = (int)$sch[0]['eid'];
							$event_add[$ev_cnt]['category']     = (int)$sch[0]['category_id'];
							$event_add[$ev_cnt]['genre2']       = (int)$sch[0]['genre2'];
							$event_add[$ev_cnt]['genre3']       = (int)$sch[0]['genre3'];
							$event_add[$ev_cnt]['sub_genre']    = (int)$sch[0]['sub_genre'];
							$event_add[$ev_cnt]['sub_genre2']   = (int)$sch[0]['sub_genre2'];
							$event_add[$ev_cnt]['sub_genre3']   = (int)$sch[0]['sub_genre3'];
							$event_add[$ev_cnt]['video_type']   = (int)$sch[0]['video_type'];
							$event_add[$ev_cnt]['audio_type']   = (int)$sch[0]['audio_type'];
							$event_add[$ev_cnt]['multi_type']   = (int)$sch[0]['multi_type'];
							$event_pf[$pf_cnt]['sch_pnt'] = $ev_cnt;
							$ev_cnt++;
							$sch_add++;
						}else{
							// $event_pf[$pf_cnt]は、早期終了時の穴埋め番組
							if( $pf_cnt == 0 ){
								//EIT[pf]より前が無いEIT[sch]をDBより１つ補完
								$sch_in = $pro_obj->fetch_array( 'channel_id', $channel_id,
										'starttime<\''.$event_pf[$pf_cnt]['starttime'].'\' AND endtime>=\''.$event_pf[$pf_cnt]['starttime'].'\' ORDER BY endtime DESC' );
								if( count( $sch_in ) ){
									//番組挿入 複数の場合を未考慮
									$event_add[$ev_cnt]['starttime']    = $sch_in[0]['starttime'];
									$event_add[$ev_cnt]['endtime']      = $event_pf[$pf_cnt]['starttime'];		// 穴埋め番組の開始時間で補正
									$event_add[$ev_cnt]['channel_disc'] = $channel_disc;
									$event_add[$ev_cnt]['title']        = $sch_in[0]['title'];
									$event_add[$ev_cnt]['desc']         = $sch_in[0]['description'];
									$event_add[$ev_cnt]['eid']          = (int)$sch_in[0]['eid'];
									$event_add[$ev_cnt]['category']     = (int)$sch_in[0]['category_id'];
									$event_add[$ev_cnt]['genre2']       = (int)$sch_in[0]['genre2'];
									$event_add[$ev_cnt]['genre3']       = (int)$sch_in[0]['genre3'];
									$event_add[$ev_cnt]['sub_genre']    = (int)$sch_in[0]['sub_genre'];
									$event_add[$ev_cnt]['sub_genre2']   = (int)$sch_in[0]['sub_genre2'];
									$event_add[$ev_cnt]['sub_genre3']   = (int)$sch_in[0]['sub_genre3'];
									$event_add[$ev_cnt]['video_type']   = (int)$sch_in[0]['video_type'];
									$event_add[$ev_cnt]['audio_type']   = (int)$sch_in[0]['audio_type'];
									$event_add[$ev_cnt]['multi_type']   = (int)$sch_in[0]['multi_type'];
									$ev_cnt++;
									$sch_add++;
								}
							}
						}
					}else{
						if( $pf_cnt == 0 ){
							if( $event_pf[$pf_cnt]['sch_pnt'] == $ev_cnt+1 ){
								$event_add[$ev_cnt]           = array_shift( $event_sch );
								$ev_cnt++;
							}else{
								//EIT[pf]より前が無いEIT[sch]をDBより１つ補完
								$sch = $pro_obj->fetch_array( 'channel_id', $channel_id,
										'starttime<\''.$event_pf[$pf_cnt]['starttime'].'\' AND endtime>=\''.$event_pf[$pf_cnt]['starttime'].'\' ORDER BY endtime DESC' );
								if( count( $sch ) ){
									//番組挿入 複数の場合を未考慮
									$event_add[$ev_cnt]['starttime']    = $sch[0]['starttime'];
									$event_add[$ev_cnt]['endtime']      = $sch[0]['endtime'];
									$event_add[$ev_cnt]['channel_disc'] = $channel_disc;
									$event_add[$ev_cnt]['title']        = $sch[0]['title'];
									$event_add[$ev_cnt]['desc']         = $sch[0]['description'];
									$event_add[$ev_cnt]['eid']          = (int)$sch[0]['eid'];
									$event_add[$ev_cnt]['category']     = (int)$sch[0]['category_id'];
									$event_add[$ev_cnt]['genre2']       = (int)$sch[0]['genre2'];
									$event_add[$ev_cnt]['genre3']       = (int)$sch[0]['genre3'];
									$event_add[$ev_cnt]['sub_genre']    = (int)$sch[0]['sub_genre'];
									$event_add[$ev_cnt]['sub_genre2']   = (int)$sch[0]['sub_genre2'];
									$event_add[$ev_cnt]['sub_genre3']   = (int)$sch[0]['sub_genre3'];
									$event_add[$ev_cnt]['video_type']   = (int)$sch[0]['video_type'];
									$event_add[$ev_cnt]['audio_type']   = (int)$sch[0]['audio_type'];
									$event_add[$ev_cnt]['multi_type']   = (int)$sch[0]['multi_type'];
									$ev_cnt++;
									$sch_add++;
								}	// else DBにない場合(特に初回起動)にrepairEPG.phpを動かさないようにする
							}
						}
						$event_add[$ev_cnt]           = array_shift( $event_sch );
						$event_pf[$pf_cnt]['sch_pnt'] = $ev_cnt;
						$ev_cnt++;
					}
				}
				$ev_lmt += $sch_add;
				if( isset( $event_add ) ){
					$event_sch = array_merge( $event_add, $event_sch );
					unset( $event_add );
				}
			}
		}

		// pf・sch非同期時 番組中止(スキップor差し替え)対策
		$pf_fwd  = -1;
		$sch_fwd = 0;
		$sch_dec = 0;
		$ev_inst = FALSE;
		for( $pf_cnt=0; $pf_cnt<$pf_lmt; $pf_cnt++ ){
			if( $event_pf[$pf_cnt]['sch_pnt'] !== -1 ){
				$event_pf[$pf_cnt]['sch_pnt'] -= $sch_dec;
				if( $pf_fwd === -1 ){
					if( $pf_cnt > 0 ){
						$pf_aft = $event_pf[$pf_cnt]['sch_pnt'];
						if( $pf_aft !== -1 ){
							if( $pf_aft > 0 ){
								$sa       = $event_pf[$pf_cnt]['status']!==START_TIME_UNCERTAINTY ?
											toTimestamp( $event_sch[$pf_aft]['starttime'] ) - toTimestamp( $event_pf[$pf_cnt]['starttime'] ) : 0;
								$cut_time = toDatetime( toTimestamp( $event_pf[0]['starttime'] ) + $sa );
								$pf_bfr   = $pf_aft;
								while( --$pf_bfr >= 0 ){
									if( strcmp( $event_sch[$pf_bfr]['endtime'], $cut_time ) <= 0 )
										break;
								}
								$dl = $pf_aft - $pf_bfr - 1;
								if( $dl > 0 ){
									array_splice( $event_sch, $pf_bfr+1, $dl );
									$event_pf[$pf_cnt]['sch_pnt'] -= $dl;
									$ev_lmt  -= $dl;
									$sch_dec += $dl;
									$ev_inst  = TRUE;
								}
							}
						}
					}
				}else{
					if( $sch_fwd+1 < $event_pf[$pf_cnt]['sch_pnt'] ){
						// 番組中止(スキップor差し替え)
						$dl = $event_pf[$pf_cnt]['sch_pnt'] - $sch_fwd - 1;
						if( $dl > 0 ){
							array_splice( $event_sch, $sch_fwd+1, $dl );
							$event_pf[$pf_cnt]['sch_pnt'] -= $dl;
							$ev_lmt  -= $dl;
							$sch_dec += $dl;
							$ev_inst  = TRUE;
						}
					}
				}
				$pf_fwd  = $pf_cnt;
				$sch_fwd = $event_pf[$pf_cnt]['sch_pnt'];
			}
		}

		$sch_sync['cnt']       = -1;
		$sch_sync['pre_check'] = FALSE;
		$debug_msg             = '';
		if( $pf_lmt ){
			// EIT[schedule]とEIT[pf]をマージ
			$pf_cnt = 0;
			for( $ev_cnt=0; $ev_cnt<$ev_lmt; $ev_cnt++ ){
				$start_cmp = strcmp( $event_sch[$ev_cnt]['starttime'], $event_pf[$pf_cnt]['starttime'] );
				if( $start_cmp >= 0 ){
					$del_tm = 0;
					if( $event_pf[$pf_cnt]['sch_pnt'] != -1 ){
						//$debug_buf = $pf_cnt.'ev_cnt::'.$ev_cnt.' event_pf[$pf_cnt]['sch_pnt']::'.$event_pf[$pf_cnt]['sch_pnt'];
						if( $event_pf[$pf_cnt]['sch_pnt'] > $ev_cnt ){
							//番組繰り上がり
							$erase_cnt = $event_pf[$pf_cnt]['sch_pnt'] - $ev_cnt;
							array_splice( $event_sch, $ev_cnt, $erase_cnt );
							$debug_msg = '[array_splice():'.$ev_lmt.'>'.count( $event_sch ).']';
							$ev_lmt -= $erase_cnt;
							for( $e_cnt=$pf_cnt+1; $e_cnt<$pf_lmt; $e_cnt++ )
								if( $event_pf[$e_cnt]['sch_pnt'] != -1 )
									$event_pf[$e_cnt]['sch_pnt'] -= $erase_cnt;
						}else
							$ev_cnt = $event_pf[$pf_cnt]['sch_pnt'];
						$event_sch[$ev_cnt]['title']      = $event_pf[$pf_cnt]['title'];
						$event_sch[$ev_cnt]['desc']       = $event_pf[$pf_cnt]['desc'];
						$event_sch[$ev_cnt]['eid']        = $event_pf[$pf_cnt]['eid'];
						$event_sch[$ev_cnt]['category']   = $event_pf[$pf_cnt]['category'];
						$event_sch[$ev_cnt]['genre2']     = $event_pf[$pf_cnt]['genre2'];
						$event_sch[$ev_cnt]['genre3']     = $event_pf[$pf_cnt]['genre3'];
						$event_sch[$ev_cnt]['sub_genre']  = $event_pf[$pf_cnt]['sub_genre'];
						$event_sch[$ev_cnt]['sub_genre2'] = $event_pf[$pf_cnt]['sub_genre2'];
						$event_sch[$ev_cnt]['sub_genre3'] = $event_pf[$pf_cnt]['sub_genre3'];
						$event_sch[$ev_cnt]['video_type'] = $event_pf[$pf_cnt]['video_type'];
						$event_sch[$ev_cnt]['audio_type'] = $event_pf[$pf_cnt]['audio_type'];
						$event_sch[$ev_cnt]['multi_type'] = $event_pf[$pf_cnt]['multi_type'];
					}else{
						//$debug_buf = '[pf insert]';
						$debug_msg = '[pf insert]';
						// 番組挿入
//						array_splice( $event_sch, $ev_cnt, 0, $event_pf[$pf_cnt] );		//番組挿入
						for( $ev_sft=$ev_lmt; $ev_sft>$ev_cnt; $ev_sft-- ){
//							$event_sch[$ev_sft] = $event_sch[$ev_sft-1];
							$event_sch[$ev_sft]['starttime']    = $event_sch[$ev_sft-1]['starttime'];
							$event_sch[$ev_sft]['endtime']      = $event_sch[$ev_sft-1]['endtime'];
							$event_sch[$ev_sft]['channel_disc'] = $event_sch[$ev_sft-1]['channel_disc'];
							$event_sch[$ev_sft]['title']        = $event_sch[$ev_sft-1]['title'];
							$event_sch[$ev_sft]['desc']         = $event_sch[$ev_sft-1]['desc'];
							$event_sch[$ev_sft]['eid']          = $event_sch[$ev_sft-1]['eid'];
							$event_sch[$ev_sft]['category']     = $event_sch[$ev_sft-1]['category'];
							$event_sch[$ev_sft]['genre2']       = $event_sch[$ev_sft-1]['genre2'];
							$event_sch[$ev_sft]['genre3']       = $event_sch[$ev_sft-1]['genre3'];
							$event_sch[$ev_sft]['sub_genre']    = $event_sch[$ev_sft-1]['sub_genre'];
							$event_sch[$ev_sft]['sub_genre2']   = $event_sch[$ev_sft-1]['sub_genre2'];
							$event_sch[$ev_sft]['sub_genre3']   = $event_sch[$ev_sft-1]['sub_genre3'];
							$event_sch[$ev_sft]['video_type']   = $event_sch[$ev_sft-1]['video_type'];
							$event_sch[$ev_sft]['audio_type']   = $event_sch[$ev_sft-1]['audio_type'];
							$event_sch[$ev_sft]['multi_type']   = $event_sch[$ev_sft-1]['multi_type'];
						}
//						$event_sch[$ev_cnt] = $event_pf[$pf_cnt];		//番組挿入
						$event_sch[$ev_cnt]['starttime']    = $event_pf[$pf_cnt]['starttime'];
//						$event_sch[$ev_cnt]['endtime']      = $event_pf[$pf_cnt]['endtime'];
						$event_sch[$ev_cnt]['channel_disc'] = $channel_disc;
						$event_sch[$ev_cnt]['title']        = $event_pf[$pf_cnt]['title'];
						$event_sch[$ev_cnt]['desc']         = $event_pf[$pf_cnt]['desc'];
						$event_sch[$ev_cnt]['eid']          = $event_pf[$pf_cnt]['eid'];
						$event_sch[$ev_cnt]['category']     = $event_pf[$pf_cnt]['category'];
						$event_sch[$ev_cnt]['genre2']       = $event_pf[$pf_cnt]['genre2'];
						$event_sch[$ev_cnt]['genre3']       = $event_pf[$pf_cnt]['genre3'];
						$event_sch[$ev_cnt]['sub_genre']    = $event_pf[$pf_cnt]['sub_genre'];
						$event_sch[$ev_cnt]['sub_genre2']   = $event_pf[$pf_cnt]['sub_genre2'];
						$event_sch[$ev_cnt]['sub_genre3']   = $event_pf[$pf_cnt]['sub_genre3'];
						$event_sch[$ev_cnt]['video_type']   = $event_pf[$pf_cnt]['video_type'];
						$event_sch[$ev_cnt]['audio_type']   = $event_pf[$pf_cnt]['audio_type'];
						$event_sch[$ev_cnt]['multi_type']   = $event_pf[$pf_cnt]['multi_type'];
						$ev_lmt++;
						for( $e_cnt=$pf_cnt+1; $e_cnt<$pf_lmt; $e_cnt++ )
							if( $event_pf[$e_cnt]['sch_pnt'] != -1 )
								$event_pf[$e_cnt]['sch_pnt']++;
					}
					if( $event_pf[$pf_cnt]['status'] == DURATION_UNCERTAINTY ){
						$now_time = time();
						if( $pf_cnt+1==$pf_lmt || $event_pf[$pf_cnt+1]['status']==START_TIME_UNCERTAINTY ){
							if( $event_pf[$pf_cnt]['sch_pnt'] != -1 ){
								$duration = toTimestamp( $event_sch[$ev_cnt]['endtime'] ) - toTimestamp( $event_sch[$ev_cnt]['starttime'] );
								$end_time = toTimestamp( $event_pf[$pf_cnt]['starttime'] ) + $duration;
							}else{
								//終了時刻不明 たぶん臨時放送
								if( $now_time % 60 )
									$now_time = $now_time + 60 - $now_time%60;
								if( $ev_cnt+1 < $ev_lmt ){
									$duration = toTimestamp( $event_sch[$ev_cnt+1]['starttime'] ) - toTimestamp( $event_pf[$pf_cnt]['starttime'] );
									if( $duration > 0 ){
										if( $duration > 2*60*60 )		// 2hは定期EPG更新周期より
											$duration = 2*60*60;
									}else
										$duration = EXTENDING_TIME;
									$stk_end = $next_start = toDatetime( $now_time + $duration );
								}else{
									$stk_end = $next_start = toDatetime( $now_time + EXTENDING_TIME );
									$del_tm  = EXTENDING_TIME;
								}
								$event_sch[$ev_cnt]['desc'] .= '(終了時刻不明)';
								goto BORDER_CHK_THR;
							}
						}else
							if( $pf_cnt+1 < $pf_lmt ){
								if( $ev_cnt+1==$ev_lmt || strcmp( $event_pf[$pf_cnt+1]['starttime'], $event_sch[$ev_cnt+1]['starttime'] )>=0 )
									$end_time = toTimestamp( $event_pf[$pf_cnt+1]['starttime'] );
								else{
									$stk_end = $next_start = $event_pf[$pf_cnt+1]['starttime'];
									goto BORDER_CHK_THR;
								}
							}else{
								if( $now_time % 60 )
									$now_time = $now_time + 60 - $now_time%60;
								$stk_end = $next_start = toDatetime( $now_time + EXTENDING_TIME );
								$del_tm  = EXTENDING_TIME;
								goto BORDER_CHK_THR;
							}
						if( $end_time < $now_time ){
							if( $now_time % 60 )
								$now_time = $now_time + 60 - $now_time%60;
							$now_time += EXTENDING_TIME;
							$del_tm    = $now_time - $end_time;
							$end_time  = $now_time;
						}else
							if( $end_time-$now_time < 60 ){
								$end_time += EXTENDING_TIME;
								$del_tm    = EXTENDING_TIME;
							}
						$stk_end = $next_start = toDatetime( $end_time );
BORDER_CHK_THR:;
					}else
						$stk_end = $next_start = $event_pf[$pf_cnt]['endtime'];
					if( $event_pf[$pf_cnt]['sch_pnt']!=-1 && $start_cmp!=0 )
						$event_sch[$ev_cnt]['starttime'] = $event_pf[$pf_cnt]['starttime'];

					//EPG更新判定
					if( $event_pf[$pf_cnt]['sch_pnt'] == -1 ){
						$sch_sync['cnt'] = $ev_cnt;
						if( $sch_obtain===TRUE && $ev_inst===FALSE ){
							$sch_sync['pre_check'] = TRUE;		// 初回EPG取得の可能性
						}
					}else
						if( $start_cmp!=0 || strcmp( $event_sch[$ev_cnt]['endtime'], $event_pf[$pf_cnt]['endtime'] )!=0 )
							$sch_sync['cnt'] = $ev_cnt;

					if( $ev_cnt>0 && strcmp( $event_sch[$ev_cnt-1]['endtime'], $event_pf[$pf_cnt]['starttime'] ) > 0 ){
						$event_sch[$ev_cnt-1]['endtime'] = $event_pf[$pf_cnt]['starttime'];
					}
					$event_sch[$ev_cnt]['endtime'] = $stk_end;
					$debug_msg .= $channel_disc.' ( '.$pf_cnt.'['.$event_pf[$pf_cnt]['status'].':'.$event_pf[$pf_cnt]['eid'].']:'.$event_pf[$pf_cnt]['starttime'].'→'.$stk_end.')';
					if( $event_pf[$pf_cnt]['sch_pnt'] == -1 )
						$event_pf[$pf_cnt]['sch_pnt'] = $ev_cnt;
					$pf_cnt++;
					//reclog( '$ev_cnt < $ev_lmt('.$ev_cnt.'::'.$ev_lmt.')'.$debug_buf, EPGREC_DEBUG );
					if( $ev_cnt+1 < $ev_lmt )
						$ev_cnt++;
					else
						goto NEXT_SUB;

					while( $pf_cnt < $pf_lmt ){
						$debug_msg .= ' ';
						if( $event_pf[$pf_cnt]['sch_pnt'] != -1 ){
//							if( $event_pf[$pf_cnt-1]['sch_pnt']+1 != $ev_cnt )
//								$next_start = $event_pf[$pf_cnt]['starttime'];
							if( $event_pf[$pf_cnt]['sch_pnt'] > $ev_cnt ){
								//番組繰り上がり
								$erase_cnt = $event_pf[$pf_cnt]['sch_pnt'] - $ev_cnt;
								array_splice( $event_sch, $ev_cnt, $erase_cnt );
								$debug_msg .= '[array_splice():'.$ev_lmt.'>'.count( $event_sch ).']';
								$ev_lmt -= $erase_cnt;
								for( $e_cnt=$pf_cnt+1; $e_cnt<$pf_lmt; $e_cnt++ )
									if( $event_pf[$e_cnt]['sch_pnt'] != -1 )
										$event_pf[$e_cnt]['sch_pnt'] -= $erase_cnt;
							}else
								$ev_cnt = $event_pf[$pf_cnt]['sch_pnt'];
							$event_sch[$ev_cnt]['channel_disc'] = $channel_disc;
							$event_sch[$ev_cnt]['title']        = $event_pf[$pf_cnt]['title'];
							$event_sch[$ev_cnt]['desc']         = $event_pf[$pf_cnt]['desc'];
							$event_sch[$ev_cnt]['eid']          = $event_pf[$pf_cnt]['eid'];
							$event_sch[$ev_cnt]['category']     = $event_pf[$pf_cnt]['category'];
							$event_sch[$ev_cnt]['genre2']       = $event_pf[$pf_cnt]['genre2'];
							$event_sch[$ev_cnt]['genre3']       = $event_pf[$pf_cnt]['genre3'];
							$event_sch[$ev_cnt]['sub_genre']    = $event_pf[$pf_cnt]['sub_genre'];
							$event_sch[$ev_cnt]['sub_genre2']   = $event_pf[$pf_cnt]['sub_genre2'];
							$event_sch[$ev_cnt]['sub_genre3']   = $event_pf[$pf_cnt]['sub_genre3'];
							$event_sch[$ev_cnt]['video_type']   = $event_pf[$pf_cnt]['video_type'];
							$event_sch[$ev_cnt]['audio_type']   = $event_pf[$pf_cnt]['audio_type'];
							$event_sch[$ev_cnt]['multi_type']   = $event_pf[$pf_cnt]['multi_type'];
							switch( $event_pf[$pf_cnt]['status'] ){
								case DURATION_UNCERTAINTY:
									if( $pf_cnt+1<$pf_lmt && $event_pf[$pf_cnt+1]['status']!=START_TIME_UNCERTAINTY )
										$duration = toTimestamp( $event_pf[$pf_cnt+1]['starttime'] ) - toTimestamp( $next_start );
									else
										$duration = toTimestamp( $event_sch[$ev_cnt]['endtime'] ) - toTimestamp( $event_sch[$ev_cnt]['starttime'] ) - $del_tm;
									if( $duration < 60 )
										$duration = EXTENDING_TIME;
									break;
								case START_TIME_UNCERTAINTY:
									$duration = toTimestamp($event_pf[$pf_cnt]['endtime']) - toTimestamp($event_pf[$pf_cnt]['starttime']);
									break;
								default:
									$duration = toTimestamp($event_pf[$pf_cnt]['endtime']) - toTimestamp($event_pf[$pf_cnt]['starttime']);
									break;
							}
						}else{
							$debug_msg .= '[pf insert]';
//							array_splice( $event_sch, $ev_cnt, 0, $event_pf[$pf_cnt] );		//番組挿入
							for( $ev_sft=$ev_lmt; $ev_sft>$ev_cnt; $ev_sft-- ){
//								$event_sch[$ev_sft] = $event_sch[$ev_sft-1];
								$event_sch[$ev_sft]['starttime']    = $event_sch[$ev_sft-1]['starttime'];
								$event_sch[$ev_sft]['endtime']      = $event_sch[$ev_sft-1]['endtime'];
								$event_sch[$ev_sft]['channel_disc'] = $event_sch[$ev_sft-1]['channel_disc'];
								$event_sch[$ev_sft]['title']        = $event_sch[$ev_sft-1]['title'];
								$event_sch[$ev_sft]['desc']         = $event_sch[$ev_sft-1]['desc'];
								$event_sch[$ev_sft]['eid']          = $event_sch[$ev_sft-1]['eid'];
								$event_sch[$ev_sft]['category']     = $event_sch[$ev_sft-1]['category'];
								$event_sch[$ev_sft]['genre2']       = $event_sch[$ev_sft-1]['genre2'];
								$event_sch[$ev_sft]['genre3']       = $event_sch[$ev_sft-1]['genre3'];
								$event_sch[$ev_sft]['sub_genre']    = $event_sch[$ev_sft-1]['sub_genre'];
								$event_sch[$ev_sft]['sub_genre2']   = $event_sch[$ev_sft-1]['sub_genre2'];
								$event_sch[$ev_sft]['sub_genre3']   = $event_sch[$ev_sft-1]['sub_genre3'];
								$event_sch[$ev_sft]['video_type']   = $event_sch[$ev_sft-1]['video_type'];
								$event_sch[$ev_sft]['audio_type']   = $event_sch[$ev_sft-1]['audio_type'];
								$event_sch[$ev_sft]['multi_type']   = $event_sch[$ev_sft-1]['multi_type'];
							}
//							$event_sch[$ev_cnt] = $event_pf[$pf_cnt];		//番組挿入
//							$event_sch[$ev_cnt]['starttime']    = $event_pf[$pf_cnt]['starttime'];
//							$event_sch[$ev_cnt]['endtime']      = $event_pf[$pf_cnt]['endtime'];
							$event_sch[$ev_cnt]['channel_disc'] = $channel_disc;
							$event_sch[$ev_cnt]['title']        = $event_pf[$pf_cnt]['title'];
							$event_sch[$ev_cnt]['desc']         = $event_pf[$pf_cnt]['desc'];
							$event_sch[$ev_cnt]['eid']          = $event_pf[$pf_cnt]['eid'];
							$event_sch[$ev_cnt]['category']     = $event_pf[$pf_cnt]['category'];
							$event_sch[$ev_cnt]['genre2']       = $event_pf[$pf_cnt]['genre2'];
							$event_sch[$ev_cnt]['genre3']       = $event_pf[$pf_cnt]['genre3'];
							$event_sch[$ev_cnt]['sub_genre']    = $event_pf[$pf_cnt]['sub_genre'];
							$event_sch[$ev_cnt]['sub_genre2']   = $event_pf[$pf_cnt]['sub_genre2'];
							$event_sch[$ev_cnt]['sub_genre3']   = $event_pf[$pf_cnt]['sub_genre3'];
							$event_sch[$ev_cnt]['video_type']   = $event_pf[$pf_cnt]['video_type'];
							$event_sch[$ev_cnt]['audio_type']   = $event_pf[$pf_cnt]['audio_type'];
							$event_sch[$ev_cnt]['multi_type']   = $event_pf[$pf_cnt]['multi_type'];
							$ev_lmt++;
							for( $e_cnt=$pf_cnt+1; $e_cnt<$pf_lmt; $e_cnt++ )
								if( $event_pf[$e_cnt]['sch_pnt'] != -1 )
									$event_pf[$e_cnt]['sch_pnt']++;
							switch( $event_pf[$pf_cnt]['status'] ){
								case DURATION_UNCERTAINTY:
									if( $pf_cnt+1<$pf_lmt && $event_pf[$pf_cnt+1]['status']!=START_TIME_UNCERTAINTY ){
										$duration = toTimestamp( $event_pf[$pf_cnt+1]['starttime'] ) - toTimestamp( $next_start );
									}else{
										if( $ev_cnt+1 < $ev_lmt ){
											$duration = toTimestamp( $event_sch[$ev_cnt+1]['starttime'] ) - toTimestamp( $next_start );		//不定
											if( $duration > 0 ){
												if( $duration > 2*60*60 )		// 2hは定期EPG更新周期より
													$duration = 2*60*60;
											}else
												$duration = EXTENDING_TIME;
										}else
											$duration = EXTENDING_TIME;		//不定
										$event_sch[$ev_cnt]['desc'] .= '(終了時刻不明)';
									}
									if( $duration < 60 )
										$duration = EXTENDING_TIME;
									break;
								case START_TIME_UNCERTAINTY:
									$duration = toTimestamp($event_pf[$pf_cnt]['endtime']) - toTimestamp($event_pf[$pf_cnt]['starttime']);
									break;
								default:
									$duration = toTimestamp($event_pf[$pf_cnt]['endtime']) - toTimestamp($event_pf[$pf_cnt]['starttime']);
									break;
							}
						}

						//EPG更新判定
						if( $sch_sync['cnt'] == -1 ){
							if( $event_pf[$pf_cnt]['sch_pnt'] == -1 ){
								$sch_sync['cnt'] = $ev_cnt;
								if( $sch_obtain===TRUE && $ev_inst===FALSE ){
									$sch_sync['pre_check'] = TRUE;		// 初回EPG取得の可能性
								}
							}else
								if( strcmp( $event_sch[$ev_cnt]['endtime'], $event_pf[$pf_cnt]['endtime'] )!=0 )
									$sch_sync['cnt'] = $ev_cnt;
						}

						$event_sch[$ev_cnt]['starttime'] = $next_start;
						$next_start                      = toDatetime( toTimestamp( $next_start ) + $duration );
						$event_sch[$ev_cnt]['endtime']   = $next_start;
						$debug_msg .= '( '.$pf_cnt.'['.$event_pf[$pf_cnt]['status'].':'.$event_pf[$pf_cnt]['eid'].']:'.$event_sch[$ev_cnt]['starttime'].'→'.$next_start.')';
						if( $event_pf[$pf_cnt]['sch_pnt'] == -1 )
							$event_pf[$pf_cnt]['sch_pnt'] = $ev_cnt;
						$pf_cnt++;
						if( $ev_cnt+1 < $ev_lmt )
							$ev_cnt++;
						else
							goto NEXT_SUB;
						$del_tm = 0;
					}
					//マージ以降をタイムシフト
					$add_time = toTimestamp($next_start) - toTimestamp($event_sch[$ev_cnt]['starttime']);
					if( $add_time > 0 ){
						$lmt_time = time();
						$lmt_time = $lmt_time + 60 - $lmt_time % 60 + 3 * 60 * 60;
						for( ; $ev_cnt<$ev_lmt; $ev_cnt++ ){
							if( strcmp( '放送休止', $event_sch[$ev_cnt]['title'] ) != 0 ){
								$event_sch[$ev_cnt]['starttime'] = $event_sch[$ev_cnt-1]['endtime'];
								$next_start                      = toTimestamp( $event_sch[$ev_cnt]['endtime'] ) + $add_time;
								$event_sch[$ev_cnt]['endtime']   = toDatetime( $next_start );
								if( $next_start > $lmt_time ){
									while( ++$ev_cnt < $ev_lmt ){
										//ここで最後に変更された番組はEPG登録時に削除される 手動予約されている場合は予約も削除される
										$event_sch[$ev_cnt]['starttime'] = $event_sch[$ev_cnt-1]['endtime'];
										$lmt_time                        = toTimestamp( $event_sch[$ev_cnt]['endtime'] ) + $add_time;
										$event_sch[$ev_cnt]['endtime']   = toDatetime( $lmt_time );
										if( $lmt_time > $next_start )
											break 2;
									}
									break;
								}
							}else{
								$cmp  = toTimestamp( $event_sch[$ev_cnt]['endtime'] ) - toTimestamp( $event_sch[$ev_cnt]['starttime'] );
								$cmp -= $add_time;
								if( $cmp > 0 ){
									$event_sch[$ev_cnt]['starttime'] = toDatetime( toTimestamp( $event_sch[$ev_cnt]['starttime'] )+$add_time );
									break;
								}else{
									array_splice( $event_sch, $ev_cnt, 1 );
									$ev_lmt--;
									if( $cmp == 0 ){
										break;
									}else{
										//他で調節が必要
										break;
									}
								}
							}
							//reclog( 'debug: '.$pf_cnt.':'.$next_start, EPGREC_DEBUG );
						}
					}
					//reclog( 'e'.$pf_cnt.' ev_cnt::'.$ev_cnt, EPGREC_DEBUG );
					$ev_cnt = $ev_lmt;		// マージが終了したのでループを抜けるおまじない
NEXT_SUB:;
					if( $single_ch )
						reclog( '単局EPG更新::'.$debug_msg, EPGREC_DEBUG );
				}
				if( $pf_cnt >= $pf_lmt )
					break;
			}
		}

		// programme 取得
		$stk_rev    = array();
		$stk_auto   = array();
		$ev_cnt     = 0;
		$channel_ch = $channel_rec->channel;
		$now_time   = toDatetime( time() );
		$epg_time   = toDatetime( time() + 3*60*60 );
		foreach( $event_sch as $sch_key => $program ){
			$starttime    = $program['starttime'];
			$endtime      = $program['endtime'];
			$title        = $program['title'];
			$desc         = stripcslashes( $program['desc'] );		// シリアライズのエスケープ解除(改行を含むためエスケープしている)
			$eid          = $program['eid'];
			$category_id  = $program['category'];
			$genre2       = $program['genre2'];
			$genre3       = $program['genre3'];
			$sub_genre    = $program['sub_genre'];
			$sub_genre2   = $program['sub_genre2'];
			$sub_genre3   = $program['sub_genre3'];
			$video_type   = $program['video_type'];
			$audio_type   = $program['audio_type'];
			$multi_type   = $program['multi_type'];
			$program_disc = md5( $channel_disc . $eid . $starttime . $endtime );
			// プログラム登録
			$records = $pro_obj->fetch_array( 'program_disc', $program_disc );
			if( count( $records ) == 0 ){
				// 新規番組
				$rewrite_eid = 0;
				try {
					// 重複チェック 同時間帯にある番組
					$records = $pro_obj->fetch_array( 'channel_id', $channel_id, 'starttime<\''.$endtime.'\' AND endtime>\''.$starttime.'\' ORDER BY starttime ASC' );
					if( count( $records ) ){
						if( $category_id==15 && $sub_genre==14 )		// 不確定な補完した放送休止は除外
							continue;
						// 重複発生＝おそらく放映時間の変更
						foreach( $records as $rec ){
							// 自動録画予約された番組は放映時間変更と同時にいったん削除する
							$prg_st = toTimestamp( $rec['starttime'] );
							$prg_ed = toTimestamp( $rec['endtime'] );
							try {
								$prev_recs = DBRecord::createRecords( RESERVE_TBL, 'WHERE complete=0 AND program_id='.$rec['id'].' ORDER BY starttime DESC' );
								foreach( $prev_recs as $reserve ){
									$rev_st    = $reserve->starttime;
									$rec_start = toTimestamp( $rev_st );
									$rev_ed    = $reserve->endtime;
									$rec_end   = toTimestamp( $rev_ed );
									$prev_tuner = $reserve->tuner;
									$rev_ds    = '予約ID:'.$reserve->id.' '.$channel_disc.':T'.$prev_tuner.'-Ch'.$reserve->channel.' '.$rev_st.'『'.$reserve->title.'』';
									if( time() >= $rec_start - $settings->former_time ) {
										if( $rewrite_eid!==$eid && (int)$rec['eid']===$eid ){
											//録画中番組の予約DB更新($rec[]の方は以降に要素を使わないので放置)
											$wrt_set = array();
											$wrt_set['starttime']    = $starttime;
											$wrt_set['endtime']      = $endtime;
											$wrt_set['program_disc'] = $program_disc;
											$pro_obj->force_update( $rec['id'], $wrt_set );
											$rewrite_eid = $eid;
										}
										//録画延伸指示(チューナ依存 recpt1ctl対応が必要)
										if( time()<$rec_end && (
										( $type!=='EX' && 
										( ( USE_RECPT1 && $prev_tuner<TUNER_UNIT1 ) || ( $prev_tuner>=TUNER_UNIT1 && $OTHER_TUNERS_CHARA["$smf_type"][$prev_tuner-TUNER_UNIT1]['cntrl'] ) ) )
										|| ( $type==='EX' && $EX_TUNERS_CHARA[$prev_tuner]['cntrl'] )
										) ){
											$add_time = toTimestamp($endtime) - $prg_ed;
											$new_end  = toDatetime( $rec_end + $add_time );
											if( $add_time > 0 ){
												// 重複判定 延伸分で重複が増える場合は面倒なので(行程そのものを変更になる)延伸をしない
												$type_str = ( $type==='BS' || $type==='CS') ? '(type=\'BS\' OR type=\'CS\')' : 'type=\''.$type.'\'';
												$bat_rev  = DBRecord::createRecords( RESERVE_TBL, 'WHERE complete=0 AND '.$type_str.' AND tuner='.$prev_tuner.
													' AND channel_id!='.$channel_id.' AND endtime>=\''.$rev_ed.'\' AND starttime<=\''.$new_end.'\' ORDER BY starttime ASC' );
												if( count( $bat_rev ) ){
													// ギリギリまで延伸
													$tmp_end  = toTimestamp( $bat_rev[0]->starttime ) - $ed_tm_sft;
													$new_end  = toDatetime( $tmp_end );
													$add_time = $tmp_end - $rec_end;
												}
											}
											$ps = search_reccmd( $reserve->id );
											if( $ps !== FALSE ){
												exec( RECPT1_CTL.' --pid '.$ps->pid.' --extend '.$add_time );		//(PT1用)
												$reserve->endtime = $new_end;
												reclog( $rev_ds.'を('.$add_time.'秒)延長', EPGREC_WARN );
											}else
												reclog( $rev_ds.'の延長に失敗('.$add_time.'秒)', EPGREC_WARN );
										}else
											// すでに開始されている録画は無視する
											reclog( $rev_ds.'は録画開始後に時間変更が発生した可能性がある', EPGREC_WARN );
									}else{
										if( (int)($reserve->autorec) === 0 ){
											// 手動予約の退避
											$arr = array();
											$arr['eid']    = (int)$rec['eid'];
											$arr['old_st'] = $prg_st;
											$arr['st_tm']  = $rec_start;
											$arr['ed_tm']  = !$reserve->shortened ? $rec_end : $rec_end+$ed_tm_sft;
											$arr['ch_id']  = $channel_id;
											$arr['title']  = $reserve->title;
											$arr['desc']   = $reserve->description;
											$arr['cat_id'] = (int)$reserve->category_id;
											$arr['rs_md']  = (int)$reserve->mode;
											$arr['discon'] = (int)$reserve->discontinuity;
											$arr['rs_dt']  = (int)$reserve->dirty;
											$arr['prior']  = (int)$reserve->priority;
											array_push( $stk_rev, $arr );
										}else{
//											reclog( $rev_ds.'は時間変更の可能性があり予約取り消し' );
											$key_stk[$key_cnt++] = (int)$reserve->autorec;
										}
										Reservation::cancel( $reserve->id );
									}
								}
								unset( $reserve );
							}
							catch( Exception $e ) {
								// 無視
							}
							if( (int)$rec['eid'] !== $rewrite_eid ){
								// 番組削除
								if( !$skip_ch ){
									// 非表示CHはログを出さない
									reclog( 'EPG更新::時間重複した番組ID'.$rec['id'].': '.$channel_disc.'::'.$rec['eid'].' '.date('Y-m-d H:i-',$prg_st).date('H:i',$prg_ed).
																																'『'.$rec['title'].'』を削除', EPGREC_DEBUG );
									// 自動予約禁止フラグの保存
									if( !(boolean)$rec['autorec'] )
										$stk_auto[] = (int)$rec['eid'];
								}
								$pro_obj->force_delete( $rec['id'] );
							}
						}
						unset( $rec );
						// 
						if( ( $sch_sync['pre_check']===TRUE || $sch_sync['cnt']==-1 ) && strcmp( $starttime, $epg_time )<0 ){
							reclog( $channel_disc.'::'.$sch_sync['cnt'].' sch_key::'.$sch_key.'　'.$starttime.'　'.$epg_time, EPGREC_DEBUG );
							$sch_cnt = $sch_key;
							do{
								if( strcmp( $event_sch[$sch_cnt]['endtime'], $now_time ) >= 0 ){
									if( strcmp( $event_sch[$sch_cnt]['starttime'], $now_time ) <= 0 ){
										reclog( $event_sch[$sch_cnt]['starttime'].'　'.$event_sch[$sch_cnt]['endtime'], EPGREC_DEBUG );
										$sch_sync['cnt']       = $sch_cnt;
										$sch_sync['pre_check'] = FALSE;
										break;
									}else
									if( $sch_cnt == 0 ){
										reclog( $event_sch[$sch_cnt]['starttime'].'　'.$event_sch[$sch_cnt]['endtime'], EPGREC_DEBUG );
										$sch_sync['cnt']       = 0;
										$sch_sync['pre_check'] = FALSE;
										break;
									}
								}else
									break;
							}while( $sch_cnt-- > 0 );
						}
					}

					// 番組延伸による過去分の重複削除
					if( $eid !== -1 ){
						$reco = DBRecord::createRecords( PROGRAM_TBL, 'WHERE channel_id='.$channel_id.' AND eid='.$eid.' AND program_disc!=\''.$program_disc.'\'' );
						foreach( $reco as $del_pro ){
							$prg_st    = toTimestamp( $del_pro->starttime );
							$prev_recs = DBRecord::createRecords( RESERVE_TBL, 'WHERE complete=0 AND program_id='.$del_pro->id.' ORDER BY starttime DESC' );
							foreach( $prev_recs as $reserve ){
								if( (int)($reserve->autorec) === 0 ){
									// 手動予約の退避
									$arr = array();
									$arr['old_id'] = $reserve->id;
									$arr['eid']    = $eid;
									$arr['old_st'] = $prg_st;
									$arr['st_tm']  = toTimestamp( $reserve->starttime );
									$arr['ed_tm']  = !$reserve->shortened ? toTimestamp( $reserve->endtime ) : toTimestamp( $reserve->endtime )+$ed_tm_sft;
									$arr['ch_id']  = $channel_id;
									$arr['title']  = $reserve->title;
									$arr['desc']   = $reserve->description;
									$arr['cat_id'] = (int)$reserve->category_id;
									$arr['rs_md']  = (int)$reserve->mode;
									$arr['discon'] = (int)$reserve->discontinuity;
									$arr['rs_dt']  = (int)$reserve->dirty;
									$arr['prior']  = (int)$reserve->priority;
									array_push( $stk_rev, $arr );
								}else{
//									reclog( $rev_ds.'は時間変更の可能性があり予約取り消し' );
									$key_stk[$key_cnt++] = (int)$reserve->autorec;
								}
								Reservation::cancel( $reserve->id );
							}
							if( !$skip_ch ){		// 非表示CHはログを出さない
								reclog( 'EPG更新::時間重複した番組ID'.$del_pro->id.': '.$channel_disc.'::'.$eid.' '.date('Y-m-d H:i-',$prg_st).date('H:i',toTimestamp( $del_pro->endtime )).
										'『'.$del_pro->title.'』を削除', EPGREC_DEBUG  );
								// 自動予約禁止フラグの保存
								if( !(boolean)$del_pro->autorec )
									$stk_auto[] = $eid;
							}
							$del_pro->delete();
						}
					}

					//録画中番組以外を登録
					if( $eid !== $rewrite_eid ){
						$wrt_set = array();
						$wrt_set['channel_disc'] = $channel_disc;
						$wrt_set['channel_id']   = $channel_id;
						$wrt_set['channel']      = $channel_ch;
						$wrt_set['title']        = $title;
						$wrt_set['description']  = $desc;
						$wrt_set['category_id']  = $category_id;
						$wrt_set['eid']          = $eid;
						$wrt_set['starttime']    = $starttime;
						$wrt_set['endtime']      = $endtime;
						$wrt_set['program_disc'] = $program_disc;
						// 初期値のものを間引き
						if( $type !== 'GR' )
							$wrt_set['type'] = $type;
						if( $sub_genre !== 16 )
							$wrt_set['sub_genre'] = $sub_genre;
						if( $genre2 !== 0 )
							$wrt_set['genre2'] = $genre2;
						if( $sub_genre2 !== 16 )
							$wrt_set['sub_genre2'] = $sub_genre2;
						if( $genre3 !== 0 )
							$wrt_set['genre3'] = $genre3;
						if( $sub_genre3 !== 16 )
							$wrt_set['sub_genre3'] = $sub_genre3;
						if( $video_type !== 0 )
							$wrt_set['video_type'] = $video_type;
						if( $audio_type !== 0 )
							$wrt_set['audio_type'] = $audio_type;
						if( $multi_type !== 0 )
							$wrt_set['multi_type'] = $multi_type;
						$pro_obj->force_update( 0, $wrt_set );
						$ev_cnt++;
					}
				}
				catch( Exception $e ){
					reclog( 'EPG更新:: プログラムテーブルに問題が生じた模様<br>'.$e->getMessage() , EPGREC_ERROR);
					return -1;
				}
			}else{
				$rec = $records[0];
				// 番組内容更新
				$reserve_chg = FALSE;
				$wrt_set     = FALSE;
				if( strcmp( $rec['title'], $title ) != 0 ){
					$title_old        = $rec['title'];
					$wrt_set['title'] = $title;
					$reserve_chg      = TRUE;
				}else
					$title_old = $title;
				if( (int)($rec['category_id']) !== $category_id ){
					$wrt_set['category_id'] = $category_id;
					$reserve_chg            = TRUE;
				}
				if( (int)($rec['sub_genre']) !== $sub_genre ){
					$wrt_set['sub_genre'] = $sub_genre;
					$reserve_chg          = TRUE;
				}
				if( $category_id===15 && $sub_genre===14 )		// 補完した放送休止は除外
					$reserve_chg = FALSE;
				if( strcmp( $rec['description'] , $desc ) != 0 ){
					$wrt_set['description'] = $desc;
				}
				if( (int)($rec['genre2']) !== $genre2 ){
					$wrt_set['genre2'] = $genre2;
				}
				if( (int)($rec['sub_genre2']) !== $sub_genre2 ){
					$wrt_set['sub_genre2'] = $sub_genre2;
				}
				if( (int)($rec['genre3']) !== $genre3 ){
					$wrt_set['genre3'] = $genre3;
				}
				if( (int)($rec['sub_genre3']) !== $sub_genre3 ){
					$wrt_set['sub_genre3'] = $sub_genre3;
				}
				if( (int)($rec['video_type']) !== $video_type ){
					$wrt_set['video_type'] = $video_type;
					$reserve_chg           = TRUE;
				}
				if( (int)($rec['audio_type']) !== $audio_type ){
					$wrt_set['audio_type'] = $audio_type;
					$reserve_chg           = TRUE;
				}
				if( (int)($rec['multi_type']) !== $multi_type ){
					$wrt_set['multi_type'] = $multi_type;
					$reserve_chg           = TRUE;
				}
				if( $wrt_set !== FALSE ){
					$pro_obj->force_update( $rec['id'], $wrt_set );
					if( $reserve_chg ){
						try {
							$prev_recs = DBRecord::createRecords( RESERVE_TBL, 'WHERE complete=0 AND program_id='.$rec['id'].' ORDER BY starttime ASC' );
							foreach( $prev_recs as $reserve ){
								if( !((boolean)$reserve->dirty) && time() < toTimestamp( $reserve->starttime ) - $ed_tm_sft ){
									// dirtyが立っていない録画予約であるなら
									if( $sch_obtain && (int)($reserve->autorec) && strcmp( $reserve->title, $title_old )==0 ){
										//自動キーワード再予約のためキャンセル
										$key_stk[$key_cnt++] = (int)$reserve->autorec;
										Reservation::cancel( $reserve->id );
									}else{
										$reserve->title       = $title;
										$reserve->description = $desc;
										reclog( 'EPG更新:: 予約ID'.$reserve->id.'のEPG情報が更新された' );
										$reserve->update();
									}
								}
							}
							unset( $reserve );
						}
						catch( Exception $e ) {
							// 無視する
						}
					}
				}
			}
		}
		unset( $program );
		if( $ev_cnt !== $ev_lmt )
			$first_epg = FALSE;

		// 自動予約禁止フラグの復帰
		foreach( $stk_auto as $can_eid ){
			$prg = DBRecord::createRecords( PROGRAM_TBL, 'WHERE channel_id='.$channel_id.' AND eid='.$can_eid );
			if( count( $prg ) ){
				$prg[0]->autorec = 0;
				$prg[0]->update();
			}
		}
		unset( $stk_auto );

		//手動予約のタイムシフト再予約
		foreach( $stk_rev as $post ){
			try{
				$prg = DBRecord::createRecords( PROGRAM_TBL, 'WHERE channel_id='.$post['ch_id'].' AND eid='.$post['eid'] );
				if( count( $prg ) ){
					if( $post['ch_id'] == (int)($prg[0]->channel_id) ){
						$add_time = toTimestamp($prg[0]->starttime) - $post['old_st'];
						$st_tm    = toDatetime( $post['st_tm'] + $add_time );
						$ed_tm    = toDatetime( $post['ed_tm'] + $add_time );
						try{
							$rval = Reservation::custom( $st_tm, $ed_tm, $post['ch_id'], $post['title'], $post['desc'], $post['cat_id'], (int)$prg[0]->id,
												0, $post['rs_md'], $post['discon'], $post['rs_dt'], $post['prior'] );
							// 手動予約のトラコン設定の予約ID修正
							list( , , $rec_id, ) = explode( ':', $rval );
							$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$post['old_id'] );
							foreach( $tran_ex as $tran_set ){
								$tran_set->type_no = $rec_id;
								$tran_set->update();
							}
							continue;
						}catch( Exception $e ){
							// 手動予約のトラコン設定削除
							$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$post['old_id'] );
							foreach( $tran_ex as $tran_set )
								$tran_set->delete();
							reclog( '再予約:: '.$e->getMessage() , EPGREC_ERROR);
						}
					}else
						reclog( '再予約:: ch_id不一致('.$post['ch_id'].'::'.$prg[0]->channel_id.')' , EPGREC_ERROR);
				}
			}catch( Exception $e ){
				reclog( '再予約:: eid不一致('.$post['eid'].')' , EPGREC_ERROR);
			}
			// EPGから消えたのでprogram_id==0の時刻指定予約として再予約(タイムシフト再予約をした場合は、元の時間が失われる)
			$st_tm = toDatetime( $post['st_tm'] );
			$ed_tm = toDatetime( $post['ed_tm'] );
			try{
				$rval = Reservation::custom( $st_tm, $ed_tm, $post['ch_id'], 'X_'.$post['title'], $post['desc'], $post['cat_id'], 0,
											0, $post['rs_md'], $post['discon'], $post['rs_dt'], $post['prior'] );
				// 手動予約のトラコン設定の予約ID修正
				list( , , $rec_id, ) = explode( ':', $rval );
				$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$post['old_id'] );
				foreach( $tran_ex as $tran_set ){
					$tran_set->type_no = $rec_id;
					$tran_set->update();
				}
			}catch( Exception $e ){
				// 手動予約のトラコン設定削除
				$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$post['old_id'] );
				foreach( $tran_ex as $tran_set )
					$tran_set->delete();
				reclog( '再再予約:: '.$e->getMessage() , EPGREC_ERROR);
			}
		}
		unset( $stk_rev );
		unset( $post );

		// 番組延伸による番組構成の乱れ修正(番組構成修正スクリプト起動)
		if( $sch_sync['cnt']!=-1 && $sch_sync['pre_check']===FALSE ){
			$event = $event_sch[$sch_sync['cnt']+1];
			if( $type==='GR' || !$skip_ch ){
				$disc = $type==='GR' ? strtok( $channel_disc, '_' ) : $channel_disc;
				if( array_key_exists( "$disc", $map ) && $map["$disc"]!='NC' ){
					$resq      = INSTALL_PATH.'/repairEpg.php '.$channel_id;
					$ps_output = shell_exec( PS_CMD );
					if( strpos( $ps_output, $resq ) === FALSE ){
						reclog( '番組構成修正スクリプト起動 '.$resq.'['.$type.':'.$map["$disc"].':'.$channel_rec->sid.']', EPGREC_DEBUG );
						@exec( $resq.' '.toTimestamp( $event['starttime']).' '.toTimestamp( $event['endtime']).' >/dev/null 2>&1 &' );
					}else
						return (string)toTimestamp( $event['starttime'] );
				}
			}
		}
		unset( $event_sch );
		unset( $event_pf );
	}

	// 自動キーワ－ド再予約
	// 残りのEPG処理に時間がかかる場合に直近番組が始まってしまい再予約に失敗する対策
//	doKeywordReservation( $type, $shm_id );
	if( $key_cnt ){
		$sem_key = sem_get_surely( SEM_KW_START );
		$result  = array_unique( $key_stk, SORT_NUMERIC );		// keyword IDの重複解消
		foreach( $result as $keyword_id ){
			$rec = new Keyword( 'id', $keyword_id );
			$rec->reservation( $type, $shm_id, $sem_key );
		}
	}

	// 廃止チャンネルの自動削除
	if( !$single_ch && !$first_epg && $type!=='GR' && HIDE_CH_EPG_GET && EXTINCT_CH_AUTO_DELETE ){
		try{
			$chs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\''.$type.'\'' );
			foreach( $chs as $ch ){
				$disc = $ch->channel_disc;
				// 受信データにチャンネルが存在するか?
				foreach( $chs_para as $ch_para ){
					if( $ch_para['id'] === $disc )
						continue 2;
				}
				// DBにチャンネルの番組が存在するか?
				$sql_cmd = 'WHERE channel_id='.$ch->id;
				if( DBRecord::countRecords( PROGRAM_TBL , $sql_cmd ) == 0 ){
					// 廃止チャンネル発見
					$msg = '(id:'.$ch->id.' '.$disc.' ' .$ch->name.' 物理CH:'.$ch->channel.')';
					if( DBRecord::countRecords( RESERVE_TBL , $sql_cmd ) == 0 ){
						// 廃止チャンネルの削除
						$key_point = array_search( $disc, array_keys( $map ) );
						if( $key_point!==FALSE && $map["$disc"]!=='NC' ){
							// xx_channel.phpの編集
							$f_nm  = INSTALL_PATH.'/settings/'.strtolower($type).'_channel.php';
							$st_ch = file( $f_nm, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
							array_splice( $st_ch, $key_point+3, 1 );
							$fp = fopen( $f_nm, 'w' );
							foreach( $st_ch as $ch_str )
								fwrite( $fp, $ch_str."\n" );
							fclose( $fp );
						}
						reclog( 'EPG更新::廃止チャンネルを自動削除しました。'.$msg, EPGREC_WARN );
						$ch->delete();
					}else
						reclog( 'EPG更新::廃止チャンネルが存在します。録画記録が存在するため自動削除を行いませんでした。'.$msg, EPGREC_WARN );
				}
			}
		}catch( Exception $e ){
		}
	}

	// Programme取得完了
	return 0;
}
?>
