<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );

$program_id = 0;
$reserve_id = 0;
$settings = Settings::factory();

if( isset($_GET['reserve_id']) ){
	$reserve_id = $_GET['reserve_id'];
	try{
		$rec = new DBRecord( RESERVE_TBL, 'id', $reserve_id );
		$program_id = $rec->program_id;
		try{
			$ret_code   = Reservation::cancel( $reserve_id, $program_id );
		}
		catch( Exception $e ){
			exit( 'Error' . $e->getMessage() );
		}

		// サムネイル削除
		if( file_exists(INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $rec->path )).'.jpg') )
			@unlink( INSTALL_PATH.$settings->thumbs.'/'.end(explode( '/', $rec->path )).'.jpg' );
		if( isset( $_GET['delete_file'] ) ){
			if( $_GET['delete_file'] == 1 ){
				$trans_obj = new DBRecord( TRANSCODE_TBL );
				// 変換中ジョブ対策は気が向いたら
				$del_trans = $trans_obj->fetch_array( 'rec_id', $reserve_id );
				foreach( $del_trans as $del_file ){
					@unlink($del_file['path']);
					$trans_obj->force_delete( $del_file['id'] );
				}
				// ファイルを削除
				if( file_exists( INSTALL_PATH.$settings->spool.'/'.$rec->path ) ){
					@unlink(INSTALL_PATH.$settings->spool.'/'.$rec->path);
				}
			}
		}
	}
	catch( Exception $e ){
		// 無視
	}
}else if( isset($_GET['program_id']) ){
	$program_id = $_GET['program_id'];
	// 予約取り消し実行
	try{
		$ret_code = Reservation::cancel( 0, $program_id );
	}
	catch( Exception $e ){
		exit( 'Error' . $e->getMessage() );
	}
}else
	exit( 'error:no id' );

// 自動録画対象フラグ変更
if( isset($_GET['autorec']) ){
	$autorec = $_GET['autorec'];
	if( $program_id ){
		try{
			$rec = new DBRecord(PROGRAM_TBL, 'id', $program_id );
			$rec->autorec = $autorec ? 0 : 1;
			$rec->update();
		}
		catch( Exception $e ){
			// 無視
		}
	}
}
exit($ret_code);
?>
