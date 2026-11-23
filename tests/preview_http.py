"""Exercise the real preview/raw endpoints with isolated storage/DB seams and a real browser.

No repository configuration, database or uploaded files are changed. Temporary PHP
fixtures and browser profiles are removed after success and failure.
"""
import argparse
import html
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
TOKEN = "a" * 32
SOURCE = '# Hello\n\n**strong** [safe](https://example.com/)\n\n![track](https://tracker.invalid/pixel)\n\n<script>window.previewPwned=1</script>\n\n[bad](javascript:alert(1))\n\n' + chr(96) * 3 + 'js\nconst item = "<img src=x onerror=alert(1)>";\n' + chr(96) * 3 + '\n'

COMMON = r"""<?php
namespace lib {
class StorHelper {
    public static function get($type = null) { return new FixtureStorage(); }
}
class FixtureStorage {
    public function exists($hash) { return ($_GET['case'] ?? '') !== 'missing-object'; }
}
}
namespace {
define('IN_CRONLITE', true);
define('VERSION', 'test');
require __ROOT__.'/includes/mustache/autoload.php';
require __ROOT__.'/includes/lib/TextPreview.php';
function event($s) { file_put_contents(__DIR__.'/../events.log', $s."\n", FILE_APPEND); }
class FixtureDB {
    public function getRow($sql, $args) {
        if(($_GET['case'] ?? '') === 'missing') return false;
        return [
            'id'=>7, 'token'=>str_repeat('a',32), 'hash'=>str_repeat('b',32),
            'name'=>'&lt;safe title&gt;.md', 'type'=>($_GET['case'] ?? '') === 'unsupported' ? 'exe' : 'md',
            'storage'=>'fixture', 'uid'=>42,
            'size'=>($_GET['case'] ?? '') === 'too-large' ? 3000000 : 100,
            'block'=>($_GET['case'] ?? '') === 'blocked' ? 1 : 0,
            'pwd'=>($_GET['case'] ?? '') === 'password' ? 'secret' : '',
        ];
    }
    public function exec($sql, $args = []) { event('count'); return true; }
}
$DB = new FixtureDB();
function is_editable_file_type($type) { return $type === 'md'; }
function get_editable_file_max_size() { return 2 * 1024 * 1024; }
function check_file_pwd($row,$pwd) { return $row['pwd'] === '' || hash_equals($row['pwd'], (string)$pwd); }
function get_storage_content($hash,$storage) {
    event('read');
    if(($_GET['case'] ?? '') === 'read-failure')return false;
    if(($_GET['case'] ?? '') === 'actual-too-large')return str_repeat('x',3000000);
    if(($_GET['case'] ?? '') === 'binary')return "binary\0content";
    return file_get_contents(__DIR__.'/../source.txt');
}
function decode_editable_content($s) {
    if(strpos($s, "\0") !== false)return ['code'=>-1, 'msg'=>'binary rejected'];
    return ['code'=>0, 'content'=>$s];
}
function file_traffic_guard($uid) {
    event('guard');
    if(($_GET['case'] ?? '') === 'quota'){
        http_response_code(403); header('Cache-Control: private, no-store'); exit('quota exhausted');
    }
}
function download_output_chunk($s) { event('out:'.strlen($s)); echo $s; }
function size_format($n) { return $n.' B'; }
function theme_render_layout($page,$data,$scope) {
    $block = require __ROOT__.'/includes/blocks/text_preview/main.php';
    $data['cfg'] = [];
    foreach($block['schema'] as $f)$data['cfg'][$f['key']] = $f['default'];
    $engine = new \Mustache\Engine(['strict_callables'=>true]);
    return $engine->render(file_get_contents(__ROOT__.'/includes/templates/blocks/text_preview/main.mustache'),$data);
}
}
"""

ROUTER = r"""<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if(strncmp($path, '/assets/', 8) === 0){
    $file = realpath(__ROOT__.$path);
    $assets = realpath(__ROOT__.'/assets').DIRECTORY_SEPARATOR;
    if(!$file || strncmp($file, $assets, strlen($assets)) !== 0){http_response_code(404);exit();}
    header('Content-Type: '.(substr($file,-3)==='.js'?'application/javascript':'text/css').'; charset=utf-8');
    readfile($file);return true;
}
if($path === '/preview.php'){
    ob_start(); include __DIR__.'/preview.php'; $body=ob_get_clean();
    if(isset($_GET['qa']))$body=str_replace('</body>','<script defer src="qa.js"></script></body>',$body);
    echo $body; return true;
}
return false;
"""

