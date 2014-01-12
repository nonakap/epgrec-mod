#!/usr/bin/php
<?php
  $script_path = dirname( __FILE__ );
  chdir( $script_path );
  include_once( $script_path . "/config.php");
	include_once( INSTALL_PATH . "/reclib.php" );

// エラーハンドラ関数
function myErrorHandler($errno, $errstr, $errfile, $errline)
{
	if (!(error_reporting() & $errno)) {
		// error_reporting 設定に含まれていないエラーコードです
		return;
	}

	switch ($errno) {
	case E_USER_ERROR:
		echo "My ERROR [".$errno."] ".$errstr."\n";
		echo "  Fatal error on line ".$errline." in file ".$errfile;
		echo ", PHP " . PHP_VERSION . " (" . PHP_OS . ")\n";
		echo "Aborting...\n";
		exit(1);
		break;

	case E_USER_WARNING:
		echo "My WARNING [".$errno."] ".$errstr."\n";
		break;

	case E_USER_NOTICE:
		echo "My NOTICE [".$errno."] ".$errstr."\n";
		break;

	case 2:		// ENOENT:	// key と一致する共有メモリセグメントがなく、IPC_CREAT が指定されていません。
		echo "ENOENT [".$errno."] ".$errstr."\n";
		echo "  Fatal error on line ".$errline." in file ".$errfile;
		echo ", PHP " . PHP_VERSION . " (" . PHP_OS . ")\n";
		echo "Aborting...\n";
		exit(1);
		break;

	default:
		echo "Unknown error type: [".$errno."] ".$errstr."\n";
		echo "  Fatal error on line ".$errline." in file ".$errfile;
		echo ", PHP " . PHP_VERSION . " (" . PHP_OS . ")\n";
		break;
	}

	/* PHP の内部エラーハンドラを実行しません */
	return true;
}

	run_user_regulate();
// 定義したエラーハンドラを設定する
$old_error_handler = set_error_handler("myErrorHandler");

	$cnt = 0;
	$shm_id = shmop_open_surely();
	for( $tuner=1; $tuner<=80; $tuner++ ){
		$rv_smph = shmop_read_surely( $shm_id, $tuner );
		if( $rv_smph )
			echo $tuner."::".$rv_smph."\n";
	}
	shmop_close( $shm_id );
	exit();
?>
