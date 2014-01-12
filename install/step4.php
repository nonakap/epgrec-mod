<?php
include_once( "../config.php");
include_once( INSTALL_PATH."/Settings.class.php" );

// 設定の保存
$settings = Settings::factory();
$settings->post();
$settings->save();

//EPG取得所要時間の計算
$BS_tuners = (int)$settings->bs_tuners;
$GR_tuners = (int)$settings->gr_tuners;
$CS_flag   = $settings->cs_rec_flg!=0 ? TRUE : FALSE;
// XML取り込みは、BS 210sec(atomD525) CS 140sec(仮定)を想定
if( $BS_tuners > 0 ){
	if( !$CS_flag ){
		$bs_max = 1;
		$bs_tim = array( 0, 220 + 15 + 30 );	// BS only
	}else{
		$bs_max = $BS_tuners>=3 ? 3 : $BS_tuners;
//		$bs_tim = array( 0, 950, 890, 890 );	// XML取り込み２並列
		$bs_tim = array( 0, 750, 510, 330 );	// XML取り込み２並列
	}
}
$gr_rec_tm = FIRST_REC + $settings->rec_switch_time + 1;
$GR_num = count( $GR_CHANNEL_MAP );
if( $BS_tuners ){
	$bs_max = $BS_tuners>=3 ? 3 : $BS_tuners;
	if( !$CS_flag ){
		$shepherd_th_tm = $bs_tim[1];
		$getepg_th_tm   = 180 + 15;
		$getepg_th_xm   = 210;
	}else{
		$shepherd_th_tm = $bs_tim[$bs_max];
		$getepg_th_tm   = 180 + 15 +( 120 + 15 ) * 2;
		$getepg_th_xm   = 210 + 140 * 2;
	}
}else{
	$shepherd_th_tm = 0;
	$getepg_th_tm   = 0;
	$getepg_th_xm   = 0;
}

if( $GR_tuners ){
	$shepherd_gr_tm = (int)ceil( $GR_num / $GR_tuners ) * $gr_rec_tm;
	$getepg_gr_tm   = $GR_num * ( 60 + 10 );
}else{
	$shepherd_gr_tm = 0;
	$getepg_gr_tm   = 0;
}

if( $shepherd_th_tm < $shepherd_gr_tm )
	$shepherd_th_tm = $shepherd_gr_tm;
$shepherd_th_tm = (int)ceil( $shepherd_th_tm / 60 );

$getepg_th_tm = (int)ceil( ($getepg_th_tm+$getepg_gr_tm) / 60 );

