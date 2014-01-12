<?php
include_once("../config.php");
include_once("../Smarty/Smarty.class.php");
include_once("../DBRecord.class.php");
include_once("../Settings.class.php");
include_once("../reclib.php" );
include_once("../tableStruct.inc.php");

function cat_house( $id, $cat_ja, $cat_en ){
	$cat_rec = new DBRecord( CATEGORY_TBL );
//	$sql = "INSERT INTO `epgrec`.`".CATEGORY_TBL."` (`id`, `name_jp`, `name_en`, `category_disc`) VALUES ('".$id."', '".$cat_ja."', '".$cat_en."', '".md5( $cat_ja.$cat_en )."');";
	$cat_rec->name_jp       = $cat_ja;
	$cat_rec->name_en       = $cat_en;
	$cat_rec->category_disc = md5( $cat_ja . $cat_en );
}

$settings = Settings::factory();
$settings->post();	// いったん保存する
$settings->save();

// データベース接続チェック
$dbh = @mysql_connect( $settings->db_host, $settings->db_user, $settings->db_pass );
if( $dbh == false ) {
	jdialog( "MySQLに接続できません。ホスト名/ユーザー名/パスワードを再チェックしてください", "step2.php" );
	exit();
}

$sqlstr = "use ".$settings->db_name;
$res = @mysql_query( $sqlstr );
if( $res == false ) {
	jdialog( "データベース名が異なるようです", "step2.php" );
	exit();
}

// DBテーブルの作成

try {
    $rec = new DBRecord( RESERVE_TBL );
    $rec->createTable( RESERVE_STRUCT );

    $rec = new DBRecord( PROGRAM_TBL );
    $rec->createTable( PROGRAM_STRUCT );

    $rec = new DBRecord( CHANNEL_TBL );
    $rec->createTable( CHANNEL_STRUCT );

    $rec = new DBRecord( CATEGORY_TBL );
    $rec->createTable( CATEGORY_STRUCT );
    
    $rec = new DBRecord( KEYWORD_TBL );
    $rec->createTable( KEYWORD_STRUCT );

    $rec = new DBRecord( LOG_TBL );
    $rec->createTable( LOG_STRUCT );
}
catch( Exception $e ) {
	jdialog("テーブルの作成に失敗しました。データベースに権限がない等の理由が考えられます。", "step2.php" );
	exit();
}

// ジャンル登録
cat_house(  1, "ニュース・報道", "news" );
cat_house(  2, "スポーツ", "sports" );
cat_house(  3, "情報", "information" );
cat_house(  4, "ドラマ", "drama" );
cat_house(  5, "音楽", "music" );
cat_house(  6, "バラエティ", "variety" );
cat_house(  7, "映画", "cinema" );
cat_house(  8, "アニメ・特撮", "anime" );
cat_house(  9, "ドキュメンタリー・教養", "documentary" );
cat_house( 10, "演劇", "stage" );
cat_house( 11, "趣味・実用", "hobby" );
cat_house( 12, "福祉", "welfare" );
cat_house( 13, "予備1", "etc1" );
cat_house( 14, "予備2", "etc2" );
cat_house( 15, "拡張", "expand" );
cat_house( 16, "その他", "etc" );

$smarty = new Smarty();
$smarty->template_dir = "../templates/";
$smarty->compile_dir = "../templates_c/";
$smarty->cache_dir = "../cache/";

$smarty->assign( "record_mode", $RECORD_MODE );
$smarty->assign( "settings", $settings );
$smarty->assign( "install_path", INSTALL_PATH );
$smarty->assign( "post_to", "step4.php" );
$smarty->assign( "sitetitle", "インストールステップ3" );
$smarty->assign( "message" , "環境設定を行います。これらの設定はデフォルトのままでも制限付きながら動作します。" );

$smarty->display("envSetting.html");
?>
