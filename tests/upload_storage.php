<?php
// Real isolated MySQL + actual ajax/API controllers; never loads the site's config.php.
if(PHP_SAPI !== 'cli')exit;
$source=dirname(__DIR__).'/';
$credential=getenv('PAN_TEST_DB_FILE');
if(!$credential || !is_file($credential))exit("Set PAN_TEST_DB_FILE for an isolated database\n");
$credentials=json_decode(ltrim(file_get_contents($credential),"\xef\xbb\xbf"),true);
$directory=sys_get_temp_dir().'/pan_upload_storage_'.bin2hex(random_bytes(8));
mkdir($directory);mkdir($directory.'/site');mkdir($directory.'/sessions');
foreach(['file','one','two'] as $sub)mkdir($directory.'/site/'.$sub);
define('ROOT',$directory.'/site/');
define('SYSTEM_ROOT',$source.'includes/');
define('SYS_KEY','upload-storage-regression-only');
define('IN_CRONLITE',true);
date_default_timezone_set('Asia/Shanghai');
require SYSTEM_ROOT.'autoloader.php';Autoloader::register();
require SYSTEM_ROOT.'functions.php';
require SYSTEM_ROOT.'upload_storage.php';
$admin=new PDO('mysql:host='.$credentials['host'].';port='.$credentials['port'].';charset=utf8mb4',$credentials['user'],$credentials['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='pan_upload_scope_'.bin2hex(random_bytes(6));
$process=null;$pipes=[];$count=0;
function upload_check($ok,$message){
	global $count;
	if(!$ok)throw new RuntimeException($message);
	$count++;echo "PASS $message\n";
}
function upload_row($id){global $DB;return $DB->getRow('SELECT * FROM pre_file WHERE id=:id',[':id'=>$id]);}
function upload_fixture($name,$content,$storage,$block=0){
	global $DB,$clientip;
	$DB->exec("INSERT INTO pre_file(name,type,size,hash,storage,token,block,uid,ip,ipkey,hide,addtime) VALUES(:name,'txt',:size,:hash,:storage,:token,:block,1001,:ip,:ipkey,1,NOW())",
		[':name'=>$name,':size'=>strlen($content),':hash'=>md5($content),':storage'=>$storage,':token'=>bin2hex(random_bytes(16)),':block'=>$block,':ip'=>$clientip,':ipkey'=>client_ip_key($clientip)]);
	return intval($DB->lastInsertId());
}
function upload_request($path,$fields=[],$upload=null,$api=false){
	global $base,$directory,$apiToken;
	$curl=curl_init($base.$path);
	$headers=$api?['Authorization: Bearer '.$apiToken]:[];
	if($upload !== null)$fields['file']=new CURLFile($upload,'text/plain',basename($upload));
	curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_COOKIEFILE=>$directory.'/cookies',CURLOPT_COOKIEJAR=>$directory.'/cookies',CURLOPT_REFERER=>$base.'/upload.php',CURLOPT_HTTPHEADER=>$headers]);
	if($fields)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$upload !== null?$fields:http_build_query($fields)]);
	$body=curl_exec($curl);
	if($body===false)throw new RuntimeException('HTTP request failed: '.curl_error($curl));
	$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
	$result=json_decode($body,true);
	if(!is_array($result))throw new RuntimeException('Invalid controller JSON (HTTP '.$code.'): '.substr($body,0,700));
	return $result;
}
try{
	$admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');$admin->exec('USE '.$database);
	$admin->exec(file_get_contents($source.'install/install.sql'));
	$config=['host'=>$credentials['host'],'port'=>$credentials['port'],'user'=>$credentials['user'],'pwd'=>$credentials['password'],'dbname'=>$database];
	$DB=new lib\PdoHelper($config);
	$conf=getAllSetting();
	$conf=array_merge($conf,['storage'=>'local','filepath'=>ROOT.'file/','storage_multi'=>'1','storage_pool'=>'','api_open'=>'1','api_referer'=>'',
		'forcelogin'=>'0','uploadfile_type'=>'0','green_check'=>'0','videoreview'=>'0','level_migrated'=>'1','level_upload_migrated'=>'1','upload_per_minute'=>'0']);
	$clientip='127.0.0.1';$uid=1001;$islogin2=true;$siteurl='http://127.0.0.1/';
	$_SERVER['REMOTE_ADDR']=$clientip;$_SESSION=[];
	$DB->exec("INSERT INTO pre_user(uid,type,openid,nickname,enable,level_id,space,addtime,lasttime) VALUES(1001,'mail','upload-scope','Scope',1,3,-1,NOW(),NOW())");
	$userrow=$DB->getRow('SELECT * FROM pre_user WHERE uid=1001');
	$created=lib\StorageInstances::save(0,'local','One',['filepath'=>ROOT.'one'],true,0);
	upload_check($created===true,'create first isolated target');
	upload_check(lib\StorageInstances::save(0,'local','Two',['filepath'=>ROOT.'two'],true,0)===true,'create second same-type target');
	$keys=array_keys(lib\StorageInstances::all());$one=$keys[0];$two=$keys[1];
	$alpha='same bytes in one target';$hash=md5($alpha);
	file_put_contents(ROOT.'one/'.$hash,$alpha);
	$first=upload_fixture('alpha.txt',$alpha,$one);
	upload_check((int)upload_existing_in_storage($hash,$one)['id']===$first,'target-local duplicate query finds its own record');
	upload_check(!upload_existing_in_storage($hash,$two),'same hash only in A cannot deduplicate into B');
	$legacy=upload_fixture('legacy.txt','legacy','');
	upload_check((int)upload_existing_in_storage(md5('legacy'),'local')['id']===$legacy,'legacy empty storage belongs to native current default only');
	$conf['storage']=$two;
	upload_check(!upload_existing_in_storage(md5('legacy'),$two),'new instance default never claims legacy empty reference');
	$conf['storage']='local';
	$wanted=['storage'=>$two,'name'=>'alpha.txt','size'=>strlen($alpha),'ext'=>'txt','hide'=>1,'pwd'=>null,'replace_id'=>0,'folder_id'=>0];
	upload_check(upload_state_compatible($wanted,$wanted),'same upload task remains retryable');
	foreach(['storage'=>$one,'name'=>'other.txt','size'=>99,'ext'=>'md','hide'=>0,'pwd'=>'changed','replace_id'=>7,'folder_id'=>9] as $key=>$value){
		$other=$wanted;$other[$key]=$value;
		upload_check(!upload_state_compatible($wanted,$other),'different '.$key.' cannot overwrite same-hash task');
	}
	// Same digest in two sources: async retries must prefer the original file_id.
	file_put_contents(ROOT.'two/'.$hash,$alpha);$second=upload_fixture('second.txt',$alpha,$two);
	upload_check(file_storage_by_hash($hash,$second)===$two,'video lookup prefers original file ID over first same-hash copy');
	$sourceInfo=green_file_source($hash,'txt',['id'=>$second],['no_view'=>true]);
	upload_check(isset($sourceInfo['path']) && realpath($sourceInfo['path'])===realpath(ROOT.'two/'.$hash),'green local-instance source follows file ID');
	$DB->exec('DELETE FROM pre_file WHERE id=:id',[':id'=>$second]);unlink(ROOT.'two/'.$hash);
	$apiKey=create_user_api_key(1001,'upload integration','',1);
	upload_check(!empty($apiKey['ok']),'create real API credential for controller authentication');
	$apiToken=$apiKey['key'];
	// The fixture only supplies common initialization. Every action below runs the production controller.
	$bootstrap="<?php\nerror_reporting(E_ERROR|E_PARSE|E_COMPILE_ERROR);\nsession_save_path(".var_export($directory.'/sessions',true).");session_start();\n"
		."define('IN_CRONLITE',true);define('ROOT',".var_export(ROOT,true).");define('SYSTEM_ROOT',".var_export(SYSTEM_ROOT,true).");define('SYS_KEY','upload-storage-regression-only');\n"
		."date_default_timezone_set('Asia/Shanghai');require SYSTEM_ROOT.'autoloader.php';Autoloader::register();require SYSTEM_ROOT.'functions.php';require SYSTEM_ROOT.'folders.php';\n"
		.'$DB=new \lib\PdoHelper('.var_export($config,true).");\n".'$conf='.var_export($conf,true).";\n"
		.'$uid=1001;$islogin2=true;$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid=1001");$clientip="127.0.0.1";$siteurl="http://".$_SERVER["HTTP_HOST"]."/";'
		.'$stor=\lib\StorHelper::getModel($conf["storage"]);if(empty($_SESSION["csrf_token"]))$_SESSION["csrf_token"]="upload-integration-csrf";'."\n";
	file_put_contents($directory.'/bootstrap.php',$bootstrap);
	mkdir(ROOT.'includes');file_put_contents(ROOT.'includes/common.php','<?php');
	foreach(['ajax','api'] as $page)file_put_contents(ROOT.$page.'.php','<?php require '.var_export($source.$page.'.php',true).';');
	file_put_contents(ROOT.'probe.php','<?php header("Content-Type:application/json");$s=getenv("NOT_USED");$state=$_SESSION["uploads"][$_GET["hash"]]??[];echo json_encode(["storage"=>$state["storage"]??null,"name"=>$state["name"]??null]);');
	$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
	$base='http://'.$address;
	$command=[PHP_BINARY,'-d','auto_prepend_file='.$directory.'/bootstrap.php','-S',$address,'-t',ROOT];
	$process=proc_open($command,[0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes,ROOT,null,['bypass_shell'=>true]);
	if(!is_resource($process))throw new RuntimeException('Cannot start isolated PHP');
	fclose($pipes[0]);
	for($i=0;$i<50;$i++){
		$check=@fsockopen('127.0.0.1',intval(substr($address,strrpos($address,':')+1)),$errno,$errstr,.1);
		if($check){fclose($check);break;}usleep(100000);
	}
	$form=['name'=>'alpha.txt','hash'=>$hash,'size'=>strlen($alpha),'show'=>0,'ispwd'=>0,'pwd'=>'','storage'=>$two,'csrf_token'=>'upload-integration-csrf'];
	$pre=upload_request('/ajax.php?act=pre_upload',$form);
	upload_check($pre['code']===0 && !$pre['third'],'actual pre_upload does not fast-upload from A when B selected');
	$blocked=upload_request('/ajax.php?act=pre_upload',array_merge($form,['storage'=>$one]));
	upload_check($blocked['code']===-1,'actual second same-hash task cannot overwrite target');
	$probe=upload_request('/probe.php?hash='.$hash);
	upload_check($probe['storage']===$two,'session keeps original selected instance after rejected task');
	$retry=upload_request('/ajax.php?act=pre_upload',$form);
	upload_check($retry['code']===0,'actual identical pre_upload can retry');
	file_put_contents($directory.'/alpha.txt',$alpha);
	$uploaded=upload_request('/ajax.php?act=upload_part',['hash'=>$hash,'chunk'=>1,'storage'=>$one,'csrf_token'=>'upload-integration-csrf'],$directory.'/alpha.txt');
	upload_check($uploaded['code']===1 && upload_row($uploaded['id'])['storage']===$two,'upload_part ignores tampered target and persists session instance');
	upload_check(file_get_contents(ROOT.'one/'.$hash)===$alpha && file_get_contents(ROOT.'two/'.$hash)===$alpha,'two actual local instances both retain correct bytes');
	$reselected=upload_request('/ajax.php?act=pre_upload',array_merge($form,['storage'=>$one,'name'=>'after-finish.txt']));
	upload_check($reselected['code']===1 && upload_row($reselected['id'])['storage']===$one,'completed task clears state and allows selecting another instance');
	$duplicate=upload_request('/ajax.php?act=pre_upload',$form);
	upload_check($duplicate['code']===1 && $duplicate['exists']===1 && upload_row($duplicate['id'])['storage']===$two,'actual fast-upload reuses target B only');
	$DB->exec('DELETE FROM pre_file WHERE id=:id',[':id'=>$duplicate['id']]);
	$beta='api-only bytes';$betaHash=md5($beta);
	file_put_contents(ROOT.'two/'.$betaHash,$beta);upload_fixture('review.txt',$beta,$two,2);file_put_contents($directory.'/api.txt',$beta);
	$api=upload_request('/api.php',['storage'=>$one,'show'=>0],$directory.'/api.txt',true);
	upload_check($api['code']===0 && $api['exists']===0 && upload_row($api['id'])['storage']===$one,'actual API creates copy in selected A rather than borrowing B');
	upload_check((int)upload_row($api['id'])['block']===2,'cross-instance actual upload retains known review state');
	$apiAgain=upload_request('/api.php',['storage'=>$one,'show'=>0],$directory.'/api.txt',true);
	upload_check($apiAgain['exists']===1 && upload_row($apiAgain['id'])['storage']===$one,'actual API can fast-upload when target already has bytes');
	// Editing keeps original storage and deletes only its orphaned old blob.
	$edited='new edited bytes';$editedHash=md5($edited);
	$result=upload_request('/ajax.php?act=saveFileContent',['id'=>$uploaded['id'],'content'=>$edited,'csrf_token'=>'upload-integration-csrf']);
	$editedRow=upload_row($uploaded['id']);
	upload_check($result['code']===0 && $editedRow['storage']===$two && $editedRow['hash']===$editedHash,'actual editor retains original instance reference');
	upload_check(file_get_contents(ROOT.'two/'.$editedHash)===$edited && !file_exists(ROOT.'file/'.$editedHash),'editor writes B and never silently migrates to default');
	upload_check(!file_exists(ROOT.'two/'.$hash) && file_exists(ROOT.'one/'.$hash),'editor orphan cleanup deletes B old blob while preserving A same hash');
	$result=upload_request('/ajax.php?act=saveFileContent',['id'=>$uploaded['id'],'content'=>$edited,'csrf_token'=>'upload-integration-csrf']);
	upload_check($result['code']===0 && upload_row($uploaded['id'])['storage']===$two && !file_exists(ROOT.'file/'.$editedHash),'unchanged edit still preserves original instance without default orphan');
	echo "PASS upload storage integration: $count assertions, actual SQL/files/ajax/API/editor\n";
}finally{
	if(is_resource($process)){proc_terminate($process);proc_close($process);}
	$admin->exec('DROP DATABASE IF EXISTS '.$database);
	if(is_dir($directory)){
		$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
		foreach($it as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
		rmdir($directory);
	}
}
