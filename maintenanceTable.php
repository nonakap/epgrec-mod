<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );
include_once( INSTALL_PATH . '/util.php' );

$settings = Settings::factory();


// チャンネル選別抽出
function get_channels( $type )
{
	global $BS_CHANNEL_MAP;
	global $CS_CHANNEL_MAP;
	global $EX_CHANNEL_MAP;

	switch( $type ){
		case 'BS':
			$map = $BS_CHANNEL_MAP;
			break;
		case 'CS':
			$map = $CS_CHANNEL_MAP;
			break;
		case 'EX':
			$map = $EX_CHANNEL_MAP;
			break;
	}
	$ext_pac = array();
	$cer_pac = array();
	try{
		$channel = DBRecord::createRecords( CHANNEL_TBL, "WHERE type = '".$type."' ORDER BY sid" );
		foreach( $channel as $ch ){
			$arr = array();
			$arr['id']           = (int)$ch->id;
			$arr['type']         = $type;
			$arr['sid']          = (int)$ch->sid;
			$arr['channel_disc'] = $ch->channel_disc;
			$arr['channel']      = $ch->channel;
			$arr['name']         = $ch->name;
			$arr['skip']         = (boolean)$ch->skip;
			if( $map[$arr['channel_disc']] !== 'NC' ){
				if( DBRecord::countRecords( PROGRAM_TBL , 'WHERE channel_id = '.$arr['id'] ) == 0 ){
					// 廃止チャンネル
					$arr['rec'] = DBRecord::countRecords( RESERVE_TBL, "WHERE channel_id = '".$arr['id']."' AND complete = '1'");
					array_push( $ext_pac, $arr );
				}else
					array_push( $cer_pac, $arr );
			}else{
				$arr['rec'] = DBRecord::countRecords( RESERVE_TBL, "WHERE channel_id = '".$arr['id']."' AND complete = '1'");
				array_push( $ext_pac, $arr );
			}
		}
	}catch( Exception $e ){
	}
	return array( $ext_pac, $cer_pac );
}

	// 廃止チャンネル管理
	$ext_chs = array();
	$cer_chs = array();
	if( (int)$settings->bs_tuners != 0 ){
		$bs_pac = get_channels( 'BS' );
		if( (boolean)$settings->cs_rec_flg ){
			$cs_pac  = get_channels( 'CS' );
			$ext_chs = array_merge( $bs_pac[0], $cs_pac[0] );
			$cer_chs = array_merge( $bs_pac[1], $cs_pac[1] );
		}else{
			$ext_chs = $bs_pac[0];
			$cer_chs = $bs_pac[1];
		}
	}
	if( EXTRA_TUNERS ){
		$ex_pac = get_channels( 'EX' );
		if( (int)$settings->bs_tuners != 0 ){
			$ext_chs = array_merge( $ext_chs, $ex_pac[0] );
			$cer_chs = array_merge( $cer_chs, $ex_pac[1] );
		}else{
			$ext_chs = $ex_pac[0];
			$cer_chs = $ex_pac[1];
		}
	}

	// スプール空き容量
	$free_spaces = get_spool_free_space( true );
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
	$smarty->assign( 'link_add',      $link_add );
	$smarty->assign( 'menu_list',     $MENU_LIST );
	$smarty->assign( 'free_size',     $free_size );
	$smarty->assign( 'free_time',     $free_time );
	$smarty->assign( 'ts_rate',       $ts_stream_rate );
	$smarty->assign( 'spool_disks',   $spool_disks );
	$smarty->assign( 'ext_chs',       $ext_chs );
	$smarty->assign( 'cer_chs',       $cer_chs );
	$smarty->assign( 'epg_get',       HIDE_CH_EPG_GET  );
	$smarty->assign( 'auto_del',      EXTINCT_CH_AUTO_DELETE );
	$smarty->display('maintenanceTable.html');
?>
