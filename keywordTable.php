<?php
include_once('config.php');
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Keyword.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );

function word_chk( $chk_wd )
{
	return ( strpos( $chk_wd, "\"" )===FALSE && strpos( $chk_wd, "'" )===FALSE ? $chk_wd : "" );
}

$settings = Settings::factory();

$weekofdays = array( '月', '火', '水', '木', '金', '土', '日' );
$prgtimes = array();
for( $i=0 ; $i < 25; $i++ ) {
	$prgtimes[$i] = $i == 24 ? 'なし' : $i.'時';
}

// 新規キーワードがポストされた

if( isset($_POST['add_keyword']) ) {
	if( $_POST['add_keyword'] == 1 ) {
		try {
			$keyword_id = $_POST['keyword_id'];
			if( $keyword_id ){
				$rec = new Keyword( 'id', $keyword_id );
			}else
				$rec = new Keyword();
			$rec->keyword      = $_POST['k_search'];
			$rec->kw_enable    = isset( $_POST['k_kw_enable'] );
			$rec->typeGR       = $_POST['k_typeGR'];
			$rec->typeBS       = $_POST['k_typeBS'];
			$rec->typeCS       = $_POST['k_typeCS'];
			$rec->typeEX       = $_POST['k_typeEX'];
			$rec->category_id  = $_POST['k_category'];
			$rec->sub_genre    = $_POST['k_sub_genre'];
			$rec->first_genre  = $_POST['k_first_genre'];
			$rec->channel_id   = $_POST['k_station'];
			$rec->use_regexp   = $_POST['k_use_regexp'];
			$rec->ena_title    = $_POST['k_ena_title'];
			$rec->ena_desc     = $_POST['k_ena_desc'];
			$rec->weekofdays   = $_POST['k_weekofday'];
			$rec->prgtime      = $_POST['k_prgtime'];
			$rec->period       = $_POST['k_period'];
			$rec->autorec_mode = $_POST['autorec_mode'];
			$rec->sft_start    = parse_time( $_POST['k_sft_start'] );
			$rec->sft_end      = parse_time( $_POST['k_sft_end'] );
			$rec->discontinuity   = isset($_POST['k_discontinuity']);
			$rec->priority        = $_POST['k_priority'];
			$rec->overlap         = isset( $_POST['k_overlap'] );
			$rec->rest_alert      = isset( $_POST['k_rest_alert'] );
			$rec->criterion_dura  = isset( $_POST['k_criterion_enab'] ) ? $_POST['k_criterion_dura'] : 0;
			$rec->smart_repeat    = isset( $_POST['k_smart_repeat'] );
			$rec->filename_format = word_chk( $_POST['k_filename'] );
			$rec->directory       = word_chk( $_POST['k_directory'] );
			$rec->update();
			if( $keyword_id )
				$rec->rev_delete();
			if( (boolean)$rec->kw_enable ){
				$t_cnt = 0;
				if( (boolean)$rec->typeGR ){
					$type = 'GR';
					$t_cnt++;
				}
				if( (boolean)$rec->typeBS ){
					$type = 'BS';
					$t_cnt++;
				}
				if( (boolean)$rec->typeCS ){
					$type = 'CS';
					$t_cnt++;
				}
				if( (boolean)$rec->typeEX ){
					$type = 'EX';
					$t_cnt++;
				}
				if( $t_cnt > 1 )
					$type = '*';
				// 録画予約実行
				$sem_key = sem_get_surely( SEM_KW_START );
				$shm_id  = shmop_open_surely();
				$rec->reservation( $type, $shm_id, $sem_key );
				shmop_close( $shm_id );
			}
		}
		catch( Exception $e ) {
			exit( $e->getMessage() );
		}
	}
}


