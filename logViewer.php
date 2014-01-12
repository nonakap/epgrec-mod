<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );


$settings = Settings::factory();

$level0 = isset($_POST['level0']);
$level1 = isset($_POST['level1']);
$level2 = isset($_POST['level2']);
$level3 = isset($_POST['level3']);

if( !$level0 && !$level1 && !$level2 && !$level3 )
	$level0 = $level1 = $level2 = TRUE;

$log_levels = array(
	0 => array( 'label' => '情報',   'view' => $level0 ),
	1 => array( 'label' => '警告',   'view' => $level1 ),
	2 => array( 'label' => 'エラー', 'view' => $level2 ),
	3 => array( 'label' => 'DEBUG',  'view' => $level3 ),
);

$search = '';
foreach( $log_levels as $key => $level ){
	if( $level['view'] ){
		if( $search !== '' )
			$search .= ',';
		$search .= "'".(string)$key."'";
	}
}
$arr = DBRecord::createRecords( LOG_TBL, 'WHERE level IN ('.$search.") ORDER BY logtime DESC, id DESC" );
$logs = array();
foreach( $arr as $low ){
	$log = array();
	$log['level']   = (int)$low->level;
	$log['label']   = $log_levels[$log['level']]['label'];
	$log['logtime'] = $low->logtime;
	$log['message'] = $low->message;
	array_push( $logs, $log );
}

	$link_add = '';
	if( (int)$settings->gr_tuners > 0 )
		$link_add .= '<option value="index.php">地上デジタル番組表</option>';
	if( (int)$settings->bs_tuners > 0 ){
		$link_add .= '<option value="index.php?type=BS">BSデジタル番組表</option>';
		if( (boolean)$settings->cs_rec_flg )
			$link_add .= '<option value="index.php?type=CS">CSデジタル番組表</option>';
	}
	if( EXTRA_TUNERS )
		$link_add .= '<option value="index.php?type=EX">'.EXTRA_NAME.'番組表</option>';

$smarty = new Smarty();

$smarty->assign( "sitetitle" , "epgrec動作ログ" );
$smarty->assign( "logs", $logs );
$smarty->assign( "link_add", $link_add );
$smarty->assign( "log_levels", $log_levels );
$smarty->assign( 'menu_list', $MENU_LIST );

$smarty->display( "logTable.html" );
?>