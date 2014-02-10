<?php

function use_alt_spool()
{
	$settings = Settings::factory();
	return strlen( $settings->alt_spool ) > 0 && substr( $settings->alt_spool, 0, 1 ) === '/' && @is_dir( $settings->alt_spool );
}

function is_alt_spool_writable()
{
	$settings = Settings::factory();
	return use_alt_spool() && @is_writable( $settings->alt_spool );
}

function rate_time( $minute )
{
	if( (int)TS_STREAM_RATE > 0 ){
		$minute /= TS_STREAM_RATE;
		return sprintf( '%dh%02dm', $minute/60, $minute%60 );
	}
	return "不明";
}

function _set_user_group_perm( &$arr, $stat )
{
	$uid = $stat['uid'];
	$gid = $stat['gid'];
	$mode = $stat['mode'];

	$euid = posix_geteuid();
	$egid = posix_getegid();

	$user_stat = posix_getpwuid( $euid );
	$user_name = $user_stat['name'];
	$is_owner = $uid === $euid || $uname === 'root';
	$arr['owner'] = $is_owner ? $user_name : '****';
	$group_stat = posix_getgrgid( $gid );
	$group_name = $group_stat['name'];
	$is_group = false;
	if( $gid === $egid ){
		$arr['group'] = $group_name;
		$is_group = true;
	}else{
		$arr['group'] = '****';
		foreach( $group_stat['members'] as $member ){
			$user = posix_getpwnam( $member );
			if( $user !== false && $user['uid'] === $euid ){
				$arr['group'] = $group_name;
				$is_group = true;
				break;
			}
		}
	}
	$arr['perm']  = sprintf("0%o", $mode );
	$writable = false;
	if( $is_owner ){
		if( ( $mode & 0300 ) === 0300 ){
			$writable = true;
		}
	}
	else if( $is_group ){
		if( ( $mode & 0030 ) === 0030 ){
			$writable = true;
		}
	}
	else if( ( $mode & 0003 ) === 0003 ){
		$writable = true;
	}
	$arr['writable'] = $writable ? '1' : '0';
}

function get_spool_free_space( $force = false )
{
	$settings = Settings::factory();
	$spool_path = INSTALL_PATH.$settings->spool;
	$spool_disks = array();
	if( !defined( 'KATAUNA' ) || $force ){
		// ストレージ空き容量取得
		$ts_stream_rate = (int)TS_STREAM_RATE;
		// 全ストレージ空き容量取得
		$root_mega = $free_mega = (int)( disk_free_space( $spool_path ) / ( 1024 * 1024 ) );
		// スプール･ルート･ストレージの空き容量保存
		$stat  = stat( $spool_path );
		$dvnum = (int)$stat['dev'];
		$spool_disks = array();
		$arr = array();
		$arr['dev']   = $dvnum;
		$arr['name']  = 'main';
		$arr['dname'] = get_device_name( $dvnum );
		$arr['path']  = (string)$settings->spool;
//		$arr['link']  = 'spool root';
		$arr['size']  = $root_mega;
		$arr['hsize'] = number_format( $root_mega/1024, 1 );
		$arr['time']  = rate_time( $root_mega );
		_set_user_group_perm( $arr, $stat );
		array_push( $spool_disks, $arr );
		$devs = array( $dvnum );
		// スプール･ルート上にある全ストレージの空き容量取得
		$files = scandir( $spool_path );
		if( $files !== FALSE ){
			array_splice( $files, 0, 2 );
			foreach( $files as $entry ){
				$entry_path = $spool_path.'/'.$entry;
				if( is_link( $entry_path ) && is_dir( $entry_path ) ){
					$stat  = stat( $entry_path );
					$dvnum = (int)$stat['dev'];
					if( !in_array( $dvnum, $devs ) ){
						$entry_mega = (int)( disk_free_space( $entry_path ) / ( 1024 * 1024 ) );
						$free_mega += $entry_mega;
						$arr = array();
						$arr['dev']   = $dvnum;
						$arr['name']  = $entry;
						$arr['dname'] = get_device_name( $dvnum );
						$arr['path']  = $settings->spool.'/'.$entry;
//						$arr['link']  = readlink( $entry_path );
						$arr['size']  = $entry_mega;
						$arr['hsize'] = number_format( $entry_mega/1024, 1 );
						$arr['time']  = rate_time( $entry_mega );
						_set_user_group_perm( $arr, $stat );
						array_push( $spool_disks, $arr );
						array_push( $devs, array( $dvnum ) );
					}
				}
			}
		}
		// 別録画ストレージ
		if( use_alt_spool() ){
			$stat = stat( (string)$settings->alt_spool );
			if ( $stat !== false ){
				$dvnum = (int)$stat['dev'];
				if( !in_array( $dvnum, $devs ) ){
					$alt_free_mega = (int)( disk_free_space( (string)$settings->alt_spool ) / ( 1024 * 1024 ) );
					$arr = array();
					$arr['dev']   = $dvnum;
					$arr['name']  = 'alt';
					$arr['dname'] = get_device_name( $dvnum );
					$arr['path']  = (string)$settings->alt_spool;
//					$arr['link']  = readlink( $entry_path );
					$arr['size']  = $alt_free_mega;
					$arr['hsize'] = number_format( $alt_free_mega/1024, 1 );
					$arr['time']  = rate_time( $alt_free_mega );
					_set_user_group_perm( $arr, $stat );
					array_push( $spool_disks, $arr );
					array_push( $devs, array( $dvnum ) );
				}
			}
		}
	}else{
		$free_mega = 0;
		$ts_stream_rate = 0;
		$arr = array();
		$arr['dev']   = 0;
		$arr['name']  = 'main';
		$arr['dname'] = 'unknown';
		$arr['path']  = $spool_path;
//		$arr['link']  = 'spool root';
		$arr['size']  = $free_mega;
		$arr['hsize'] = number_format( $free_mega/1024, 1 );
		$arr['time']  = rate_time( $free_mega );
		$arr['owner'] = '****';
		$arr['group'] = '****';
		$arr['perm']  = '000000';
		$arr['writable'] = '0';
		array_push( $spool_disks, $arr );
	}

	return array(
		'free_size'      => $free_mega,
		'free_hsize'     => number_format( $free_mega/1024, 1 ),
		'free_time'      => rate_time( $free_mega ),
		'ts_stream_rate' => $ts_stream_rate,
		'spool_disks'    => $spool_disks
	);
}
