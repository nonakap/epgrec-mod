#!/usr/bin/php
<?php
$script_path = dirname( __FILE__ );
chdir( $script_path );
include_once($script_path . '/config.php');
include_once(INSTALL_PATH . '/Settings.class.php' );
include_once(INSTALL_PATH . '/DBRecord.class.php' );
include_once(INSTALL_PATH . '/tableStruct.inc.php' );
include_once(INSTALL_PATH . '/reclib.php' );

$settings = Settings::factory();
$dbh = mysql_connect( $settings->db_host, $settings->db_user, $settings->db_pass );
if( $dbh !== FALSE ) {

	$sqlstr = "use ".$settings->db_name;
	mysql_query( $sqlstr );

	$sqlstr = "set NAMES 'utf8'";
	mysql_query( $sqlstr );

	$former_time     = (int)$settings->former_time;
	$rec_switch_time = (int)$settings->rec_switch_time;
	$ed_tm_sft       = $former_time + $rec_switch_time;
	$force_cont_rec  = (boolean)$settings->force_cont_rec;

	// インデックス追加
	// KEYWORD_TBL
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add kw_enable boolean not null default '1' AFTER type" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add typeEX boolean not null default '1' AFTER typeCS" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add weekofdays integer not null default '127' AFTER weekofday" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add period integer not null default '1' AFTER prgtime" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add overlap boolean not null default '1' AFTER priority" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add criterion_dura integer not null default '".(CRITERION_CHECK?'1':'0')."'" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add rest_alert integer not null default '".(REST_ALERT?'1':'0')."'" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." add smart_repeat boolean not null default '0'" );
	$recs = DBRecord::createRecords(KEYWORD_TBL);
	foreach( $recs as $rec ) {
		$rec->typeEX = FALSE;
		if( $rec->type === '-' )
			$rec->kw_enable = FALSE;
		if( $rec->weekofday != 7 )
			$rec->weekofdays = 0x01 << $rec->weekofday;
		if( $rec->id <= 10 )
			$rec->overlap = FALSE;
		$rec->update();
	}
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." DROP type" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.KEYWORD_TBL." DROP weekofday" );

	// RESERVE_TBL
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.RESERVE_TBL." add shortened boolean not null default '0' AFTER endtime" );
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.RESERVE_TBL." add overlap boolean not null default '1' AFTER priority" );
	$recs = DBRecord::createRecords( RESERVE_TBL, "WHERE complete = '0'" );
	foreach( $recs as $rec ) {
		$change = FALSE;
		if( $rec->autorec <= 10 ){
			$rec->overlap = FALSE;
			$change       = TRUE;
		}
		if( $force_cont_rec && !(boolean)$rec->discontinuity && (int)$rec->program_id ){
			$event = new DBRecord( PROGRAM_TBL, 'id', $rec->program_id );
			$tmp_end_time = toTimestamp( $event->endtime );
			if( (int)$rec->autorec ){
				$keyword  = new DBRecord( KEYWORD_TBL, 'id', $rec->autorec );
				$tmp_end_time += $keyword->sft_end;
			}
			if( $tmp_end_time-$ed_tm_sft === toTimestamp( $rec->endtime ) ){
				$rec->shortened = TRUE;
				$change         = TRUE;
			}
		}
		if( $change )
			$rec->update();
	}

	// PROGRAM_TBL
	mysql_query( "ALTER TABLE ".$settings->tbl_prefix.PROGRAM_TBL." add key_id integer not null default '0'" );
}
else
	exit( "DBの接続に失敗\n" );
?>
