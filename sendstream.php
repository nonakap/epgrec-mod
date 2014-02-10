<?php
header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');
header('Last-Modified: '. gmdate("D, d M Y H:i:s"). ' GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');


include_once("config.php");
include_once(INSTALL_PATH . "/DBRecord.class.php" );
include_once(INSTALL_PATH . "/reclib.php" );
include_once(INSTALL_PATH . "/Settings.class.php" );
include_once(INSTALL_PATH . "/util.php" );

$settings = Settings::factory();

if( ! isset( $_GET['reserve_id'] )) jdialog("予約番号が指定されていません", "recordedTable.php");
$reserve_id = $_GET['reserve_id'];


try{
	$rrec = new DBRecord( RESERVE_TBL, "id", $reserve_id );

	$start_time = toTimestamp($rrec->starttime);
	$end_time = toTimestamp($rrec->endtime );
	$duration = $end_time - $start_time;

	$size = 3 * 1024 * 1024 * $duration;	// 1秒あたり3MBと仮定

	$extension = pathinfo( $rrec->path, PATHINFO_EXTENSION );
	if( $extension === 'ts' ){
		header('Content-type: video/mp2t');	// video/x-mp2t-mphl-188?
	}else
	if( $extension === 'mp4' ){
		header('Content-type: video/mp4');
	}else{
		header('Content-type: video/mpeg');
	}
	$filename = pathinfo( $rrec->path, PATHINFO_BASENAME );
	header('Content-Disposition: inline; filename="'.$filename.'"');
	header('Content-Length: ' . $size );

	while (ob_get_level() > 0)
		ob_end_clean();
	flush();

	$moviepath = INSTALL_PATH.$settings->spool.'/'.$rrec->path;
	if( use_alt_spool() ){
		$altmoviepath = $settings->alt_spool.'/'.$filename;
		if( !@is_dir( $altmoviepath ) && @is_readable( $altmoviepath ) ){
			$moviepath = $altmoviepath;
		}
	}
	$fp = @fopen( $moviepath, "r" );
	if( $fp !== false ) {
		do {
			$start = microtime(true);
			if( feof( $fp ) ) break;
			echo fread( $fp, 6292 );
			@usleep( 2000 - (int)((microtime(true) - $start) * 1000 * 1000));
		}
		while( connection_aborted() == 0 );
		fclose($fp);
	}
}
catch(exception $e ) {
	exit( $e->getMessage() );
}
?>
