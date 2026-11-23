<?php
/*
 * 上传选定的storage引用是本次任务的归属，不能因全站已有同hash而跨实例秒传。
 * 秒传只查目标存储；实际写入后的审核状态仍可由上层按内容hash继承。
 */
if(!defined('IN_CRONLITE'))exit;

function upload_existing_in_storage($hash, $storage){
	global $DB, $conf;
	$args = [':hash'=>$hash, ':storage'=>$storage];
	$condition = 'storage=:storage';
	//空字段只兼容尚未迁移的原生默认，不能将旧数据绑定到后来新增的inst默认。
	if(!\lib\StorageInstances::isReference($storage) && $storage === $conf['storage'])$condition .= " OR storage=''";
	return $DB->getRow('SELECT * FROM pre_file WHERE hash=:hash AND ('.$condition.') ORDER BY id ASC LIMIT 1', $args);
}

function upload_state_compatible($previous, $wanted){
	if(!is_array($previous))return true;
	//同一任务重试可以继续；同hash的另一个文件/目标不能覆盖正在上传的会话状态。
	foreach(['storage','name','size','ext','hide','pwd','replace_id','folder_id'] as $key){
		if(!array_key_exists($key, $previous) || !array_key_exists($key, $wanted) || (string)$previous[$key] !== (string)$wanted[$key])return false;
	}
	return true;
}
