"""Real Edge DOM + production Mustache/JS/PHP helper integration.

python tests/file_batch_browser.py
Requires local PHP, Edge and websocket-client. All services, DB, sessions and browser
profile are isolated in a temporary directory and removed in finally.
"""
import base64
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

import websocket

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PAN_TEST_PHP", "D:/xampp/php/php.exe")
EDGE = os.environ.get("PAN_TEST_EDGE", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe")


def port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def wait_for(fn, description, timeout=18):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            result = fn()
            if result:
                return result
        except (OSError, ValueError):
            pass
        time.sleep(0.1)
    raise AssertionError("Timeout: " + description)


class CDP:
    def __init__(self, url):
        self.ws = websocket.create_connection(url, timeout=10, suppress_origin=True)
        self.next_id = 0

    def call(self, method, params=None):
        self.next_id += 1
        request_id = self.next_id
        self.ws.send(json.dumps({"id": request_id, "method": method, "params": params or {}}))
        while True:
            response = json.loads(self.ws.recv())
            if response.get("id") == request_id:
                if "error" in response:
                    raise AssertionError(response["error"])
                return response.get("result", {})

    def evaluate(self, expression):
        result = self.call("Runtime.evaluate", {"expression": expression, "returnByValue": True, "awaitPromise": True})
        if "exceptionDetails" in result:
            raise AssertionError(result["exceptionDetails"])
        return result.get("result", {}).get("value")


def main():
    temp = Path(tempfile.mkdtemp(prefix="pan-file-batch-browser-")).resolve()
    php = browser = cdp = None
    log = None
    checks = 0

    def check(value, message):
        nonlocal checks
        if not value:
            raise AssertionError(message)
        checks += 1
        print("PASS " + message, flush=True)

    try:
        # Browser test transport is local; use the same pinned libraries as the site.
        for name, url in {
            "jquery.js": "https://s4.zstatic.net/ajax/libs/jquery/2.1.4/jquery.min.js",
            "layer.js": "https://s4.zstatic.net/ajax/libs/layer/3.1.1/layer.js",
            "theme/default/layer.css": "https://s4.zstatic.net/ajax/libs/layer/3.1.1/theme/default/layer.css",
        }.items():
            target = temp / name
            target.parent.mkdir(parents=True, exist_ok=True)
            with urllib.request.urlopen(url, timeout=25) as response:
                target.write_bytes(response.read())
        source = (ROOT / "tests/file_batch.php").read_text(encoding="utf-8")
        adapter = source[source.index("class BatchSqliteDatabase{"):source.index("$checks=0;")]
        adapter = adapter.replace("new PDO('sqlite::memory:')", "new PDO('sqlite:'.__DIR__.'/test.sqlite')")
        (temp / "adapter.php").write_text("<?php\n" + adapter, encoding="utf-8")
        router = r"""<?php
ini_set('session.save_path',__DIR__.'/sessions');session_start();
define('IN_CRONLITE',true);define('ROOT',__DIR__.'/');define('SYSTEM_ROOT',SOURCE.'/includes/');
require SYSTEM_ROOT.'functions.php';require SYSTEM_ROOT.'file_batch.php';require __DIR__.'/adapter.php';
$DB=new BatchSqliteDatabase();$siteurl='http://'.$_SERVER['HTTP_HOST'].'/';$conf=['apiurl'=>$siteurl];
$DB->exec('CREATE TABLE IF NOT EXISTS pre_file(id INTEGER PRIMARY KEY,uid INTEGER,folder_id INTEGER DEFAULT 0,name TEXT,type TEXT,token TEXT,block INTEGER DEFAULT 0,addtime TEXT)');
if(!$DB->getColumn('SELECT count(*) FROM pre_file')){
 foreach([[1,10,'first.txt'],[2,10,'A &amp; B.txt'],[30,0,'guest.txt'],[32,0,'second.txt']] as $f){
  $DB->exec('INSERT INTO pre_file(id,uid,name,type,token,addtime) VALUES(:id,:uid,:name,:type,:token,:date)',
   [':id'=>$f[0],':uid'=>$f[1],':name'=>$f[2],':type'=>'txt',':token'=>md5('ui-'.$f[0]),':date'=>date('Y-m-d H:i:s')]);
 }
}
$_SESSION['csrf_token']='browser-batch-csrf';$_SESSION['fileids']=[30,32];
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(isset($_GET['mode']))$_SESSION['test_mode']=$_GET['mode']==='guest'?'guest':'account';
$uid=($_SESSION['test_mode']??'account')==='account'?10:0;$islogin2=$uid>0;
if($path==='/user.php'||$path==='/ajax.php'){
 header('Content-Type:application/json');echo json_encode(file_batch_handle($_GET['act']??'',$_POST,$path==='/user.php'),JSON_UNESCAPED_UNICODE);exit;
}
if($path==='/probe'){
 header('Content-Type:application/json');echo json_encode($DB->getAll('SELECT id,name FROM pre_file ORDER BY id'));exit;
}
if($path==='/file-batch.js'){header('Content-Type:application/javascript');readfile(SOURCE.'/assets/js/file-batch.js');exit;}
if(is_file(__DIR__.$path))return false;
spl_autoload_register(function($class){if(strpos($class,'Mustache\\')===0){$p=SOURCE.'/includes/mustache/src/'.str_replace('\\','/',substr($class,9)).'.php';if(is_file($p))require $p;}});
$cfg=[];foreach(file_batch_schema() as $f)$cfg[$f['key']]=$f['default'];
$rows=[];foreach($DB->getAll('SELECT * FROM pre_file WHERE uid=:uid ORDER BY id',[':uid'=>$uid]) as $r){
 $rows[]=['id'=>$r['id'],'name_html'=>$r['name'],'name_raw'=>$r['name'],'token'=>$r['token'],'type_text'=>'txt','fileurl'=>'./down.php/'.$r['token'].'.txt',
 'viewurl'=>'./file.php?hash='.$r['token'],'can_delete'=>true,'can_rename'=>true,'size'=>'10 B','icon'=>'fa-file-text-o','addtime'=>$r['addtime']];
}
$data=['cfg'=>$cfg,'batch_labels_html'=>file_batch_labels_html($cfg),'rows'=>$rows,'numrows'=>count($rows),'page'=>1,'pages'=>1,'guest_mine'=>!$uid,'is_mine'=>true];
$engine=new Mustache\Engine();
$template=SOURCE.'/includes/templates/blocks/'.($uid?'user/files_list':'index/filelist').'.mustache';
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font:15px sans-serif;color:#243147;padding:20px}button,input,select,textarea{font:inherit;margin:5px;padding:7px}table{width:100%;border-collapse:collapse}td,th{padding:9px;border-bottom:1px solid #ddd;text-align:left}.uc-dialog{padding:15px}.uc-input{max-width:100%;box-sizing:border-box}.batch-rule input{width:98%}.uc-col-state,.uc-col-time{display:none}[hidden]{display:none!important}.uc-batchbar{padding:10px;background:#f4f7fb}.table-responsive{overflow:auto}.text-warning{color:#9b5400}</style>
</head><body><?php echo $engine->render(file_get_contents($template),$data);?>
<script src="/jquery.js"></script><script src="/layer.js"></script><script>
var uc_csrf='browser-batch-csrf',replace_csrf_token=uc_csrf;window.refreshCount=0;
window.PanList={refresh:function(){window.refreshCount++}};window.copied='';
Object.defineProperty(navigator,'clipboard',{value:{writeText:function(text){window.copied=text;return Promise.resolve()}}});
document.getElementById(<?php echo json_encode($uid?'ucBatchBar':'guestBatchBar')?>).hidden=false;
</script><script src="/file-batch.js"></script></body></html>
"""
        # PDO adapter uses real SQL for all mutations; only MySQL locking is excluded here.
        adapter_path = temp / "adapter.php"
        adapter_text = adapter_path.read_text(encoding="utf-8").replace(
            "public function getRow($sql,$args=[])", "public function getAll($sql,$args=[]){return $this->query($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}\n\tpublic function getRow($sql,$args=[])"
        )
        adapter_path.write_text(adapter_text, encoding="utf-8")
        router = router.replace("SOURCE", json.dumps(str(ROOT).replace("\\", "/")))
        (temp / "router.php").write_text(router, encoding="utf-8")
        (temp / "sessions").mkdir()
        http_port, debug_port = port(), port()
        base = "http://127.0.0.1:" + str(http_port)
        log = open(temp / "php.log", "w", encoding="utf-8")
        hidden = getattr(subprocess, "CREATE_NO_WINDOW", 0)
        php = subprocess.Popen([PHP, "-S", "127.0.0.1:" + str(http_port), "-t", str(temp), str(temp / "router.php")], stdout=log, stderr=log, creationflags=hidden)
        wait_for(lambda: urllib.request.urlopen(base + "/?mode=account", timeout=1).status == 200, "PHP fixture")
        browser = subprocess.Popen([EDGE, "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
                                    "--remote-allow-origins=*", "--remote-debugging-port=" + str(debug_port),
                                    "--user-data-dir=" + str(temp / "edge-profile"), "about:blank"],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=hidden)

        def create_target():
            request = urllib.request.Request("http://127.0.0.1:" + str(debug_port) + "/json/new?" + urllib.parse.quote(base + "/?mode=account"), method="PUT")
            with urllib.request.urlopen(request, timeout=2) as response:
                return json.load(response)["webSocketDebuggerUrl"]

        cdp = CDP(wait_for(create_target, "Edge CDP"))
        cdp.call("Runtime.enable")
        cdp.call("Page.enable")
        cdp.call("Emulation.setDeviceMetricsOverride", {"width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True})
        for mode in ("account", "guest"):
            if mode == "guest":
                cdp.call("Page.navigate", {"url": base + "/?mode=guest"})
            wait_for(lambda: cdp.evaluate("document.readyState==='complete' && !!window.PanFileBatch && document.querySelector('[data-file-batch-owner]').getAttribute('data-file-batch-owner')===" + json.dumps(mode)), mode + " script load")
            cdp.evaluate("document.querySelectorAll('tbody .uc-check,tbody .guest-check').forEach(function(el){el.checked=true})")
            cdp.evaluate("document.querySelector('[data-file-batch=copy]').click()")
            wait_for(lambda: cdp.evaluate("!!document.querySelector('.layui-layer textarea')"), mode + " links dialog")
            check(cdp.evaluate("document.querySelector('.layui-layer textarea').value.split('\\n').length===2"), mode + " real selected rows create two external links")
            cdp.evaluate("document.querySelector('.layui-layer-btn0').click()")
            wait_for(lambda: cdp.evaluate("window.copied.indexOf('/down.php/')>=0"), mode + " clipboard")
            check(cdp.evaluate("window.copied.split('\\n').length===2"), mode + " copy uses clipboard button")
            cdp.evaluate("layer.closeAll()")
            wait_for(lambda: cdp.evaluate("document.querySelectorAll('.layui-layer').length===0"), mode + " copy dialog closes")
            cdp.evaluate("document.querySelector('[data-file-batch=rename]').click()")
            wait_for(lambda: cdp.evaluate("!!document.querySelector('.file-batch-dialog')"), mode + " rename form")
            cdp.evaluate("var mode=document.querySelector('[name=mode]');mode.value='affix';mode.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[name=prefix]').value='ui-';document.querySelector('.file-batch-dialog').closest('.layui-layer').querySelector('.layui-layer-btn0').click()")
            wait_for(lambda: cdp.evaluate("document.querySelectorAll('.batch-results tbody tr').length===2"), mode + " preview rows")
            check(cdp.evaluate("document.querySelector('.file-batch-dialog').closest('.layui-layer').querySelector('.layui-layer-btn0').textContent==='确认改名'"), mode + " requires separate confirmation after preview")
            probe = cdp.evaluate("fetch('/probe').then(function(r){return r.json()})")
            selected_ids = (1, 2) if mode == "account" else (30, 32)
            check(all(not row["name"].startswith("ui-") for row in probe if row["id"] in selected_ids), mode + " preview has no database mutation")
            check(cdp.evaluate("document.querySelector('.file-batch-dialog').closest('.layui-layer').getBoundingClientRect().width<=window.innerWidth"), mode + " dialog fits mobile viewport")
            cdp.evaluate("document.querySelector('.file-batch-dialog').closest('.layui-layer').querySelector('.layui-layer-btn0').click()")
            wait_for(lambda: cdp.evaluate("document.querySelectorAll('.batch-result-rows tbody tr').length===2"), mode + " result")
            probe = cdp.evaluate("fetch('/probe').then(function(r){return r.json()})")
            check(all(row["name"].startswith("ui-") for row in probe if row["id"] in selected_ids), mode + " confirmation executes production PHP rename")
            check(cdp.evaluate("window.refreshCount===1"), mode + " invokes local refresh without navigation")
            check(cdp.evaluate("document.querySelector('.batch-result-rows').textContent.indexOf('&amp;amp;')<0"), mode + " names are displayed as text without double escaping")
            artifact = os.environ.get("PAN_TEST_BATCH_SCREENSHOTS")
            if artifact:
                wait_for(lambda: cdp.evaluate("document.querySelectorAll('.layui-layer-page').length===1 && parseFloat(getComputedStyle(document.querySelector('.batch-result-rows').closest('.layui-layer')).opacity)>=0.99"), mode + " result animation settles")
                output = Path(artifact).resolve()
                output.mkdir(parents=True, exist_ok=True)
                shot = cdp.call("Page.captureScreenshot", {"format": "png"})
                (output / ("batch-" + mode + ".png")).write_bytes(base64.b64decode(shot["data"]))
            cdp.evaluate("layer.closeAll()")
        check(cdp.evaluate("document.querySelectorAll('script[src*=\"file-batch\"]').length===1"), "shared script binds once")
        print("PASS total=" + str(checks) + " real Edge/mobile/account+guest", flush=True)
    except BaseException:
        if cdp:
            try:
                print("DOM diagnostics: " + str(cdp.evaluate("document.body.innerText")).replace("\n", " ")[-2500:], flush=True)
            except Exception:
                pass
        raise
    finally:
        if cdp:
            try:
                cdp.call("Browser.close")
            except Exception:
                pass
            cdp.ws.close()
        for process in (browser, php):
            if process and process.poll() is None:
                if os.name == "nt":
                    subprocess.run(["taskkill", "/PID", str(process.pid), "/T", "/F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
                else:
                    process.terminate()
                try:
                    process.wait(timeout=8)
                except subprocess.TimeoutExpired:
                    process.kill()
        if log:
            log.close()
        # This task-created directory alone is disposable; never remove the site or user data.
        if temp.name.startswith("pan-file-batch-browser-") and temp.parent == Path(tempfile.gettempdir()).resolve():
            for attempt in range(30):
                try:
                    shutil.rmtree(temp)
                    break
                except PermissionError:
                    if attempt == 29:
                        raise
                    time.sleep(0.2)


if __name__ == "__main__":
    main()