BROWSER = r"""(function(){
  var failures=[],checks=0;
  function ok(value,label){checks++;if(!value)failures.push(label);}
  try{
    var p=window.PanTextPreview;
    ok(!!p,'preview loaded');
    ['javascript:alert(1)','data:text/html,x','vbscript:msgbox(1)','//tracker.invalid','https:\\evil.invalid','https://evil.invalid/\n'].forEach(function(url){ok(!p.safeLink(url),'blocked '+url);});
    ok(p.safeLink('https://example.com/path'),'https allowed');
    ok(p.safeLink('mailto:someone@example.com'),'mailto allowed');
    var rendered=document.getElementById('tp-render');
    ok(!rendered.hidden,'markdown shown first');
    ok(rendered.querySelector('h1').textContent==='Hello','markdown heading rendered');
    ok(rendered.querySelector('strong').textContent==='strong','markdown bold rendered');
    ok(!rendered.querySelector('script,img,svg,iframe,object,input,style'),'no active content/images');
    ok(!Array.from(rendered.querySelectorAll('a')).some(function(a){return !p.safeLink(a.getAttribute('href'));}),'links restricted');
    ok(window.previewPwned===undefined,'source scripts not executed');
    var clean=document.createElement('div');
    clean.appendChild(p.cleanMarkup('<svg><script>alert(1)</script></svg><img src="https://tracker.invalid"><a href="javascript:alert(1)" onclick="bad()" style="color:red">bad</a><span class="hljs-string" onmouseover="bad()">x</span>',document));
    ok(!clean.querySelector('svg,script,img,[onclick],[onmouseover],[style],[href]'),'defensive sanitizer rejects elements/attributes');
    ok(!!clean.querySelector('.hljs-string'),'highlight spans retained');
    var before=document.getElementById('tp-content').textContent;
    var after=Array.from(document.querySelectorAll('#tp-code li')).map(function(li){return li.textContent;}).join('\n');
    ok(before===after,'source content preserved through highlighting and line splitting');
    ok(!!document.querySelector('#tp-render .hljs-keyword'),'fenced code syntax highlighting applied');
    document.getElementById('tp-source-tab').click();
    ok(rendered.hidden && !document.getElementById('tp-source').hidden,'source tab switch');
    ok(!document.getElementById('tp-lines').disabled,'line numbers available');
    var lines=document.getElementById('tp-lines');lines.checked=false;lines.dispatchEvent(new Event('change'));
    ok(!document.getElementById('tp-source').classList.contains('tp-line-numbers'),'line numbers toggle');
    var wrap=document.getElementById('tp-wrap');wrap.checked=false;wrap.dispatchEvent(new Event('change'));
    ok(!document.getElementById('tp-source').classList.contains('tp-wrap'),'wrap toggle');
    document.getElementById('tp-render-tab').click();
    ok(!rendered.hidden && lines.disabled,'reading tab restores and disables source controls');
    var box=document.querySelector('[data-text-preview]');
    var huge='x'.repeat(210000);
    document.getElementById('tp-content').textContent=huge;
    p.init(document);
    ok(document.getElementById('tp-lines').disabled,'large source avoids expensive processing');
    ok(!!document.getElementById('tp-status').textContent,'large source fallback explained');
    ok(document.getElementById('tp-content').textContent===huge,'large source preserved');
    // Restore the representative Markdown view for optional visual QA.
    window.location.hash='qa-complete';
    document.getElementById('tp-content').textContent=before;
    document.getElementById('tp-code').replaceChildren();
    rendered.replaceChildren();
    p.init(document);
    ok(document.documentElement.scrollWidth<=window.innerWidth,'mobile page fits viewport');
  }catch(e){failures.push(String(e.stack||e));}
  var result=document.createElement('output');result.id='qa-result';result.setAttribute('data-failed',failures.length);
  result.textContent=JSON.stringify({checks:checks,failures:failures});document.body.appendChild(result);
})();"""


