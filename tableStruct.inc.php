<?php
// データベーステーブル定義


// 予約テーブル
define( "RESERVE_STRUCT", 
	"id integer not null auto_increment primary key,".				// ID
	"channel_disc varchar(128) not null default 'none',".			// channel disc
	"channel_id integer not null  default '0',".					// channel ID
	"program_id integer not null default '0',".						// Program ID
	"type varchar(8) not null default 'GR',".						// 種別（GR/BS/CS）
	"channel varchar(10) not null default '0',".					// チャンネル
	"title varchar(512) not null default 'none',".					// タイトル
	"description varchar(512) not null default 'none',".			// 説明 text->varchar
	"category_id integer not null default '0',".					// カテゴリID
	"starttime datetime not null default '1970-01-01 00:00:00',".	// 開始時刻
	"endtime datetime not null default '1970-01-01 00:00:00',".		// 終了時刻
	"shortened boolean not null default '0',".						// 隣接短縮フラグ
	"job integer not null default '0',".							// job番号
	"path blob default null,".										// 録画ファイルパス
	"complete boolean not null default '0',".						// 完了フラグ
	"reserve_disc varchar(128) not null default 'none',".			// 識別用hash
	"autorec integer not null default '0',".						// キーワードID
	"mode integer not null default '0',".							// 録画モード
	"tuner integer not null default '0',".							// チューナー番号
	"priority integer not null default '10',".						// 優先度
	"overlap boolean not null default '1',".							// ダーティフラグ
	"dirty boolean not null default '0',".							// ダーティフラグ
	"discontinuity boolean not null default '0',".					// 隣接録画禁止フラグ 禁止なら1
	"index reserve_chid_idx (channel_id),".							// インデックス
	"index reserve_ch_idx (channel_disc),".
	"index reserve_st_idx (starttime),".
	"index reserve_ed_idx (endtime),".
	"index reserve_pri_idx (priority),".
	"index reserve_pid_idx (program_id),".
	"index reserve_cmp_idx (complete),".
	"index reserve_type_idx (type)".
	""
);


// 番組表テーブル
define( "PROGRAM_STRUCT",
	"id integer not null auto_increment primary key,".				// ID
	"channel_disc varchar(128) not null default 'none',".			// channel disc
	"channel_id integer not null default '0',".						// channel ID
	"type varchar(8) not null default 'GR',".						// 種別（GR/BS/CS）
	"channel varchar(10) not null default '0',".					// チャンネル
	"eid integer not null default '0',".							// event ID
	"title varchar(512) not null default 'none',".					// タイトル
	"description varchar(512) not null default 'none',".			// 説明 text->varchar
	"category_id integer not null default '0',".					// カテゴリ(ジャンル)ID
	"sub_genre integer not null default '16',".						// サブジャンルID
	"genre2 integer not null default '0',".							// ジャンル2ID
	"sub_genre2 integer not null default '16',".					// サブジャンル2ID
	"genre3 integer not null default '0',".							// ジャンル3ID
	"sub_genre3 integer not null default '16',".					// サブジャンル3ID
	"video_type integer not null default '0',".						// 映像仕様
	"audio_type integer not null default '0',".						// 音声仕様
	"multi_type integer not null default '0',".						// 副音声(?)
	"starttime datetime not null default '1970-01-01 00:00:00',".	// 開始時刻
	"endtime datetime not null default '1970-01-01 00:00:00',".		// 終了時刻
	"program_disc varchar(128) not null default 'none',".	 		// 識別用hash
	"autorec boolean not null default '1',".						// 自動録画有効無効
	"key_id integer not null default '0',".							// 自動予約禁止フラグをたてた自動キーワードID
	"index program_chid_idx (channel_id),".							// インデックス
	"index program_chdisc_idx (channel_disc),".
	"index program_st_idx (starttime),".
	"index program_ed_idx (endtime),".
	"index program_disc_idx (program_disc),".
	"index program_eid_idx (eid),".
	"index program_cat_idx (category_id,sub_genre),".
	"index program_title_idx (title),".
	"index program_desc_idx (description),".
	"index program_type_idx (type)".
	""
);


define( "CHANNEL_STRUCT",
	"id integer not null auto_increment primary key,".				// ID
	"type varchar(8) not null default 'GR',".						// 種別
	"channel varchar(10) not null default '0',".					// channel
	"name varchar(512) not null default 'none',".					// 表示名
	"channel_disc varchar(128) not null default 'none',".			// 識別用hash
	"sid varchar(64) not null default 'hd',".						// サービスID用02/23/2010追加
	"skip boolean not null default '0'".							// チャンネルスキップ用03/13/2010追加
	""
);

define( "CATEGORY_STRUCT",
	"id integer not null auto_increment primary key,".				// ID
	"name_jp varchar(512) not null default 'none',".				// 表示名
	"name_en varchar(512) not null default 'none',".				// 同上
	"category_disc varchar(128) not null default 'none'"			// 識別用hash
);


define( "KEYWORD_STRUCT",
	"id integer not null auto_increment primary key,".				// ID
	"keyword varchar(512) not null default '*',".					// 表示名
	"kw_enable boolean not null default '1',".						// 有効・無効フラグ
	"typeGR boolean not null default '1',".							// 地デジフラグ
	"typeBS boolean not null default '1',".							// BSフラグ
	"typeCS boolean not null default '1',".							// CSフラグ
	"typeEX boolean not null default '1',".							// CSフラグ
	"channel_id integer not null default '0',".						// channel ID
	"category_id integer not null default '0',".					// カテゴリ(ジャンル)ID
	"sub_genre integer not null default '16',".						// サブジャンルID
	"use_regexp boolean not null default '0',".						// 正規表現を使用するなら1
	"ena_title boolean not null default '1',".						// タイトル検索対象フラグ
	"ena_desc boolean not null default '1',".						// 概要検索対象フラグ
	"autorec_mode integer not null default '0',".					// 自動録画のモード02/23/2010追加
	"weekofdays integer not null default '127',".					// 曜日
	"prgtime enum ('0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24') not null default '24',".	// 時間　03/13/2010追加
	"period integer not null default '1',".							// 上の期間
	"first_genre boolean not null default '1',".					// 1
	"priority integer not null default '10',".						// 優先度
	"overlap boolean not null default '1',".						// 重複予約許可フラグ
	"sft_start integer not null default '0',".						// 録画開始時刻シフト量(秒)
	"sft_end integer not null default '0',".						// 録画終了時刻シフト量(秒)
	"discontinuity boolean not null default '0',".					// 隣接録画禁止フラグ 禁止なら1
	"directory varchar(256) default null,".							// 保存ディレクトリ
	"filename_format varchar(256) default null,".					// 録画ファイル名の形式
	"criterion_dura integer not null default '0',".					// 収録時間変動警告の基準時間
	"rest_alert integer not null default '1',".						// 放送休止警報
	"smart_repeat boolean not null default '1',".					// 
	"index keyword_pri_idx (priority)".
	""
);

define( "LOG_STRUCT",
	"id integer not null auto_increment primary key".				// ID
	",logtime  datetime not null default '1970-01-01 00:00:00'".	// 記録日時
	",level integer not null default '0'".							// エラーレベル
	",message varchar(512) not null default ''".
	""
);

?>
