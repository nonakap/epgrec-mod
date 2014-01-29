<?php

// インストールパス
define( "INSTALL_PATH", dirname(__FILE__) );

// settings/gr_channel.phpが作成された場合、
// config.php内の$GR_CHANNEL_MAPは無視されます


// 首都圏用地上デジタルチャンネルマップ
// 識別子 => チャンネル番号
$GR_CHANNEL_MAP = array(
	"GR27" => "27",		// NHK
	"GR26" => "26",		// 教育
	"GR25" => "25",		// 日テレ
	"GR22" => "22",		// 東京
	"GR21" => "21",		// フジ
	"GR24" => "24",		// テレ朝
	"GR23" => "23",		// テレ東
//	"GR20" => "20",		// MX TV
//	"GR18" => "18",		// テレ神
	"GR30" => "30",		// 千葉
//	"GR32" => "32",		// テレ玉
	"GR28" => "28",		// 大学
);


/*
// 大阪地区デジタルチャンネルマップ（参考）
$GR_CHANNEL_MAP = array(
	"GR24" => "24",		// NHK
	"GR13" => "13",		// 教育
	"GR16" => "16",		// 毎日
	"GR15" => "15",		// 朝日
	"GR17" => "17",		// 関西
	"GR14" => "14",		// 読売
	"GR18" => "18",		// テレビ大阪
);
*/

/*
// 名古屋地区デジタルチャンネルマップ（参考）
$GR_CHANNEL_MAP = array(
	"GR23" => "23", // TV愛知
	"GR18" => "18", // CBC
	"GR19" => "19", // 中京TV
//	"GR27" => "27", // 三重TV
	"GR21" => "21", // 東海TV
	"GR22" => "22", // 名古屋TV (メ～テレ)
	"GR13" => "13", // NHK Educational
	"GR20" => "20", // NHK Gemeral
);
*/

// 録画モード（option）

$RECORD_MODE = array(
	// ※ 0は必須で、変更不可です。
	0 => array(
		'name' => 'Full TS',	// モードの表示名
		'suffix' => '_fl.ts',	// ファイル名のサフィックス
	),
	
	1 => array(
		'name' => 'HD TS',	// 最小のTS
		'suffix' => '.ts',	// do-record.shのカスタマイズが必要
	),
	
	2 => array(
		'name' => 'SD TS',	// CSのSD用
		'suffix' => 'SD.ts',	// do-record.shのカスタマイズが必要
	),
	
	/* Example is as follows.
	3 => array(
		'name' => '12Mbps MPEG4',
		'suffix' => '.avi',
	),
	*/
);

//
// チューナー設定
// settings/tuner.conf.php があればそれを優先する
//
if( file_exists( INSTALL_PATH."/settings/tuner.conf.php" ) ) {
	include_once( INSTALL_PATH."/settings/tuner.conf.php" );
} else {
	define( "TUNER_UNIT1", 0 );							// 第一チューナーの各放送波の論理チューナ数(地上波･衛星波で共用 ex.PT1が1枚なら2)
	define( "TUNER_UNIT2", 0 );							// 上記以外の論理チューナ数(未使用)

	// PT1キャラデバ版ドライバー使用時に変更すること
	define( "USE_RECPT1", FALSE );						// recpt1使用時はTRUEにすること
	define( "RECPT1_EPG_PATCH", FALSE );				// recpt1 EPG単独出力パッチ使用時はTRUE

	// PTシリーズ以外のチューナーの個別設定(チューナー数に応じて増やすこと)
	$OTHER_TUNERS_CHARA = array(
		// 地デジ
		'GR' => array(
			0 => array(
				'epgTs' => FALSE,			// EPG用TS出力パッチ使用時はTRUE
				'cntrl' => FALSE,			// recpt1ctl対応パッチ使用時はTRUE
				'httpS' => FALSE,			// httpサーバー機能対応時はTRUE
			),
			1 => array(
				'epgTs' => FALSE,
				'cntrl' => FALSE,
				'httpS' => FALSE,
			),
		),
		// 衛星(BS/CS)
		'BS' => array(
			0 => array(
				'epgTs' => FALSE,
				'cntrl' => FALSE,
				'httpS' => FALSE,
			),
			1 => array(
				'epgTs' => FALSE,
				'cntrl' => FALSE,
				'httpS' => FALSE,
			),
		)
	);

	// スカパー！プレミアム（対応中、ただしハードが無いのでデバッグ不可能）
	define( 'EXTRA_TUNERS', 0 );					// チューナー数
	define( 'EXTRA_NAME', 'スカパー！プレミアム' );	// 放送波名
	define( 'EX_EPG_TIME', 240 );					// EPG受信時間
	define( 'EX_EPG_CHANNEL',  'CS15_0'  );			// EPG受信Ch
	$EX_TUNERS_CHARA = array(
		0 => array(
			'epgTs' => FALSE,			// EPG用TS出力パッチ使用時はTRUE
			'cntrl' => FALSE,			// recpt1ctl対応パッチ使用時はTRUE
			'httpS' => FALSE,			// httpサーバー機能対応時はTRUE
		),
		1 => array(
			'epgTs' => FALSE,
			'cntrl' => FALSE,
			'httpS' => FALSE,
		),
	);
}