$cs_rec_flg = (boolean)$settings->cs_rec_flg;
$keywords   = array();
try {
	$recs = Keyword::createRecords(KEYWORD_TBL, 'ORDER BY id ASC' );
	foreach( $recs as $rec ) {
		$arr = array();
		$arr['id'] = $rec->id;
		$arr['keyword'] = $rec->keyword;
		$arr['type'] = '';
		if( $rec->typeGR && $rec->typeBS && ( !$cs_rec_flg || $rec->typeCS ) && ( EXTRA_TUNERS==0 || $rec->typeEX ) ){
			$arr['type'] .= 'ALL';
		}else{
			$cnt = 0;
			if( $rec->typeGR ){
				$arr['type'] .= 'GR';
				$cnt++;
			}
			if( $settings->bs_tuners > 0 ){
				if( $rec->typeBS ){
					if( $cnt )
						$arr['type'] .= '<br>';
					$arr['type'] .= 'BS';
					$cnt++;
				}
				if( $cs_rec_flg && $rec->typeCS ){
					if( $cnt )
						$arr['type'] .= '<br>';
					$arr['type'] .= 'CS';
				}
			}
			if( EXTRA_TUNERS>0 && $rec->typeEX ){
				if( $cnt )
					$arr['type'] .= '<br>';
				$arr['type'] .= 'EX';
			}
		}
		$arr['k_type'] = $rec->kw_enable;
		if( $rec->channel_id ) {
			try {
				$crec = new DBRecord(CHANNEL_TBL, 'id', $rec->channel_id );
				$arr['channel'] = $crec->name;
			}catch( exception $e ){
				$rec->channel_id = 0;
				$arr['channel']  = 'すべて';
			}
		}
		else $arr['channel'] = 'すべて';
//		$arr['k_channel'] = $rec->channel_id;
		if( $rec->category_id ) {
			$crec = new DBRecord(CATEGORY_TBL, 'id', $rec->category_id );
			$arr['category'] = $crec->name_jp;
		}
		else $arr['category'] = 'すべて';
		$arr['k_category'] = $rec->category_id;
		$arr['sub_genre'] = $rec->sub_genre;
		$arr['first_genre'] = $rec->first_genre;
		
		$arr['options'] = '';
		if( defined( 'KATAUNA' ) ){
			$arr['options']  = (boolean)$rec->use_regexp ? '正' : '－';
			$arr['options'] .= (boolean)$rec->ena_title ? 'タ' : '－';
			$arr['options'] .= (boolean)$rec->ena_desc ? '概' : '－';
			$arr['options'] .= '<br>';
			$arr['options'] .= (boolean)$rec->overlap ? '多' : '－';
			$arr['options'] .= (boolean)$rec->rest_alert ? '無' : '－';
			$arr['options'] .= (boolean)$rec->criterion_dura ? '幅' : '－';
		}else
			$arr['options'] = (boolean)$rec->use_regexp ? '○' : '×';

		if( $rec->weekofdays != 0x7f ){
			$arr['weekofday'] = '';
			for( $b_cnt=0; $b_cnt<7; $b_cnt++ ){
				if( $rec->weekofdays & ( 0x01 << $b_cnt ) ){
					$arr['weekofday'] .= $weekofdays[$b_cnt];
				}
			}
		}else
			$arr['weekofday'] = '－';
		$arr['prgtime'] = $prgtimes[$rec->prgtime];
		$arr['period']  = $rec->period;
		$arr['autorec_mode'] = $RECORD_MODE[(int)$rec->autorec_mode]['name'];
		$arr['sft_start'] = transTime( $rec->sft_start, TRUE );
		$arr['sft_end']   = transTime( $rec->sft_end, TRUE );
		$arr['discontinuity'] = $rec->discontinuity;
		$arr['priority'] = $rec->priority;
		array_push( $keywords, $arr );
	}
}
catch( Exception $e ) {
	exit( $e->getMessage() );
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

$smarty->assign( 'keywords', $keywords );
$smarty->assign( 'link_add', $link_add );
$smarty->assign( 'menu_list', $MENU_LIST );
$smarty->assign( 'sitetitle', '自動録画キーワードの管理' );
$smarty->display( 'keywordTable.html' );
?>