"""Real Edge DOM + production theme CSS/Mustache/JS/PHP helper integration.

python tests/file_batch_browser.py
Requires local PHP, Edge and websocket-client. All services, DB, sessions and browser
profile are isolated in a temporary directory and removed in finally.
Every built-in theme is checked; console/workspace/aurora/night receive desktop
and mobile screenshots when PAN_TEST_BATCH_SCREENSHOTS is set.
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

LAYOUT_METRICS = r"""(function(){
 var panel=document.querySelector('.pan-batch-layer');
 if(!panel)return null;
 var rect=panel.getBoundingClientRect(),content=panel.querySelector('.layui-layer-content'),footer=panel.querySelector('.layui-layer-btn');
 function box(el){var r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};}
 function resolve(prop,value){var probe=document.createElement('span');probe.style[prop]=value;panel.appendChild(probe);var result=getComputedStyle(probe)[prop];probe.remove();return result;}
 var fields=Array.from(panel.querySelectorAll('input,select,textarea')).filter(function(el){var r=el.getBoundingClientRect();return r.width>0&&r.height>0;});
 var buttons=Array.from(footer?footer.querySelectorAll('a'):[]),primary=buttons[0];
 var overlap=false;
 fields.forEach(function(a,i){fields.slice(i+1).forEach(function(b){var x=box(a),y=box(b);if(Math.min(x.right,y.right)-Math.max(x.left,y.left)>2&&Math.min(x.bottom,y.bottom)-Math.max(x.top,y.top)>2)overlap=true;});});
 return {
  panel:box(panel),content:content?box(content):null,footer:footer?box(footer):null,
  controls:fields.map(function(el){var s=getComputedStyle(el);return {name:el.name||el.tagName,box:box(el),bg:s.backgroundColor,color:s.color};}),
  buttons:buttons.map(function(el){return {box:box(el),clipped:el.scrollWidth>el.clientWidth+2};}),
  bg:getComputedStyle(panel).backgroundColor,primaryBg:primary?getComputedStyle(primary).backgroundColor:'',
  primaryColor:primary?getComputedStyle(primary).color:'',
  expected:{bg:resolve('backgroundColor','var(--popup-bg)'),soft:resolve('backgroundColor','var(--popup-soft)'),text:resolve('color','var(--popup-text)'),primary:resolve('backgroundColor','var(--popup-primary)'),onPrimary:resolve('color','var(--popup-on-primary)')},
  textarea:panel.querySelector('textarea')?box(panel.querySelector('textarea')):null,
  overlap:overlap,contentScroll:content?{width:content.clientWidth,scrollWidth:content.scrollWidth,height:content.clientHeight,scrollHeight:content.scrollHeight}:null,
  cells:Array.from(panel.querySelectorAll('td')).map(function(el){return {clipped:el.scrollWidth>el.clientWidth+2,text:el.textContent};}),
  viewport:{width:innerWidth,height:innerHeight}
 };
})()"""


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
        # Use production styles, not a hand-written facsimile of the form layout.
        themes = sorted(path.parent.name for path in (ROOT / "themes").glob("*/theme.json"))
        check(len(themes) == 28, "all 28 built-in themes are covered")
        for relative in ("includes/templates/blocks/user/files_scripts.mustache",
                         "includes/templates/blocks/index/mine_scripts.mustache"):
            check("assets/css/file-batch.css?v={{version}}" in (ROOT / relative).read_text(encoding="utf-8"),
                  relative + " registers the dedicated stylesheet with the asset version")
        for name in themes:
            shutil.copytree(ROOT / "themes" / name, temp / "themes" / name)
        (temp / "assets/css").mkdir(parents=True)
        shutil.copy2(ROOT / "assets/css/style.css", temp / "assets/css/style.css")
        shutil.copytree(ROOT / "assets/css/build", temp / "assets/css/build")
        # Download the same pinned shared styles and scripts as shell/head.mustache.
        for name, url in {
            "jquery.js": "https://s4.zstatic.net/ajax/libs/jquery/1.12.4/jquery.min.js",
            "layer.js": "https://s4.zstatic.net/ajax/libs/layer/3.1.1/layer.js",
            "theme/default/layer.css": "https://s4.zstatic.net/ajax/libs/layer/3.1.1/theme/default/layer.css",
            "theme/default/icon.png": "https://s4.zstatic.net/ajax/libs/layer/3.1.1/theme/default/icon.png",
            "bootstrap.css": "https://s4.zstatic.net/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css",
            "material.css": "https://s4.zstatic.net/ajax/libs/bootstrap-material-design/0.5.10/css/bootstrap-material-design.min.css",
            "ripples.css": "https://s4.zstatic.net/ajax/libs/bootstrap-material-design/0.5.10/css/ripples.min.css",
            "font-awesome.css": "https://s4.zstatic.net/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css",
            "fonts/fontawesome-webfont.woff2": "https://s4.zstatic.net/ajax/libs/font-awesome/4.7.0/fonts/fontawesome-webfont.woff2",
        }.items():
            target = temp / name
            target.parent.mkdir(parents=True, exist_ok=True)
            with urllib.request.urlopen(url, timeout=25) as response:
                target.write_bytes(response.read())
        source = (ROOT / "tests/file_batch.php").read_text(encoding="utf-8")
        adapter = source[source.index("class BatchSqliteDatabase{"):source.index("$checks=0;")]
        adapter = adapter.replace("new PDO('sqlite::memory:')",
                                  "new PDO('sqlite:'.__DIR__.'/case-'.$_SESSION['test_case'].'.sqlite')")
        (temp / "adapter.php").write_text("<?php\n" + adapter, encoding="utf-8")
        router = r"""<?php