//shepherd.php 所要時間算出
$tmpdrive_size = disk_free_space( "/tmp" );
$gr_bs_para = FALSE;
if( RECPT1_EPG_PATCH && TUNER_UNIT1>0 ){
	$gr_pt1 = $GR_tuners<TUNER_UNIT1? $GR_tuners : TUNER_UNIT1;
	$bs_pt1 = $BS_tuners<TUNER_UNIT1? $BS_tuners : TUNER_UNIT1;
}else{
	$gr_pt1 = 0;
	$bs_pt1 = 0;
}
for( $tuner=0; $tuner<$GR_tuners-TUNER_UNIT1; $tuner++ ){
	if( $OTHER_TUNERS_CHARA['GR'][$tuner]['epgTs'] )
		$gr_pt1++;
}
for( $tuner=0; $tuner<$BS_tuners-TUNER_UNIT1; $tuner++ ){
	if( $OTHER_TUNERS_CHARA['BS'][$tuner]['epgTs'] )
		$bs_pt1++;
}
$gr_oth = $GR_tuners - $gr_pt1;
$bs_oth = $BS_tuners - $bs_pt1;
if( $gr_pt1>0 || $bs_pt1>0 ){
	if( $gr_oth && $tmpdrive_size<=(GR_OTH_EPG_SIZE+GR_XML_SIZE) || $bs_oth && $tmpdrive_size<=(BS_OTH_EPG_SIZE+BS_XML_SIZE) ){
		reclog( 'shepherd.php::テンポラリー容量が不十分なためEPG更新が出来ません。空き容量を確保してください。', EPGREC_ERR );
		exit();
	}
	$gr_work_size = $gr_oth || $gr_pt1 ? GR_OTH_EPG_SIZE * $gr_oth + GR_PT1_EPG_SIZE * $gr_pt1 + GR_XML_SIZE : 0;

	if( $bs_oth ){
		$th_work_size = BS_OTH_EPG_SIZE + BS_XML_SIZE;
		if( $bs_oth > 1 )
			$th_work_size += CS_OTH_EPG_SIZE * ( $bs_oth - 1 );
	}else
		$th_work_size = 0;
	if( $bs_pt1 && $bs_oth < 3 ){
		$th_work_size += $bs_oth ? CS_PT1_EPG_SIZE : BS_PT1_EPG_SIZE;
		if( 3-$bs_oth > 1 )
			$th_work_size += CS_PT1_EPG_SIZE * ( 3 - $bs_oth - 1 );
	}
	if( $gr_work_size+$th_work_size > $tmpdrive_size ){
		$gr_bs_sepa = TRUE;
		if( $th_work_size > $tmpdrive_size )
			$bs_use = 1;
		else
			$bs_use = $bs_max<3 ? $bs_max : 3;
		$gr_use = $gr_work_size>$tmpdrive_size ? 1 : $GR_tuners;
	}else{
		$gr_bs_sepa = FALSE;
		$gr_use     = $GR_tuners;
		$bs_use     = $bs_max;
	}
}else{
	$tune_cnts = (int)( $tmpdrive_size / GR_OTH_EPG_SIZE );
	if( $tune_cnts == 0 ){
		echo 'step4.php::テンポラリー容量が不十分なためEPG更新が出来ません。空き容量を確保してください。';
		exit();
	}
	$bs_tmp = array( 0, 3, 4, 6 );
	if( $BS_tuners > 0 ){
		if( $tune_cnts < 3 ){
			echo 'shepherd.php::テンポラリー容量が不十分なため衛星波のEPG更新が出来ません。空き容量を確保してください。';
			exit();
		}else{
			if( $tune_cnts == 3 ){
				$gr_bs_para = TRUE;
				$gr_use = $GR_tuners>3 ? 3 : $GR_tuners;
				$bs_use = 1;
				reclog( 'shepherd.php::テンポラリー容量が不十分なため地上波･衛星波並列受信が出来ません。空き容量を確保してください。', EPGREC_WARN );
			}else{
				if( $GR_tuners > 0 ){
					if( $bs_tmp[$bs_max]+$GR_tuners > $tune_cnts ){
						$minimam = 11 * 60;
						$bs_use  = $bs_max;
						for( $bs_stk=$bs_max; $bs_stk>0; $bs_stk-- )
							if( $tune_cnts > $bs_tmp[$bs_stk] ){
								$temp = abs( $bs_tim[$bs_stk] - (int)ceil( $GR_num / ($tune_cnts-$bs_tmp[$bs_stk]) )*$gr_rec_tm );
								if( $minimam >= $temp ){
									$minimam = $temp;
									$bs_use  = $bs_stk;
								}
							}
						$gr_use = $tune_cnts - $bs_tmp[$bs_use];
						//所要時間算出
						$gr_times = (int)ceil( $GR_num / $gr_use ) * $gr_rec_tm;
						$para_tm  = $gr_times<$bs_tim[$bs_use] ? $bs_tim[$bs_use] : $gr_times;
						//セパレート･モード時の所要時間算出
						$gr_use_sepa = $GR_tuners>$tune_cnts ? $tune_cnts : $GR_tuners;
						$gr_times    = (int)ceil( $GR_num / $gr_use_sepa ) * $gr_rec_tm;
						for( $bs_use_sepa=$bs_max; $bs_use_sepa>0; $bs_use_sepa-- )
							if( $bs_tmp[$bs_use_sepa] <= $tune_cnts )
								break;
						$sepa_tm = $gr_times + $bs_tim[$bs_use_sepa];
						//地上波･衛星波 分離判定
						if( $sepa_tm < $para_tm ){
							$gr_bs_para = TRUE;
							$gr_use = $gr_use_sepa;
							$bs_use = $bs_use_sepa;
						}
					}else{
						$gr_use = $GR_tuners;
						$bs_use = $bs_max;
					}
				}else{
					$gr_use = 0;
					for( $bs_use=$bs_max; $bs_use>0; $bs_use-- )
						if( $bs_tmp[$bs_use] <= $tune_cnts )
							break;
				}
			}
		}
	}else{
		$gr_use = $GR_tuners>$tune_cnts ? $tune_cnts : $GR_tuners;
		$bs_use = 0;
	}
}
$gr_times = $gr_use ? (int)ceil( $GR_num / $gr_use )*$gr_rec_tm : 0;
if( $gr_bs_para ){
	$shepherd_tm = $gr_times + $bs_tim[$bs_use];
}else{
	$shepherd_tm = $gr_times>$bs_tim[$bs_use] ? $gr_times : $bs_tim[$bs_use];
}
$shepherd_tm = (int)ceil( $shepherd_tm / 60 );
?>

