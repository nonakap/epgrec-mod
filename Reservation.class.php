<?php
//include_once( INSTALL_PATH . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );


// 予約クラス

class Reservation {

	public static function simple( $program_id , $autorec = 0, $mode = 0, $discontinuity=0 ) {
		$rval = 0;
		try {
			$prec = new DBRecord( PROGRAM_TBL, 'id', $program_id );
			
			$rval = self::custom(
				$prec->starttime,
				$prec->endtime,
				$prec->channel_id,
				$prec->title,
				$prec->description,
				$prec->category_id,
				$program_id,
				$autorec,
				$mode,
				$discontinuity );
				
		}
		catch( Exception $e ) {
			throw $e;
		}
		return $rval.( strpos( $prec->description, '【終】' )!==FALSE ? ':1' : ':0' );
	}


	public static function custom(
		$starttime,				// 開始時間Datetime型
		$endtime,				// 終了時間Datetime型
		$channel_id,			// チャンネルID
		$title = 'none',		// タイトル
		$description = 'none',	// 概要
		$category_id = 0,		// カテゴリID
		$program_id = 0,		// 番組ID
		$autorec = 0,			// 自動録画ID
		$mode = 0,				// 録画モード
		$discontinuity = 0,		// 隣接禁止フラグ
		$dirty = 0,				// ダーティフラグ
		$man_priority = MANUAL_REV_PRIORITY	// 優先度
	) {
		$settings = Settings::factory();
		$crec = new DBRecord( CHANNEL_TBL, 'id', $channel_id );
		// 時間を計算
		$start_time = toTimestamp( $starttime );
		$end_time   = toTimestamp( $endtime );
		$job = 0;
		try {
			if( $autorec ){
				$keyword  = new DBRecord( KEYWORD_TBL, 'id', $autorec );
				$priority = (int)$keyword->priority;
				$overlap  = (boolean)$keyword->overlap;

				// 同一番組予約チェック
				if( $program_id ){
					if( !$overlap )
						$num = DBRecord::countRecords( RESERVE_TBL, "WHERE program_id = '".$program_id."'" );
					else
						$num = DBRecord::countRecords( RESERVE_TBL, "WHERE program_id = '".$program_id."' AND ( overlap = '0' OR autorec = '".$autorec."' ) AND priority >= '".$priority."'" );
					if( $num ) {
						throw new Exception('同一の番組が録画予約されています');
					}
				}

				$duration = $end_time - $start_time;
				if( (int)$keyword->criterion_dura && $duration!=(int)$keyword->criterion_dura ){
					if( (int)$keyword->criterion_dura > 1 )
						reclog( '<a href="programTable.php?keyword_id='.$autorec.'">自動キーワードID:'.$autorec.'</a> にヒットした'.$crec->channel_disc.'-Ch'.$crec->channel.
								' <a href="index.php?type='.$crec->type.'&length='.$settings->program_length.'&time='.date( 'YmdH', $start_time ).'">'.$starttime.
								'</a>『'.htmlspecialchars($title).'』は、収録時間が'.
								($keyword->criterion_dura/60).'分間から'.($duration/60).'分間に変動しています。', EPGREC_WARN );
					$keyword->criterion_dura = $duration;
					$keyword->update();
				}
				$tmp_start = $start_time + (int)$keyword->sft_start;
				$tmp_end   = $end_time + (int)$keyword->sft_end;
/*
				if( $tmp_start>=$end_time || $tmp_end<=$start_time || $tmp_start>=$tmp_end )
					throw new Exception( '時刻シフト量が異常なため、開始時刻が終了時刻以降に指定されています' );
				else{
					$start_time = $tmp_start;
					$end_time   = $tmp_end;
				}
*/
				if( $start_time<$tmp_end && $tmp_start<$end_time ){
					$start_time = $tmp_start;
					$end_time   = $tmp_end;
				}else{
					// 枠内の編成変更対策(2番組から1番組のみに対応)
					$half_time = $duration / 2;
					if( strpos( $title, '%TL_SB' )!==FALSE && $tmp_start<($end_time-$half_time) && $start_time<($tmp_end+$half_time) ){
						$start_time = $tmp_start;
						$end_time   = $tmp_end + $half_time;
					}else
						throw new Exception( '時刻シフト量が異常なため、開始時刻が終了時刻以降に指定されています' );
				}
			}else{
				$priority = (int)$man_priority;
				$overlap  = FALSE;
			}
			if( $start_time >= $end_time )
				throw new Exception( '開始時刻が終了時刻以降に指定されています' );

			$former_time     = (int)$settings->former_time;
			$extra_time      = (int)$settings->extra_time;
			$rec_switch_time = (int)$settings->rec_switch_time;
			$ed_tm_sft       = $former_time + $rec_switch_time;
			$ed_tm_sft_chk   = $ed_tm_sft + $extra_time;
			//チューナ仕様取得
			if( $crec->type === 'GR' ){
				$tuners   = (int)($settings->gr_tuners);
				$type_str = "type = 'GR'";
				$smf_type = 'GR';
			}else
			if( $crec->type === 'EX' ){
				$tuners   = EXTRA_TUNERS;
				$type_str = "type = 'EX'";
				$smf_type = 'EX';
			}else{
				$tuners   = (int)($settings->bs_tuners);
				$type_str = "(type = 'BS' OR type = 'CS')";
				$smf_type = 'BS';
			}
			$stt_str  = toDatetime( $start_time-$ed_tm_sft_chk );
			$end_str  = toDatetime( $end_time+$ed_tm_sft_chk );
			$battings = DBRecord::countRecords( RESERVE_TBL, "WHERE complete = '0' AND ".$type_str.
															" AND starttime <= '".$end_str.
															"' AND endtime >= '".$stt_str."'" );		//重複数取得
			if( $battings > 0 ){
				//重複
				//予約群 先頭取得
				$prev_trecs = array();
				while( 1 ){
					try{
						$sql_cmd = "WHERE complete = '0' AND ".$type_str.
															" AND starttime < '".$stt_str.
															"' AND endtime >= '".$stt_str."'";
						$cnt = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
						if( $cnt === 0 )
							break;
						$prev_trecs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd.' ORDER BY starttime ASC' );
						if( $prev_trecs == null )
							break;
						$stt_str = toDatetime( toTimestamp( $prev_trecs[0]->starttime )-$ed_tm_sft_chk );
					}catch( Exception $e ){
						break;
					}
				}
				//予約群 最後尾取得
				while( 1 ){
					try{
						$sql_cmd = "WHERE complete = '0' AND ".$type_str.
															" AND starttime <= '".$end_str.
															"' AND endtime > '".$end_str."'";
						$cnt = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
						if( $cnt === 0 )
							break;
						$prev_trecs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd.' ORDER BY endtime DESC' );
						if( $prev_trecs == null )
							break;
						$end_str = toDatetime( toTimestamp( $prev_trecs[0]->endtime )+$ed_tm_sft_chk );
					}catch( Exception $e ){
						break;
					}
				}

				//重複予約配列取得
				$sql_cmd = "WHERE complete = '0' AND ".$type_str.
															" AND starttime >= '".$stt_str.
															"' AND endtime <= '".$end_str."' ORDER BY starttime ASC, endtime DESC";
				$prev_trecs = DBRecord::createRecords( RESERVE_TBL, $sql_cmd );
				// 予約修正に必要な情報を取り出す
				$trecs = array();
				for( $cnt=0; $cnt<count($prev_trecs) ; $cnt++ ){
					$trecs[$cnt]['id']            = (int)$prev_trecs[$cnt]->id;
					$trecs[$cnt]['program_id']    = (int)$prev_trecs[$cnt]->program_id;
					$trecs[$cnt]['channel_id']    = (int)$prev_trecs[$cnt]->channel_id;
					$trecs[$cnt]['title']         = $prev_trecs[$cnt]->title;
					$trecs[$cnt]['description']   = $prev_trecs[$cnt]->description;
					$trecs[$cnt]['channel']       = (int)$prev_trecs[$cnt]->channel;
					$trecs[$cnt]['category_id']   = (int)$prev_trecs[$cnt]->category_id;
					$trecs[$cnt]['start_time']    = toTimestamp( $prev_trecs[$cnt]->starttime );
					$trecs[$cnt]['end_time']      = toTimestamp( $prev_trecs[$cnt]->endtime );
					$trecs[$cnt]['shortened']     = (boolean)$prev_trecs[$cnt]->shortened;
					$trecs[$cnt]['end_time_sort'] = $trecs[$cnt]['shortened'] ? $trecs[$cnt]['end_time']+$ed_tm_sft : $trecs[$cnt]['end_time'];
					$trecs[$cnt]['autorec']       = (int)$prev_trecs[$cnt]->autorec;
					$trecs[$cnt]['path']          = $prev_trecs[$cnt]->path;
					$trecs[$cnt]['mode']          = (int)$prev_trecs[$cnt]->mode;
					$trecs[$cnt]['dirty']         = (int)$prev_trecs[$cnt]->dirty;
					$trecs[$cnt]['tuner']         = (int)$prev_trecs[$cnt]->tuner;
					$trecs[$cnt]['priority']      = (int)$prev_trecs[$cnt]->priority;
					$trecs[$cnt]['overlap']       = (boolean)$prev_trecs[$cnt]->overlap;
					$trecs[$cnt]['discontinuity'] = (int)$prev_trecs[$cnt]->discontinuity;
					$trecs[$cnt]['status']        = 1;
				}
				//新規予約を既予約配列に追加
				$trecs[$cnt]['id']            = 0;
				$trecs[$cnt]['program_id']    = $program_id;
				$trecs[$cnt]['channel_id']    = (int)$crec->id;
				$trecs[$cnt]['title']         = $title;
				$trecs[$cnt]['description']   = $description;
				$trecs[$cnt]['channel']       = (int)$crec->channel;
				$trecs[$cnt]['category_id']   = $category_id;
				$trecs[$cnt]['start_time']    = $start_time;
				$trecs[$cnt]['end_time']      = $end_time;
				$trecs[$cnt]['end_time_sort'] = $end_time;
				$trecs[$cnt]['shortened']     = FALSE;
				$trecs[$cnt]['autorec']       = $autorec;
				$trecs[$cnt]['path']          = '';
				$trecs[$cnt]['mode']          = $mode;
				$trecs[$cnt]['dirty']         = $dirty;
				$trecs[$cnt]['tuner']         = -1;
				$trecs[$cnt]['priority']      = $priority;
				$trecs[$cnt]['overlap']       = $overlap;
				$trecs[$cnt]['discontinuity'] = $discontinuity;
				$trecs[$cnt]['status']        = 1;

				//全重複予約をソート
				foreach( $trecs as $key => $row ){
					$volume[$key]  = $row['start_time'];
					$edition[$key] = $row['end_time_sort'];
				}
				array_multisort( $volume, SORT_ASC, $edition, SORT_ASC, $trecs );

RETRY:;
				//予約配列参照用配列の初期化
				$r_cnt = 0;
				foreach( $trecs as $key => $row ){
					if( $row['status'] )
						$t_tree[0][$r_cnt++] = $key;
				}
				// 重複予約をチューナー毎に分配
				for( $t_cnt=0; $t_cnt<$tuners ; $t_cnt++ ){
					$b_rev = 0;
					$n_0 = 1;
					$n_1 = 0;
					if( isset( $t_tree[$t_cnt] ) )
					while( $n_0 < count($t_tree[$t_cnt]) ){
//file_put_contents( '/tmp/debug.txt', "[".count($t_tree[$t_cnt])."-".$n_0."]\n", FILE_APPEND );
						$af_st     = $trecs[$t_tree[$t_cnt][$n_0]]['start_time'];
//						$bf_st     = $trecs[$t_tree[$t_cnt][$b_rev]]['start_time'];
//						$bf_org_ed = $trecs[$t_tree[$t_cnt][$b_rev]]['end_time'];
						$bf_ed     = $trecs[$t_tree[$t_cnt][$b_rev]]['end_time_sort'];
						$variation = $af_st - $bf_ed;
						if( $variation<0 || ( ( $settings->force_cont_rec!=1 || $trecs[$t_tree[$t_cnt][$b_rev]]['discontinuity']==1 ) && $variation<$ed_tm_sft_chk ) ){
							//完全重複 隣接禁止時もここ
							$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$n_0];
							$n_1++;
//file_put_contents( '/tmp/debug.txt', ' '.count($t_tree[$t_cnt]).">", FILE_APPEND );
							array_splice( $t_tree[$t_cnt], $n_0, 1 );
//file_put_contents( '/tmp/debug.txt', count($t_tree[$t_cnt])."\n", FILE_APPEND );
						}else
						if( $variation < $ed_tm_sft_chk ){
							//隣接重複
							// 重複数算出
							$t_ovlp = 0;
//file_put_contents( '/tmp/debug.txt', ' $t_ovlp ', FILE_APPEND );
							if( isset( $t_tree[$t_cnt+1] ) ){
								foreach( $t_tree[$t_cnt+1] as $trunk ){
									if( $trecs[$trunk]['start_time']<=$bf_ed && $trecs[$trunk]['end_time_sort']>=$bf_ed )
										$t_ovlp++;
								}
//file_put_contents( '/tmp/debug.txt', $t_ovlp." -> ", FILE_APPEND );
							}
							$s_ch = -1;
							for( $br_lmt=$n_0; $br_lmt<count($t_tree[$t_cnt]); $br_lmt++ ){
								//同じ開始時間の物をカウント
								$variation = $trecs[$t_tree[$t_cnt][$br_lmt]]['start_time'] - $bf_ed;
								if( 0<=$variation && $variation<$ed_tm_sft_chk ){
									$t_ovlp++;
									//同じCh
									if( $trecs[$t_tree[$t_cnt][$b_rev]]['channel_id'] === $trecs[$t_tree[$t_cnt][$br_lmt]]['channel_id'] )
										$s_ch = $br_lmt;
								}else
									break;
							}
//file_put_contents( '/tmp/debug.txt', $t_ovlp."\n", FILE_APPEND );

							if( $t_ovlp<=$tuners-$t_cnt || ( $settings->force_cont_rec==1 && $trecs[$t_tree[$t_cnt][$b_rev]]['discontinuity']!=1 ) ){
//file_put_contents( '/tmp/debug.txt', ' '.count($t_tree[$t_cnt]).">>\n", FILE_APPEND );
								if( $t_ovlp<=TUNER_UNIT1-1-$t_cnt && $t_ovlp <= $tuners-1-$t_cnt ){
									//(使い勝手の良い)チューナに余裕あり
									for( $cc=$n_0; $cc<$br_lmt; $cc++ ){
										$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
										$n_1++;
									}
//file_put_contents( '/tmp/debug.txt', " array1-(".($br_lmt-$n_0).")\n", FILE_APPEND );
									array_splice( $t_tree[$t_cnt], $n_0, $br_lmt-$n_0 );
								}else{
									//チューナに余裕なし
									if( $s_ch !== -1 ){
										//同じCh同士を隣接 いらんかな？
										for( $cc=$n_0; $cc<$s_ch; $cc++ ){
											$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
											$n_1++;
										}
										for( $cc=$s_ch+1; $cc<$br_lmt; $cc++ ){
											$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
											$n_1++;
										}
//file_put_contents( '/tmp/debug.txt', " array2-1-(".$t_ovlp." ".$br_lmt." ".$s_ch." ".$n_0.")\n", FILE_APPEND );
//file_put_contents( '/tmp/debug.txt', " array2-2-(".($br_lmt-($s_ch+1)).")\n", FILE_APPEND );
										if( $br_lmt-($s_ch+1) > 0 )
											array_splice( $t_tree[$t_cnt], $s_ch+1, $br_lmt-($s_ch+1) );
//file_put_contents( '/tmp/debug.txt', " array2-3-(".($s_ch-$n_0).")\n", FILE_APPEND );
										if( $s_ch-$n_0 > 0 )
											array_splice( $t_tree[$t_cnt], $n_0, $s_ch-$n_0 );
										$b_rev++;
										$n_0++;
									}else{
										//頭の予約を隣接
										$b_rev++;
										$n_0++;
										for( $cc=$n_0; $cc<$br_lmt; $cc++ ){
											$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
											$n_1++;
										}
//file_put_contents( '/tmp/debug.txt', " array3A-(".($br_lmt-$n_0).")\n", FILE_APPEND );
										if( $br_lmt-$n_0 > 0 )
											array_splice( $t_tree[$t_cnt], $n_0, $br_lmt-$n_0 );
									}
								}
							}else
								goto PRIORITY_CHECK;
//file_put_contents( '/tmp/debug.txt', "  >>".count($t_tree[$t_cnt])."\n", FILE_APPEND );
						}else{
							//隣接なし
							$b_rev++;
							$n_0++;
//file_put_contents( '/tmp/debug.txt', "  <<<".count($t_tree[$t_cnt]).">>>\n", FILE_APPEND );
						}
//file_put_contents( '/tmp/debug.txt', " [[".count($t_tree[$t_cnt])."-".$n_0."]]\n", FILE_APPEND );
					}
				}
//file_put_contents( '/tmp/debug.txt', "分配完了\n\n", FILE_APPEND );
//var_dump($t_tree);
				//重複解消不可処理
				if( count($t_tree) > $tuners ){
PRIORITY_CHECK:
					if( $autorec ){
						//優先度判定
						$sql_cmd = "WHERE complete = '0' AND ".$type_str." AND priority < '".$priority.
															"' AND starttime <= '".toDatetime($end_time).
															"' AND endtime >= '".toDatetime($start_time)."'";
						$pri_lmt = DBRecord::countRecords( RESERVE_TBL, $sql_cmd );
						if( $pri_lmt ){
							$pri_ret = DBRecord::createRecords( RESERVE_TBL, $sql_cmd.' ORDER BY priority ASC' );
							for( $cnt=$pri_c=0; $cnt<count($trecs) ; $cnt++ )
								if( $trecs[$cnt]['id'] === (int)$pri_ret[$pri_c]->id ){
									if( $trecs[$cnt]['status'] ){
										//優先度の低い予約を仮無効化
										$trecs[$cnt]['status'] = 0;
										unset( $t_tree );
//file_put_contents( '/tmp/debug.txt', "RETRY\n\n", FILE_APPEND );
										goto RETRY;
									}
									if( ++$pri_c === $pri_lmt )
										break;
								}
						}
						//自動予約禁止
						$event = new DBRecord( PROGRAM_TBL, 'id', $program_id );
						if( (int)$event->key_id!==0 && (int)$event->key_id!==$autorec && DBRecord::countRecords( KEYWORD_TBL, 'WHERE id = '.$event->key_id )!==0 )
							goto LOG_THROW;
						$event->key_id = $autorec;
						$event->update();
						reclog( '<a href="programTable.php?keyword_id='.$autorec.'">自動キーワードID:'.$autorec.
								' </a>にヒットした'.$crec->channel_disc.'-Ch'.$crec->channel.
								' <a href="index.php?type='.$crec->type.'&length='.$settings->program_length.'&time='.date( 'YmdH', toTimestamp( $starttime ) ).'">'.$starttime.
								'</a>『'.htmlspecialchars($title).'』は重複により予約できません', EPGREC_WARN );
LOG_THROW:;
					}
					throw new Exception( '重複により予約できません' );
				}
// file_put_contents( '/tmp/debug.txt', "重複解消\n", FILE_APPEND );
				//チューナ番号の解決
				$t_blnk        = array_fill( 0, $tuners, 0 );
				$t_num         = array_fill( 0, $tuners, -1 );
				$tuner_no      = array_fill( 0, $tuners, -1 );
				$tuner_cnt     = array_fill( 0, $tuners, -1 );
				$tree_lmt      = count( $t_tree );
				$division_mode = 0;
				//録画中のチューナ番号取得
				for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
					if( $trecs[$t_tree[$tree_cnt][0]]['id'] !== 0 ){
						$prev_start_time = $trecs[$t_tree[$tree_cnt][0]]['start_time'] - $former_time;
						if( time() >= $prev_start_time ){
							$t_num[$tree_cnt]          = $trecs[$t_tree[$tree_cnt][0]]['tuner'];
							$t_blnk[$t_num[$tree_cnt]] = 2;
							$division_mode             = 1;
						}
					}
				//チューナー毎の予約配列中で多数使用しているチューナー番号を採用・重複時は早い者勝ち
				for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
					if( $t_num[$tree_cnt] === -1 ){
						$stk = array_fill( 0, $tuners, 0 );
						//各チューナーの予約数集計
						for( $rv_cnt=0; $rv_cnt<count($t_tree[$tree_cnt]); $rv_cnt++ ){
							$tmp_tuner = $trecs[$t_tree[$tree_cnt][$rv_cnt]]['tuner'];
							if( $tmp_tuner !== -1 )
								$stk[$tmp_tuner]++;
						}
						//予約数最多のチューナー番号を選択
						for( $tuner_c=0; $tuner_c<$tuners; $tuner_c++ )
							if( $t_blnk[$tuner_c]!==2 && $stk[$tuner_c] > $tuner_cnt[$tree_cnt] ){
								$tuner_no[$tree_cnt]  = $tuner_c;
								$tuner_cnt[$tree_cnt] = $stk[$tuner_c];
							}
					}
				//指定チューナー番号を最多指定している予約配列に仮決定
				for( $tuner_c=0; $tuner_c<$tuners; $tuner_c++ )
					if( $t_blnk[$tuner_c] !== 2 ){
						$tmp_cnt  = 0;
						$tmp_tree = -1;
						for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
							if( $tuner_no[$tree_cnt]===$tuner_c && $tuner_cnt[$tree_cnt]>$tmp_cnt ){
								$tmp_cnt  = $tuner_cnt[$tree_cnt];
								$tmp_tree = $tree_cnt;
							}
						if( $tmp_tree !== -1 ){
							$t_num[$tmp_tree] = $tuner_c;
							$t_blnk[$tuner_c] = 1;
						}
					}
				for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
					//未決定な配列への空番号割り当て
					if( $t_num[$tree_cnt] === -1 ){
						for( $tuner_c=0; $tuner_c<$tuners; $tuner_c++ )
							if( !$t_blnk[$tuner_c] ){
								$t_num[$tree_cnt] = $tuner_c;
								$t_blnk[$tuner_c] = 1;
								break;
							}
					}else
						//前に空がありハード的にチューナーが変更される場合のみチューナー番号変更
						if( $t_num[$tree_cnt]>=TUNER_UNIT1 && $t_num[$tree_cnt]>=$tree_lmt )
							for( $tuner_c=0; $tuner_c<TUNER_UNIT1; $tuner_c++ )
								if( !$t_blnk[$tuner_c] ){
									if( $t_blnk[$t_num[$tree_cnt]] !== 2 ){
										$t_blnk[$t_num[$tree_cnt]] = 0;
										$t_num[$tree_cnt]          = $tuner_c;
										$t_blnk[$tuner_c]          = 1;
									}else
										//録画中の予約以外を別配列に移動
										if( $tree_lmt < $tuners ){
											$t_tree[$tree_lmt] = array_slice( $t_tree[$tree_cnt], 1 );
											array_splice( $t_tree[$tree_cnt], 1 );
											$t_num[$tree_lmt++] = $tuner_c;
											$t_blnk[$tuner_c]   = 1;
										}
									break;
								}
				//優先度判定で削除になった予約をキャンセル
				foreach( $trecs as $sel )
					if( !$sel['status'] ){
						self::cancel( $sel['id'] );
					}
				$tuner_chg = 0;
				//新規予約・隣接解消再予約等 隣接禁止については分配時に解決済
				for( $t_cnt=0; $t_cnt<$tuners ; $t_cnt++ ){
// file_put_contents( '/tmp/debug.txt', ($t_cnt+1)."(".count($t_tree[$t_cnt]).")\n", FILE_APPEND );
//var_dump($t_tree[$t_cnt]);
					if( isset( $t_tree[$t_cnt] ) )
					for( $n_0=0,$n_lmt=count($t_tree[$t_cnt]); $n_0<$n_lmt ; $n_0++ ){
						// 予約修正に必要な情報を取り出す
						$prev_id            = $trecs[$t_tree[$t_cnt][$n_0]]['id'];
						$prev_program_id    = $trecs[$t_tree[$t_cnt][$n_0]]['program_id'];
						$prev_channel_id    = $trecs[$t_tree[$t_cnt][$n_0]]['channel_id'];
						$prev_title         = $trecs[$t_tree[$t_cnt][$n_0]]['title'];
						$prev_description   = $trecs[$t_tree[$t_cnt][$n_0]]['description'];
						$prev_channel       = $trecs[$t_tree[$t_cnt][$n_0]]['channel'];
						$prev_category_id   = $trecs[$t_tree[$t_cnt][$n_0]]['category_id'];
						$prev_start_time    = $trecs[$t_tree[$t_cnt][$n_0]]['start_time'];
						$prev_end_time      = $trecs[$t_tree[$t_cnt][$n_0]]['end_time'];
						$prev_shortened     = $trecs[$t_tree[$t_cnt][$n_0]]['shortened'];
						$prev_autorec       = $trecs[$t_tree[$t_cnt][$n_0]]['autorec'];
						$prev_path          = $trecs[$t_tree[$t_cnt][$n_0]]['path'];
						$prev_mode          = $trecs[$t_tree[$t_cnt][$n_0]]['mode'];
						$prev_dirty         = $trecs[$t_tree[$t_cnt][$n_0]]['dirty'];
						$prev_tuner         = $trecs[$t_tree[$t_cnt][$n_0]]['tuner'];
						$prev_priority      = $trecs[$t_tree[$t_cnt][$n_0]]['priority'];
						$prev_overlap       = $trecs[$t_tree[$t_cnt][$n_0]]['overlap'];
						$prev_discontinuity = $trecs[$t_tree[$t_cnt][$n_0]]['discontinuity'];
						if( $n_0 < $n_lmt-1 )
							$next_start_time = $trecs[$t_tree[$t_cnt][$n_0+1]]['start_time'];
						if( $prev_id === 0 ){
							//新規予約
							if( $n_0<$n_lmt-1 && $prev_end_time+$ed_tm_sft_chk>$next_start_time ){
								$prev_end_time -= $ed_tm_sft;
								$prev_shortened = TRUE;
							}
							try {
								$job = self::at_set( 
									$prev_start_time,			// 開始時間Datetime型
									$prev_end_time,				// 終了時間Datetime型
									$prev_channel_id,			// チャンネルID
									$prev_title,				// タイトル
									$prev_description,			// 概要
									$prev_category_id,			// カテゴリID
									$prev_program_id,			// 番組ID
									$prev_autorec,				// 自動録画
									$prev_mode,
									$prev_dirty,
									$t_num[$t_cnt],				// チューナ
									$prev_priority,
									$prev_overlap,
									$prev_discontinuity,
									$prev_shortened
									);
							}
							catch( Exception $e ) {
								throw new Exception( '新規予約できません' );
							}
							continue;
						}else
							if( time() < $prev_start_time-$former_time ){
								//録画開始前
								if( $prev_tuner !== $t_num[$t_cnt] )
									$tuner_chg = 1;
								$shortened_clear = FALSE;
								if( $n_0 < $n_lmt-1 ){
									if( !$prev_shortened ){
										if( $prev_end_time > $next_start_time-$ed_tm_sft_chk ){
											//隣接解消再予約
											$prev_end_time -= $ed_tm_sft;
											$prev_shortened = TRUE;
											try {
												// いったん予約取り消し
												self::cancel( $prev_id );
												// 再予約
												self::at_set( 
													$prev_start_time,			// 開始時間Datetime型
													$prev_end_time,				// 終了時間Datetime型
													$prev_channel_id,			// チャンネルID
													$prev_title,				// タイトル
													$prev_description,			// 概要
													$prev_category_id,			// カテゴリID
													$prev_program_id,			// 番組ID
													$prev_autorec,				// 自動録画
													$prev_mode,
													$prev_dirty,
													$t_num[$t_cnt],				// チューナ
													$prev_priority,
													$prev_overlap,
													$prev_discontinuity,
													$prev_shortened
													);
											}
											catch( Exception $e ) {
												throw new Exception( '予約できません' );
											}
											continue;
										}
									}else{
										if( $prev_end_time+$ed_tm_sft+$ed_tm_sft_chk <= $next_start_time ){
											//終了時間短縮解消再予約
											$prev_end_time += $ed_tm_sft;
											$prev_shortened = FALSE;
											try {
												// いったん予約取り消し
												self::cancel( $prev_id );
												// 再予約
												self::at_set( 
													$prev_start_time,			// 開始時間Datetime型
													$prev_end_time,				// 終了時間Datetime型
													$prev_channel_id,			// チャンネルID
													$prev_title,				// タイトル
													$prev_description,			// 概要
													$prev_category_id,			// カテゴリID
													$prev_program_id,			// 番組ID
													$prev_autorec,				// 自動録画
													$prev_mode,
													$prev_dirty,
													$t_num[$t_cnt],				// チューナ
													$prev_priority,
													$prev_overlap,
													$prev_discontinuity,
													$prev_shortened
													);
											}
											catch( Exception $e ) {
												throw new Exception( '予約できません' );
											}
											continue;
										}
									}
								}else
									if( $prev_shortened ){
										// 条件が不足してるかも
										$prev_end_time  += $ed_tm_sft;
										$prev_shortened  = FALSE;
										$shortened_clear = TRUE;
									}
								//チューナ変更処理+末尾evennt短縮解消
								if( $prev_tuner!==$t_num[$t_cnt] || $shortened_clear ){
									try {
										// いったん予約取り消し
										self::cancel( $prev_id );
										// 再予約
										self::at_set( 
											$prev_start_time,			// 開始時間Datetime型
											$prev_end_time,				// 終了時間Datetime型
											$prev_channel_id,			// チャンネルID
											$prev_title,				// タイトル
											$prev_description,			// 概要
											$prev_category_id,			// カテゴリID
											$prev_program_id,			// 番組ID
											$prev_autorec,				// 自動録画
											$prev_mode,
											$prev_dirty,
											$t_num[$t_cnt],				// チューナ
											$prev_priority,
											$prev_overlap,
											$prev_discontinuity,
											$prev_shortened
											);
									}
									catch( Exception $e ) {
										throw new Exception( 'チューナ機種の変更に失敗' );
									}
								}
							}else
							if( $n_0===0 && $n_lmt>1 && ( ( $smf_type!=='EX' &&
									( ( USE_RECPT1 && $prev_tuner<TUNER_UNIT1 ) || ( $prev_tuner>=TUNER_UNIT1 && $OTHER_TUNERS_CHARA["$smf_type"][$prev_tuner-TUNER_UNIT1]['cntrl'] ) ) )
									|| ( $smf_type==='EX' && $EX_TUNERS_CHARA[$prev_tuner]['cntrl'] ) ) ){
								//録画中
								if( !$prev_shortened ){
									if( $prev_end_time > $next_start_time-$ed_tm_sft_chk ){
										//録画時間短縮指示
										$ps = search_reccmd( $prev_id );
										if( $ps !== FALSE ){
											exec( RECPT1_CTL.' --pid '.$ps->pid.' --extend -'.($ed_tm_sft+$extra_time) );
											for( $i=0; $i<count($prev_trecs) ; $i++ ){
												if( $prev_id === (int)$prev_trecs[$i]->id ){
													$prev_trecs[$i]->endtime        = toDatetime( $prev_end_time - $ed_tm_sft );
													$prev_trecs[$i]->prev_shortened = TRUE;
													$prev_trecs[$i]->update();
													break;
												}
											}
										}
									}
								}else{
									if( $prev_end_time+$ed_tm_sft+$ed_tm_sft_chk <= $next_start_time ){
										//録画時間延伸指示
										$ps = search_reccmd( $prev_id );
										if( $ps !== FALSE ){
											exec( RECPT1_CTL.' --pid '.$ps->pid.' --extend '.($ed_tm_sft+$extra_time) );
											for( $i=0; $i<count($prev_trecs) ; $i++ ){
												if( $prev_id === (int)$prev_trecs[$i]->id ){
													$prev_trecs[$i]->endtime        = toDatetime( $prev_end_time + $ed_tm_sft );
													$prev_trecs[$i]->prev_shortened = FALSE;
													$prev_trecs[$i]->update();
													break;
												}
											}
										}
									}
								}
							}
					}
				}
				return $job.':'.$tuner_chg;			// 成功
			}else{
				//単純予約
				try {
					$job = self::at_set(
						$start_time,
						$end_time,
						$channel_id,
						$title,
						$description,
						$category_id,
						$program_id,
						$autorec,
						$mode,
						$dirty,
						0,		// チューナー番号
						$priority,
						$overlap,
						$discontinuity,
						FALSE
					);
				}
				catch( Exception $e ) {
					throw new Exception( '予約できません' );
				}
				return $job.':0';			// 成功
			}
		}
		catch( Exception $e ) {
			throw $e;
		}
	}
	// custom 終了


	public static function update_title(
		$rec,					// 既存レコード
		$old_title,				// 変更前タイトル
		$title					// タイトル
	) {
		if( $rec->complete == 0 )
			return self::at_update_title( $rec, $old_title, $rec->title );
		return self::rename_filename( $rec, $old_title, $rec->title );
	}


	private static function at_update_title(
		$rec,					// 既存レコード
		$old_title,				// 変更前タイトル
		$title					// タイトル
	) {
		$settings = Settings::factory();

		$start_time = toTimestamp( $rec->starttime );
		$end_time = toTimestamp( $rec->endtime );

		$now_time = time();
		$rec_start  = $start_time - $settings->former_time;
		$epg_time = array( 'GR' => FIRST_REC, 'BS' => 180, 'CS' => 120, 'EX' => 180 );
		if( $rec_start - $epg_time[$rec->type] <= $now_time ){
			// 即時録画となる時間を過ぎている場合には予約更新はしない
			return false;
		}
		$padding_tm = $start_time % 60 ? PADDING_TIME + $start_time % 60 : PADDING_TIME;
		$at_start   = ( $start_time - $padding_tm <= $now_time ) ? $now_time : $start_time - $padding_tm;
		$sleep_time = $rec_start - $at_start;

		// 録画設定
		$env = array( 'CHANNEL'    => null,
		              'DURATION'   => null,
		              'OUTPUT'     => null,
		              'TYPE'       => null,
		              'TUNER'      => null,
		              'MODE'       => null,
		              'TUNER_UNIT' => null,
		              'THUMB'      => null,
		              'FORMER'     => null,
		              'FFMPEG'     => null,
		              'SID'        => null,
		              'EID'        => null,
		              'RESOLUTION' => null,
		              'ASPECT'     => null,
		              'AUDIO_TYPE' => null,
		              'BILINGUAL'  => null,
		);

		// 現在の録画設定取得
		$process = popen( escapeshellcmd( $settings->at.' -c '.$rec->job ) , 'r' );
		if( !is_resource( $process ) ) {
			reclog( 'at -c '.$rec->job.' の実行に失敗した模様', EPGREC_ERROR);
			throw new Exception('at -c 実行エラー');
		}
		$buffer = stream_get_contents( $process );
		if( $buffer === false ) {
			reclog( 'at -c '.$rec->job.' の実行に失敗した模様', EPGREC_ERROR);
			pclose( $process );
			throw new Exception('at -c 実行エラー');
		}
		pclose( $process );
		foreach( $env as $name=>$value ) {
			if ( preg_match( '/'.$name.'=(.*); export '.$name.'\n*/', $buffer, $matches ) !== 1 )
				throw new Exception('"'.$name.'"行が見つかりません');
			$env[$name] = $matches[1];
		}

		// ファイル名更新
		$crec_     = new DBRecord( CHANNEL_TBL, 'id', $rec->channel_id );
		$filenames = self::create_filenames( $start_time, $end_time, $rec->type, $crec_->sid, $rec->channel, $crec_->name, $title, $rec->category_id, $rec->mode, $rec->autorec, $env['DURATION'] );
		$add_dir   = $filenames[0];
		$filename  = $filenames[1];
		$thumbname = $filenames[2];
		$newoutput = INSTALL_PATH.$settings->spool.'/'.$add_dir.$filename;
		$newthumb = INSTALL_PATH.$settings->thumbs.'/'.$thumbname;
		if ( $env['OUTPUT'] === $newoutput && $env['THUMB'] === $newthumb ){
			// ファイル名に変更が無い場合は予約更新しない
			return false;
		}
		$env['OUTPUT'] = $newoutput;
		$env['THUMB'] = $newthumb;

		// 録画予約追加
		$oldjob = $rec->job;
		$job = self::do_reserve( $rec, $env, $at_start, $sleep_time );
		$rec->path = $add_dir.$filename;
		$rec->job = $job;
		$rec->dirty = 1;
		$rec->update();

		// 古い録画予約を削除
		while( true ){
			$ret_cd = system( $settings->atrm . " " . $oldjob, $var_ret );
			if( $ret_cd !== FALSE && $var_ret == 0 ){
				reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のタイトルを『'.$rec->title.'』に変更しました。' );
				break;
			}
			$rarr       = explode( "\n", str_replace( "\t", ' ', shell_exec( $settings->at.'q' ) ) );
			$search_job = $oldjob.' ';
			$search_own = posix_getlogin();
			foreach( $rarr as $str_var ){
				if( strncmp( $str_var, $search_job, strlen( $search_job ) ) == 0 ){
					if( strpos( $str_var, $search_own ) !== FALSE )
						continue 2;
					else{
						reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のタイトルを『'.$rec->title.'』に変更しましたが、古い AT-JOB:'.$oldjob.'の削除に失敗しました。 ('.$search_own.'以外でJOBが登録されています)', EPGREC_ERROR );
						break 2;
					}
				}
			}
			reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のタイトルを『'.$rec->title.'』に変更しましたが、古い AT-JOB:'.$oldjob.'の削除に失敗しました。 (JOBが有りませんでした)' );
			break;
		}
		return true;
	}


	private static function rename_filename(
		$rec,					// 既存レコード
		$old_title,				// 変更前タイトル
		$title					// タイトル
	) {
		$settings = Settings::factory();

		$start_time = toTimestamp( $rec->starttime );
		$end_time = toTimestamp( $rec->endtime );
		$rec_start = $start_time - $settings->former_time;
		$duration = $end_time - $rec_start;

		// 新ファイル名
		$crec_     = new DBRecord( CHANNEL_TBL, 'id', $rec->channel_id );
		$filenames = self::create_filenames( $start_time, $end_time, $rec->type, $crec_->sid, $rec->channel, $crec_->name, $title, $rec->category_id, $rec->mode, $rec->autorec, $duration );
		$add_dir   = $filenames[0];
		$filename  = $filenames[1];
		$thumbname = $filenames[2];
		$newoutput = INSTALL_PATH.$settings->spool.'/'.$add_dir.$filename;
		$newthumb = INSTALL_PATH.$settings->thumbs.'/'.$thumbname;

		// 旧動画ファイル名
		$oldoutput = INSTALL_PATH.$settings->spool.'/'.$rec->path;
		if ( !is_readable ( $oldoutput ) ){
			$oldoutput = null;
			$tmp_replace_title = '##'.md5( $newoutput ).'##';
			$wfilename = preg_replace( '/'.$title.'/', $tmp_replace_title, $newoutput, 1 );
			$wfilename = preg_replace( '/[[*?]/', '\\\\$0', $wfilename );
			$wfilename = preg_replace( '/'.$tmp_replace_title.'/', '*', $wfilename, 1 );
			$files = glob( $wfilename, GLOB_NOSORT );
			if ( $files !== false && count( $files ) == 1 && strlen( $files[0] ) > 0 && is_readable( $files[0] ) ){
				$oldoutput = $files[0];
			}
		}

		// 旧サムネイルファイル名
		$oldthumb = INSTALL_PATH.$settings->thumbs.'/'.array_pop(explode('/', $rec->path)).'.jpg';
		if ( !is_readable ( $oldthumb ) ){
			$oldthumb = null;
			$tmp_replace_title = '##'.md5( $newthumb ).'##';
			$wthumbname = preg_replace( '/'.$title.'/', $tmp_replace_title, $newthumb, 1 );
			$wthumbname = preg_replace( '/[[*?]/', '\\\\$0', $wthumbname );
			$wthumbname = preg_replace( '/'.$tmp_replace_title.'/', '*', $wthumbname, 1 );
			$files = glob( $wthumbname, GLOB_NOSORT );
			if ( $files !== false && count( $files ) == 1 && strlen( $files[0] ) > 0 && is_readable( $files[0] ) ){
				$oldthumb = $files[0];
			}
		}

		//
		// ファイル移動
		//
		if ( !isset( $oldoutput ) ){
			// 変更前の動画ファイルが見つからなかった場合は何もしない
			return false;
		}
		if ( $newoutput === $oldoutput && $newthumb === $oldthumb ) {
			// 同じ名前の場合は何もしない
			return false;
		}

		if ( isset( $oldoutput ) && $newoutput !== $oldoutput ){
			if ( !@rename( $oldoutput, $newoutput ) ){
				reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のファイル『'.$oldoutput.'』を『'.$newoutput.'』に移動できませんでした。', EPGREC_ERROR );
				return false;
			}
		}
		if ( isset( $oldthumb ) && $newthumb !== $oldthumb ){
			if ( !@rename( $oldthumb, $newthumb ) ){
				if ( isset( $oldoutput ) && $newoutput !== $oldoutput ){
					if ( !@rename( $newoutput, $oldoutput ) ){
						reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のファイル『'.$newoutput.'』を『'.$oldoutput.'』に戻せませんでした。', EPGREC_ERROR );
					}
				}
				reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のサムネイルファイル『'.$oldthumb.'』を『'.$newthumb.'』に変更できませんでした。', EPGREC_ERROR );
				return false;
			}
		}

		// 移動できたのでファイルパスも更新
		$rec->path = $add_dir.$filename;
		$rec->dirty = 1;
		$rec->update();
		reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'のファイル『'.$oldoutput.'』を『'.$newoutput.'』に移動しました。' );
		return true;
	}


	private static function create_filenames(
		$start_time,			// 開始時間
		$end_time,				// 終了時間
		$channel_type,			// チャンネル種別(GR/BS)
		$channel_sid,			// チャンネルサービスID
		$channel_number,		// チャンネル番号
		$channel_name,			// チャンネル名
		$title,					// タイトル
		$category_id,			// カテゴリID
		$mode,  				// 録画モード
		$autorec,				// 自動録画ID
		$duration				// 録画時間
	) {
		global $RECORD_MODE;
		$settings = Settings::factory();
		$spool_path = INSTALL_PATH . $settings->spool;
		if( $autorec )
			$keyword = new DBRecord( KEYWORD_TBL, 'id', $autorec );

/*
		%TITLE%			番組タイトル
		%TITLEn%		番組タイトル(n=1-9 1枠の複数タイトルから選別変換 '/'でセパレートされているものとする)
		%TL_SBn%		タイトル+複数話分割(n=1-n 1枠の複数サブタイトルから選別変換)
		%ST%			開始日時（ex.200907201830)
		%ET%			終了日時
		%TYPE%			GR/BS/CS
		%SID%			サービスID
		%CH%			チャンネル番号
		%CHNAME%		チャンネル名
		%DOW%			曜日（Sun-Mon）
		%DOWJ%			曜日（日-土）
		%YEAR%			開始年
		%MONTH%			開始月
		%DAY%			開始日
		%HOUR%			開始時
		%MIN%			開始分
		%SEC%			開始秒
		%DURATION%		録画時間（秒）
		%DURATIONHMS%	録画時間（hh:mm:ss）
*/
		$day_of_week = array( '日','月','火','水','木','金','土' );
		$filename = $autorec && $keyword->filename_format != "" ? $keyword->filename_format : $settings->filename_format;

		$temp = trim($title);
		if( strncmp( $temp, '[￥]', 5 ) == 0 ){
			$out_title = substr( $temp, 5 );
		}else
			$out_title = $temp;
		// %TITLE%
		$filename = mb_str_replace('%TITLE%', $out_title, $filename);
		// %TITLEn%	番組タイトル(n=1-9 1枠の複数タイトルから選別変換 '/'でセパレートされているものとする)
		$magic_c = strpos( $filename, '%TITLE' );
		if( $magic_c !== FALSE ){
			$tl_num = $filename[$magic_c+6];
			if( ctype_digit( $tl_num ) && $filename[$magic_c+7]==='%' ){
				if( strpos( $out_title, '/' )!==FALSE ){
					$split_tls = explode( '/', $out_title );
					$filename  = mb_str_replace( '%TITLE'.$tl_num.'%', $split_tls[(int)$tl_num-1], $filename );
				}else
					$filename = mb_str_replace( '%TITLE'.$tl_num.'%', $out_title.$tl_num, $filename );
			}
		}
		// %TL_SBn%	タイトル+複数話分割(n=1-n 1枠の複数サブタイトルから選別変換)
		$magic_c = strpos( $filename, '%TL_SB' );
		if( $magic_c !== FALSE ){
			$magic_c += 6;
			$tl_num   = 0;
			while( ctype_digit( $filename[$magic_c] ) )
				$tl_num = $tl_num * 10 + (int)$filename[$magic_c++];
			if( $tl_num>0 && $filename[$magic_c]==='%' ){
				if( strpos( $out_title, '」#' ) !== FALSE ){
					list( $pictitle, $sbtls ) = explode( ' #', $out_title );
					$split_tls = explode( '」#', $sbtls );
					$pictitle .= ' #'.$split_tls[$tl_num-1];
					if( $tl_num < count( $split_tls ) )
						$pictitle .= '」';
					$filename = mb_str_replace( '%TL_SB'.$tl_num.'%', $pictitle, $filename );
				}else
					$filename = mb_str_replace( '%TL_SB'.$tl_num.'%', $out_title, $filename );
			}
		}
		// %ST%	開始日時
		$filename = mb_str_replace('%ST%',date('YmdHis', $start_time), $filename );
		// %ET%	終了日時
		$filename = mb_str_replace('%ET%',date('YmdHis', $end_time), $filename );
		// %TYPE%	GR/BS
		$filename = mb_str_replace('%TYPE%',$channel_type, $filename );
		// %SID%	サービスID
		$filename = mb_str_replace('%SID%',$channel_sid, $filename );
		// %CH%	チャンネル番号
		$filename = mb_str_replace('%CH%',$channel_number, $filename );
		// %CHNAME%	チャンネル名
		$filename = mb_str_replace('%CHNAME%',$channel_name, $filename );
		// %DOW%	曜日（Sun-Mon）
		$filename = mb_str_replace('%DOW%',date('D', $start_time), $filename );
		// %DOWJ%	曜日（日-土）
		$filename = mb_str_replace('%DOWJ%',$day_of_week[(int)date('w', $start_time)], $filename );
		// %YEAR%	開始年
		$filename = mb_str_replace('%YEAR%',date('Y', $start_time), $filename );
		// %MONTH%	開始月
		$filename = mb_str_replace('%MONTH%',date('m', $start_time), $filename );
		// %DAY%	開始日
		$filename = mb_str_replace('%DAY%',date('d', $start_time), $filename );
		// %HOUR%	開始時
		$filename = mb_str_replace('%HOUR%',date('H', $start_time), $filename );
		// %MIN%	開始分
		$filename = mb_str_replace('%MIN%',date('i', $start_time), $filename );
		// %SEC%	開始秒
		$filename = mb_str_replace('%SEC%',date('s', $start_time), $filename );
		// %DURATION%	録画時間（秒）
		$filename = mb_str_replace('%DURATION%',$duration, $filename );
		// %DURATIONHMS%	録画時間（hh:mm:ss）
		$filename = mb_str_replace('%DURATIONHMS%',transTime($duration,TRUE), $filename );
		// %[YmdHisD]*%	開始日時(date()に書式をそのまま渡す 非変換部に'%'を使う場合は誤変換に注意・対策はしない)
		if( substr_count( $filename, '%' ) >= 2 ){
			$split_tls = explode( '%', $filename );
			$iti       = $filename[0]==='%' ? 0 : 1;
			$filename  = mb_str_replace('%'.$split_tls[$iti].'%',date( $split_tls[$iti], $start_time ), $filename );
		}

		if( defined( 'KATAUNA' ) ){
			// しょぼかるからサブタイトル取得(スケジュール未登録)
			if( $category_id==8 && strpos( $filename, '「」' )!==FALSE ){
				$title_piece = explode( ' #', $filename );		// タイトル分離
				$trans       = str_replace( ' ', '', $title_piece[0] );
				if( ( $handle = fopen( INSTALL_PATH.'/settings/Title_base.csv', "r+") ) !== FALSE ){
					do{
						// タイトルリスト1行読み込み
						if( ( $data = fgetcsv( $handle ) ) === FALSE ){
							// 該当タイトルをしょぼカレで検索
							$search_nm = $title_piece[0];
							while(1){
								$find_ps = file_get_contents( 'http://cal.syoboi.jp/find?sd=0&r=0&v=0&kw='.urlencode($search_nm) );		// エンコードは変わるかも
								if( $find_ps !== FALSE ){
									if( strpos( $find_ps, "href=\"/tid/" ) !== FALSE ){
										list( $dust_trim, $dust ) = explode( '外部サイトの検索結果', $find_ps );
										$tl_list = explode( "href=\"/tid/", $dust_trim );
										for( $loop=1; $loop<count($tl_list); $loop++ ){
											if( strpos( $tl_list[$loop], "\">".$search_nm.'</a>' ) !== FALSE ){
												list( $tid, ) = explode( "\">", $tl_list[$loop] );
												$data = array( (int)$tid, 1, $title_piece[0], $trans, str_replace( '・', '', $trans ) );
												fputcsv( $handle, $data );
												break 2;
											}
										}
										break 2;
									}else{
										if( $search_nm === $trans )
											break 2;	// end
										$search_nm = $trans;
									}
								}else
									break 2;
							}
						}
						if( is_numeric( $data[0] ) && $data[0]!==0 ){
							switch( $data[1] ){
								case 1:		// 国内
								case 4:		// 特撮
								case 10:	// 国内放送終了
								case 7:		// OVA
								case 20:	// 児童
								case 21:	// 非視聴
								case 22:	// 海外
									$num = count( $data );
									for( $loop=2; $loop<$num; $loop++ ){
										if( $loop === 2 ){
											$official = str_replace( '^', '', $data[2] );
											$dte      = str_replace( ' ', '', $official );
										}else
											$dte = $data[$loop];
										if( strcmp( $trans, $dte ) == 0 ){
											// 異形タイトルを正式タイトルに修正
											if( $loop === 2 ){
												if( strcmp( $official, $title_piece[0] ) )
													$filename = str_replace( $title_piece[0], $official, $filename );
											}else
												$filename = str_replace( $dte, $official, $filename );
											// しょぼカレから全サブタイトル取得
											$st_list = file( 'http://cal.syoboi.jp/db.php?Command=TitleLookup&Fields=SubTitles&TID='.$data[0], FILE_IGNORE_NEW_LINES );
											if( $st_list !== FALSE ){
												$st_count = count( $st_list );
												if( strpos( $title_piece[1], '」#' ) !== FALSE )
													$sub_pieces = explode( '」#', $title_piece[1] );
												else
													$sub_pieces[0] = $title_piece[1];
												foreach( $sub_pieces as $sub_piece ){
													if( strpos( $sub_piece.'」', '「」' ) !== FALSE ){
														$scount = (int)$sub_piece;							// 強引？
														if( $scount <= $st_count ){
															$num_cmp = sprintf( "%d*", $scount );
															if( strpos( $st_list[$scount-1], $num_cmp ) !== FALSE ){
																if( $scount === $st_count ){
																	list( $subsplit, $dust ) = explode( '</SubTitles>', $st_list[$scount-1] );
																	list( , $subtitle )      = explode( $num_cmp, $subsplit );
																}else
																	list( , $subtitle ) = explode( $num_cmp, $st_list[$scount-1] );
																$filename = str_replace( sprintf( '#%02d「」', $scount ), sprintf( '#%02d「%s」', $scount, $subtitle ), $filename );
															}
														}
													}
												}
											}
											break 3;
										}
									}
									break;
								default:
									break;
							}
						}
					}while( !isset( $search_nm ) );
					fclose( $handle );
				}
			}
		}

		// あると面倒くさそうな文字を全部_に
//		$filename = preg_replace("/[ \.\/\*:<>\?\\|()\'\"&]/u","_", trim($filename) );
		
		// 全角に変換したい場合に使用
/*		$trans = array( "[" => "［",
						"]" => "］",
						"/" => "／",
						"'" => "’",
						"\"" => "”",
						"\\" => "￥",
				);
		$filename = strtr( $filename, $trans );
*/
		// UTF-8に対応できない環境があるようなのでmb_ereg_replaceに戻す
//		$filename = mb_ereg_replace("[ \./\*:<>\?\\|()\'\"&]","_", trim($filename) );
		$filename = mb_ereg_replace("[\\/\'\"]","_", trim($filename) );

		// ディレクトリ付加
		$add_dir = $autorec && $keyword->directory != "" ? $keyword->directory.'/' : "";

		// 文字コード変換
		if( defined( 'FILESYSTEM_ENCODING' ) ) {
			$filename = mb_convert_encoding( $filename, FILESYSTEM_ENCODING, 'UTF-8' );
			$add_dir  = mb_convert_encoding( $add_dir, FILESYSTEM_ENCODING, 'UTF-8' );
		}

		// ファイル名長制限+ファイル名重複解消
		$fl_len     = strlen( $filename );
		$fl_len_lmt = 255 - strlen( $RECORD_MODE["$mode"]['suffix'] );
		// サムネール
		if( (boolean)$settings->use_thumbs ){
			$fl_len_lmt -= 4;		// '.jpg'
		}
		if( $fl_len > $fl_len_lmt ){
			$longname = $filename;
			$filename = mb_strncpy( $filename, $fl_len_lmt );
			if( preg_match( '/^(.*)\040(\#\d+)(「.*」)/', $longname, $matches ) )
				file_put_contents( $spool_path.'/'.$add_dir.$matches[1].' '.$matches[2].'.txt', $matches[2].str_replace('」#', "」\n#", $matches[3] )."\n\n", FILE_APPEND );
			else
				file_put_contents( $spool_path.'/longname.txt', $filename." <-\n".$longname."\n->\n", FILE_APPEND );
			$fl_len = strlen( $filename );
		}
		$files = scandir( $spool_path.'/'.$add_dir );
		if( $files !== FALSE )
			array_splice( $files, 0, 2 );
		else
			$files = array();
		$file_cnt = 0;
		$tmp_name = $filename;
		$sql_que  = "WHERE path LIKE '".mysql_real_escape_string($add_dir.$tmp_name.$RECORD_MODE["$mode"]['suffix'])."'";
		while( in_array( $tmp_name.$RECORD_MODE["$mode"]['suffix'], $files ) || DBRecord::countRecords( RESERVE_TBL, $sql_que )!==0 ){
			$file_cnt++;
			$len_dec = strlen( (string)$file_cnt );
			if( $fl_len > $fl_len_lmt-$len_dec ){
				$filename = mb_strncpy( $filename, $fl_len_lmt-$len_dec );
				$fl_len   = strlen( $filename );
			}
			$tmp_name = $filename.$file_cnt;
			$sql_que  = "WHERE path LIKE '".mysql_real_escape_string($add_dir.$tmp_name.$RECORD_MODE["$mode"]['suffix'])."'";
		}
		$filename  = $tmp_name.$RECORD_MODE["$mode"]['suffix'];
		$thumbname = $filename.'.jpg';

		return array( $add_dir, $filename, $thumbname );
	}


	private static function do_reserve(
		$rrec,					// 登録レコード
		$env,					// 録画設定
		$at_start,				// コマンド起動時間
		$sleep_time				// 録画前待ち時間
	) {
		$settings   = Settings::factory();
		$spool_path = INSTALL_PATH . $settings->spool;

		// AT発行準備
		$cmdline = escapeshellcmd( $settings->at.' '.date( 'H:i m/d/Y', $at_start ) );
		$descriptor = array( 0 => array( 'pipe', 'r' ),
		                     1 => array( 'pipe', 'w' ),
		                     2 => array( 'pipe', 'w' ),
		);
		// ATで予約する
		$process = proc_open( $cmdline , $descriptor, $pipes, $spool_path, $env );
		if( !is_resource( $process ) ) {
			reclog( 'atの実行に失敗した模様', EPGREC_ERROR );
			throw new Exception( 'AT実行エラー' );
		}
		fwrite( $pipes[0], 'echo $$ >/tmp/tuner_'.$rrec->type.$rrec->tuner."\n" );		//SHのPID
		if( $sleep_time ){
			$tmpfile = $spool_path.'/tmp';
			if( $rrec->program_id && $sleep_time > $settings->rec_switch_time )
				fwrite( $pipes[0], "echo 'temp' > ".$tmpfile.' & sync & '.INSTALL_PATH.'/scoutEpg.php '.$rrec->id.' & rm -f '.$tmpfile." &\n" );		//HDD spin-up + 単発EPG更新
			else
				fwrite( $pipes[0], "echo 'temp' > ".$tmpfile.' & sync & rm -f '.$tmpfile." &\n" );		//HDD spin-up
			fwrite( $pipes[0], $settings->sleep.' '.$sleep_time."\n" );
		}
		fwrite( $pipes[0], DO_RECORD.' '.$rrec->id."\n" );		//$rrec->id追加は録画キャンセルのためのおまじない
		fwrite( $pipes[0], COMPLETE_CMD.' '.$rrec->id."\n" );
		if( (boolean)$settings->use_thumbs ){
			$gen_thumbnail = defined( 'GEN_THUMBNAIL' ) ? GEN_THUMBNAIL : INSTALL_PATH.'/gen-thumbnail.sh';
			fwrite( $pipes[0], $gen_thumbnail."\n" );
		}
		fclose( $pipes[0] );
		// 標準エラーを取る
		$rstring = stream_get_contents( $pipes[2] );

		fclose( $pipes[2] );
		fclose( $pipes[1] );
		proc_close( $process );
		// job番号を取り出す
		$rarr = array();
		$tok = strtok( $rstring, " \n" );
		while( $tok !== false ) {
			array_push( $rarr, $tok );
			$tok = strtok( " \n" );
		}
		// OSを識別する(Linux、またはFreeBSD)
		//$job = php_uname('s') == 'FreeBSD' ? 'Job' : 'job';
		$job = PHP_OS == 'FreeBSD' ? 'Job' : 'job';
		$key = array_search( $job, $rarr );
		if( $key !== false ) {
			if( is_numeric( $rarr[$key+1]) ) {
				return $rarr[$key+1];	// 成功
			}
		}
		// エラー
		reclog( 'ジョブNoの取得に失敗', EPGREC_ERROR );
		throw new Exception( 'ジョブNoの取得に失敗' );
	}


	private static function at_set(
		$start_time,			// 開始時間
		$end_time,				// 終了時間
		$channel_id,			// チャンネルID
		$title = 'none',		// タイトル
		$description = 'none',	// 概要
		$category_id = 0,		// カテゴリID
		$program_id = 0,		// 番組ID
		$autorec = 0,			// 自動録画ID
		$mode = 0,				// 録画モード
		$dirty = 0,				// ダーティフラグ
		$tuner = 0,				// チューナ
		$priority,				// 優先度
		$overlap,				// 重複予約可否
		$discontinuity,			// 隣接短縮可否
		$shortened				// 隣接短縮フラグ
	) {
		$settings   = Settings::factory();
		$spool_path = INSTALL_PATH.$settings->spool;
		$crec_      = new DBRecord( CHANNEL_TBL, 'id', $channel_id );

		//即時録画の指定チューナー確保
		$epg_time = array( 'GR' => FIRST_REC, 'BS' => 180, 'CS' => 120, 'EX' => 180 );
		if( $start_time-$settings->former_time-$epg_time[$crec_->type] <= time() ){
			$shm_nm   = array( SEM_GR_START, SEM_ST_START, SEM_EX_START );
			switch( $crec_->type ){
				case 'GR':
					$sem_type = 0;
					break;
				case 'BS':
				case 'CS':
					$sem_type = 1;
					break;
				case 'EX':
					$sem_type = 2;
					break;
			}
			$shm_name = $shm_nm[$sem_type] + $tuner;
			$sem_id   = sem_get_surely( $shm_name );
			if( $sem_id === FALSE )
				throw new Exception( 'セマフォ・キー確保に失敗' );
			$cc=0;
			while(1){
				if( sem_acquire( $sem_id ) === TRUE ){
					$shm_id = shmop_open_surely();
					$smph   = shmop_read_surely( $shm_id, $shm_name );
					if( $smph == 2 ){
						// リアルタイム視聴停止
						$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
						posix_kill( $real_view, 9 );		// 録画コマンド停止
						shmop_write_surely( $shm_id, $shm_name, 0 );
						shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo clear
						shmop_close( $shm_id );
						$sleep_time = $settings->rec_switch_time;
					}else
						if( $smph == 1 ){
							// EPG受信停止
							$rec_trace = 'TUNER='.$tuner.' MODE=0 OUTPUT='.$settings->temp_data.'_'.$crec_->type;
							$ps_output = shell_exec( PS_CMD );
							$rarr      = explode( "\n", $ps_output );
							for( $cc=0; $cc<count($rarr); $cc++ ){
								if( strpos( $rarr[$cc], $rec_trace ) !== FALSE ){
									$ps = ps_tok( $rarr[$cc] );
									while( ++$cc < count($rarr) ){
										$c_ps = ps_tok( $rarr[$cc] );
										if( $ps->pid == $c_ps->ppid ){
											$ps = $c_ps;
											while( ++$cc < count($rarr) ){
												$c_ps = ps_tok( $rarr[$cc] );
												if( $ps->pid == $c_ps->ppid ){
													posix_kill( $c_ps->pid, 15 );		//EPG受信停止
													$sleep_time = $settings->rec_switch_time;
													break 4;
												}
											}
										}
									}
									$sleep_time = $settings->rec_switch_time;
									break 2;
								}
							}
						}
					break;
				}else
					if( ++$cc < 5 )
						sleep(1);
					else
						throw new Exception( 'チューナー確保に失敗' );
			}
		}

		//時間がらみ調整
		$now_time = time();
		if( $start_time-$settings->former_time <= $now_time ){	// すでに開始されている番組
			$at_start = $now_time;
			if( isset( $sleep_time ) )
				$now_time += $sleep_time;
			else
				$sleep_time = 0;
			$rec_start = $start_time = $now_time;		// 即開始
		}else{
			if( $now_time < $end_time ){
				$rec_start  = $start_time - $settings->former_time;
				$padding_tm = $start_time%60 ? PADDING_TIME+$start_time%60 : PADDING_TIME;
				$at_start   = ( $start_time-$padding_tm <= $now_time ) ? $now_time : $start_time - $padding_tm;
				$sleep_time = $rec_start - $at_start;
			}else
				throw new Exception( '終わっている番組です' );
		}
		$duration = $end_time - $rec_start;
		if( $duration < $settings->former_time ) {	// 終了間際の番組は弾く
			throw new Exception( '終わりつつある/終わっている番組です' );
		}
		if( $program_id ){
			$prg = new DBRecord( PROGRAM_TBL, 'id', $program_id );
			$resolution = (int)$prg->video_type & 0xF0;
			$aspect     = (int)$prg->video_type & 0x0F;
			$audio_type = (int)$prg->audio_type;
			$bilingual  = (int)$prg->multi_type;
			$eid        = (int)$prg->eid;
			if( $autorec )
				$keyword = new DBRecord( KEYWORD_TBL, 'id', $autorec );
			$prg->key_id = 0;	// 自動予約禁止解除
			$prg->update();
		}else{
			$resolution = 0;
			$aspect     = 0;
			$audio_type = 0;
			$bilingual  = 0;
			$eid        = 0;
		}
		if( !$shortened )
			$duration += $settings->extra_time;			//重複による短縮がされてないものは糊代を付ける
		$rrec = null;
		try {
			// ファイル名生成
			$filenames = self::create_filenames( $start_time, $end_time, $crec_->type, $crec_->sid, $crec_->channel, $crec_->name, $title, $category_id, $mode, $autorec, $duration );
			$add_dir   = $filenames[0];
			$filename  = $filenames[1];
			$thumbname = $filenames[2];

			// 予約レコード生成
			$rrec = new DBRecord( RESERVE_TBL );
			$rrec->channel_disc  = $crec_->channel_disc;
			$rrec->channel_id    = $crec_->id;
			$rrec->program_id    = $program_id;
			$rrec->type          = $crec_->type;
			$rrec->channel       = $crec_->channel;
			$rrec->title         = $title;
			$rrec->description   = $description;
			$rrec->category_id   = $category_id;
			$rrec->starttime     = toDatetime( $start_time );
			$rrec->endtime       = toDatetime( $end_time );
			$rrec->path          = $add_dir.$filename;
			$rrec->autorec       = $autorec;
			$rrec->mode          = $mode;
			$rrec->tuner         = $tuner;
			$rrec->priority      = $priority;
			$rrec->overlap       = $overlap;
			$rrec->discontinuity = $discontinuity;
			$rrec->shortened     = $shortened;
			$rrec->reserve_disc  = md5( $crec_->channel_disc . toDatetime( $start_time ). toDatetime( $end_time ) );

			// 予約実施
			$env = array( 'CHANNEL'    => $crec_->channel,
			              'DURATION'   => $duration,
			              'OUTPUT'     => $spool_path.'/'.$add_dir.$filename,
			              'TYPE'       => $crec_->type,
			              'TUNER'      => $tuner,
			              'MODE'       => $mode,
			              'TUNER_UNIT' => TUNER_UNIT1,
			              'THUMB'      => INSTALL_PATH.$settings->thumbs.'/'.$thumbname,
			              'FORMER'     => $settings->former_time,
			              'FFMPEG'     => $settings->ffmpeg,
			              'SID'        => $crec_->sid,
			              'EID'        => $eid,
			              'RESOLUTION' => $resolution,
			              'ASPECT'     => $aspect,
			              'AUDIO_TYPE' => $audio_type,
			              'BILINGUAL'  => $bilingual,
			);
			$job = self::do_reserve( $rrec, $env, $at_start, $sleep_time );
			if( isset( $sem_id ) )
				while( sem_release( $sem_id ) === FALSE )
					usleep( 100 );
			$sem_id = null;
			$rrec->job = $job;
			$rrec->update();
			reclog( '予約ID:'.$rrec->id.' '.$rrec->channel_disc.':T'.$rrec->tuner.'-Ch'.$rrec->channel.' '.$rrec->starttime.'『'.$title.'』を登録' );
			return $program_id.':'.$tuner.':'.$rrec->id;			// 成功
		}
		catch( Exception $e ) {
			if( $rrec != null ) {
				if( $rrec->id ) {
					// 予約を取り消す
					$rrec->delete();
				}
			}
			if( isset( $sem_id ) )
				while( sem_release( $sem_id ) === FALSE )
					usleep( 100 );
			throw $e;
		}
	}

	// 取り消し
	public static function cancel( $reserve_id = 0, $program_id = 0 ) {
		$settings = Settings::factory();
		$rec = null;
		try {
			if( $reserve_id ) {
				$rec = new DBRecord( RESERVE_TBL, 'id' , $reserve_id );
				$ret = '0';
			}
			else if( $program_id ) {
				$prev_recs = DBRecord::createRecords( RESERVE_TBL, "WHERE complete = '0' AND program_id = '".$program_id."' ORDER BY starttime ASC" );
				$rec = $prev_recs[0];
				$ret = (string)(count( $prev_recs ) - 1);
			}
			if( $rec == null ) {
				throw new Exception('IDの指定が無効です');
			}
			if( ! $rec->complete ) {
				// 予約解除
				$rec_st = toTimestamp($rec->starttime);
				$pad_tm = $rec_st%60 ? PADDING_TIME+60-$rec_st%60 : PADDING_TIME;
				$rec_at = $rec_st - $pad_tm;
				$rec_st -= $settings->former_time;
				$rec_ed = toTimestamp($rec->endtime);
				$now_tm = time();
				if( $rec_at-2 <= $now_tm ){
					if( $rec_st-2 <= $now_tm ){
						// 実行中の予約解除
						if( $now_tm <= $rec_ed ){
							if( $rec_st >= $now_tm )
								sleep(3);
							//録画停止
							$ps = search_reccmd( $rec->id );
							if( $ps !== FALSE ){
								$rec->autorec = ( $rec->autorec + 1 ) * -1;
								$rec->update();
								$smf_type = $rec->type=='CS' ? 'BS' : $rec->type;
								if( ( $smf_type!=='EX' &&
										( ( USE_RECPT1 && $rec->tuner<TUNER_UNIT1 ) || ( $rec->tuner>=TUNER_UNIT1 && $OTHER_TUNERS_CHARA["$smf_type"][$prev_tuner-TUNER_UNIT1]['cntrl'] ) ) )
										|| ( $smf_type==='EX' && $EX_TUNERS_CHARA[$prev_tuner]['cntrl'] ) ){
									// recpt1ctlで停止
									exec( RECPT1_CTL.' --pid '.$ps->pid.' --time 10 >/dev/null' );
								}else{
									//コントローラの無いチューナへの汎用処理
									posix_kill( $ps->pid, 15 );		//録画停止
								}
								return $ret;
							}
						}
						//DB残留 DB削除へ
					}else{
						if( $rec_at >= $now_tm )
							sleep(3);
						//sleep待機中の予約解除
						$sleep_ppid  = (int)trim( file_get_contents( '/tmp/tuner_'.$rec->type.$rec->tuner ) );
						$ps_output   = shell_exec( PS_CMD );
						$rarr        = explode( "\n", $ps_output );
						$scout_cmd   = INSTALL_PATH.'/scoutEpg.php '.$rec->id;
						$my_pid      = posix_getpid();
						$sleep_pid   = 0;
						$scout_pid   = 0;
						$dorec_pid   = 0;
						$dorecsh_pid = 0;
						$recepg_pid  = 0;
						$stop_stk    = 0;
						for( $cc=0; $cc<count($rarr); $cc++ ){
							if( $sleep_pid == 0 ){
								if( strpos( $rarr[$cc], 'sleep ' ) !== FALSE ){
									$ps = ps_tok( $rarr[$cc] );
									if( (int)$ps->ppid == $sleep_ppid ){
										posix_kill( $sleep_ppid, 15 );		//親プロセス(AT?)停止
										$sleep_pid = (int)$ps->pid;
										posix_kill( $sleep_pid, 15 );		//(sleep)停止
										$stop_stk++;
										continue;
									}
								}
							}
							if( $scout_pid == 0 ){
								if( strpos( $rarr[$cc], $scout_cmd ) !== FALSE ){
									$ps = ps_tok( $rarr[$cc] );
									$scout_pid = (int)$ps->pid;
									$temp_ts   = $settings->temp_data.'_'.$rec->type.'_'.$scout_pid;
									$stop_stk++;
								}
							}else
							if( $dorec_pid == 0 ){
								if( strpos( $rarr[$cc], $temp_ts.' '.DO_RECORD ) !== FALSE ){
									$ps = ps_tok( $rarr[$cc] );
									if( (int)$ps->ppid == $scout_pid ){
										if( $scout_pid != $my_pid )			//自殺防止
											posix_kill( $scout_pid, 15 );		//scoutEpg.php停止
										$dorec_pid = (int)$ps->pid;
									}
								}
							}else
							if( $dorecsh_pid == 0 ){
								if( strpos( $rarr[$cc], 'sh '.DO_RECORD ) !== FALSE ){
									$ps = ps_tok( $rarr[$cc] );
									if( (int)$ps->ppid == $dorec_pid ){
										posix_kill( $dorec_pid, 15 );		//do_record.sh停止
										$dorecsh_pid = (int)$ps->pid;
									}
								}
							}else
							if( $recepg_pid==0 && strpos( $rarr[$cc], $temp_ts )!==FALSE ){
								$ps = ps_tok( $rarr[$cc] );
								if( (int)$ps->ppid == $dorecsh_pid ){
									posix_kill( $dorecsh_pid, 15 );		//do_record.sh停止
									$recepg_pid = (int)$ps->pid;
									posix_kill( $recepg_pid, 15 );		//EPG録画停止
								}
							}
						}
						if( $stop_stk ){
							reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'『'.$rec->title.'』を削除' );
							$rec->delete();
							return $ret;
						}
						throw new Exception( '予約キャンセルに失敗した' );
					}
				}else{
					//AT削除
					while(1){
						$ret_cd = system( $settings->atrm . " " . $rec->job, $var_ret );
						if( $ret_cd!==FALSE && $var_ret==0 ){
							reclog( '予約ID:'.$rec->id.' '.$rec->channel_disc.':T'.$rec->tuner.'-Ch'.$rec->channel.' '.$rec->starttime.'『'.$rec->title.'』を削除' );
							break;
						}
						$rarr       = explode( "\n", str_replace( "\t", ' ', shell_exec( $settings->at.'q' ) ) );
						$search_job = $rec->job.' ';
						$search_own = posix_getlogin();
						foreach( $rarr as $str_var ){
							if( strncmp( $str_var, $search_job, strlen( $search_job ) ) == 0 ){
								if( strpos( $str_var, $search_own ) !== FALSE )
									continue 2;
								else{
									reclog( '予約ID:'.$rec->id.'の削除を中止しました。 AT-JOB:'.$rec->job.'の削除に失敗しました。 ('.$search_own.'以外でJOBが登録されている)', EPGREC_ERROR );
									return $ret;
								}
							}
						}
						reclog( '予約ID:'.$rec->id.'を削除しましたが AT-JOB:'.$rec->job.'の削除に失敗しました。 (JOBが有りませんでした)' );
						break;
					}
				}
			}
			$rec->delete();
			return $ret;
		}
		catch( Exception $e ) {
			reclog('Reservation::cancel 予約キャンセルでDB接続またはアクセスに失敗した模様 $reserve_id:'.$reserve_id.' $program_id:'.$program_id, EPGREC_ERROR );
			throw $e;
		}
	}
}
?>
