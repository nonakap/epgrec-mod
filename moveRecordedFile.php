<?php
header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
header('Last-Modified: '.gmdate("D, d M Y H:i:s").' GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

include_once('config.php');
include_once(INSTALL_PATH."/DBRecord.class.php");
include_once(INSTALL_PATH."/Settings.class.php");
include_once(INSTALL_PATH."/reclib.php");
include_once(INSTALL_PATH."/util.php");

if( !isset( $_POST['reserve_id'] ) ) {
	exit("Error: 予約IDが指定されていません" );
}
$reserve_id = $_POST['reserve_id'];

if( !is_alt_spool_writable() ){
	exit("Error: 別動画保存ディレクトリが使用できません" );
}

$settings = Settings::factory();

try {
	$rec = new DBRecord( RESERVE_TBL, "id", $reserve_id );

	$output = INSTALL_PATH.$settings->spool.'/'.$rec->path;
	$altoutput = $settings->alt_spool.'/'.pathinfo( $rec->path, PATHINFO_BASENAME );

	if( file_exists( $output ) && file_exists( $altoutput ) ){
		exit("Error: 動画保存ディレクトリと別動画保存ディレクトリの両方にファイルが存在しています" );
	}
	else if( file_exists( $output ) ){
		$mtime = @filemtime( $output );
		if( rename( $output, $altoutput ) ){
			if( $mtime !== false && file_exists( $altoutput ) ){
				touch( $altoutput, $mtime, time() );
			}
		}else{
			exit("Error: 別動画保存ディレクトリに移動できませんでした" );
		}
	}
	else if( file_exists( $altoutput ) ){
		$mtime = @filemtime( $altoutput );
		if( rename( $altoutput, $output ) ){
			if( $mtime !== false && file_exists( $output ) ){
				touch( $output, $mtime, time() );
			}
		}else{
			exit("Error: 動画保存ディレクトリに移動できませんでした" );
		}
	}
	else{
		exit("Error: 動画保存ディレクトリと別動画保存ディレクトリのどちらにもファイルがありません" );
	}
}
catch( Exception $e ) {
	exit("Error: ". $e->getMessage());
}

// スプール空き容量
$result = array();
$free_spaces = get_spool_free_space();
$result['free_size'] = $free_spaces['free_hsize'];
$result['free_time'] = $free_spaces['free_time'];
$result['ts_rate'] = $free_spaces['ts_stream_rate'];
$result['use_alt_spool'] = false;
if( use_alt_spool() ){
	$spool_disks = $free_spaces['spool_disks'];
	foreach( $spool_disks as $disk ){
		if( $disk['name'] === 'alt' && $disk['path'] === (string)$settings->alt_spool ){
			$result['use_alt_spool'] = true;
			$result['alt_free_size'] = $disk['hsize'];
			$result['alt_free_time'] = $disk['time'];
			break;
		}
	}
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
exit( json_encode( $result ) );
