<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );

$program_id = 0;
$reserve_id = 0;
$settings = Settings::factory();

if(isset($_GET['reserve_id'])) {
	$reserve_id = $_GET['reserve_id'];
	try {
		$rec = new DBRecord( RESERVE_TBL, 'id' , $reserve_id );
		$program_id = $rec->program_id;
		
		if( isset( $_GET['delete_file'] ) ) {
			if( $_GET['delete_file'] == 1 ) {
				// ファイルを削除
				$filename = pathinfo( $rec->path,  PATHINFO_BASENAME );
				@unlink( INSTALL_PATH.'/'.$settings->spool.'/'.$rec->path );
				@unlink( INSTALL_PATH.'/'.$settings->thumbs.'/'.$filename.'.jpg' );
				if( is_alt_spool_writable() ){
					@unlink( $settings->alt_spool.'/'.$filename );
				}
			}
		}
	}
	catch( Exception $e ) {
		// 無視
	}
}else if( isset($_GET['program_id'])) {
	$program_id = $_GET['program_id'];
}
else
	exit( 'error:no id' );



// 自動録画対象フラグ変更
if( isset($_GET['autorec'])) {
	$autorec = $_GET['autorec'];
	if( $program_id ) {
		try {
			$rec = new DBRecord(PROGRAM_TBL, 'id', $program_id );
			$rec->autorec = $autorec ? 0 : 1;
			$rec->update();
		}
		catch( Exception $e ) {
			// 無視
		}
	}
}

// 予約取り消し実行
try {
	$ret_code = Reservation::cancel( $reserve_id, $program_id );
}
catch( Exception $e ) {
	exit( 'Error' . $e->getMessage() );
}
exit($ret_code);
?>
