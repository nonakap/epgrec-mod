<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Reservation.class.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );


class Keyword extends DBRecord {
	
	public function __construct($property = null, $value = null ) {
		try {
			parent::__construct(KEYWORD_TBL, $property, $value );
		}
		catch( Exception $e ) {
			throw $e;
		}
	}
	
	static public function search(  $keyword = "", 
									$use_regexp = false,
									$ena_title = FALSE,
									$ena_desc = FALSE,
									$typeGR = FALSE,
									$typeBS = FALSE,
									$typeCS = FALSE,
									$typeEX = FALSE,
									$category_id = 0,
									$channel_id = 0,
									$weekofday = 0x7f,
									$prgtime = 24,
									$period = 1,
									$sub_genre = 16,
									$first_genre = 1,
									$limit = 300 ) {
		$sts = Settings::factory();
		
		$dbh = @mysql_connect($sts->db_host, $sts->db_user, $sts->db_pass );
		
		// ちょっと先を検索する
//		$options = " WHERE starttime > '".date('Y-m-d H:i:s', time() + $sts->padding_time + 60 )."'";
		$options = ' WHERE endtime > now()';
		
		if( $keyword != "" ) {
			if( $ena_title ){
				$search_sorce = $ena_desc ? " AND CONCAT(title, ' ', description)" : ' AND title';
			}else
				$search_sorce = ' AND description';
			if( $use_regexp ) {
				$options .= $search_sorce." REGEXP '".mysql_real_escape_string($keyword)."'";
			}
			else {
				foreach( explode( ' ', trim($keyword) ) as $key ){
					$k_len = strlen( $key );
					if( $k_len>1 && $key[0]==='-' ){
						$k_len--;
						$key = substr( $key, 1 );
						$options .= $search_sorce.' not like ';
					}else
						$options .= $search_sorce.' like ';
					if( $key[0]==='"' && $k_len>2 && $key[$k_len-1]==='"' )
						$key = substr( $key, 1, $k_len-2 );
					$options .= "'%".mysql_real_escape_string( $key )."%'";
				}
			}
		}
		
		$types = '';
		$t_cnt = 0;
		if( $typeGR ){
			$types .= "'GR'";
			$t_cnt++;
		}
		if( $typeBS ){
			if( $types !== '' )
				$types .= ',';
			$types .= "'BS'";
			$t_cnt++;
		}
		if( $typeCS ){
			if( $types !== '' )
				$types .= ',';
			$types .= "'CS'";
			$t_cnt++;
		}
		if( $typeEX ){
			if( $types !== '' )
				$types .= ',';
			$types .= "'EX'";
			$t_cnt++;
		}
		if( 0<$t_cnt && $t_cnt<4 )
			$options .= ' AND type IN ('.$types.')';
		
		if( $channel_id != 0 ){
			$options .= " AND channel_id = '".$channel_id."'";
		}else{
			$que = 'WHERE type IN ('.$types.") AND skip = '1'";
			if( DBRecord::countRecords( CHANNEL_TBL, $que ) ){
				$chs = DBRecord::createRecords( CHANNEL_TBL, $que );
				$ch_ids = '';
				foreach( $chs as $ch ){
					if( $ch_ids !== '' )
						$ch_ids .= ',';
					$ch_ids .= "'".(string)$ch->id."'";
				}
				$options .= ' AND channel_id NOT IN ('.$ch_ids.')';
			}
		}
		
		if( $category_id != 0 ) {
			if( $first_genre ){
				if( $category_id!=15 && $sub_genre==16 || $sub_genre==18 )
					$options .= " AND category_id = '".$category_id."'";
				else
					$options .= " AND category_id = '".$category_id."' AND sub_genre = '".$sub_genre."'";
			}else{
				if( $category_id!=15 && $sub_genre==16 || $sub_genre==18 )
					$options .= " AND ( category_id = '".$category_id."' OR genre2 = '".$category_id."' OR genre3 = '".$category_id."' )";
				else
					$options .= " AND ((category_id = '".$category_id."' AND sub_genre = '".$sub_genre.
								"') OR (genre2 = '".$category_id."' AND sub_genre2 = '".$sub_genre.
								"') OR (genre3 = '".$category_id."' AND sub_genre3 = '".$sub_genre."'))";
			}
		}
		
		if( $prgtime != 24 ) {
			if( $prgtime+$period <= 24 ){
				$options .= self::setWeekofdays( $weekofday );
				$options .= " AND time(starttime) BETWEEN cast('".sprintf( '%02d:00:00', $prgtime )."' as time) AND cast('".sprintf( '%02d:59:59', $prgtime+($period-1) )."' as time)";
			}else{
				$top_que = " time(starttime) BETWEEN cast('00:00:00' as time) AND cast('".sprintf( '%02d:59:59', ($period-(24-$prgtime)-1) )."' as time) ";
				$btm_que = " time(starttime) BETWEEN cast('".sprintf( '%02d:00:00', $prgtime )."' as time) AND cast('23:59:59' as time) ";
				if( $weekofday == 0x7f )
					$options .= ' AND ('.$btm_que.'OR'.$top_que.')';
				else{
					$top_days = '';
					$btm_days = '';
					for( $b_cnt=0; $b_cnt<6; $b_cnt++ ){
						if( $weekofday & ( 0x01 << $b_cnt ) ){
							if( $top_days !== '' )
								$top_days .= ',';
							$top_days .= "'".(string)($b_cnt+1)."'";
							if( $btm_days !== '' )
								$btm_days .= ',';
							$btm_days .= "'".(string)$b_cnt."'";
						}
					}
					if( $weekofday & 0x40 ){
						if( $top_days !== '' )
							$top_days .= ',';
						$top_days .= "'0'";
						if( $btm_days !== '' )
							$btm_days .= ',';
						$btm_days .= "'6'";
					}
					$options .= ' AND ( ( WEEKDAY(starttime) IN ('.$top_days.') AND'.$top_que.') OR ( WEEKDAY(starttime) IN ('.$btm_days.') AND'.$btm_que.') )';
				}
			}
		}else
			$options .= self::setWeekofdays( $weekofday );
		
		$options .= ' ORDER BY starttime ASC  LIMIT '.$limit ;
		
		$recs = array();
		try {
			$recs = DBRecord::createRecords( PROGRAM_TBL, $options );
		}
		catch( Exception $e ) {
			throw $e;
		}
		return $recs;
	}
	