<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
"http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta http-equiv="Content-Style-Type" content="text/css">
<title>インストール最終ステップ</title>
</head>

<body>

<p>初期設定が完了しました。<br>
下のリンクをクリックするとEPGの初回受信が始まります。初回受信が終了するまで番組表は表示できません。</p>

<p>EPG受信後、/etc/cron.d/以下にcronによるEPG受信の自動実行を設定する必要があります。<br>
Debian/Ubuntu用の設定ファイルは<?php echo INSTALL_PATH; ?>/cron.d/getepgです。Debian/Ubuntuをお使いの方は<br>
<pre>
$ sudo cp <?php echo INSTALL_PATH; ?>/cron.d/getepg /etc/cron.d/ [Enter]
</pre>
<br>という具合にコピーするだけで動作するでしょう。それ以外のディストリビューションをお使いの方は<br>
Debian/Ubuntu用の設定ファイルを参考に、適切にcronの設定を行ってください。</p>

<p>なお、設定ミスや受信データの異常によってEPGの初回受信に失敗すると番組表の表示はできません。<br>
設定ミスが疑われる場合、<a href="<?php echo $settings->install_url; ?>/install/step1.php">インストール設定</a>を実行し直してください。<br>
また、手動でEPGの受信を試みるのもひとつの方法です。コンソール上で、<br>
<pre>
$ <?php echo INSTALL_PATH; ?>/getepg.php [Enter]
</pre>
<br>
と実行してください。</p>
<br>
<p>EPGの受信には<?php echo $getepg_th_tm; ?>分程度かかります。</p>
<a href="step5.php?script=/getepg.php&amp;time=<?php echo $getepg_th_tm; ?>">このリンクをクリックするとEPGの初回受信を開始します。</a>
<p><br><br></p>
<p>EPG受信を並列受信EPG取得スクリプト"shepherd.php"にて行うと<?php echo $shepherd_tm; ?>分程度かかります。</p>

<p>EPG受信後、EPG受信の自動実行をshepherd.phpにて行う場合は、上記説明中の"getepg"を"shepherd"に置き換えてお読みください。</p>

<p>＜注意事項＞<br>
当スクリプトは、全チューナーを総動員してEPG受信を行います。<br>
録画コマンドでEPG用TS出力が出来ない場合は、テンポラリーを大量消費します。<br>
テンポラリーが不足する場合、それに応じて同時受信数を減らしますがEPG取得完了が遅くなります。<br>
テンポラリー容量の目安は、PT2 1枚の場合、地上波のみ340MB・地上波+BS 850MB・地上波+BS+CS 1020MBです。<br>
2枚挿しの場合は地上波のみ680MB・地上波+BS 1190MB・地上波+BS+CS 1700MBとなります。<br>
(衛星波のEPG受信は最大3チューナーまでしか使用しませんので若干減ります。)<br>
地デジ10局+BS+CSを同時受信する場合は、2720MB使用します。<br>
十分なテンポラリーを確保した環境下での最短受信時間は、<?php echo $shepherd_th_tm; ?>分です。</p>

<a href="step5.php?script=/shepherd.php&amp;time=<?php echo $shepherd_tm; ?>">このリンクをクリックすると並列受信EPG取得スクリプト"shepherd.php"にてEPGの初回受信を開始します。</a>
</body>
</html>
