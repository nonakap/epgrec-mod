<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );

$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );


function rate_time( $minute )
{
	$minute /= TS_STREAM_RATE;
	return sprintf( '%dh%02dm', $minute/60, $minute%60 );
}

$page      = 1;
$full_mode = FALSE;

try{
	$res_obj = new DBRecord( RESERVE_TBL );
	$rvs     = $res_obj->fetch_array( null, null, 'complete=0 ORDER BY starttime ASC' );
	$res_cnt = count( $rvs );

	if( ( SEPARATE_RECORDS_RESERVE===FALSE && SEPARATE_RECORDS<1 ) || ( SEPARATE_RECORDS_RESERVE!==FALSE && SEPARATE_RECORDS_RESERVE<1 ) )	// "<1"にしているのはフェイルセーフ
		$full_mode = TRUE;
	else{
		if( isset( $_GET['page']) ){
			if( $_GET['page'] === '-' )
				$full_mode = TRUE;
			else
				$page = (int)$_GET['page'];
		}
		$separate_records = SEPARATE_RECORDS_RESERVE!==FALSE ? SEPARATE_RECORDS_RESERVE : SEPARATE_RECORDS;
		$view_overload    = VIEW_OVERLOAD_RESERVE!==FALSE ? VIEW_OVERLOAD_RESERVE : VIEW_OVERLOAD;
		if( $res_cnt <= $separate_records+$view_overload )
			$full_mode = TRUE;
	}

	if( $full_mode ){
		$start_record = 0;
		$end_record   = $res_cnt;
	}else{
		$start_record = ( $page - 1 ) * $separate_records;
		$end_record   = $page * $separate_records;
	}

	$settings     = Settings::factory();
	$reservations = array();
	$ch_name      = array();
	foreach( $rvs as $key => $r ){
		$arr = array();
		$end_time = toTimestamp($r['endtime']);
		if( $end_time < time() ){
			switch( at_clean( $r, $settings ) ){
				case 0:
					// 予約終了化(録画済一覧に終了状態を出すようにしたいね)
					$wrt_set['complete'] = 1;
					$rev_obj->force_update( $r['id'], $wrt_set );
					continue;
				case 1:	// トランスコード中
					$arr['status'] = 1;
					break;
				case 2:	// 別ユーザーでAT登録
					$arr['status'] = 2;
					break;
			}
		}else
			$arr['status'] = 0;
		if( $start_record<=$key && $key<$end_record ){
			if( $r['program_id'] ){
				try{
					$prg = new DBRecord( PROGRAM_TBL, 'id', $r['program_id'] );
					$sub_genre = $prg->sub_genre;
				}catch( exception $e ) {
					reclog( 'reservationTable.php::予約ID:'.$r['id'].'  '.$e->getMessage(), EPGREC_ERROR );
					$sub_genre = 16;
				}
			}else
				$sub_genre = 16;
			$arr['id']      = $r['id'];
			$arr['type']    = $r['type'];
			$arr['tuner']   = $r['tuner'];
			$arr['channel'] = $r['channel'];
			if( !isset( $ch_name[$r['channel_id']] ) ){
				$ch                        = new DBRecord( CHANNEL_TBL, 'id', $r['channel_id'] );
				$ch_name[$r['channel_id']] = $ch->name;
			}
			$arr['channel_name'] = $ch_name[$r['channel_id']];
			$start_time          = toTimestamp($r['starttime']);
			$arr['date']         = date( 'm/d(', $start_time ).$week_tb[date( 'w', $start_time )].')';
			$arr['starttime']    = date( 'H:i:s-', $start_time );
			$arr['endtime']      = !$r['shortened'] ? date( 'H:i:s', $end_time ) : '<font color="#0000ff">'.date( 'H:i:s', $end_time ).'</font>';
			$arr['duration']     = date( 'H:i:s', $end_time-$start_time-9*60*60 );
			$arr['prg_top']      = date( 'YmdH', $start_time-60*60*1 );
			$arr['mode']         = $RECORD_MODE[$r['mode']]['name'];
			$arr['title']        = $r['title'];
			$arr['description']  = $r['description'];
			$arr['cat']          = $r['category_id'];
			$arr['autorec']      = $r['autorec'] ? $r['autorec'] : '□';
			$arr['keyword']      = putProgramHtml( $arr['title'], $r['type'], $r['channel_id'], $r['category_id'], $sub_genre );
			array_push( $reservations, $arr );
		}
	}

	$spool_path  = INSTALL_PATH.$settings->spool;
	$spool_disks = array();
	if( !defined( 'KATAUNA' ) ){
		// ストレージ空き容量取得
		$ts_stream_rate = TS_STREAM_RATE;
		// 全ストレージ空き容量取得
		$root_mega = $free_mega = (int)( disk_free_space( $spool_path ) / ( 1024 * 1024 ) );
		// スプール･ルート･ストレージの空き容量保存
		$stat  = stat( $spool_path );
		$dvnum = (int)$stat['dev'];
		$spool_disks = array();
		$arr = array();
		$arr['dev']   = $dvnum;
		$arr['dname'] = get_device_name( $dvnum );
		$arr['path']  = $settings->spool;
//		$arr['link']  = 'spool root';
		$arr['size']  = number_format( $root_mega/1024, 1 );
		$arr['time']  = rate_time( $root_mega );
		array_push( $spool_disks, $arr );
		$devs = array( $dvnum );
		// スプール･ルート上にある全ストレージの空き容量取得
		$files = scandir( $spool_path );
		if( $files !== FALSE ){
			array_splice( $files, 0, 2 );
			foreach( $files as $entry ){
				$entry_path = $spool_path.'/'.$entry;
				if( is_link( $entry_path ) && is_dir( $entry_path ) ){
					$stat  = stat( $entry_path );
					$dvnum = (int)$stat['dev'];
					if( !in_array( $dvnum, $devs ) ){
						$entry_mega   = (int)( disk_free_space( $entry_path ) / ( 1024 * 1024 ) );
						$free_mega   += $entry_mega;
						$arr = array();
						$arr['dev']   = $dvnum;
						$arr['dname'] = get_device_name( $dvnum );
						$arr['path']  = $settings->spool.'/'.$entry;
//						$arr['link']  = readlink( $entry_path );
						$arr['size']  = number_format( $entry_mega/1024, 1 );
						$arr['time']  = rate_time( $entry_mega );
						array_push( $spool_disks, $arr );
						array_push( $devs, array( $dvnum ) );
					}
				}
			}
		}
	}else{
		$free_mega      = 0;
		$ts_stream_rate = 0;
		$arr = array();
		$arr['dev']     = 0;
		$arr['dname']   = 'unknown';
		$arr['path']    = $spool_path;
//		$arr['link']    = 'spool root';
		$arr['size']    = number_format( $free_mega/1024, 1 );
		$arr['time']    = rate_time( $free_mega );
		array_push( $spool_disks, $arr );
	}

	$link_add = '';
	if( (int)$settings->gr_tuners > 0 )
		$link_add .= '<option value="index.php">地上デジタル番組表</option>';
	if( (int)$settings->bs_tuners > 0 ){
		$link_add .= '<option value="index.php?type=BS">BSデジタル番組表</option>';
		if( (boolean)$settings->cs_rec_flg )
			$link_add .= '<option value="index.php?type=CS">CSデジタル番組表</option>';
	}
	if( EXTRA_TUNERS )
		$link_add .= '<option value="index.php?type=EX">'.EXTRA_NAME.'番組表</option>';

	$smarty = new Smarty();
	$smarty->assign( 'sitetitle','録画予約一覧');
	$smarty->assign( 'reservations', $reservations );
	$smarty->assign( 'free_size', number_format( $free_mega/1024, 1 ) );
	$smarty->assign( 'free_time', rate_time( $free_mega ) );
	$smarty->assign( 'ts_rate', $ts_stream_rate );
	$smarty->assign( 'link_add', $link_add );
	$smarty->assign( 'pager', $full_mode ? '' : make_pager( 'reservationTable.php', $separate_records, $res_cnt, $page ) );
	$smarty->assign( 'menu_list', $MENU_LIST );
	$smarty->display('reservationTable.html');
}
catch( exception $e ) {
	exit( $e->getMessage() );
}
?>
