<?php

// トランスコード設定例
// 旧設定(do-record.shでトラコン)との併用は可能だが'array'の前の数値は、$RECORD_MODEにマージする際に振り直されるのでこの変数内で重複しないようにするだけでよい。
// 以下を有効にするとトラコン機能を使用できるようになる(ffmpegの環境構築や設定は各自でggr・サンプルのMPEG4とMPEG4SDはこのままで動くが画質悪し)
$TRANS_MODE = array(
/*
	3 => array(
		'name'    => 'MPEG4',				// モードの表示名
		'suffix'  => '_.ts',				// TS拡張子
		'tsuffix' => '.mp4',				// トラコン拡張子('suffix'と'tsuffix'は同じ文字数にする事(ファイル名生成が手抜きなので自動キーワードの場合は問題でるかも))
		'command' => '',					// トランスコードコマンド(''の場合はTRANS_CMDを使用)
		'format'  => '-f mp4',				// ファイルフォーマット(コンテナ)
		'video'   => '-vcodec mpeg4',		// ビデオ(CODEC・関連オプション)
		'vbrate'  => '',					// ビデオビットレート
		'fps'     => '',					// フレームレート
		'aspect'  => '',					// アスペクト比
		'size'    => '',					// 解像度
		'audio'   => '-acodec copy',		// オーディオ(CODEC・関連オプション)
		'abrate'  => '',					// オーディオビットレート
		'tm_rate' => 1.5,					// (未対応)変換時間効率倍数(ジョブ制御用)
	),
	4 => array(
		'name'    => 'MPEG4SD',
		'suffix'  => '(SD).ts',
		'tsuffix' => '-SD.mp4',
		'command' => '',
		'format'  => '-f mp4',
		'video'   => '-vcodec mpeg4',
		'vbrate'  => '-vb 512k',
		'fps'     => '-r 30000/1001',
		'aspect'  => '-aspect 16:9',
		'size'    => '-s 640x360',
		'audio'   => '-acodec copy',
		'abrate'  => '',
		'tm_rate' => 0.5,
	),
	5 => array(
		'name'    => 'H264-HD',
		'suffix'  => '(HD).ts',
		'tsuffix' => '-HD.mp4',
		'command' => '',
		'format'  => '-f mp4',
		'video'   => '-vcodec libx264',
		'vbrate'  => '',
		'fps'     => '-r 30000/1001',
		'aspect'  => '-aspect 16:9',
		'size'    => '-s 1920x1080',
		'audio'   => '-acodec libfaac -ac 2 -ar 48000',
		'abrate'  => '-ab 128k',
		'tm_rate' => 1.5,
	),
*/
);

// トランスコードコマンドとオプション
// %FFMPEG%		エンコードコマンド($settings->ffmpegに置換される)
// %TS%			入力ファイル名
// %TRANS%		出力ファイル名
// %FORMAT%		ファイルフォーマット(コンテナ)
// %VIDEO%		ビデオ(CODEC・関連オプション)
// %VBRATE%		ビデオビットレート
// %FPS%		フレームレート
// %ASPECT%		アスペクト比
// %SIZE%		サイズ(画角)
// %AUDIO%		オーディオ(CODEC・関連オプション)
// %ABRATE%		オーディオビットレート
define( 'TRANS_CMD', '%FFMPEG% -y -i %TS% %FORMAT% %VIDEO% %FPS% %ASPECT% %SIZE% %VBRATE% -bufsize 20000k -maxrate 25000k %AUDIO% %ABRATE% -threads 1 %TRANS%' );

define( 'TRANS_ROOT', '%VIDEO%' );					// トランスコードファイル出力パス(フルパスで指定・%VIDEO%は INSTALL_PATH.'/video'に置換される・
													// GUIからの視聴は、%VIDEO%以降にしないとだめ)
define( 'TRANS_PARA', 1 );							// トランスコード並行実行数
define( 'TRANS_SET_KEYWD', 3 );						// 自動キーワードのトランスコード設定セット数
define( 'TRANS_FULLTIME', FALSE );					// (未対応)録画中もトランスコードするならTRUE
define( 'TRANS_STOP_TIMEZONE', '00:00-03:00' );		// (未対応)トランスコード禁止時間帯
define( 'MOVIE_VIEWER', 'vlc' );					// (未対応)視聴ソフト名(トランスコード停止に使用)
?>
