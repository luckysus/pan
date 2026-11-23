"""Real isolated-site HTTP test; requires PAN_TEST_DB_FILE for a disposable MariaDB."""
import contextlib
import hashlib
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = r"D:\xampp\php\php.exe"
DBFILE = os.environ["PAN_TEST_DB_FILE"]
SETUP = r"""<?php
$c=json_decode(ltrim(file_get_contents(getenv('PAN_TEST_DB_FILE')), "\xef\xbb\xbf"),true);
$pdo=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=getenv('PAN_HTTP_DB');
if(isset($argv[1]) && $argv[1]==='drop'){$pdo->exec('DROP DATABASE IF EXISTS '.$db);exit;}
if(isset($argv[1]) && $argv[1]==='state'){
 $pdo->exec('USE '.$db);
 echo json_encode(['version'=>$pdo->query("SELECT v FROM pre_config WHERE k='version'")->fetchColumn(),
 'storage'=>$pdo->query("SELECT storage FROM pre_file WHERE name='legacy.txt'")->fetchColumn(),
 'used_at'=>(bool)$pdo->query("SHOW COLUMNS FROM pre_storage_instance LIKE 'used_at'")->fetchColumn()]);
 exit;
}
$pdo->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4');
$pdo->exec('USE '.$db);
$pdo->exec(file_get_contents(__DIR__.'/baseline.sql'));
// 模拟中间版已有实例表但缺used_at，验证实际升级入口能独立补列。
$schema=file_get_contents(getenv('PAN_HTTP_ROOT').'/install/update_1037.sql');
$pdo->exec(str_replace("  used_at datetime DEFAULT NULL,\n",'',$schema));
$pdo->exec("INSERT INTO pre_file (name,type,size,hash,token,storage,addtime,ip,uid) VALUES ('legacy.txt','txt',3,'".str_repeat('a',32)."','".str_repeat('b',32)."','',NOW(),'127.0.0.1',0)");
file_put_contents(__DIR__.'/site/file/'.str_repeat('a',32),'old');
$syskey=bin2hex(random_bytes(16));$pass=bin2hex(random_bytes(16));
$conf=['admin_user'=>'local-test','admin_pwd'=>$pass,'syskey'=>$syskey,'level_migrated'=>'1','level_upload_migrated'=>'1','storage_multi'=>'1','storage'=>'local','filepath'=>__DIR__.'/site/file/'];
$q=$pdo->prepare('REPLACE INTO pre_config (k,v) VALUES (?,?)');foreach($conf as $k=>$v)$q->execute([$k,$v]);
$pdo->exec('UPDATE pre_level SET online_edit=1');
$dbconfig=['host'=>$c['host'],'port'=>$c['port'],'user'=>$c['user'],'pwd'=>$c['password'],'dbname'=>$db];
file_put_contents(__DIR__.'/site/config.php','<?php $dbconfig='.var_export($dbconfig,true).';');
require getenv('PAN_HTTP_ROOT').'/includes/functions.php';
$token=authcode("local-test\t".md5('local-test'.$pass.'!@#%!s!0')."\t".(time()+3600),'ENCODE',$syskey);
file_put_contents(__DIR__.'/auth.json',json_encode(['token'=>$token,'pass'=>$pass]));
"""


