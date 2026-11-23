<?php
/*
 * 两个“我的文件”入口共用的批量操作。
 * 预览只读；确认使用会话内一次性计划，重新校验归属和旧名称，不能把前端回传的新名称当作授权。
 */
if(!defined('IN_CRONLITE'))exit;

//卡片上的新增按钮和弹窗文案都进入同一份schema，28套主题与自定义布局可直接调整。
function file_batch_schema(){
	$text = [
		'copy'=>['复制外链按钮', '批量复制外链'],
		'rename'=>['批量改名标题', '批量改名'],
		'links_title'=>['外链弹窗标题', '已选文件的外链'],
		'close'=>['关闭按钮', '关闭'],
		'copied'=>['复制成功提示', '外链已复制'],
		'manual_copy'=>['复制失败提示', '请手动复制已选中的外链'],
		'failed'=>['操作失败提示', '操作失败，请重新预览后重试'],
		'network'=>['网络错误提示', '网络错误，请稍后重试'],
		'old'=>['原文件名列', '原文件名'],
		'new'=>['新文件名列', '新文件名'],
		'status'=>['处理结果列', '处理结果'],
		'mode'=>['改名方式标签', '改名方式'],
		'replace_mode'=>['查找替换选项', '查找替换'],
		'affix_mode'=>['前后缀选项', '添加前缀或后缀'],
		'find'=>['查找标签', '查找文字'],
		'replacement'=>['替换标签', '替换成（可留空）'],
		'prefix'=>['前缀标签', '文件名前缀'],
		'suffix'=>['后缀标签', '文件名后缀（加在扩展名前）'],
		'hint'=>['改名说明', '仅处理选中的文件，文件夹不参与。保留扩展名及外链；先预览再确认，同目录重名或无权限的项目会跳过。'],
		'preview'=>['预览按钮', '预览修改'],
		'apply'=>['确认按钮', '确认改名'],
		'adjust'=>['调整按钮', '调整规则并重新预览'],
		'no_change'=>['没有可改名项提示', '没有可以修改的文件，请调整规则'],
		'result'=>['结果弹窗标题', '批量改名结果'],
		'select'=>['未选择提示', '请先选择文件，文件夹不参与此操作'],
		'limit'=>['数量限制提示', '一次最多处理100个文件'],
	];
	$schema = [];
	foreach($text as $key=>$item)$schema[] = ['key'=>'batch_'.$key, 'type'=>'text', 'label'=>$item[0], 'default'=>$item[1]];
	return $schema;
}