	private function setWeekofdays( $weekofday = 0x7f ){
		if( $weekofday != 0x7f ){
			$weeks = '';
			for( $b_cnt=0; $b_cnt<7; $b_cnt++ ){
				if( $weekofday & ( 0x01 << $b_cnt ) ){
					if( $weeks !== '' )
						$weeks .= ',';
					$weeks .= "'".(string)$b_cnt."'";
				}
			}
			return ' AND WEEKDAY(starttime) IN ('.$weeks.')';
		}else
			return '';
	}
	
	private function getPrograms() {
		if( $this->__id == 0 ) return false;
		$recs = array();
		try {
			 $recs = self::search( $this->keyword, $this->use_regexp, $this->ena_title, $this->ena_desc, $this->typeGR, $this->typeBS, $this->typeCS, $this->typeEX,
			 						$this->category_id, $this->channel_id, $this->weekofdays, $this->prgtime, $this->period, $this->sub_genre, $this->first_genre );
		}
		catch( Exception $e ) {
			throw $e;
		}
		return $recs;
	}
	
	public function reservation( $wave_type, $shm_id, $sem_key ) {
		if( $this->__id == 0 ) return;

		// keyword_id排他処理
		while(1){
			if( sem_acquire( $sem_key ) === TRUE ){
				// keyword_id占有チェック
				$shm_cnt = SEM_KW_START;
				do{
					if( shmop_read_surely( $shm_id, $shm_cnt ) === (int)$this->__id ){
						while( sem_release( $sem_key ) === FALSE )
							usleep( 100 );
						usleep( 1000 );
						continue 2;
					}
				}while( ++$shm_cnt < SEM_KW_START+SEM_KW_MAX );

				// keyword_id占有
				$shm_cnt = SEM_KW_START;
				do{
					if( shmop_read_surely( $shm_id, $shm_cnt ) !== 0 )
						continue;
					shmop_write_surely( $shm_id, $shm_cnt, (int)$this->__id );
					while( sem_release( $sem_key ) === FALSE )
						usleep( 100 );
					break 2;
				}while( ++$shm_cnt < SEM_KW_START+SEM_KW_MAX );
				while( sem_release( $sem_key ) === FALSE )
					usleep( 100 );
				usleep( 2000 );
			}
		}
		$precs = array();
		try {
			$precs = $this->getPrograms();
		}
		catch( Exception $e ) {
			// keyword_id開放
			while( sem_acquire( $sem_key ) === FALSE )
				usleep( 100 );
			shmop_write_surely( $shm_id, $shm_cnt, 0 );
			while( sem_release( $sem_key ) === FALSE )
				usleep( 100 );
			throw $e;
		}
		if( count( $precs ) > 0 ){
			// 一気に録画予約
			foreach( $precs as $rec ) {
				try {
					if( $rec->autorec && ( $wave_type==='*' || $rec->type===$wave_type || ( $wave_type==='BS' && $rec->type==='CS' ) || ( $wave_type==='CS' && $rec->type==='BS' ) ) ){
						$pieces = explode( ':', Reservation::simple( $rec->id, $this->__id, $this->autorec_mode, $this->discontinuity ) );
						if( (int)$pieces[0] ){
							usleep( 1000 );		// 書き込みがDBに反映される時間を見極める。
							// 最終回フラグ
							if( (int)$pieces[4] && (int)$this->rest_alert!=0 && (int)$this->rest_alert!=3 ){
								$this->rest_alert = 3;
								$this->update();
							}
						}
					}
				}
				catch( Exception $e ) {
					// 無視
				}
			}
			if( (int)$this->rest_alert == 2 ){
				$this->rest_alert = 1;
				$this->update();
			}
		}else{
			switch( (int)$this->rest_alert ){
				case 2:
					if( $wave_type!=='*' || date( 'H', time() )!=='00' )
						break;
				case 1:
					reclog( '<a href="programTable.php?keyword_id='.$this->__id.'">自動キーワードID:'.$this->__id.
							' 『'.htmlspecialchars($this->keyword).'』</a>は、該当番組がありません。', EPGREC_WARN );
					$this->rest_alert = 2;
					$this->update();
					break;
				case 3:
					reclog( '<a href="programTable.php?keyword_id='.$this->__id.'">自動キーワードID:'.$this->__id.
							' 『'.htmlspecialchars($this->keyword).'』</a>の最終回は、放送されました。', EPGREC_WARN );
					break;
			}
		}
		// keyword_id開放
		while( sem_acquire( $sem_key ) === FALSE )
			usleep( 100 );
		shmop_write_surely( $shm_id, $shm_cnt, 0 );
		while( sem_release( $sem_key ) === FALSE )
			usleep( 100 );
	}
	