def main():
    assertions = 0

    def check(value, message):
        nonlocal assertions
        if not value:
            raise AssertionError(message)
        assertions += 1

    with tempfile.TemporaryDirectory(prefix="pan-site-http-") as temporary:
        case = Path(temporary)
        site = case / "site"
        site.mkdir()
        tracked = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT).decode().split("\0")
        additions = [
            "admin/storage_instances.php", "preview.php", "includes/storage_fields.php",
            "includes/lib/StorageInstances.php", "includes/lib/StorageInstanceDriver.php",
            "includes/lib/TextPreview.php", "includes/lib/HttpCompat.php", "includes/file_batch.php", "includes/upload_storage.php", "install/update_1037.sql",
        ]
        for relative in tracked + additions:
            if not relative or relative == "config.php" or relative.split("/")[0] in {"tools", "tests", "docs"}:
                continue
            source = ROOT / relative
            if source.is_file():
                target = site / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)
        for relative in ["includes/vendor", "assets/vendor", "includes/blocks/text_preview",
                         "includes/templates/blocks/text_preview"]:
            shutil.copytree(ROOT / relative, site / relative, dirs_exist_ok=True)
        for relative in ["includes/layouts/text-preview.json", "includes/layouts/admin/storage_instances.json",
                         "assets/js/file-batch.js", "assets/js/text-preview.js", "assets/css/text-preview.css"]:
            shutil.copy2(ROOT / relative, site / relative)
        (site / "file").mkdir(exist_ok=True)
        (site / "instance-one").mkdir()
        (site / "instance-two").mkdir()
        (site / "install/install.lock").touch()
        (case / "baseline.sql").write_bytes((ROOT / "tests/fixtures/install_1036.sql").read_bytes())
        (case / "setup.php").write_text(SETUP, encoding="utf-8")
        env = dict(os.environ, PAN_HTTP_ROOT=str(ROOT), PAN_HTTP_DB="pan_http_" + secrets.token_hex(6))
        subprocess.run([PHP, str(case / "setup.php")], env=env, check=True, capture_output=True)
        process = None
        try:
            auth = json.loads((case / "auth.json").read_text())
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            base = f"http://127.0.0.1:{port}"
            jar = http.cookiejar.CookieJar()
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar))
            headers = {"User-Agent": "Mozilla/5.0 PanRegression", "Accept": "text/html,*/*",
                       "Accept-Language": "zh-CN", "Referer": base + "/admin/storage_instances.php"}
            with (case / "server.log").open("wb") as log:
                process = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", str(site)], cwd=site,
                                           stdout=log, stderr=log)

                def request(path, data=None, binary=None, content_type=None):
                    body = binary if binary is not None else (urllib.parse.urlencode(data).encode() if data is not None else None)
                    request_headers = dict(headers)
                    if content_type:
                        request_headers["Content-Type"] = content_type
                    if path.startswith("/admin/"):
                        request_headers["Cookie"] = "; ".join(
                            [cookie.name + "=" + cookie.value for cookie in jar]
                            + ["admin_token=" + urllib.parse.quote(auth["token"])]
                        )
                    req = urllib.request.Request(base + path, data=body, headers=request_headers)
                    try:
                        with opener.open(req, timeout=15) as response:
                            return response.status, response.read().decode("utf-8", "replace")
                    except urllib.error.HTTPError as error:
                        return error.code, error.read().decode("utf-8", "replace")

                for _ in range(50):
                    try:
                        status, body = request("/")
                        break
                    except urllib.error.URLError:
                        time.sleep(0.1)
                check("升级" in body, "old database must require upgrade")
                _, body = request("/install/update.php", {"user": "local-test", "pass": auth["pass"]})
                check("升级完成" in body, "actual authenticated 1036 upgrade succeeds")
                _, body = request("/install/update.php")
                check("校验通过" in body or "升级完成" in body, "upgrade entry can be repeated")
                state = json.loads(subprocess.check_output([PHP, str(case / "setup.php"), "state"], env=env))
                check(state["version"] == "1037" and state["used_at"], "upgrade repairs partial instance schema before setting version")
                check(state["storage"] == "local", "upgrade pins legacy empty storage to old default once")
                status, body = request("/admin/storage_instances.php")
                check(status == 200 and "保存实例" in body, "new admin page really renders theme panel")
                check("Fatal error" not in body, "no fatal error in admin page")
                token = html.unescape(re.search(r'name="token" value="([^"]+)"', body).group(1))
                data = {"action": "save", "token": token, "id": "0", "type": "local", "name": "Isolated One",
                        "cfg[filepath]": str(site / "instance-one"), "cfg[downfile_protocol]": "1", "enabled": "1", "min_tier": "0"}
                status, body = request("/admin/storage_instances.php", dict(data, token="invalid"))
                check(status == 403, "admin mutation rejects invalid csrf")
                status, body = request("/admin/storage_instances.php", data)
                check(status == 200 and "Isolated One" in body and "操作成功" in body, "create instance through real admin handler")
                check('value="" placeholder="留空保留原值"' not in body or "password" in body, "form is valid")
                _, body = request("/admin/set_stor.php")
                check("管理多存储实例" in body, "legacy storage page exposes management entry")
                _, body = request("/upload.php")
                check("Isolated One" in body, "front upload list sees allowed instance")
                _, body = request("/admin/storage_instances.php", {"action": "default", "id": "1", "token": token})
                check("操作成功" in body, "set new instance as default through real handler")
                _, body = request("/text.php?hash=" + "b" * 32 + "&preview=1")
                check(body == "old", "legacy file still reads old storage after changing default to different instance")
                _, body = request("/admin/storage_instances.php?type=onedrive")
                check("Refresh Token" in body and 'type="password"' in body, "instance authorization fields render as secrets")
                check("Fatal error" not in body, "native schema generation has no fatal")
                data.update({"name": "Isolated Two", "cfg[filepath]": str(site / "instance-two")})
                _, body = request("/admin/storage_instances.php", data)
                check("Isolated Two" in body and "操作成功" in body, "create second local instance")
                _, body = request("/ajax.php?act=csrf_token")
                csrf = json.loads(body)["csrf_token"]
                payload = b"same content in two independent stores"
                digest = hashlib.md5(payload).hexdigest()

                def prepare(storage, name="sample.txt", content=payload):
                    _, body = request("/ajax.php?act=pre_upload", {
                        "name": name, "hash": hashlib.md5(content).hexdigest(), "size": len(content),
                        "storage": storage, "show": "1", "ispwd": "0", "pwd": "", "csrf_token": csrf
                    })
                    return json.loads(body)

                def upload(content=payload):
                    boundary = "pan" + secrets.token_hex(12)
                    chunks = []
                    for key, value in {"hash": hashlib.md5(content).hexdigest(), "chunk": "1", "csrf_token": csrf}.items():
                        chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
                    chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="sample.txt"\r\nContent-Type: text/plain\r\n\r\n'.encode() + content + b"\r\n")
                    chunks.append(f"--{boundary}--\r\n".encode())
                    _, body = request("/ajax.php?act=upload_part", binary=b"".join(chunks), content_type=f"multipart/form-data; boundary={boundary}")
                    return json.loads(body)

                check(prepare("inst_1")["code"] == 0, "pre-upload admits first instance")
                first = upload()
                check(first["code"] == 1, "real multipart upload writes first instance: " + str(first.get("msg")))
                second_pre = prepare("inst_2")
                check(second_pre["code"] == 0 and not second_pre.get("exists"), "same hash only in A does not instant-upload into selected B")
                check(prepare("inst_1", "other.txt")["code"] == -1, "conflicting same-hash task cannot overwrite pending instance")
                _, body = request("/admin/storage_instances.php", {"action": "delete", "id": "2", "token": token})
                check("操作成功" in body, "soft stop through real admin handler")
                second = upload()
                check(second["code"] == 1, "in-flight upload completes in original instance after soft stop")
                check((site / "instance-one" / digest).read_bytes() == payload and (site / "instance-two" / digest).read_bytes() == payload, "two actual uploads remain physically isolated")
                _, body = request("/ajax.php?act=saveFileContent", {"id": second["id"], "content": "edited only second", "csrf_token": csrf})
                edited = json.loads(body)
                check(edited["code"] == 0, "online edit of stopped instance stays usable")
                check((site / "instance-two" / hashlib.md5(b"edited only second").hexdigest()).read_bytes() == b"edited only second", "edit writes original second instance")
                check((site / "instance-one" / digest).read_bytes() == payload and not (site / "instance-two" / digest).exists(), "edit leaves A unchanged and removes only B old orphan")
            print(f"PASS isolated full-site HTTP: {assertions} assertions")
        except Exception:
            if (case / "server.log").exists():
                print((case / "server.log").read_text(errors="replace")[-3000:])
            raise
        finally:
            if process:
                process.terminate()
                process.wait(timeout=10)
            subprocess.run([PHP, str(case / "setup.php"), "drop"], env=env, check=True, capture_output=True)


if __name__ == "__main__":
    main()
