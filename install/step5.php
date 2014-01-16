<?php
include_once( "../config.php");
include_once( INSTALL_PATH."/Settings.class.php" );

$settings = Settings::factory();

if( isset( $_GET['script'] ) ){
	$epg_rec = $_GET['script'];
	if( !file_exists( INSTALL_PATH.$epg_rec ) ){
		$alert_msg = '不法侵入者による攻撃を受けました。['.$_SERVER['REMOTE_HOST'].'('.$_SERVER['REMOTE_ADDR'].")]\nSCRIPT::[".$epg_rec.']';
		reclog( $alert_msg, EPGREC_WARN );
		file_put_contents( INSTALL_PATH.$settings->spool.'/alert.log', date("Y-m-d H:i:s").' '.$alert_msg."\n", FILE_APPEND );
		syslog( LOG_WARNING, $alert_msg );
		exit();
	}
}else
	exit();
if( isset( $_GET['time'] ) )
	$rec_time = $_GET['time'];
else
	exit();

echo 'EPGの初回受信を行います。'.$rec_time.'分程度後に<a href="'.$settings->install_url.'">epgrecのトップページ</a>を開いてください。';

@exec( INSTALL_PATH.$epg_rec.' >/dev/null 2>&1 &' );

exit();

?>