// リアルタイム視聴
define( "REALVIEW_HTTP", FALSE );					// リアルタイム視聴を有効にするときはtrueに
define( "REALVIEW_HTTP_PORT", "8888" ); 			// リアルタイム視聴ポート番号を入力する
define( "REALVIEW_PID", "/tmp/realview" );			// リアルタイム視聴チューナーPID保存テンポラリ

// EPG取得関連
define( "HIDE_CH_EPG_GET", FALSE );					// 非表示チャンネルのEPGを取得するならTRUE
define( "EXTINCT_CH_AUTO_DELETE", FALSE );			// 廃止チャンネルを自動削除するならTRUE(HIDE_CH_EPG_GET=TRUE時のみに有効・メンテナンス画面あり)

// 自動キーワ－ド予約の警告設定初期値(登録キーワード毎に変更可能)
define( 'CRITERION_CHECK', FALSE );					// 収録時間変動
define( 'REST_ALERT', FALSE );						// 番組がヒットしない場合

// セキュリティ関連
define( "SETTING_CHANGE_GIP", FALSE );				// グローバルIPからの設定変更を許可する場合はTRUE
//////////////////////////////////////////////////////////////////////////////
// 以降の変数・定数はほとんどの場合、変更する必要はありません

// 以降は必要に応じて変更する

define( 'MANUAL_REV_PRIORITY', 10 );				// 手動予約の優先度
define( 'HTTPD_USER', 'www-data' );					// HTTPD(apache)アカウント
define( 'HTTPD_GROUP', 'www-data' );					// HTTPD(apache)アカウント
define( "PADDING_TIME", 180 );						// 詰め物時間(変更禁止)
define( "DO_RECORD", INSTALL_PATH . "/do-record.sh" );		// レコードスクリプト
define( "COMPLETE_CMD", INSTALL_PATH . "/recomplete.php" );	// 録画終了コマンド
define( "GEN_THUMBNAIL", INSTALL_PATH . "/gen-thumbnail.sh" );	// サムネール生成スクリプト
define( 'PS_CMD', 'ps -u '.HTTPD_USER.' -f' );			// HTTPD(apache)アカウントで実行中のコマンドPID取得に使用
define( "RECPT1_CTL", "/usr/local/bin/recpt1ctl" );		// recpt1のコントロールコマンド
define( 'FIRST_REC', 80 );							// EPG[schedule]受信時間
define( 'SHORT_REC', 6 );							// EPG[p/f]受信時間
define( 'REC_RETRY_LIMIT', 60 );					// 録画再試行時間
define( "GR_PT1_EPG_SIZE", (int)(1.1*1024*1024) );	// GR EPG TSファイルサイズ(PT1)
define( "BS_PT1_EPG_SIZE", (int)(5.5*1024*1024) );	// BS EPG TSファイルサイズ(PT1)
define( "CS_PT1_EPG_SIZE", (int)(4*1024*1024) );	// CS EPG TSファイルサイズ(PT1)
define( "GR_OTH_EPG_SIZE", (int)(170*1024*1024) );	// GR EPG TSファイルサイズ
define( "BS_OTH_EPG_SIZE", (int)(170*3*1024*1024) );	// BS EPG TSファイルサイズ
define( "CS_OTH_EPG_SIZE", (int)(170*2*1024*1024) );	// CS EPG TSファイルサイズ
define( "GR_XML_SIZE", (int)(300*1024) );	// GR EPG XMLファイルサイズ
define( "BS_XML_SIZE", (int)(4*1024*1024) );	// BS EPG XMLファイルサイズ
define( "TS_STREAM_RATE", 110 );					// １分あたりのTSサイズ(MB・ストレージ残り時間計算用)

// PT1_REBOOTをTRUEにする場合は、root権限で visudoコマンドを実行して
// www-data ALL = (ALL) NOPASSWD: /sbin/shutdown
// の一行を追加してください。詳しくは visudoを調べてください。

define( "PT1_REBOOT", FALSE );							// PT1が不安定なときにリブートするかどうか
define( "REBOOT_CMD", 'sudo /sbin/shutdown -r now' );	// リブートコマンド
//define( 'REBOOT_CMD', 'sudo '.INSTALL_PATH.'/driver_reset.sh' );	// pt1ドライバー再読込み こっちにする場合は、modprobeをHTTPDから使えるようにして
define( 'REBOOT_COMMENT', 'PT2 is out of order: SYSTEM REBOOT ' );

// BS/CSでEPGを取得するチャンネル
// 通常は変える必要はありません
// BSでepgdumpが頻繁に落ちる場合は、受信状態のいいチャンネルに変えることで
// 改善するかもしれません

define( "BS_EPG_CHANNEL",  "BS15_0"  );	// BS

