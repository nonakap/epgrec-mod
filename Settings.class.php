<?php
include_once('config.php');
include_once( INSTALL_PATH . "/DBRecord.class.php" );
include_once( INSTALL_PATH . "/recLog.inc.php" );

class Settings extends SimpleXMLElement {

	public static function factory() {
		$CONFIG_XML = get_config_path( CONFIG_FILE );
		if( !is_null( $CONFIG_XML ) ){
			$xmlfile = file_get_contents( $CONFIG_XML );
			$obj = new self($xmlfile);

			// 8月14日以降に追加した設定項目の自動生成

			// キーワード自動録画の録画モード
			if( $obj->exists("autorec_mode") == 0 ){
				$obj->autorec_mode = 0;
				$obj->save();
			}
			// CSの録画
			if( $obj->exists("cs_rec_flg") == 0 ){
				$obj->cs_rec_flg = 0;
				$obj->save();
			}

			// DB設定ファイル
			self::load_db_conf( $obj );

			return $obj;
		}
		else {
			// 初回起動
			$xmlfile = '<?xml version="1.0" encoding="UTF-8" ?><epgrec></epgrec>';
			$xml = new self($xmlfile);

			// 旧config.phpを読み取って設定
			if(defined("SPOOL") ) $xml->spool = SPOOL;
			else $xml->spool = "/video";

			if(defined("THUMBS") ) $xml->thumbs = THUMBS;
			else $xml->thumbs = "/thumbs";

			if(defined("INSTALL_URL")) $xml->install_url = INSTALL_URL;
			else $xml->install_url = "http://localhost/epgrec";

			if(defined("BS_TUNERS")) $xml->bs_tuners = BS_TUNERS;
			else $xml->bs_tuners = 0;

			if(defined("GR_TUNERS")) $xml->gr_tuners = GR_TUNERS;
			else $xml->gr_tuners = 1;

			if(defined("CS_REC_FLG")) $xml->cs_rec_flg = CS_REC_FLG;
			else $xml->cs_rec_flg = 0;

			if(defined("FORMER_TIME")) $xml->former_time = FORMER_TIME;
			else $xml->former_time = 5;

			if(defined("EXTRA_TIME")) $xml->extra_time = EXTRA_TIME;
			else $xml->extra_time = 3;

			if(defined("FORCE_CONT_REC")) $xml->force_cont_rec = FORCE_CONT_REC ? 1 : 0;
			else $xml->force_cont_rec = 1;

			if(defined("REC_SWITCH_TIME")) $xml->rec_switch_time = REC_SWITCH_TIME;
			else $xml->rec_switch_time = 10;

			if(defined("USE_THUMBS")) $xml->use_thumbs = USE_THUMBS ? 1 : 0;
			else $xml->use_thumbs = 0;

			if(defined("MEDIATOMB_UPDATE")) $xml->mediatomb_update = MEDIATOMB_UPDATE ? 1 : 0;
			else $xml->mediatomb_update = 0;

			if(defined("FILENAME_FORMAT")) $xml->filename_format = FILENAME_FORMAT;
			else $xml->filename_format = "%TYPE%%CH%_%ST%_%ET%";

			if(defined("DB_HOST")) $xml->db_host = DB_HOST;
			else $xml->db_host = "localhost";

			if(defined("DB_NAME")) $xml->db_name = DB_NAME;
			else $xml->db_name = "yourdbname";

			if(defined("DB_USER")) $xml->db_user = DB_USER;
			else $xml->db_user = "yourname";

			if(defined("DB_PASS")) $xml->db_pass = DB_PASS;
			else $xml->db_pass = "yourpass";

			if(defined("TBL_PREFIX")) $xml->tbl_prefix = TBL_PREFIX;
			else $xml->tbl_prefix = "Recorder_";

			if(defined("EPGDUMP")) $xml->epgdump = EPGDUMP;
			else $xml->epgdump = "/usr/local/bin/epgdump";

			if(defined("AT")) $xml->at = AT;
			else $xml->at = "/usr/bin/at";

			if(defined( "ATRM" )) $xml->atrm = ATRM;
			else $xml->atrm = "/usr/bin/atrm";

			if(defined( "SLEEP" )) $xml->sleep = SLEEP;
			else $xml->sleep = "/bin/sleep";

			if(defined( "FFMPEG" )) $xml->ffmpeg = FFMPEG;
			else $xml->ffmpeg = "/usr/bin/ffmpeg";

			if(defined("TEMP_DATA" )) $xml->temp_data = TEMP_DATA;
			else $xml->temp_data = "/tmp/__temp.ts";

			if(defined("TEMP_XML")) $xml->temp_xml = TEMP_XML;
			else $xml->temp_xml = "/tmp/__temp.xml";

			// index.phpで使う設定値
			// 表示する番組表の長さ（時間）
			$xml->program_length = 8;
			// 1局の幅
			$xml->ch_set_width = 150;
			// 1分あたりの高さ
			$xml->height_per_hour = 120;

			// 8月14日版以降に追加した設定項目

			// キーワード自動録画の録画モード
			$xml->autorec_mode = 0;

			// CS録画
			$xml->cs_rec_flg = 0;

			// DB設定ファイル
			self::load_db_conf( $xml );

			$xml->save();
			
			return $xml;
		}
	}

