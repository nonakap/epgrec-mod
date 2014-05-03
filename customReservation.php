<?php
include_once('config.php');
include_once( INSTALL_PATH . "/DBRecord.class.php" );
include_once( INSTALL_PATH . "/reclib.php" );
include_once( INSTALL_PATH . "/Reservation.class.php" );
include_once( INSTALL_PATH . "/Settings.class.php" );

$settings = Settings::factory();

$program_id = 0;
if( isset( $_POST['program_id'] ) ) $program_id = $_POST['program_id'];


if(!(
   isset($_POST['shour'])       && 
   isset($_POST['smin'])        &&
   isset($_POST['smonth'])      &&
   isset($_POST['sday'])        &&
   isset($_POST['syear'])       &&
   isset($_POST['ehour'])       &&
   isset($_POST['emin'])        &&
   isset($_POST['emonth'])      &&
   isset($_POST['eday'])        &&
   isset($_POST['eyear'])       &&
   isset($_POST['channel_id'])  &&
   isset($_POST['title'])       &&
   isset($_POST['description']) &&
   isset($_POST['category_id']) &&
   isset($_POST['record_mode']) &&
   isset($_POST['discontinuity']) &&
   isset($_POST['priority'])
)) {
	exit("Error:予約に必要な値がセットされていません");
}


$start_time = @mktime( $_POST['shour'], $_POST['smin'], $_POST['ssec'], $_POST['smonth'], $_POST['sday'], $_POST['syear'] );
if( ($start_time < 0) || ($start_time === false) ) {
	exit("Error:開始時間が不正です" );
}

$end_time = @mktime( $_POST['ehour'], $_POST['emin'], $_POST['esec'], $_POST['emonth'], $_POST['eday'], $_POST['eyear'] );
if( ($end_time < 0) || ($end_time === false) ) {
	exit("Error:終了時間が不正です" );
}

$channel_id = $_POST['channel_id'];
$title = $_POST['title'];
$description = $_POST['description'];
$category_id = $_POST['category_id'];
$mode = $_POST['record_mode'];
$discontinuity = $_POST['discontinuity'];
$priority = $_POST['priority'];


$rval = 0;
try{
	$rval = Reservation::custom(
		toDatetime($start_time),
		toDatetime($end_time),
		$channel_id,
		$title,
		$description,
		$category_id,
		$program_id,
		0,		// 自動録画
		$mode,	// 録画モード
		$discontinuity,
		1,		// ダーティフラグ
		$priority
	);
}
catch( Exception $e ) {
	exit( "Error:".$e->getMessage() );
}
if( isset( $RECORD_MODE[$mode]['tsuffix'] ) ){
	// 手動予約のトラコン設定
	list( , , $rec_id, ) = explode( ':', $rval );
	$tex_obj = new DBRecord( TRANSEXPAND_TBL );
	$tex_obj->key_id  = 0;
	$tex_obj->type_no = $rec_id;
	$tex_obj->mode    = $mode;
	$tex_obj->ts_del  = isset($_POST['ts_del']) ? $_POST['ts_del'] : 0;
	$tex_obj->dir     = '';
	$tex_obj->update();
}
exit( $rval );
?>