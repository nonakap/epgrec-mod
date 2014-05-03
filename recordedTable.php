<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );

$settings = Settings::factory();

$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );


$search      = '';
$category_id = 0;
$station     = 0;
$key_id      = FALSE;
$page        = 1;
$full_mode   = FALSE;


$options = 'starttime<\''. date('Y-m-d H:i:s').'\'';	// ながら再生は無理っぽい？

$rev_obj = new DBRecord( RESERVE_TBL );

$act_trans = array_key_exists( 'tsuffix', end($RECORD_MODE) );
if( $act_trans )
	$trans_obj = new DBRecord( TRANSCODE_TBL );

if( isset( $_GET['key']) )
	$key_id = (int)trim($_GET['key']);
else
	if( isset( $_POST['key_id']) )
		$key_id = (int)$_POST['key_id'];

$rev_opt = $key_id!==FALSE ? ' AND autorec='.$key_id : '';


if( isset($_POST['do_search']) ){
	if( isset($_POST['search']) ){
		if( $_POST['search'] != '' ){
			$search = $_POST['search'];
			foreach( explode( ' ', trim($search) ) as $key ){
				$k_len = strlen( $key );
				if( $k_len>1 && $key[0]==='-' ){
					$k_len--;
					$key      = substr( $key, 1 );
					$rev_opt .= ' AND CONCAT(title,\' \', description) NOT LIKE ';
				}else
					$rev_opt .= ' AND CONCAT(title,\' \', description) LIKE ';
				if( $key[0]==='"' && $k_len>2 && $key[$k_len-1]==='"' )
					$key = substr( $key, 1, $k_len-2 );
				$rev_opt .= '\'%'.$rev_obj->sql_escape( $key ).'%\'';
			}
		}
	}
	if( isset($_POST['category_id']) ){
		if( $_POST['category_id'] != 0 ){
			$category_id = $_POST['category_id'];
			$rev_opt    .= ' AND category_id='.$_POST['category_id'];
		}
	}
	if( isset($_POST['station']) ){
		if( $_POST['station'] != 0 ){
			$station  = $_POST['station'];
			$rev_opt .= ' AND channel_id='.$_POST['station'];
		}
	}
	if( isset($_POST['full_mode']) )
		$full_mode = $_POST['full_mode'];
}

if( isset($_POST['do_delete']) ){
	$delete_file = isset($_POST['delrec']);
	$id_list     = $rev_obj->fetch_array( null, null, 'complete=1'.$rev_opt );
	if( isset($_POST['delall']) ){
		$del_list    = $id_list;
		$rev_opt     = '';
		$search      = '';
		$category_id = 0;
		$station     = 0;
		$key_id      = FALSE;
	}else{
		$del_list = array();
		foreach( $id_list as $del_id ){
			if( isset($_POST['del'.$del_id['id']]) )
				array_push( $del_list, $del_id );
		}
	}

	foreach( $del_list as $rec ){
		// 予約取り消し実行
		try {
			$ret_code = Reservation::cancel( $rec['id'], 0 );
		}catch( Exception $e ){
			// 無視
		}
		// サムネイル削除
		if( file_exists(INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $rec['path'] )).'.jpg') )
			@unlink( INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $rec['path'] )).'.jpg' );
		if( $delete_file ){
			// トラコンファイル削除
			if( $act_trans ){
				// 変換中ジョブ対策は気が向いたら
				$del_trans = $trans_obj->fetch_array( 'rec_id', $rec['id'] );
				foreach( $del_trans as $del_file ){
					@unlink($del_file['path']);
					$trans_obj->force_delete( $del_file['id'] );
				}
			}
			// ファイルを削除
			if( file_exists( INSTALL_PATH.$settings->spool.'/'.$rec['path'] ) ){
				@unlink(INSTALL_PATH.$settings->spool.'/'.$rec['path']);
			}
		}
	}
}


