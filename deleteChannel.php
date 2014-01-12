<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );


if( isset($_GET['delete_id']) ){
	try {
		$del_id = $_GET['delete_id'];
		$crec   = new DBRecord( CHANNEL_TBL, 'id', $del_id );
		$type   = $crec->type;
		$disc   = $crec->channel_disc;
		$crec->delete();

		// xx_channel.phpの編集
		$settings = Settings::factory();
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
		$f_nm      = INSTALL_PATH.'/settings/'.strtolower($type).'_channel.php';
		$st_ch     = file( $f_nm, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$key_point = array_search( $disc, array_keys( $map ) );
		if( $key_point !== FALSE ){
			if( $map[$disc] !== 'NC' ){
				array_splice( $st_ch, $key_point+3, 1 );
				$fp = fopen( $f_nm, 'w' );
				foreach( $st_ch as $ch_str )
					fwrite( $fp, $ch_str."\n" );
				fclose( $fp );
			}
		}

		// 
		if( isset($_GET['change_id']) ){
			$chg_id = (int)$_GET['change_id'];
			try {
				$arr = array();
				$arr = DBRecord::createRecords( RESERVE_TBL, "WHERE channel_id = '".$del_id."'" );
				foreach( $arr as $val ){
					$val->channel_id = $chg_id;
					$val->update();
				}
			}catch( Exception $e ) {
				exit('Error: 録画管理チャンネル情報更新失敗 ('.$del_id.'=>'.$chg_id.')' );
			}
		}
	}catch( Exception $e ) {
		exit('Error: チャンネル削除失敗 ('.$del_id.')' );
	}
	exit();
}
?>