	// キーワード編集対応にて下の関数より分離
	public function rev_delete() {
		if( $this->id == 0 ) return;
		
		$precs = array();
		try {
			// ジャンルだけなどのザックリとしたキーワードの削除だと他の予約を巻き込むので修正
			$precs = DBRecord::createRecords( RESERVE_TBL, "WHERE complete = '0' AND autorec = '".$this->id."'" );
		}
		catch( Exception $e ) {
			return;
		}
		// 一気にキャンセル
		foreach( $precs as $reserve ) {
			try {
				Reservation::cancel( $reserve->id );
				usleep( 100 );		// あんまり時間を空けないのもどう?
			}
			catch( Exception $e ) {
				// 無視
			}
		}
	}

	public function delete() {
		$this->rev_delete();
		try {
			parent::delete();
		}
		catch( Exception $e ) {
			throw $e;
		}
	}

	// staticなファンクションはオーバーライドできない
	static function createKeywords( $options = '' ) {
		$retval = array();
		$arr = array();
		try{
			$tbl = new self();
			$sqlstr = 'SELECT * FROM '.$tbl->__table.' ' .$options;
			$result = $tbl->__query( $sqlstr );
		}
		catch( Exception $e ) {
			throw $e;
		}
		if( $result === false ) throw new exception('レコードが存在しません');
		while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
			array_push( $retval, new self('id', $row['id']) );
		}
		return $retval;
	}
	
	public function __destruct() {
		parent::__destruct();
	}
}
?>
