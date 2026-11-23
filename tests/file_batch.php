<?php
// php tests/file_batch.php：真实SQLite SQL行为测试；设置PAN_TEST_MYSQL_PORT后改用隔离MySQL。
if(PHP_SAPI !== 'cli')exit;
$source = dirname(__DIR__).'/';
$temp = sys_get_temp_dir().'/pan_batch_'.bin2hex(random_bytes(8));
mkdir($temp);
define('ROOT', $temp.'/');
define('SYSTEM_ROOT', $source.'includes/');
define('IN_CRONLITE', true);
require SYSTEM_ROOT.'functions.php';
require SYSTEM_ROOT.'file_batch.php';
date_default_timezone_set('Asia/Shanghai');

class BatchSqliteDatabase{
	public $db;
	public function __construct(){ $this->db=new PDO('sqlite::memory:'); $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
	public function query($sql,$args=[]){
		$sql=str_replace(' FOR UPDATE','',$sql);
		if(strpos($sql,'UPDATE ')===0)$sql=preg_replace('/ LIMIT 1$/','',$sql);
		$stmt=$this->db->prepare($sql); $stmt->execute($args); return $stmt;
	}
	public function exec($sql,$args=[]){return $this->query($sql,$args)!==false;}
	public function getRow($sql,$args=[]){return $this->query($sql,$args)->fetch(PDO::FETCH_ASSOC);}
	public function getColumn($sql,$args=[]){
		//SQLite没有服务器命名锁；并发隔离必须使用MySQL模式另外验证。
		if(strpos($sql,'GET_LOCK(')!==false || strpos($sql,'RELEASE_LOCK(')!==false)return 1;
		return $this->query($sql,$args)->fetchColumn();
	}
	public function beginTransaction(){return $this->db->beginTransaction();}
	public function commit(){return $this->db->commit();}
	public function rollBack(){return $this->db->rollBack();}
}
$checks=0; $admin=null; $database='';
function check_batch($condition,$message){
	global $checks;
	if(!$condition)throw new RuntimeException('FAIL '.$message);
	$checks++; echo 'PASS '.$message."\n";
}
function add_batch_file($id,$uid,$name,$folder=0,$block=0,$age=0,$type='txt'){
	global $DB;
	$DB->exec('INSERT INTO pre_file(id,uid,name,folder_id,block,addtime,type,token) VALUES(:id,:uid,:name,:folder,:block,:date,:type,:token)',
		[':id'=>$id,':uid'=>$uid,':name'=>htmlspecialchars($name,ENT_QUOTES,'UTF-8'),':folder'=>$folder,':block'=>$block,
		 ':date'=>date('Y-m-d H:i:s',time()-$age*86400),':type'=>$type,':token'=>md5('batch-file-'.$id)]);
}
function batch_call($action,$input=[],$account=true){
	return file_batch_handle($action,$input+['csrf_token'=>'test-batch-csrf'],$account);
}
function batch_identity($id){
	global $uid,$islogin2;
	$uid=$id;$islogin2=$id>0;
}
function preview_batch($ids,$rules=[],$account=true){
	return batch_call('fileBatchRenamePreview',['ids'=>$ids]+$rules+['mode'=>'affix','prefix'=>'new-','suffix'=>''],$account);
}
function batch_name($id){global $DB;return $DB->getColumn('SELECT name FROM pre_file WHERE id=:id',[':id'=>$id]);}
try{
	if(getenv('PAN_TEST_MYSQL_PORT')){
		$config=['host'=>getenv('PAN_TEST_MYSQL_HOST')?:'127.0.0.1','port'=>getenv('PAN_TEST_MYSQL_PORT'),'user'=>getenv('PAN_TEST_MYSQL_USER')?:'root','pwd'=>getenv('PAN_TEST_MYSQL_PASSWORD')?:''];
		$admin=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['user'],$config['pwd'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
		$database='pan_batch_'.bin2hex(random_bytes(8));
		$admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');
		$config['dbname']=$database;
		require SYSTEM_ROOT.'lib/PdoHelper.php';
		$DB=new lib\PdoHelper($config);
	}else{$DB=new BatchSqliteDatabase();}
	$DB->exec('CREATE TABLE pre_file (id INTEGER PRIMARY KEY, uid INTEGER NOT NULL, folder_id INTEGER NOT NULL DEFAULT 0, name VARCHAR(512) NOT NULL, type VARCHAR(30), token VARCHAR(32), block INTEGER NOT NULL DEFAULT 0, addtime VARCHAR(30))');
	$_SESSION=['csrf_token'=>'test-batch-csrf','fileids'=>[30,31,32,2]];
	$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'files.example','HTTP_REFERER'=>'https://files.example/sub/user.php'];
	$siteurl='https://files.example/sub/';
	$conf=['apiurl'=>$siteurl,'name_block'=>'banned'];
	batch_identity(10);
	add_batch_file(1,10,'report.txt');
	add_batch_file(2,20,'private.txt');
	add_batch_file(3,10,'new-existing.txt');
	add_batch_file(4,10,'existing.txt');
	add_batch_file(5,10,'blocked.txt',0,1);
	add_batch_file(6,10,'empty.txt');
	add_batch_file(7,10,'different.txt',7);
	add_batch_file(8,10,'A & B.txt');
	add_batch_file(9,10,'abc.TXT');
	add_batch_file(10,10,'no-extension',0,0,0,'');
	add_batch_file(11,10,'one.txt');
	add_batch_file(12,10,'two.txt');
	add_batch_file(13,10,'政策.txt');
	add_batch_file(14,10,'partial-one.txt');
	add_batch_file(15,10,'partial-two.txt');
	add_batch_file(16,10,'moved.txt');
	add_batch_file(17,10,'freeze-after-preview.txt');
	add_batch_file(30,0,'guest.txt');
	add_batch_file(31,0,'oldguest.txt',0,0,8);
	add_batch_file(32,0,'second.txt');
	add_batch_file(33,0,'new-guest.txt');
	check_batch(batch_call('fileBatchLinks',['ids'=>[1],'csrf_token'=>'wrong'])['code']!==0,'CSRF错误被拒');
	check_batch(batch_call('fileBatchLinks',['ids'=>[1],'csrf_token'=>[]])['code']!==0,'数组CSRF被拒');
	$_SERVER['REQUEST_METHOD']='GET';
	check_batch(batch_call('fileBatchLinks',['ids'=>[1]])['code']!==0,'GET不能执行批量接口');
	$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_REFERER']='https://evil.example/';
	check_batch(batch_call('fileBatchLinks',['ids'=>[1]])['code']!==0,'跨站来源被拒');
	$_SERVER['HTTP_REFERER']='https://files.example/sub/';
	check_batch(batch_call('fileBatchLinks',['ids'=>['1 OR 1=1']])['code']!==0,'非法ID格式被拒');
	check_batch(batch_call('fileBatchLinks',['ids'=>range(1,101)])['code']!==0,'超过100项被拒');
	$links=batch_call('fileBatchLinks',['ids'=>[1,2,5]]);
	check_batch(count($links['links'])===1 && count($links['rows'])===3,'复制链接逐项授权并报告冻结和越权');
	check_batch($links['links'][0]==='https://files.example/sub/down.php/'.md5('batch-file-1').'.txt','外链使用真实站点根和文件token');
	check_batch($links['rows'][1]['old']==='' && strpos(json_encode($links),'private.txt')===false,'越权结果不泄露文件名');
	$p=preview_batch([1,2,5]);
	check_batch($p['ready']===1 && batch_name(1)==='report.txt','预览无写入且逐项过滤越权/冻结');
	check_batch($p['rows'][0]['name']==='new-report.txt','前缀保留扩展名');
	$a=batch_call('fileBatchRenameApply',['token'=>$p['token'],'ids'=>[2],'name'=>'hijack']);
	check_batch($a['ok']===1 && batch_name(1)==='new-report.txt' && batch_name(2)==='private.txt','确认只执行服务器计划，忽略伪造ID/名称');
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['code']!==0,'预览token一次性防重放');
	$p=preview_batch([4]);
	check_batch($p['ready']===0 && $p['token']==='','同目录已有目标名称则跳过');
	$p=preview_batch([7],['prefix'=>'new-']);
	check_batch($p['ready']===1,'不同目录允许同名策略独立');
	$p=preview_batch([8],['prefix'=>'ok-','suffix'=>'-done']);
	check_batch($p['rows'][0]['name']==='ok-A & B-done.txt','实体解码后改名，后缀在扩展名前');
	$a=batch_call('fileBatchRenameApply',['token'=>$p['token']]);
	check_batch($a['ok']===1 && batch_name(8)==='ok-A &amp; B-done.txt','入库统一转义且不重复编码');
	$p=preview_batch([9],['mode'=>'replace','find'=>'abc','replace'=>'z']);
	check_batch($p['rows'][0]['name']==='z.txt','查找替换不更改文件类型');
	$p=preview_batch([10],['suffix'=>'-tail']);
	check_batch($p['rows'][0]['name']==='new-no-extension-tail','无扩展名文件可添加前后缀');
	check_batch(preview_batch([6],['mode'=>'replace','find'=>'empty','replace'=>''])['ready']===0,'空基础文件名拒绝');
	check_batch(preview_batch([6],['prefix'=>'../'])['ready']===0,'路径分隔符拒绝');
	check_batch(preview_batch([6],['prefix'=>str_repeat('x',120)])['ready']===0,'最终名称超长拒绝');
	check_batch(preview_batch([6],['prefix'=>'banned-'])['ready']===0,'违禁名称拒绝');
	check_batch(preview_batch([6],['prefix'=>[]])['code']!==0,'数组规则参数拒绝');
	check_batch(preview_batch([6],['prefix'=>"\xff"])['code']!==0,'非法UTF8规则被拒');
	check_batch(count(batch_call('fileBatchLinks',['ids'=>[1,1]])['links'])===1,'重复ID去重');
	$p=preview_batch([11,12],['mode'=>'replace','find'=>'.','replace'=>'']);
	check_batch($p['ready']===0,'不匹配的规则不会产生可提交计划');
	$p=preview_batch([11]);
	$DB->exec('UPDATE pre_file SET name=:name WHERE id=:id',[':name'=>'changed.txt',':id'=>11]);
	$a=batch_call('fileBatchRenameApply',['token'=>$p['token']]);
	check_batch($a['ok']===0 && batch_name(11)==='changed.txt','预览后文件名变化拒绝覆盖');
	$p=preview_batch([12]);
	$DB->exec('UPDATE pre_file SET uid=:uid WHERE id=:id',[':uid'=>20,':id'=>12]);
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['ok']===0,'预览后归属变化再次授权');
	$p=preview_batch([14,15]);
	add_batch_file(18,10,'new-partial-one.txt');
	$a=batch_call('fileBatchRenameApply',['token'=>$p['token']]);
	check_batch($a['ok']===1 && $a['fail']===1 && batch_name(14)==='partial-one.txt' && batch_name(15)==='new-partial-two.txt','确认时冲突逐项跳过，其他项目仍成功');
	$p=preview_batch([16]);
	$DB->exec('UPDATE pre_file SET folder_id=:folder WHERE id=:id',[':folder'=>5,':id'=>16]);
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['ok']===0,'预览后移动目录不能沿用旧计划');
	$p=preview_batch([17]);
	$DB->exec('UPDATE pre_file SET block=:block WHERE id=:id',[':block'=>1,':id'=>17]);
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['ok']===0,'预览后被冻结再次拒绝');
	$p=preview_batch([13]);
	$conf['name_block']='new-';
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['ok']===0,'确认时重新检查最新违禁规则');
	$conf['name_block']='banned';
	$p=preview_batch([13]);$_SESSION['file_batch_rename'][$p['token']]['time']=time()-601;
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['code']!==0,'十分钟后计划过期');
	$p=preview_batch([13]);batch_identity(20);
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']])['code']!==0,'登录身份变化不能使用旧计划');
	batch_identity(10);
	for($i=0;$i<8;$i++)preview_batch([13]);
	check_batch(count($_SESSION['file_batch_rename'])===5,'会话只保存最近五个预览计划');
	if($admin){
		check_batch(intval($DB->getColumn('SELECT IS_FREE_LOCK(:name)', [':name'=>'pan_batch_name_'.sha1('10:0')]))===1,'成功及失败后MySQL目录命名锁均释放');
	}
	batch_identity(0);
	$p=preview_batch([30,31,2],[],false);
	check_batch($p['ready']===1,'游客仅能操作本会话七天内uid0文件');
	check_batch($p['rows'][0]['status']==='ready','别的游客浏览器同名不算本会话冲突');
	check_batch(preview_batch([30],[],true)['ready']===0,'游客不能走账号接口获得权限');
	$a=batch_call('fileBatchRenameApply',['token'=>$p['token']],false);
	check_batch($a['ok']===1 && batch_name(30)==='new-guest.txt','游客确认改名成功');
	$p=preview_batch([32],[],false);
	unset($_SESSION['fileids'][array_search(32,$_SESSION['fileids'],true)]);
	check_batch(batch_call('fileBatchRenameApply',['token'=>$p['token']],false)['ok']===0,'游客会话失去文件ID后确认被拒');
	$cfg=['batch_copy'=>'自定义复制"<script>'];
	$labels=file_batch_labels_html($cfg);
	check_batch(strpos($labels,'<script>')===false && json_decode(html_entity_decode($labels,ENT_QUOTES,'UTF-8'),true)['batch_copy']===$cfg['batch_copy'],'自定义文案序列化可安全放HTML属性');
	check_batch(count(file_batch_schema())===26,'个人中心与游客使用统一可配置文案');
	echo "PASS total=$checks backend=".($admin?'mysql':'sqlite')."\n";
}finally{
	if($admin && $database)$admin->exec('DROP DATABASE '.$database);
	if(is_dir($temp)){
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
		foreach($iterator as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}
		rmdir($temp);
	}
}
