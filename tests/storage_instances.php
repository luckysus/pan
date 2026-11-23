<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 独立MySQL回归：只使用显式提供的测试凭据，并创建/删除随机测试库。
$root = dirname(__DIR__);
require $root.'/includes/autoloader.php';
Autoloader::register();
require $root.'/includes/functions.php';
define('ROOT', $root.'/');
$credFile = getenv('PAN_TEST_DB_FILE');
if (!$credFile || !is_file($credFile)) { fwrite(STDERR, "Set PAN_TEST_DB_FILE to an isolated database credential file\n"); exit(2); }
$credentials = json_decode(ltrim(file_get_contents($credFile), "\xef\xbb\xbf"), true);
$pdo = new PDO('mysql:host='.$credentials['host'].';port='.$credentials['port'].';charset=utf8mb4', $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dbName = 'pan_instances_'.bin2hex(random_bytes(6));
$dir = sys_get_temp_dir().'/pan_instances_'.bin2hex(random_bytes(8));
$count = 0;
function assert_instance($condition, $message) {
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
}
function instance_upload($driver, $name, $content) {
    $tmp = tempnam(sys_get_temp_dir(), 'pan_instance_blob_');
    try { file_put_contents($tmp, $content); return $driver->savefile($name, $tmp, 'text/plain'); }
    finally { if (is_file($tmp)) unlink($tmp); }
}
try {
    $pdo->exec("CREATE DATABASE ".$dbName." CHARACTER SET utf8mb4");
    $pdo->exec("USE ".$dbName);
    // 原始1036安装结构模拟真实升级，不在测试中伪造实例表。
    $baseline = file_get_contents(__DIR__.'/fixtures/install_1036.sql');
    if (!$baseline) throw new RuntimeException('Cannot read fixed 1036 migration fixture');
    $pdo->exec($baseline);
    assert_instance($pdo->query("SELECT v FROM pre_config WHERE k='version'")->fetchColumn() === '1036', 'fixture is the fixed pre-upgrade schema');
    $pdo->exec("INSERT INTO pre_file (name,type,size,hash,token,storage,addtime,ip,uid) VALUES ('legacy.txt','txt',3,'".str_repeat('a',32)."','".str_repeat('b',32)."','local',NOW(),'127.0.0.1',0)");
    $pdo->exec(file_get_contents($root.'/install/update_1037.sql'));
    $pdo->exec(file_get_contents($root.'/install/update_1037.sql'));
    assert_instance($pdo->query("SELECT storage FROM pre_file WHERE name='legacy.txt'")->fetchColumn() === 'local', '1037 upgrade must preserve old storage references');
    $DB = new \lib\PdoHelper(['host'=>$credentials['host'], 'port'=>$credentials['port'], 'user'=>$credentials['user'], 'pwd'=>$credentials['password'], 'dbname'=>$dbName]);
    $conf = getAllSetting();
    mkdir($dir);
    foreach (['legacy','one','two'] as $sub) mkdir($dir.'/'.$sub);
    $conf['storage'] = 'local';
    $conf['filepath'] = $dir.'/legacy/';
    $conf['storage_multi'] = '1';
    $conf['storage_pool'] = '';
    assert_instance(\lib\StorageInstances::save(0,'local','One',['filepath'=>$dir.'/one'],true,0) === true, 'create first instance');
    assert_instance(\lib\StorageInstances::save(0,'local','Two',['filepath'=>$dir.'/two'],true,2) === true, 'create second same-type instance');
    $rows = \lib\StorageInstances::all(); $keys = array_keys($rows); $one=$keys[0]; $two=$keys[1];
    $a = \lib\StorHelper::get($one); $b = \lib\StorHelper::get($two);
    assert_instance(empty(\lib\StorageInstances::row($one)['used_at']), 'driver initialization must not freeze unused configuration');
    assert_instance(\lib\StorageInstances::markUsed($one) && \lib\StorageInstances::markUsed($two), 'upload admission explicitly freezes instance');
    assert_instance(instance_upload($a,'same','first'), 'write first');
    assert_instance(instance_upload($b,'same','second'), 'write second');
    assert_instance($a->get('same') === 'first' && $b->get('same') === 'second', 'same hash names stay in separate instances');
    assert_instance($conf['filepath'] === $dir.'/legacy/', 'global filepath restored');
    assert_instance(realpath($a->filepath('same')) === realpath($dir.'/one/same'), 'local instance exposes correct native path');
    assert_instance(\lib\StorHelper::is_range($one) && !\lib\StorHelper::is_cloud($one), 'capabilities resolve base type');
    assert_instance(!\lib\StorageInstances::available($two,0) && !\lib\StorageInstances::available($two,1) && \lib\StorageInstances::available($two,2), 'membership gate');
    assert_instance(in_array($one, storage_allowed_list(), true) && !in_array($two, storage_allowed_list(), true), 'guest upload list excludes restricted instance');
    $missing = \lib\StorHelper::get('inst_99999');
    assert_instance(!$missing->exists('same'), 'missing instance never falls back to current storage');
    $DB->exec("INSERT INTO pre_file (name,type,size,hash,token,storage,addtime,ip,uid) VALUES ('owned.txt','txt',5,:h,:t,:s,NOW(),'127.0.0.1',0)", [':h'=>str_repeat('c',32),':t'=>str_repeat('d',32),':s'=>$one]);
    assert_instance(\lib\StorageInstances::remove($rows[$one]['id']) === true && \lib\StorageInstances::row($one) !== null, 'stop new uploads without deleting referenced configuration');
    assert_instance(\lib\StorageInstances::save($rows[$one]['id'],'local','Changed',['filepath'=>$dir.'/two'],true,0) !== true, 'cannot repoint files by editing instance connection');
    assert_instance(\lib\StorageInstances::save($rows[$one]['id'],'local','Renamed',['filepath'=>$dir.'/one'],false,0) === true, 'rename/disable without changing connection');
    assert_instance($a->get('same') === 'first', 'disabled storage remains readable');
    assert_instance(!in_array($one,storage_allowed_list(),true), 'disabled instance excluded from upload selection');
    assert_instance(\lib\StorageInstances::save(0,'local','Missing',['filepath'=>''],true,0) !== true, 'empty local instance never aliases default');
    assert_instance(\lib\StorageInstances::save(0,'local','Missing',['filepath'=>$dir.'/missing'],true,0) !== true, 'missing local directory rejected');
    assert_instance(\lib\StorageInstances::save(0,'local','Alias',['filepath'=>$dir.'/one/../one'],true,0) !== true, 'realpath alias rejected');
    assert_instance(\lib\StorageInstances::save(0,'local','Alias',['filepath'=>$dir.'/legacy'],true,0) !== true, 'legacy local directory cannot be aliased');
    mkdir($dir.'/unused');
    mkdir($dir.'/unused-new');
    assert_instance(\lib\StorageInstances::save(0,'local','Unused',['filepath'=>$dir.'/unused'],true,0) === true, 'create unused configurable instance');
    $tempKeys=array_keys(\lib\StorageInstances::all());$unused=end($tempKeys);$unusedRow=\lib\StorageInstances::row($unused);
    $DB->exec("UPDATE pre_storage_instance SET used_at=NOW() WHERE id=:id",[':id'=>$unusedRow['id']]);
    assert_instance(\lib\StorageInstances::save($unusedRow['id'],'local','Raced',['filepath'=>$dir.'/unused-new'],true,0) !== true, 'database CAS blocks stale-cache repoint after another request starts upload');
    mkdir($dir.'/before-mark');
    assert_instance(\lib\StorageInstances::save(0,'local','Before mark',['filepath'=>$dir.'/before-mark'],true,0)===true,'create before-mark race fixture');
    $tempKeys=array_keys(\lib\StorageInstances::all());$race=end($tempKeys);$oldRace=\lib\StorageInstances::row($race);
    $changed=$oldRace['config'];$changed['filepath']=$dir.'/unused-new';
    $DB->exec("UPDATE pre_storage_instance SET config=:c WHERE id=:id",[':c'=>json_encode($changed),':id'=>$oldRace['id']]);
    assert_instance(!\lib\StorageInstances::markUsed($race),'markUsed rejects stale driver target when admin changed configuration first');
    assert_instance(\lib\StorageInstances::markUsed($two) && \lib\StorageInstances::markUsed($two),'same-second repeated admission remains valid');
    mkdir($dir.'/vanishing');
    assert_instance(\lib\StorageInstances::save(0,'local','Vanishing',['filepath'=>$dir.'/vanishing'],true,0) === true,'create disappearing-directory fixture');
    $tempKeys=array_keys(\lib\StorageInstances::all());$vanishing=end($tempKeys);$vanishModel=\lib\StorHelper::get($vanishing);
    rmdir($dir.'/vanishing');
    assert_instance(!$vanishModel->exists('same') && $conf['filepath']===$dir.'/legacy/','runtime missing local directory fails closed without global fallback');
    assert_instance(instance_upload($a,'inflight','continued') && $a->get('inflight') === 'continued', 'already started upload can finish after soft stop');
    assert_instance(\lib\StorageInstances::save($rows[$two]['id'],'local','Two',['filepath'=>$dir.'/one'],true,2) !== true, 'connection frozen as soon as upload starts, before pre_file insertion');
    $conf['storage'] = $two;
    assert_instance(\lib\StorageInstances::save($rows[$two]['id'],'local','Two',['filepath'=>$dir.'/two'],false,2) !== true, 'cannot disable current default');
    assert_instance(\lib\StorageInstances::remove($rows[$two]['id']) !== true, 'cannot delete default');
    $conf['storage']='local';
    // 令牌写回隔离：实际saveSetting必须只更新目标实例，不能污染全局pre_config。
    assert_instance(\lib\StorageInstances::save(0,'onedrive','OD1',['onedrive_client_id'=>'one','onedrive_client_secret'=>'secret','onedrive_refresh_token'=>'refresh'],true,0) === true,'create native-token instance');
    $odkeys=array_keys(\lib\StorageInstances::all());$od=end($odkeys);
    $conf['onedrive_access_token']='global-token';
    \lib\StorageInstances::scope($od,function(){ global $conf; assert_instance($conf['onedrive_access_token']==='', 'never inherit another account token'); saveSetting('onedrive_access_token','instance-token'); });
    assert_instance($conf['onedrive_access_token']==='global-token','restore global token');
    assert_instance(\lib\StorageInstances::row($od)['config']['onedrive_access_token']==='instance-token','persist native token to instance');
    assert_instance(getSetting('onedrive_access_token') !== 'instance-token','native token not written to global configuration');
    $newConfig=\lib\StorageInstances::row($od)['config'];$newConfig['onedrive_access_token']='concurrently-rotated';
    $DB->exec("UPDATE pre_storage_instance SET config=:c WHERE id=:id",[':c'=>json_encode($newConfig),':id'=>\lib\StorageInstances::row($od)['id']]);
    assert_instance(\lib\StorageInstances::save(\lib\StorageInstances::row($od)['id'],'onedrive','OD metadata',['onedrive_client_id'=>'one','onedrive_client_secret'=>'','onedrive_refresh_token'=>''],true,0)===true,'metadata update succeeds with concurrently rotated token');
    assert_instance(\lib\StorageInstances::row($od)['config']['onedrive_access_token']==='concurrently-rotated','metadata update never overwrites concurrently rotated credentials');
    $adminChanged=\lib\StorageInstances::row($od)['config'];$adminChanged['onedrive_path']='different-admin-target';
    $DB->exec("UPDATE pre_storage_instance SET config=:c WHERE id=:id",[':c'=>json_encode($adminChanged),':id'=>\lib\StorageInstances::row($od)['id']]);
    $persisted=\lib\StorageInstances::scope($od,function(){return saveSetting('onedrive_access_token','stale-test-token');});
    assert_instance($persisted===false,'connection-test token write rejects concurrently edited connection');
    $actual=json_decode($DB->getColumn("SELECT config FROM pre_storage_instance WHERE id=:id",[':id'=>\lib\StorageInstances::row($od)['id']]),true);
    assert_instance($actual['onedrive_path']==='different-admin-target' && $actual['onedrive_access_token']==='concurrently-rotated','stale token save cannot undo admin connection edit');
    \lib\StorageInstances::reset();
    try { \lib\StorageInstances::scope($od,function(){global $conf; $conf['filepath']='wrong';throw new RuntimeException('expected');}); } catch (RuntimeException $e) {}
    assert_instance($conf['filepath']===$dir.'/legacy/','restore configuration even on exception');
    // 扩展驱动使用实例命名空间保存映射/状态，不能串回旧类型。
    assert_instance(\lib\StorageInstances::save(0,'ftp','FTP',['address'=>'localhost','username'=>'test'],true,0)===true,'create extension instance');
    $extkeys=array_keys(\lib\StorageInstances::all());$ext=end($extkeys);
    $extension=\lib\StorHelper::get($ext);
    $rp=new ReflectionProperty($extension,'driver');$rp->setAccessible(true);$native=$rp->getValue($extension);
    assert_instance(is_object($native),'extension constructed');
    foreach (['mapSet'=>['obj','instance-id'], 'saveState'=>[['token'=>'isolated']], 'saveConfig'=>[['refresh_token'=>'rotated']]] as $method=>$args) {
        $rm=new ReflectionMethod($native,$method);$rm->setAccessible(true);
        \lib\StorageInstances::scope($ext,function()use($rm,$native,$args){$rm->invokeArgs($native,$args);});
    }
    assert_instance($DB->getColumn("SELECT fid FROM pre_stor_map WHERE storage=:s AND name='obj'",[':s'=>$ext])==='instance-id','extension mapping isolated');
    assert_instance(!$DB->getColumn("SELECT fid FROM pre_stor_map WHERE storage='ftp' AND name='obj'"),'legacy mapping not changed');
    assert_instance(isset($conf['stor_'.$ext.'_state']) && strpos($conf['stor_'.$ext.'_state'],'isolated')!==false,'extension state scope preserved');
    \lib\StorageInstances::scope($ext,function()use($ext){global $conf; assert_instance(strpos($conf['stor_ftp'],'rotated')!==false && strpos($conf['stor_ftp'],'localhost')!==false,'rotated extension config reused without losing connection parameters');});
    // 完整当前安装SQL也必须可执行，不仅升级脚本可执行。
    $fresh=$dbName.'_new';$pdo->exec("CREATE DATABASE ".$fresh." CHARACTER SET utf8mb4");$pdo->exec("USE ".$fresh);
    try { $pdo->exec(file_get_contents($root.'/install/install.sql')); assert_instance($pdo->query("SELECT COUNT(*) FROM pre_storage_instance")->fetchColumn()==0,'fresh install supports instances'); }
    finally {$pdo->exec("DROP DATABASE ".$fresh);}
    echo "PASS storage instances: ".$count." assertions, real MySQL upgrade/reentry/fresh-install and driver isolation\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS ".$dbName);
    if (is_dir($dir)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item) { if($item->isDir())rmdir($item->getPathname()); else unlink($item->getPathname()); }
        rmdir($dir);
    }
}