define( "CS1_EPG_CHANNEL", "CS2" );	// CS1 2,8,10
define( "CS2_EPG_CHANNEL", "CS4" );	// CS2 4,6,12,14,16,18,20,22,24


// DBテーブル情報　以下は変更しないでください
define( 'RESERVE_TBL',  'reserveTbl' );						// 予約テーブル
define( 'PROGRAM_TBL',  'programTbl' );						// 番組表
define( 'CHANNEL_TBL',  'channelTbl' );						// チャンネルテーブル
define( 'CATEGORY_TBL', 'categoryTbl' );					// カテゴリテーブル
define( 'KEYWORD_TBL', 'keywordTbl' );						// キーワードテーブル
// ログテーブル
define( 'LOG_TBL', 'logTbl' );

// 全国用BSデジタルチャンネルマップ
check_ch_map( 'bs_channel.php' );
include_once( INSTALL_PATH.'/settings/bs_channel.php' );

// 全国用CSデジタルチャンネルマップ
check_ch_map( 'cs_channel.php' );
include_once( INSTALL_PATH.'/settings/cs_channel.php' );

// スカパー！プレミアム・チャンネルマップ
if( EXTRA_TUNERS ){
	check_ch_map( 'ex_channel.php' );
	include_once( INSTALL_PATH.'/settings/ex_channel.php' );
}

// 地上デジタルチャンネルテーブルsettings/gr_channel.phpが存在するならそれを
// 優先する
if( check_ch_map( 'gr_channel.php', isset( $GR_CHANNEL_MAP ) ) ){
	unset($GR_CHANNEL_MAP);
	include_once( INSTALL_PATH.'/settings/gr_channel.php' );
}


// セキュリティ強化
if( isset($_SERVER['REMOTE_ADDR']) ){
	if( $_SERVER['REMOTE_ADDR'] === '127.0.0.1' ){
		$NET_AREA = 'H';			// local host
	}else
	if( strncmp( $_SERVER['REMOTE_ADDR'], '192.168.', 8 ) === 0 ){
		$NET_AREA = 'C';			// class C
	}else
	if( strncmp($_SERVER['REMOTE_ADDR'], '10.', 3 ) === 0 ){
		$NET_AREA = 'A';			// class A
	}else{
		$adrs = explode( '.', $_SERVER['REMOTE_ADDR'] );
		if( $adrs[0]==='172' && ((int)$adrs[1]&0xf0)==0x10 )
			$NET_AREA = 'B';			// class B
		else
			$NET_AREA = 'G';			// blobal
	}
}else
	$NET_AREA = FALSE;
$AUTHORIZED = isset($_SERVER['REMOTE_USER']);

// グローバルIPからのアクセスにHTTP認証を強要
if( $NET_AREA==='G' && !$AUTHORIZED && ( !defined('HTTP_AUTH_GIP') || HTTP_AUTH_GIP ) ){
/*
	echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n";
	echo "<html><head>\n";
	echo "<title>404 Not Found</title>\n";
	echo "</head><body>\n";
	echo "<h1>Not Found</h1>\n";
	echo "<p>The requested URL ".$_SERVER['PHP_SELF']." was not found on this server.</p>\n";
	echo "<hr>\n";
	echo "<address>".$_SERVER['SERVER_SOFTWARE']." Server at ".$_SERVER['SERVER_ADDR']." Port 80</address>;\n";
	echo "</body></html>\n";
*/
	$alert_msg = 'グローバルIPからのアクセスにHTTP認証が設定されていません。IP::['.$_SERVER['REMOTE_ADDR'].'('.$_SERVER['REMOTE_HOST'].')] SCRIPT::['.$_SERVER['PHP_SELF'].']';
	include_once( INSTALL_PATH . '/DBRecord.class.php' );
	include_once( INSTALL_PATH . '/recLog.inc.php' );
	reclog( $alert_msg, EPGREC_WARN );
	exit;
}

// チャンネルMAPファイルを操作された場合(削除･不正コード挿入など)を想定
// epgrecUNA以外からの操作が可能なため対応
function check_ch_map( $ch_file, $gr_safe=FALSE )
{
	$inc_file = INSTALL_PATH.'/settings/'.$ch_file;
	if( file_exists( $inc_file ) ){
		if( filesize( $inc_file ) > 0 ){
			$rd_data = file_get_contents( $inc_file );
			$search  = '$'.strtoupper( substr( $ch_file, 0, 2 ) ).'_CHANNEL_MAP';
			if( strpos( $rd_data, $search )!==FALSE && strpos( $rd_data, ");\n?>" )!==FALSE ){
				if( substr_count( $rd_data, ';' ) == 1 ){
					return TRUE;
				}
			}
		}
	}
	if( $gr_safe )
		return FALSE;
	else{
		include_once( INSTALL_PATH . '/DBRecord.class.php' );
		include_once( INSTALL_PATH . '/recLog.inc.php' );
		reclog( $inc_file.' が壊れているか不正コードが挿入されている可能性があります。ファイルを確認してください。', EPGREC_ERROR );
		exit;
	}
}
?>