ini_set('session.save_path',__DIR__.'/sessions');session_start();
define('IN_CRONLITE',true);define('ROOT',__DIR__.'/');define('SYSTEM_ROOT',SOURCE.'/includes/');
require SYSTEM_ROOT.'functions.php';require SYSTEM_ROOT.'file_batch.php';require __DIR__.'/adapter.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(isset($_GET['case']) && preg_match('/^[a-z0-9_-]{1,60}$/D',$_GET['case']))$_SESSION['test_case']=$_GET['case'];
if(empty($_SESSION['test_case']))$_SESSION['test_case']='initial';
if(isset($_GET['scenario']))$_SESSION['test_scenario']=in_array($_GET['scenario'],['long_skip','hundred'],true)?$_GET['scenario']:'basic';
$scenario=$_SESSION['test_scenario']??'basic';
$DB=new BatchSqliteDatabase();$siteurl='http://'.$_SERVER['HTTP_HOST'].'/';$conf=['apiurl'=>$siteurl];
$DB->exec('CREATE TABLE IF NOT EXISTS pre_file(id INTEGER PRIMARY KEY,uid INTEGER,folder_id INTEGER DEFAULT 0,name TEXT,type TEXT,token TEXT,block INTEGER DEFAULT 0,addtime TEXT)');
if(!$DB->getColumn('SELECT count(*) FROM pre_file')){
 $long='季度项目资料整理-'.str_repeat('很长的中文文件名',7).'.txt';
 $long2=str_repeat('QuarterlyReportWithoutSpaces',3).' &amp; Archive.txt';
 $fixtures=$scenario==='long_skip'
  ? [[1,10,$long],[2,10,$long2],[3,10,'ui-'.$long2],[30,0,$long],[32,0,$long2],[33,0,'ui-'.$long2]]
  : [[1,10,'first.txt'],[2,10,'A &amp; B.txt'],[30,0,'guest.txt'],[32,0,'second.txt']];
 if($scenario==='hundred'){
  $fixtures=[];for($i=100;$i<200;$i++)$fixtures[]=[$i,10,'bulk-'.$i.'-'.str_repeat('LongNoSpaces',4).'.txt'];
 }
 foreach($fixtures as $f){
  $DB->exec('INSERT INTO pre_file(id,uid,name,type,token,addtime) VALUES(:id,:uid,:name,:type,:token,:date)',
   [':id'=>$f[0],':uid'=>$f[1],':name'=>$f[2],':type'=>'txt',':token'=>md5('ui-'.$f[0]),':date'=>date('Y-m-d H:i:s')]);
 }
}
$_SESSION['csrf_token']='browser-batch-csrf';$_SESSION['fileids']=[30,32,33];
if(isset($_GET['mode']))$_SESSION['test_mode']=$_GET['mode']==='guest'?'guest':'account';
$uid=($_SESSION['test_mode']??'account')==='account'?10:0;$islogin2=$uid>0;
if($path==='/user.php'||$path==='/ajax.php'){
 header('Content-Type:application/json');echo json_encode(file_batch_handle($_GET['act']??'',$_POST,$path==='/user.php'),JSON_UNESCAPED_UNICODE);exit;
}
if($path==='/probe'){
 header('Content-Type:application/json');echo json_encode($DB->getAll('SELECT id,name FROM pre_file ORDER BY id'));exit;
}
if($path==='/file-batch.js'){header('Content-Type:application/javascript');readfile(SOURCE.'/assets/js/file-batch.js');exit;}
if($path==='/assets/css/file-batch.css'){header('Content-Type:text/css');readfile(SOURCE.'/assets/css/file-batch.css');exit;}
if(is_file(__DIR__.$path))return false;
spl_autoload_register(function($class){if(strpos($class,'Mustache\\')===0){$p=SOURCE.'/includes/mustache/src/'.str_replace('\\','/',substr($class,9)).'.php';if(is_file($p))require $p;}});
require SYSTEM_ROOT.'theme_engine.php';require SYSTEM_ROOT.'theme_shell.php';
$theme=isset($_GET['theme']) && theme_get($_GET['theme'])?$_GET['theme']:'console';
$bodyclass=theme_body_class($theme);$style=theme_css_bundle_url($theme,'style','/');
$cfg=[];foreach(file_batch_schema() as $f)$cfg[$f['key']]=$f['default'];
$row_filter=$scenario==='hundred'?'id>=100 AND id<200':'id IN (1,2,30,32)';
$rows=[];foreach($DB->getAll('SELECT * FROM pre_file WHERE uid=:uid AND '.$row_filter.' ORDER BY id',[':uid'=>$uid]) as $r){
 $rows[]=['id'=>$r['id'],'name_html'=>$r['name'],'name_raw'=>$r['name'],'token'=>$r['token'],'type_text'=>'txt','fileurl'=>'./down.php/'.$r['token'].'.txt',
 'viewurl'=>'./file.php?hash='.$r['token'],'can_delete'=>true,'can_rename'=>true,'size'=>'10 B','icon'=>'fa-file-text-o','addtime'=>$r['addtime']];
}
$data=['cfg'=>$cfg,'batch_labels_html'=>file_batch_labels_html($cfg),'rows'=>$rows,'numrows'=>count($rows),'page'=>1,'pages'=>1,'guest_mine'=>!$uid,'is_mine'=>true];
$engine=new Mustache\Engine();
$template=SOURCE.'/includes/templates/blocks/'.($uid?'user/files_list':'index/filelist').'.mustache';
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/font-awesome.css"><link rel="stylesheet" href="/bootstrap.css">
<link rel="stylesheet" href="/material.css"><link rel="stylesheet" href="/ripples.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($style,ENT_QUOTES,'UTF-8')?>">
<link rel="stylesheet" href="/assets/css/file-batch.css">
</head><body class="<?php echo htmlspecialchars($bodyclass,ENT_QUOTES,'UTF-8')?>" data-test-theme="<?php echo htmlspecialchars($theme,ENT_QUOTES,'UTF-8')?>" data-test-case="<?php echo htmlspecialchars($_SESSION['test_case'],ENT_QUOTES,'UTF-8')?>">
<main class="container"><section class="uc-panel"><?php echo $engine->render(file_get_contents($template),$data);?></section></main>
<script src="/jquery.js"></script><script src="/layer.js"></script><script>
var uc_csrf='browser-batch-csrf',replace_csrf_token=uc_csrf;window.refreshCount=0;
window.PanList={refresh:function(){window.refreshCount++;document.querySelectorAll('tbody .uc-check,tbody .guest-check').forEach(function(el){el.checked=false});}};window.copied='';
window.testErrors=[];window.testUnhandled=[];window.addEventListener('error',function(event){if(event.error)window.testErrors.push(String(event.error));});
window.addEventListener('unhandledrejection',function(event){window.testUnhandled.push(String(event.reason));});
window.testRequests=[];$(document).ajaxComplete(function(event,xhr,settings){window.testRequests.push({url:settings.url,status:xhr.status,response:xhr.responseText});});
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

        def settle_panel(description):
            return wait_for(lambda: cdp.evaluate("!!document.querySelector('.pan-batch-layer') && parseFloat(getComputedStyle(document.querySelector('.pan-batch-layer')).opacity)>=0.99 && document.querySelectorAll('.layui-layer-page').length===1"), description)

        def close_panels():
            cdp.evaluate("layer.closeAll()")
            wait_for(lambda: cdp.evaluate("document.querySelectorAll('.layui-layer').length===0 && !document.querySelector('.file-batch-dialog,.batch-links-text,.batch-result-rows')"),
                     "dialogs close without restoring orphaned forms into the page")

        def navigate(theme, width, case_name, scenario="basic", mode="account"):
            cdp.call("Emulation.setDeviceMetricsOverride", {"width": width, "height": 844 if width < 768 else 900,
                                                          "deviceScaleFactor": 1, "mobile": width < 768})
            query = urllib.parse.urlencode({"mode": mode, "theme": theme, "case": case_name, "scenario": scenario})
            cdp.call("Page.navigate", {"url": base + "/?" + query})
            expression = ("document.readyState==='complete' && !!window.PanFileBatch && "
                          "document.body.getAttribute('data-test-case')===" + json.dumps(case_name)
                          + " && document.body.getAttribute('data-test-theme')===" + json.dumps(theme))
            wait_for(lambda: cdp.evaluate(expression), theme + " actual styles and script load")

        def select_files():
            cdp.evaluate("var chosen=document.querySelectorAll('tbody .uc-check,tbody .guest-check');chosen.forEach(function(el){el.checked=true;el.dispatchEvent(new Event('change',{bubbles:true}))});document.querySelectorAll('#ucSelCount,#guestSelCount').forEach(function(el){el.textContent=String(chosen.length)})")

        def screenshot(name):
            artifact = os.environ.get("PAN_TEST_BATCH_SCREENSHOTS")
            if not artifact:
                return
            settle_panel(name + " animation settles")
            output = Path(artifact).resolve()
            output.mkdir(parents=True, exist_ok=True)
            shot = cdp.call("Page.captureScreenshot", {"format": "png"})
            (output / ("batch-" + name + ".png")).write_bytes(base64.b64decode(shot["data"]))

        def geometry(description, textarea=False, fields=False, palette=True):
            settle_panel(description)
            metrics = cdp.evaluate(LAYOUT_METRICS)
            panel, viewport = metrics["panel"], metrics["viewport"]
            check(panel["left"] >= 7 and panel["right"] <= viewport["width"] - 7
                  and panel["top"] >= -1 and panel["bottom"] <= viewport["height"] + 1,
                  description + " dialog stays within viewport")
            check(not metrics["overlap"], description + " controls do not overlap")
            check(all(control["box"]["left"] >= panel["left"] + 7
                      and control["box"]["right"] <= panel["right"] - 7 for control in metrics["controls"]),
                  description + " controls fit content width")
            check(metrics["footer"] is not None and metrics["content"]["bottom"] <= metrics["footer"]["top"] + 2
                  and all(button["box"]["bottom"] <= min(panel["bottom"], viewport["height"]) + 1
                          and not button["clipped"] for button in metrics["buttons"]),
                  description + " footer remains separate and buttons stay visible")
            check(metrics["contentScroll"]["scrollWidth"] <= metrics["contentScroll"]["width"] + 2,
                  description + " content has no horizontal overflow")
            if palette:
                expected = metrics["expected"]
                check(metrics["bg"] == expected["bg"] and expected["bg"] not in ("rgba(0, 0, 0, 0)", ""),
                      description + " panel uses current popup background")
                check(metrics["primaryBg"] == expected["primary"] and metrics["primaryColor"] == expected["onPrimary"],
                      description + " primary action uses current theme colors")
                check(all(control["bg"] in (expected["bg"], expected["soft"])
                          and control["color"] == expected["text"] for control in metrics["controls"]),
                      description + " form controls use popup rather than page-surface colors")
            if textarea:
                check(metrics["textarea"] is not None and metrics["textarea"]["height"] >= 150
                      and metrics["textarea"]["width"] >= panel["width"] - 85,
                      description + " link textarea is multiline and comfortably wide")
            if fields:
                visible_inputs = [control["box"] for control in metrics["controls"] if control["name"] in ("find", "replace")]
                check(len(visible_inputs) == 2, description + " initial replacement fields are visible")
                if viewport["width"] < 768:
                    check(abs(visible_inputs[0]["top"] - visible_inputs[1]["top"]) >= 45
                          and min(item["width"] for item in visible_inputs) > panel["width"] * .7,
                          description + " mobile fields stack with full usable width")
                else:
                    check(abs(visible_inputs[0]["top"] - visible_inputs[1]["top"]) < 8,
                          description + " desktop fields share a balanced row")
            return metrics

        def open_links():
            select_files()
            cdp.evaluate("document.querySelector('[data-file-batch=copy]').click()")
            wait_for(lambda: cdp.evaluate("!!document.querySelector('.pan-batch-layer textarea')"), "links preview appears")
            settle_panel("links preview")

        def open_rename():
            select_files()
            cdp.evaluate("document.querySelector('[data-file-batch=rename]').click()")
            wait_for(lambda: cdp.evaluate("!!document.querySelector('.file-batch-dialog')"), "rename form appears")
            settle_panel("rename form")

        def preview_rename():
            cdp.evaluate("var field=document.querySelector('[name=mode]');field.value='affix';field.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[name=prefix]').value='ui-';document.querySelector('.file-batch-dialog').closest('.layui-layer').querySelector('.layui-layer-btn0').click()")
            wait_for(lambda: cdp.evaluate("document.querySelectorAll('.batch-results tbody tr').length===2"), "rename preview rows")

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
            screenshot(mode)
            close_panels()
        check(cdp.evaluate("document.querySelectorAll('script[src*=\"file-batch\"]').length===1"), "shared script binds once")
        palettes = set()
        for theme in themes:
            navigate(theme, 390, "palette-" + theme)
            open_links()
            metrics = geometry(theme + " 390 links", textarea=True)
            palettes.add(metrics["bg"])
            check("assets/css/" in cdp.evaluate("Array.from(document.querySelectorAll('link[rel=stylesheet]')).map(function(el){return el.getAttribute('href')}).join(' ')"),
                  theme + " uses the actual production stylesheet")
            close_panels()
            open_rename()
            geometry(theme + " 390 initial rename", fields=True)
            close_panels()
        check(len(palettes) >= 4, "theme sweep actually exercises distinct light, dark and glass palettes")

        for theme in ("console", "workspace", "aurora", "night"):
            for width in (390, 1180):
                case_name = "visual-" + theme + "-" + str(width)
                description = theme + " " + str(width)
                navigate(theme, width, case_name, scenario="long_skip")
                open_rename()
                geometry(description + " initial rename", fields=True)
                screenshot(theme + "-" + str(width) + "-rename-initial")
                if width == 1180 and theme == "console":
                    cdp.call("Emulation.setDeviceMetricsOverride", {"width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True})
                    wait_for(lambda: cdp.evaluate("var rect=document.querySelector('.pan-batch-layer').getBoundingClientRect();rect.left>=7 && rect.right<=innerWidth-7 && Math.abs(rect.left-(innerWidth-rect.width)/2)<=3"),
                             description + " resize recenters dialog")
                    geometry(description + " desktop-to-mobile resize", fields=True)
                    screenshot(theme + "-1180-to390-rename")
                    cdp.call("Emulation.setDeviceMetricsOverride", {"width": 1180, "height": 900, "deviceScaleFactor": 1, "mobile": False})
                    wait_for(lambda: cdp.evaluate("var rect=document.querySelector('.pan-batch-layer').getBoundingClientRect();Math.abs(rect.left-(innerWidth-rect.width)/2)<=3"),
                             description + " restoring desktop recenters dialog")
                preview_rename()
                metrics = geometry(description + " long-name preview", palette=False)
                check(not any(cell["clipped"] for cell in metrics["cells"]),
                      description + " long Chinese and unbroken Latin names wrap without clipping")
                check(cdp.evaluate("document.querySelector('.batch-results').textContent.indexOf('同名')>=0"),
                      description + " skipped conflict remains readable in preview")
                if width == 390:
                    cdp.evaluate("var body=document.querySelector('.pan-batch-layer .layui-layer-content');body.scrollTop=body.scrollHeight")
                    geometry(description + " scrolled preview footer", palette=False)
                    cdp.evaluate("document.querySelector('.pan-batch-layer .layui-layer-content').scrollTop=0")
                screenshot(theme + "-" + str(width) + "-rename-preview")
                before = cdp.evaluate("fetch('/probe').then(function(r){return r.json()})")
                check(all(not row["name"].startswith("ui-") for row in before if row["id"] in (1, 2)),
                      description + " long-name preview is still read-only")
                cdp.evaluate("document.querySelector('.file-batch-dialog').closest('.layui-layer').querySelector('.layui-layer-btn0').click()")
                wait_for(lambda: cdp.evaluate("document.querySelectorAll('.batch-result-rows tbody tr').length===1"), description + " result rows")
                geometry(description + " rename result")
                after = cdp.evaluate("fetch('/probe').then(function(r){return r.json()})")
                check(next(row for row in after if row["id"] == 1)["name"].startswith("ui-")
                      and not next(row for row in after if row["id"] == 2)["name"].startswith("ui-"),
                      description + " confirmed rename applies ready row and leaves conflict untouched")
                screenshot(theme + "-" + str(width) + "-rename-result")
                close_panels()
                open_links()
                geometry(description + " links", textarea=True)
                screenshot(theme + "-" + str(width) + "-links")
                close_panels()
                check(cdp.evaluate("!document.querySelector('.file-batch-dialog,.batch-links-text,.batch-result-rows')"),
                      description + " closing every stage leaves no hidden dialog form")
                cdp.call("Emulation.setDeviceMetricsOverride", {"width": max(390, width - 30), "height": 844, "deviceScaleFactor": 1, "mobile": width < 768})
                check(cdp.evaluate("window.testErrors.length===0"), description + " resize after close has no stale-handler exception")
        # A long result list and a short viewport must scroll inside the modal,
        # without pushing its confirmation buttons below the screen.
        navigate("workspace", 390, "hundred-short", scenario="hundred")
        cdp.call("Emulation.setDeviceMetricsOverride", {"width": 390, "height": 480, "deviceScaleFactor": 1, "mobile": True})
        open_rename()
        cdp.evaluate("var field=document.querySelector('[name=mode]');field.value='affix';field.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[name=prefix]').value='ui-';document.querySelector('.file-batch-dialog').closest('.layui-layer').querySelector('.layui-layer-btn0').click()")
        wait_for(lambda: cdp.evaluate("document.querySelectorAll('.batch-results tbody tr').length===100"), "100 item preview")
        metrics = geometry("100 items in short viewport", palette=False)
        check(metrics["contentScroll"]["scrollHeight"] > metrics["contentScroll"]["height"],
              "100 item preview scrolls inside modal content")
        cdp.evaluate("var body=document.querySelector('.pan-batch-layer .layui-layer-content');body.scrollTop=body.scrollHeight")
        geometry("100 item preview scrolled to bottom", palette=False)
        screenshot("workspace-390x480-hundred-preview")
        close_panels()

        # Clipboard rejection can arrive after the user closes its source modal.
        # This must not dereference a removed textarea or create an unhandled rejection.
        navigate("console", 390, "clipboard-close")
        open_links()
        cdp.evaluate("navigator.clipboard.writeText=function(){return new Promise(function(resolve,reject){window.rejectClipboard=reject})};document.querySelector('.pan-batch-layer .layui-layer-btn0').click()")
        wait_for(lambda: cdp.evaluate("typeof window.rejectClipboard==='function'"), "clipboard operation pending")
        close_panels()
        failures = cdp.evaluate("window.rejectClipboard(new Error('expected closed-dialog clipboard test'));new Promise(function(resolve){setTimeout(function(){resolve({errors:window.testErrors,unhandled:window.testUnhandled})},50)})")
        check(not failures["errors"] and not failures["unhandled"], "late clipboard rejection after modal close causes no exception")
        check(cdp.evaluate("!document.querySelector('.file-batch-dialog,.batch-links-text,.batch-result-rows')"),
              "late clipboard fallback does not resurrect removed forms")
        print("PASS total=" + str(checks) + " real Edge/account+guest/28 themes/390+1180/production styles", flush=True)
    except BaseException:
        if cdp:
            try:
                print("DOM diagnostics: " + str(cdp.evaluate("document.body.innerText")).replace("\n", " ")[-2500:], flush=True)
                diagnostics = cdp.evaluate("""(function(){
                    var button=document.querySelector('[data-file-batch=copy]');
                    var bar=button?button.closest('[data-file-batch-owner]'):null;
                    return {
                        errors:window.testErrors||[],unhandled:window.testUnhandled||[],requests:window.testRequests||[],
                        jquery:window.jQuery?window.jQuery.fn.jquery:null,
                        layer:typeof window.layer,layerOpen:window.layer?typeof window.layer.open:null,
                        owner:bar?bar.getAttribute('data-file-batch-owner'):null,
                        accountLists:document.querySelectorAll('.uc-filelist:not(.guest-filelist)').length,
                        guestLists:document.querySelectorAll('.guest-filelist').length,
                        checkboxes:Array.from(document.querySelectorAll('tbody .uc-check,tbody .guest-check')).map(function(el){
                            var row=el.closest('tr');return {checked:el.checked,disabled:el.disabled,id:row?row.getAttribute('data-id'):null};
                        })
                    };
                })()""")
                print("Runtime diagnostics: " + json.dumps(diagnostics, ensure_ascii=False), flush=True)
            except Exception:
                pass
        if (temp / "php.log").exists():
            print("Fixture PHP log tail:\n" + (temp / "php.log").read_text(encoding="utf-8", errors="replace")[-4000:], flush=True)
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