try{
	$ch_list   = $rev_obj->distinct( 'channel_id', 'WHERE '.$options );
	$ch_opt    = count( $ch_list ) ? ' AND id IN ('.implode( ',', $ch_list ).')' : '';
	$stations  = array();
	$chid_list = array();
	$stations[0]['id']       = $chid_list[0] = 0;
	$stations[0]['name']     = 'すべて';
	$stations[0]['selected'] = (! $station) ? 'selected' : '';
	$stations[0]['count']    = 0;
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'GR\' AND skip=0'.$ch_opt.' ORDER BY id' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'BS\' AND skip=0'.$ch_opt.' ORDER BY sid' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'CS\' AND skip=0'.$ch_opt.' ORDER BY sid' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, 'WHERE type=\'EX\' AND skip=0'.$ch_opt.' ORDER BY sid' );
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $chid_list[] = (int)$c->id;
		$arr['name']     = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $stations, $arr );
	}
//	$chid_list = array_column( $stations, 'id' );		// PHP5.5

	$cat_list = $rev_obj->distinct( 'category_id', 'WHERE '.$options );
	$cat_opt  = count( $ch_list ) ? 'WHERE id IN ('.implode( ',', $cat_list ).')' : '';
	$crecs    = DBRecord::createRecords( CATEGORY_TBL, $cat_opt );
	$cats     = array();
	$cats[0]['id'] = 0;
	$cats[0]['name'] = 'すべて';
	$cats[0]['selected'] = $category_id == 0 ? 'selected' : '';
	$cats[0]['count']    = 0;
	foreach( $crecs as $c ){
		$arr = array();
		$arr['id']       = $c->id;
		$arr['name']     = $c->name_jp;
		$arr['selected'] = $c->id == $category_id ? 'selected' : '';
		$arr['count']    = 0;
		array_push( $cats, $arr );
	}


	$rvs = $rev_obj->fetch_array( null, null, $options.$rev_opt.' ORDER BY starttime DESC' );
	$stations[0]['count'] = $cats[0]['count'] = count( $rvs );

	if( ( SEPARATE_RECORDS_RECORDED===FALSE &&  SEPARATE_RECORDS<1 ) || ( SEPARATE_RECORDS_RECORDED!==FALSE && SEPARATE_RECORDS_RECORDED<1 ) )	// "<1"にしているのはフェイルセーフ
		$full_mode = TRUE;
	else{
		if( isset( $_GET['page']) ){
			if( $_GET['page'] === '-' )
				$full_mode = TRUE;
			else
				$page = (int)$_GET['page'];
		}
		$separate_records = SEPARATE_RECORDS_RECORDED!==FALSE ? SEPARATE_RECORDS_RECORDED : SEPARATE_RECORDS;
		$view_overload    = VIEW_OVERLOAD_RECORDED!==FALSE ? VIEW_OVERLOAD_RECORDED : VIEW_OVERLOAD;
		if( $stations[0]['count'] <= $separate_records+$view_overload )
			$full_mode = TRUE;
	}

	if( $full_mode ){
		$start_record = 0;
		$end_record   = $stations[0]['count'];
	}else{
		$start_record = ( $page - 1 ) * $separate_records;
		$end_record   = $page * $separate_records;
	}

	$records = array();
	foreach( $rvs as $key => $r ){
		$arr = array();
		if( (int)$r['channel_id'] ){
			$chid_key = array_search( (int)$r['channel_id'], $chid_list );
			if( $chid_key !== FALSE ){
				$arr['station_name'] = $stations[$chid_key]['name'];
				$stations[$chid_key]['count']++;
			}else{
				$arr['station_name'] = 'lost';
			}
		}else
			$arr['station_name'] = 'lost';
		$arr['cat'] = (int)$r['category_id'];
		if( $arr['cat'] ){
			$cat_key = array_search( $arr['cat'], $cat_list );
			if( $cat_key !== FALSE )
				$cats[$cat_key+1]['count']++;
		}
		if( $start_record<=$key && $key<$end_record ){
			$arr['id']          = (int)$r['id'];
			$start_time         = toTimestamp($r['starttime']);
			$end_time           = toTimestamp($r['endtime']);
			$arr['starttime']   = date( 'Y/m/d(', $start_time ).$week_tb[date( 'w', $start_time )].')<br>'.date( 'H:i:s', $start_time );
			$arr['duration']    = date( 'H:i:s', $end_time-$start_time-9*60*60 );
			$arr['asf']         = $settings->install_url.'/viewer.php?reserve_id='.$r['id'];
			$arr['title']       = htmlspecialchars($r['title'],ENT_QUOTES);
			$arr['description'] = htmlspecialchars($r['description'],ENT_QUOTES);
			if( file_exists(INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $r['path'] )).'.jpg') )
				$arr['thumb'] = '<img src="'.$settings->install_url.$settings->thumbs.'/'.rawurlencode(end(explode( '/', $r['path'] ))).'.jpg" />';
			else
				$arr['thumb'] = '';
			$arr['keyword']     = putProgramHtml( $arr['title'], '*', 0, $r['category_id'], 16 );
			$arr['key_id']      = (int)$r['autorec'];
			if( $arr['key_id'] && DBRecord::countRecords( KEYWORD_TBL, 'WHERE id='.$arr['key_id'] ) == 0 ){
				$arr['key_id'] = $wrt_set['autorec'] = 0;
				$rev_obj->force_update( $r['id'], $wrt_set );
			}
			if( file_exists( INSTALL_PATH.$settings->spool.'/'.$r['path'] ) ){
				$arr['view_set'] = '<a href="'.$arr['asf'].'" title="クリックすると視聴できます（ブラウザの設定でASFとVLCを関連付けている必要があります）"'.
									' style="white-space: pre; background-color: '.(time()<$end_time ? 'greenyellow' : 'limegreen').'; color: black;"> '.
									(isset($RECORD_MODE[$r['mode']]['tsuffix']) ? 'TS' : $RECORD_MODE[$r['mode']]['name']).' </a>';
			}else
				$arr['view_set'] = '';
			if( $act_trans ){
				$tran_ex = $trans_obj->fetch_array( 'rec_id', $arr['id'] );
				foreach( $tran_ex as $loop => $tran_unit ){
					$element = '';
					switch( $tran_unit['status'] ){
						case 0:
							$element = '<a style="white-space: pre; background-color: yellow;"> '.$RECORD_MODE[$tran_unit['mode']]['name'].' </a>';
							break;
						case 1:
							$element = '<a style="white-space: pre; background-color: greenyellow;" href="'.
																						$arr['asf'].'&trans='.$tran_unit['id'].'"> '.$tran_unit['name'].' </a>';
							break;
						case 2:
							if( file_exists( $tran_unit['path'] ) ){
								$element = '<a style="white-space: pre; background-color: limegreen; color: black" href="'.
																						$arr['asf'].'&trans='.$tran_unit['id'].'"> '.$tran_unit['name'].' </a>';
							}else
								$trans_obj->force_delete( $tran_unit['id'] );
							break;
						case 3:
							$element = '<a style="white-space: pre; background-color: red; color: white;"> '.$tran_unit['name'].' </a>';
							break;
					}
					if( $element !== '' ){
						if( $arr['view_set'] !== '' )
							$arr['view_set'] .= '<br>';
						$arr['view_set'] .= $element;
					}
				}
			}
			if( $arr['view_set'] === '' )
				$arr['view_set'] = '<a style="white-space: pre;"><del> '.$RECORD_MODE[$r['mode']]['name'].' </del></a>';
			array_push( $records, $arr );
		}
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
	$smarty->assign('sitetitle','録画済一覧'.($key_id!==FALSE ? ' No.'.$key_id : '') );
	$smarty->assign( 'records', $records );
	$smarty->assign( 'search', $search );
	$smarty->assign( 'stations', $stations );
	$smarty->assign( 'cats', $cats );
	$smarty->assign( 'key_id', $key_id );
	$smarty->assign( 'station', $station );
	$smarty->assign( 'category_id', $category_id );
	$smarty->assign( 'use_thumbs', $settings->use_thumbs );
	$smarty->assign( 'full_mode', $full_mode );
	$smarty->assign( 'pager', $full_mode ? '' : make_pager( 'recordedTable.php', $separate_records, $stations[0]['count'], $page ) );
	$smarty->assign( 'link_add', $link_add );
	$smarty->assign( 'menu_list', $MENU_LIST );
	$smarty->display('recordedTable.html');
}
catch( exception $e ){
	exit( $e->getMessage() );
}
?>