function file_batch_labels_html($cfg){
	$labels = [];
	foreach(file_batch_schema() as $field){
		$labels[$field['key']] = isset($cfg[$field['key']]) && is_scalar($cfg[$field['key']]) ? (string)$cfg[$field['key']] : $field['default'];
	}
	return htmlspecialchars(json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function file_batch_ids($input){
	if(!is_array($input))return [];
	if(count($input) > 100)return [];
	$ids = [];
	foreach($input as $id){
		if((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,9}$/', (string)$id))return [];
		$ids[intval($id)] = intval($id);
	}
	return array_values($ids);
}

function file_batch_owns($row, $account_only){
	global $islogin2, $uid;
	if(!$row)return false;
	if($account_only)return !empty($islogin2) && intval($uid) > 0 && intval($row['uid']) === intval($uid);
	//游客只认本浏览器七天内的游客文件，不能借会话残留ID改登录账号名下的文件。
	if(empty($islogin2) && intval($row['uid']) !== 0)return false;
	return can_manage_file($row);
}

function file_batch_text($input, $key){
	if(!isset($input[$key]))return '';
	if(!is_string($input[$key]) || !mb_check_encoding($input[$key], 'UTF-8'))return false;
	if(mb_strlen($input[$key], 'UTF-8') > 120)return false;
	return $input[$key];
}

function file_batch_rules($input){
	$mode = isset($input['mode']) && is_string($input['mode']) ? $input['mode'] : '';
	if(!in_array($mode, ['replace', 'affix'], true))return [null, '请选择改名方式'];
	$rules = ['mode'=>$mode];
	foreach(['find','replace','prefix','suffix'] as $key){
		$rules[$key] = file_batch_text($input, $key);
		if($rules[$key] === false)return [null, '改名规则格式错误，单项最多120个字'];
	}
	if($mode === 'replace' && $rules['find'] === '')return [null, '请填写要查找的文字'];
	if($mode === 'affix' && $rules['prefix'] === '' && $rules['suffix'] === '')return [null, '请填写前缀或后缀'];
	return [$rules, ''];
}

function file_batch_new_name($row, $rules){
	global $conf;
	if(intval($row['block']) === 1)return ['', '', '文件已被冻结，无法重命名'];
	$old = html_entity_decode($row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$ext = empty($row['type']) ? '' : strtolower($row['type']);
	$base = $old;
	if($ext !== '' && substr(strtolower($base), -strlen($ext)-1) === '.'.$ext){
		$base = substr($base, 0, -strlen($ext)-1);
	}
	$new = $rules['mode'] === 'replace'
		? str_replace($rules['find'], $rules['replace'], $base)
		: $rules['prefix'].$base.$rules['suffix'];
	$new = trim($new);
	if($new === '')return ['', '', '文件名不能为空'];
	if(preg_match('/[\/\\\\:*?"<>|\x00-\x1f\x7f]/u', $new))return ['', '', '文件名不能含路径分隔符、控制字符或特殊字符'];
	if($ext !== '')$new .= '.'.$ext;
	$stored = htmlspecialchars($new, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	if(mb_strlen($stored, 'UTF-8') > 120)return ['', '', '文件名不能超过120个字'];
	if(!empty($conf['name_block'])){
		foreach(explode('|', $conf['name_block']) as $bad){
			if($bad !== '' && (strpos($stored, $bad) !== false || strpos($new, $bad) !== false))return ['', '', '文件名包含不允许的内容'];
		}
	}
	return [$stored, $new, ''];
}

//冲突限于同一归属者的同一目录。游客uid都为0，不能将其他浏览器的文件误算进来。
function file_batch_conflict($row, $name){
	global $DB, $islogin2;
	$sql = 'SELECT id FROM pre_file WHERE id<>:id AND uid=:uid AND folder_id=:folder AND name=:name';
	$args = [':id'=>intval($row['id']), ':uid'=>intval($row['uid']), ':folder'=>intval($row['folder_id']), ':name'=>$name];
	if(empty($islogin2)){
		$guest = isset($_SESSION['fileids']) && is_array($_SESSION['fileids']) ? $_SESSION['fileids'] : [];
		$guest = array_values(array_unique(array_filter(array_map('intval', $guest))));
		if(!$guest)return false;
		$marks = [];
		foreach($guest as $i=>$id){ $key = ':g'.$i; $marks[] = $key; $args[$key] = $id; }
		$sql .= ' AND id IN ('.implode(',', $marks).')';
	}
	$result = $DB->query($sql.' LIMIT 1', $args);
	if($result === false)throw new RuntimeException('无法检查同名文件');
	return (bool)$result->fetch();
}

function file_batch_identity($account_only){
	global $islogin2, $uid;
	return ($account_only ? 'account:' : 'mine:').(!empty($islogin2) ? intval($uid) : 'guest');
}

function file_batch_handle($action, $input, $account_only = false){
	global $DB;
	if(!is_array($input))return ['code'=>-1, 'msg'=>'参数格式错误'];
	if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')return ['code'=>-1, 'msg'=>'请使用POST请求'];
	if(!checkRefererHost())return ['code'=>-1, 'msg'=>'来源校验失败'];
	if(!isset($input['csrf_token'], $_SESSION['csrf_token']) || !is_string($input['csrf_token']) || !is_string($_SESSION['csrf_token'])
		|| $_SESSION['csrf_token'] === '' || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])){
		return ['code'=>-1, 'msg'=>'CSRF TOKEN ERROR'];
	}
	if($action === 'fileBatchRenameApply')return file_batch_apply($input, $account_only);
	if(!in_array($action, ['fileBatchLinks','fileBatchRenamePreview'], true))return ['code'=>-1, 'msg'=>'未知批量操作'];
	$ids = file_batch_ids(isset($input['ids']) ? $input['ids'] : []);
	if(!$ids)return ['code'=>-1, 'msg'=>'请选择1至100个有效文件'];
	$rules = null;
	if($action === 'fileBatchRenamePreview'){
		list($rules, $error) = file_batch_rules($input);
		if($error !== '')return ['code'=>-1, 'msg'=>$error];
	}
	$rows = []; $plan = []; $reserved = []; $links = [];
	try{
		foreach($ids as $id){
			$row = $DB->getRow('SELECT * FROM pre_file WHERE id=:id LIMIT 1', [':id'=>$id]);
			$item = ['id'=>$id, 'old'=>'', 'name'=>'', 'status'=>'skip', 'msg'=>'文件不存在或无权限'];
			if(!file_batch_owns($row, $account_only)){ $rows[]=$item; continue; }
			$item['old'] = html_entity_decode($row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if($action === 'fileBatchLinks'){
				if(intval($row['block']) >= 1){
					$item['msg'] = '文件已冻结或待人工审核'; $rows[]=$item; continue;
				}
				$item['url'] = site_root_url().'down.php/'.rawurlencode($row['token']).'.'.rawurlencode($row['type'] ?: 'file');
				$item['status'] = 'ok'; $item['msg'] = '可复制'; $links[] = $item['url']; $rows[] = $item; continue;
			}
			list($stored, $name, $error) = file_batch_new_name($row, $rules);
			$item['name'] = $name;
			if($error !== ''){ $item['msg']=$error; $rows[]=$item; continue; }
			if($stored === $row['name']){ $item['msg']='名称没有变化'; $rows[]=$item; continue; }
			$key = intval($row['uid']).':'.intval($row['folder_id']).':'.mb_strtolower($stored, 'UTF-8');
			if(isset($reserved[$key]) || file_batch_conflict($row, $stored)){
				$item['msg']='目标目录已有同名文件，已跳过'; $rows[]=$item; continue;
			}
			$reserved[$key] = true;
			$item['status']='ready'; $item['msg']='等待确认'; $rows[]=$item;
			$plan[] = ['id'=>$id, 'old'=>$row['name'], 'name'=>$stored, 'uid'=>intval($row['uid']), 'folder'=>intval($row['folder_id'])];
		}
	}catch(Throwable $e){
		return ['code'=>-1, 'msg'=>'读取批量操作数据失败，请稍后重试'];
	}
	if($action === 'fileBatchLinks')return ['code'=>count($links) ? 0 : -1, 'msg'=>'可复制'.count($links).'个外链，跳过'.(count($rows)-count($links)).'项', 'rows'=>$rows, 'links'=>$links];
	$token = '';
	if($plan){
		if(empty($_SESSION['file_batch_rename']) || !is_array($_SESSION['file_batch_rename']))$_SESSION['file_batch_rename'] = [];
		foreach($_SESSION['file_batch_rename'] as $key=>$previous){
			if(!is_array($previous) || ($previous['time'] ?? 0) < time()-600)unset($_SESSION['file_batch_rename'][$key]);
		}
		while(count($_SESSION['file_batch_rename']) >= 5)array_shift($_SESSION['file_batch_rename']);
		$token = bin2hex(random_bytes(24));
		$_SESSION['file_batch_rename'][$token] = ['time'=>time(), 'identity'=>file_batch_identity($account_only), 'rules'=>$rules, 'rows'=>$plan];
	}
	return ['code'=>0, 'msg'=>'可修改'.count($plan).'项，跳过'.(count($rows)-count($plan)).'项', 'rows'=>$rows, 'token'=>$token, 'ready'=>count($plan)];
}

function file_batch_apply($input, $account_only){
	global $DB;
	$token = isset($input['token']) && is_string($input['token']) ? $input['token'] : '';
	if(!preg_match('/^[a-f0-9]{48}$/', $token) || empty($_SESSION['file_batch_rename'][$token])){
		return ['code'=>-1, 'msg'=>'改名预览已失效，请重新预览'];
	}
	$plan = $_SESSION['file_batch_rename'][$token];
	if($plan['time'] < time()-600 || $plan['identity'] !== file_batch_identity($account_only)){
		unset($_SESSION['file_batch_rename'][$token]);
		return ['code'=>-1, 'msg'=>'改名预览已过期或身份已变化，请重新预览'];
	}
	//一个计划只能确认一次；前端不能额外传ID或名称来扩充它。
	unset($_SESSION['file_batch_rename'][$token]);
	$rows = []; $ok = 0;
	foreach($plan['rows'] as $entry){
		$item = ['id'=>$entry['id'], 'old'=>html_entity_decode($entry['old'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'name'=>html_entity_decode($entry['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'status'=>'skip', 'msg'=>'文件已变化，请重新预览'];
		$transaction = false; $lock = '';
		try{
			//同一归属者同目录的两个批量请求串行化，避免都在冲突检查后写入同一名称。
			$scope = $entry['uid'].':'.$entry['folder'];
			if(!$entry['uid'])$scope .= ':'.session_id();
			$lock = 'pan_batch_name_'.sha1($scope);
			if(intval($DB->getColumn('SELECT GET_LOCK(:key, 5)', [':key'=>$lock])) !== 1)throw new RuntimeException('目录正忙');
			if(!$DB->beginTransaction())throw new RuntimeException('开启事务失败');
			$transaction = true;
			$row = $DB->getRow('SELECT * FROM pre_file WHERE id=:id LIMIT 1 FOR UPDATE', [':id'=>$entry['id']]);
			if(!file_batch_owns($row, $account_only) || intval($row['block']) === 1 || $row['name'] !== $entry['old']
				|| intval($row['folder_id']) !== $entry['folder'] || intval($row['uid']) !== $entry['uid']){
				$DB->rollBack(); $transaction=false; $rows[]=$item; continue;
			}
			list($current_name, $plain_name, $name_error) = file_batch_new_name($row, $plan['rules']);
			if($name_error !== '' || $current_name !== $entry['name']){
				$item['msg']=$name_error ?: '改名规则已变化，请重新预览';
				$DB->rollBack(); $transaction=false; $rows[]=$item; continue;
			}
			if(file_batch_conflict($row, $entry['name'])){
				$item['msg']='目标目录已有同名文件，已跳过'; $DB->rollBack(); $transaction=false; $rows[]=$item; continue;
			}
			$updated = $DB->query('UPDATE pre_file SET name=:name WHERE id=:id AND uid=:uid AND folder_id=:folder AND name=:old AND block<>1 LIMIT 1',
				[':name'=>$entry['name'], ':id'=>$entry['id'], ':uid'=>$entry['uid'], ':folder'=>$entry['folder'], ':old'=>$entry['old']]);
			//PdoHelper::exec($sql, $args)返回execute()的布尔值，不能拿它当影响行数。
			if($updated === false || $updated->rowCount() !== 1)throw new RuntimeException('名称写入失败');
			if(!$DB->commit())throw new RuntimeException('提交事务失败');
			$transaction=false; $item['status']='ok'; $item['msg']='已重命名'; $ok++;
		}catch(Throwable $e){
			if($transaction){ try{$DB->rollBack();}catch(Throwable $ignored){} }
			$item['msg']='保存失败，请重新预览后重试';
		}finally{
			if($lock !== '')$DB->getColumn('SELECT RELEASE_LOCK(:key)', [':key'=>$lock]);
		}
		$rows[]=$item;
	}
	if($ok)layout_cache_bump();
	return ['code'=>$ok ? 0 : -1, 'msg'=>'已重命名'.$ok.'项，跳过'.(count($rows)-$ok).'项', 'ok'=>$ok, 'fail'=>count($rows)-$ok, 'rows'=>$rows];
}