def main():
    args = argparse.ArgumentParser()
    args.add_argument("--php", default="D:/xampp/php/php.exe")
    args.add_argument("--browser")
    args.add_argument("--artifacts", type=Path)
    opts = args.parse_args()
    browser = opts.browser or next((p for p in [
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe"] if Path(p).is_file()), None)
    if not browser:
        raise RuntimeError("Chrome/Edge is required for the DOM/XSS checks")
    creation = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    with tempfile.TemporaryDirectory(prefix="pan-preview-http-") as td:
        tmp = Path(td)
        (tmp / "includes").mkdir()
        php_root = "'" + ROOT.as_posix().replace("'", "\\'") + "'"
        (tmp / "includes/common.php").write_text(COMMON.replace("__ROOT__", php_root), encoding="utf-8")
        (tmp / "router.php").write_text(ROUTER.replace("__ROOT__", php_root), encoding="utf-8")
        (tmp / "qa.js").write_text(BROWSER, encoding="utf-8")
        (tmp / "source.txt").write_text(SOURCE, encoding="utf-8", newline="")
        for name in ("preview.php", "text.php"):
            shutil.copyfile(ROOT / name, tmp / name)
        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            port = s.getsockname()[1]
        base = "http://127.0.0.1:" + str(port)
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        proc = subprocess.Popen([opts.php, "-S", "127.0.0.1:" + str(port), "-t", td, str(tmp / "router.php")],
                                cwd=td, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=creation)
        checks = 0
        try:
            def request(path):
                try:
                    with opener.open(base + path, timeout=10) as response:
                        return response.status, dict(response.headers), response.read().decode("utf-8")
                except urllib.error.HTTPError as response:
                    return response.code, dict(response.headers), response.read().decode("utf-8")
            for _ in range(100):
                try:
                    request("/preview.php?hash=" + TOKEN)
                    break
                except urllib.error.URLError:
                    if proc.poll() is not None:
                        raise RuntimeError("PHP fixture exited before startup")
                    time.sleep(.05)
            else:
                raise RuntimeError("PHP fixture did not start")
            def ok(value, label):
                nonlocal checks
                checks += 1
                if not value:
                    raise AssertionError(label)
            for case, code in [("missing",404),("missing-object",404),("blocked",403),("unsupported",415),
                               ("too-large",413),("actual-too-large",413),("read-failure",502),("binary",415)]:
                status, headers, body = request("/preview.php?hash=" + TOKEN + "&case=" + case)
                ok(status == code, case + " rejected with " + str(code))
                ok("previewPwned" not in body, case + " leaks no source")
            status, headers, body = request("/preview.php?hash[]=bad")
            ok(status == 404, "array token safely rejected")
            for password in ("", "&pwd=wrong"):
                status, headers, body = request("/preview.php?hash=" + TOKEN + "&case=password" + password)
                ok(status == 401 and 'type="password"' in body, "password form shown")
                ok("previewPwned" not in body, "wrong password leaks no content")
            status, headers, body = request("/preview.php?hash=" + TOKEN + "&case=password&pwd=secret")
            ok(status == 200 and "previewPwned" in body, "correct password allowed")
            ok("&lt;script&gt;" in body and "<script>window.previewPwned" not in body, "source escaped in HTML")
            ok("img-src 'none'" in headers.get("Content-Security-Policy",""), "CSP blocks tracking images")
            ok("connect-src 'none'" in headers["Content-Security-Policy"], "CSP blocks source network requests")
            ok(headers.get("Referrer-Policy") == "no-referrer", "password not leaked via referrer")
            ok("no-store" in headers.get("Cache-Control",""), "private content not cached")
            (tmp / "events.log").write_text("")
            status, headers, body = request("/preview.php?hash=" + TOKEN + "&case=quota")
            ok(status == 403 and "previewPwned" not in body, "quota exhausted prevents content")
            events = (tmp / "events.log").read_text().splitlines()
            ok("guard" in events and not any(e.startswith("out:") for e in events), "quota checked before output")
            (tmp / "events.log").write_text("")
            status, headers, body = request("/preview.php?hash=" + TOKEN + "&preview=1")
            events = (tmp / "events.log").read_text().splitlines()
            ok("count" not in events and "guard" in events, "embedded preview skips count but checks traffic")
            ok("out:" + str(len(body.encode("utf-8"))) in events, "actual response bytes passed through traffic output")
            status, headers, body = request("/text.php?hash=" + TOKEN)
            ok(body == SOURCE, "raw text body remains byte-for-byte compatible")
            ok(headers.get("Content-Type","").startswith("text/plain"), "raw interface stays text/plain")
            ok(headers.get("X-Content-Type-Options") == "nosniff", "raw interface blocks MIME sniffing")
            # Use CDP, not --dump-dom: Windows GUI browser launchers may not
            # expose stdout. A real emulated viewport also avoids Edge's minimum
            # headless window width when checking the mobile layout.
            import websocket
            with socket.socket() as s:
                s.bind(("127.0.0.1",0))
                debug_port = s.getsockname()[1]
            cmd = [browser, "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
                   "--disable-background-networking", "--disable-component-update", "--hide-scrollbars",
                   "--remote-debugging-port="+str(debug_port), "--user-data-dir="+str(tmp / "browser-profile"), "about:blank"]
            browser_proc, browser_pid = None, None
            if os.name == "nt":
                quote = lambda s: "'" + str(s).replace("'", "''") + "'"
                command = ("$p=Start-Process -FilePath " + quote(browser)
                           + " -ArgumentList @(" + ",".join(quote(a) for a in cmd[1:]) + ")"
                           + " -WindowStyle Hidden -PassThru; $p.Id")
                launched = subprocess.check_output(["powershell","-NoProfile","-Command",command],
                                                   creationflags=creation, timeout=15)
                browser_pid = int(launched.strip())
            else:
                browser_proc = subprocess.Popen(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            browser_socket, page_socket = None, None
            try:
                debug_url = "http://127.0.0.1:"+str(debug_port)
                for _ in range(100):
                    try:
                        version = json.load(opener.open(debug_url+"/json/version",timeout=1))
                        break
                    except (urllib.error.URLError, TimeoutError):
                        time.sleep(.1)
                else:
                    raise RuntimeError("Headless browser did not expose its local CDP endpoint")
                browser_socket = websocket.create_connection(version["webSocketDebuggerUrl"],timeout=8,suppress_origin=True)
                tabs = json.load(opener.open(debug_url+"/json/list",timeout=3))
                tab = next(t for t in tabs if t.get("type")=="page")
                page_socket = websocket.create_connection(tab["webSocketDebuggerUrl"],timeout=8,suppress_origin=True)
                call_id = 0
                def call(socket,method,params=None):
                    nonlocal call_id
                    call_id += 1
                    wanted = call_id
                    socket.send(json.dumps({"id":wanted,"method":method,"params":params or {}}))
                    while True:
                        reply=json.loads(socket.recv())
                        if reply.get("id")==wanted:
                            if "error" in reply:raise RuntimeError(str(reply["error"]))
                            return reply.get("result",{})
                call(page_socket,"Page.enable")
                for width in (390,1180):
                    call(page_socket,"Emulation.setDeviceMetricsOverride",{"width":width,"height":900,"deviceScaleFactor":1,"mobile":width<600})
                    call(page_socket,"Page.navigate",{"url":base+"/preview.php?hash="+TOKEN+"&qa=1"})
                    result=None
                    for _ in range(100):
                        value=call(page_socket,"Runtime.evaluate",{"expression":"document.querySelector('#qa-result') && document.querySelector('#qa-result').textContent","returnByValue":True})
                        result=value.get("result",{}).get("value")
                        if result:break
                        time.sleep(.05)
                    ok(result is not None,"browser harness completed at "+str(width))
                    detail=json.loads(result)
                    ok(not detail["failures"],"DOM safety: "+json.dumps(detail,ensure_ascii=False))
                    if opts.artifacts:
                        import base64
                        opts.artifacts.mkdir(parents=True,exist_ok=True)
                        shot=call(page_socket,"Page.captureScreenshot",{"format":"png","captureBeyondViewport":False})
                        (opts.artifacts / ("preview-"+str(width)+".png")).write_bytes(base64.b64decode(shot["data"]))
                    print("Browser",width,detail["checks"],"checks passed")
            finally:
                if page_socket:page_socket.close()
                if browser_socket:
                    try:
                        browser_socket.send(json.dumps({"id":999999,"method":"Browser.close"}))
                    except Exception:pass
                    browser_socket.close()
                if browser_proc:
                    try:browser_proc.wait(timeout=8)
                    except subprocess.TimeoutExpired:browser_proc.terminate();browser_proc.wait(timeout=8)
                if browser_pid:
                    command="Wait-Process -Id "+str(browser_pid)+" -Timeout 8 -ErrorAction SilentlyContinue"
                    subprocess.run(["powershell","-NoProfile","-Command",command],creationflags=creation,capture_output=True,timeout=12)
                # Crashpad/profile writes may finish shortly after Browser.close.
                for _ in range(30):
                    try:
                        shutil.rmtree(tmp / "browser-profile")
                        break
                    except OSError:time.sleep(.1)
            print("HTTP/access/traffic/raw", checks, "checks passed")
        finally:
            proc.terminate()
            try:
                proc.wait(timeout=8)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait()


if __name__ == "__main__":
    main()
