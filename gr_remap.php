#!/usr/bin/php
<?php
	$script_path = dirname( __FILE__ );
	chdir( $script_path );
	include_once( $script_path . '/config.php');
	include_once( INSTALL_PATH . '/DBRecord.class.php' );
	include_once( INSTALL_PATH . '/reclib.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );

	run_user_regulate();

	while( ( list( $ch_disk, $value ) = each( $GR_CHANNEL_MAP ) ) ){
		$sql_cmd = "WHERE channel_disc LIKE '".$ch_disc."_%'";
		$num     = DBRecord::countRecords( CHANNEL_TBL , $sql_cmd );
		if( $num > 0 ){
			$chs = DBRecord::createRecords( CHANNEL_TBL , $sql_cmd );
			foreach( $chs as $ch ){
				if( $ch->channel != $value ){
					// 物理Ch更新
					$ch->channel = $value;
					$ch->update();
					// 自動キーワード
					$sql_cmd = "WHERE typeGR = '1' AND channel_id = ".$ch->id;
					$num     = DBRecord::countRecords( KEYWORD_TBL , $sql_cmd );
					if( $num > 0 ){
						$kws = DBRecord::createRecords( KEYWORD_TBL , $sql_cmd.' ORDER BY priority DESC' );
						foreach( $kws as $key ){
							if( (boolean)$key->kw_enable ){
								$key->rev_delete();
								// 録画予約実行
								$sem_key = sem_get_surely( SEM_KW_START );
								$shm_id  = shmop_open_surely( TRUE );
								if( $shm_id !== FALSE ){
									$key->reservation( 'GR', $shm_id, $sem_key );
									shmop_close( $shm_id );
								}
							}
						}
					}
					// 手動予約
					$sql_cmd = "WHERE typeGR = '1' AND channel_id = '".$ch->id."' AND autorec = '0' AND complete = '0'";
					$num     = DBRecord::countRecords( RESERVE_TBL , $sql_cmd );
					if( $num > 0 ){
						$revs = DBRecord::createRecords( RESERVE_TBL , $sql_cmd.' ORDER BY priority DESC' );
						foreach( $revs as $rev ){
							$pre_id        = $rev->id;
							$starttime     = $rev->starttime;
							$endtime       = $rev->endtime;
							$channel_id    = $rev->channel_id;
							$title         = $rev->title;
							$description   = $rev->description;
							$category_id   = $rev->category_id;
							$program_id    = $rev->program_id;
							$mode          = $rev->mode;
							$discontinuity = $rev->discontinuity;
							$priority      = $rev->priority;
							Reservation::cancel( $pre_id );
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
								// 手動予約のトラコン設定の予約ID修正
								list( , , $rec_id, ) = explode( ':', $rval );
								$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$pre_id );
								foreach( $tran_ex as $tran_set ){
									$tran_set->type_no = $rec_id;
									$tran_set->update();
								}
							}
							catch( Exception $e ) {
								// 手動予約のトラコン設定削除
								$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$pre_id );
								foreach( $tran_ex as $tran_set )
									$tran_set->delete();
								reclog( "Error:".$e->getMessage() );
							}
						}
					}
				}
			}
		}
	}
	exit();
?>