	private static function load_db_conf( $obj ){
		$db_conf = get_config_path( DB_CONFIG_FILE );
		if( !is_null( $db_conf ) ){
			$dbconf = simplexml_load_file( $db_conf );
			if( $dbconf !== FALSE ){
				if( isset( $dbconf->db_host ) ){
					$obj->db_host = $dbconf->db_host;
				}
				if( isset( $dbconf->db_name ) ){
					$obj->db_name = $dbconf->db_name;
				}
				if( isset( $dbconf->db_user ) ){
					$obj->db_user = $dbconf->db_user;
				}
				if( isset( $dbconf->db_pass ) ){
					$obj->db_pass = $dbconf->db_pass;
				}
			}
		}
	}

	public function exists( $property ) {
		return (int)count( $this->{$property} );
	}

	public function post() {
		global $_POST;

		foreach( $_POST as $key => $value ){
			if( $this->exists($key) ){
				$trim_post = trim( $value );
				if( $key === 'filename_format' ){
					if( stristr( $trim_post, 'wget ' )===FALSE && stristr( $trim_post, 'rm ' )===FALSE && stristr( $trim_post, 'sudo ' )===FALSE
					&& stristr( $trim_post, 'cp ' )===FALSE && stristr( $trim_post, 'mv ' )===FALSE && stristr( $trim_post, 'dd ' )===FALSE ){	// 念のため
						if( strpos( $trim_post, "\"" )===FALSE && strpos( $trim_post, "'" )===FALSE ){
							continue;
						}
					}
				}else{
					if( strpos( $trim_post, ' ' ) === FALSE ){		// 引数の設定を検知
						$escp_post = escapeshellcmd( $trim_post );	// escapeshellcmd()はマルチバイト文字未対応
						if( $trim_post === $escp_post ){
							continue;
						}
					}
				}
				// 不法侵入による攻撃
				$alert_msg = '不法侵入者による攻撃を受けました。IP::['.$_SERVER['REMOTE_ADDR'].'('.$_SERVER['REMOTE_HOST'].')] '.$key.' => '.$trim_post;
				reclog( $alert_msg, EPGREC_WARN );
				file_put_contents( INSTALL_PATH.$this->spool.'/alert.log', date("Y-m-d H:i:s").' '.$alert_msg."\n", FILE_APPEND );
				syslog( LOG_WARNING, $alert_msg );
				return;
			}
		}
		if( $NET_AREA === 'G' ){
			if( !$AUTHORIZED ){			// $_SERVER['HTTPS']!=='on'
				$alert_msg = 'グローバルIPからの設定変更です。IP::['.$_SERVER['REMOTE_ADDR'].'('.$_SERVER['REMOTE_HOST'].')] ';
				reclog( $alert_msg, EPGREC_WARN );
				file_put_contents( INSTALL_PATH.$this->spool.'/alert.log', date("Y-m-d H:i:s").' '.$alert_msg."\n", FILE_APPEND );
				syslog( LOG_WARNING, $alert_msg );
			}
			if( SETTING_CHANGE_GIP === FALSE )
				return;
		}
		foreach( $_POST as $key => $value ) {
			if( $this->exists($key) ) {
				$this->{$key} = trim($value);
			}
		}
	}

	public function save() {
		$config = get_config_path( CONFIG_FILE );
		if( !is_null( $config ) ){
			$db_conf = get_config_path( DB_CONFIG_FILE );
			if( !is_null( $db_conf ) ){
				$xml = $this->asXML();
				$save_obj = new self( $xml );
				unset($save_obj->db_host);
				unset($save_obj->db_name);
				unset($save_obj->db_user);
				unset($save_obj->db_pass);
				$save_obj->asXML( $config );
			}else{
				$this->asXML( $config );
			}
		}
	}
}
if( !isset($NET_AREA) || !isset($AUTHORIZED) )exit;
?>
