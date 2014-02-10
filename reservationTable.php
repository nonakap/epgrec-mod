<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );
include_once( INSTALL_PATH . '/util.php' );

$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );

try{
	$settings = Settings::factory();

	$reservations = array();
	$rvs = DBRecord::createRecords(RESERVE_TBL, "WHERE complete='0' ORDER BY starttime ASC" );
	foreach( $rvs as $r ) {
		$end_time = toTimestamp($r->endtime);
		if( $end_time < time() ){
			$r->complete = 1;
			$r->update();
			continue;
		}
		$ch  = new DBRecord(CHANNEL_TBL, 'id', $r->channel_id );
		$cat = new DBRecord(CATEGORY_TBL, 'id', $r->category_id );
		if( $r->program_id ){
			try{
				$prg = new DBRecord(PROGRAM_TBL, "id", $r->program_id );
				$sub_genre = $prg->sub_genre;
			}catch( exception $e ) {
				reclog( 'reservationTable.php::予約ID:'.$r->id.'  '.$e->getMessage(), EPGREC_ERROR );
				$sub_genre = 16;
			}
		}else
			$sub_genre = 16;
		$arr = array();
		$arr['id'] = $r->id;
		$arr['type'] = $r->type;
		$arr['tuner'] = $r->tuner;
		$arr['channel'] = $r->channel;
		$arr['channel_name'] = $ch->name;
		$start_time = toTimestamp($r->starttime);
		$arr['date'] = date( 'm/d(', $start_time ).$week_tb[date( 'w', $start_time )].')';
		$arr['starttime'] = date( 'H:i:s-', $start_time );
		$arr['endtime'] = !$r->shortened ? date( 'H:i:s', $end_time ) : '<font color="#0000ff">'.date( 'H:i:s', $end_time ).'</font>';
		$arr['duration'] = date( 'H:i:s', $end_time-$start_time-9*60*60 );
		$arr['prg_top'] = date( 'YmdH', $start_time-60*60*1 );
		$arr['mode'] = $RECORD_MODE[$r->mode]['name'];
		$arr['title'] = $r->title;
		$arr['description'] = $r->description;
		$arr['cat'] = $cat->name_en;
		$arr['autorec'] = $r->autorec ? $r->autorec : '□';
		$arr['keyword'] = putProgramHtml( $arr['title'], $r->type, $r->channel_id, $r->category_id, $sub_genre );
		array_push( $reservations, $arr );
	}

	// スプール空き容量
	$free_spaces = get_spool_free_space();
	$free_size = $free_spaces['free_hsize'];
	$free_time = $free_spaces['free_time'];
	$ts_stream_rate = $free_spaces['ts_stream_rate'];
	if( use_alt_spool() ){
		$spool_disks = $free_spaces['spool_disks'];
		foreach( $spool_disks as $disk ){
			if( $disk['name'] === 'alt' && $disk['path'] === (string)$settings->alt_spool ){
				$alt_free_size = $disk['hsize'];
				$alt_free_time = $disk['time'];
				break;
			}
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
	$smarty->assign( 'sitetitle','録画予約一覧');
	$smarty->assign( 'reservations', $reservations );
	$smarty->assign( 'free_size', $free_size );
	$smarty->assign( 'free_time', $free_time );
	$smarty->assign( 'ts_rate', $ts_stream_rate );
	$smarty->assign( 'use_alt_spool', isset( $alt_free_size ) && isset( $alt_free_time ) ? 1 : 0 );
	$smarty->assign( 'alt_free_size', isset( $alt_free_size ) ? $alt_free_size : 0 );
	$smarty->assign( 'alt_free_time', isset( $alt_free_time ) ? $alt_free_time : 0 );
	$smarty->assign( 'link_add', $link_add );
	$smarty->assign( 'menu_list', $MENU_LIST );
	$smarty->display('reservationTable.html');
}
catch( exception $e ) {
	exit( $e->getMessage() );
}
?>
