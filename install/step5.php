<?php
include_once( "../config.php");
include_once( INSTALL_PATH."/Settings.class.php" );

$settings = Settings::factory();

if( isset( $_GET['script'] ) )
	$epg_rec = $_GET['script'];
else
	exit();
if( isset( $_GET['time'] ) )
	$rec_time = $_GET['time'];
else
	exit();

echo 'EPGの初回受信を行います。'.$rec_time.'分程度後に<a href="'.$settings->install_url.'">epgrecのトップページ</a>を開いてください。';

@exec( INSTALL_PATH.$epg_rec.' >/dev/null 2>&1 &' );

exit();

?>