<?php
namespace { if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } }
namespace Qiniu\Http {
    //仅在捕获区域查询时阻断网络；正常TLS测试仍调用真正的cURL。
    function curl_setopt_array($handle, array $options) {
        if (!empty($GLOBALS['sdk_capture_region'])) {
            $GLOBALS['sdk_region_url'] = $options[CURLOPT_URL];
            \curl_close($handle);
            throw new \RuntimeException('captured-region-url');
        }
        return \curl_setopt_array($handle, $options);
    }
}
namespace {
//本机 TLS/SDK 回归，不读取站点配置，不使用真实云凭据。
error_reporting(E_ALL & ~E_DEPRECATED);
$projectRoot = dirname(__DIR__);
$vendorPath = getenv('PAN_SDK_VENDOR_PATH') ?: $projectRoot . '/includes/vendor';
require_once $vendorPath . '/autoload.php';
require_once $projectRoot . '/includes/autoloader.php';
Autoloader::register();

if (($argv[1] ?? '') === '--tls-server') {
    $fixtureRoot = $argv[2];
    $address = $argv[3];
    $context = stream_context_create(['ssl' => [
        'local_cert' => $fixtureRoot . '/cert.pem', 'local_pk' => $fixtureRoot . '/key.pem',
        //测试服务不要求客户端证书；客户端仍必须验证服务端证书。
        'verify_peer' => false,
    ]]);
    $server = stream_socket_server('tls://' . $address, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    if (!$server) { fwrite(STDERR, $error); exit(1); }
    file_put_contents($fixtureRoot . '/ready', '1');
    while (true) {
        $client = @stream_socket_accept($server, 1);
        if (!$client) continue;
        stream_set_timeout($client, 3);
        while (($line = fgets($client)) !== false && trim($line) !== '') {}
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
        fclose($client);
    }
}
if (($argv[1] ?? '') === '--qiniu-ca') {
    $response = \Qiniu\Http\Client::get($argv[2], [], new \Qiniu\Http\RequestOptions(2, null, 3));
    $result = ['status' => $response->statusCode, 'body' => $response->body];
    if ($response->error) { $result['error'] = $response->error; $result['ca_file'] = ini_get('curl.cainfo'); $result['ca_exists'] = is_file(ini_get('curl.cainfo')); }
    echo json_encode($result);
    exit;
}

if (($argv[1] ?? '') === '--upyun-ca') {
    $upyun = new \lib\Storage\Upyun(['serviceName' => 'fixture', 'operatorName' => 'fixture', 'operatorPwd' => 'fixture-only']);
    \Upyun\Config::$restApiEndPoint = $argv[2];
    $get = $upyun->get('blob');
    $saved = $upyun->downloadTo('blob', $argv[3]);
    echo json_encode(['get' => $get, 'download' => $saved, 'file' => file_get_contents($argv[3])]);
    exit;
}

$checks = 0;
function sdk_check($expected, $actual, $label) {
    global $checks;
    $checks++;
    if ($expected !== $actual) throw new RuntimeException($label . "\nExpected " . var_export($expected, true) . "\nActual " . var_export($actual, true));
}
function sdk_private($object, $name) {
    $property = new ReflectionProperty($object, $name);
    $property->setAccessible(true);
    return $property->getValue($object);
}

$fixtureRoot = sys_get_temp_dir() . '/pan-sdk-tls-' . bin2hex(random_bytes(6));
$server = null;
$socket = null;
$oldNoProxy = getenv('NO_PROXY');
$error = null;
try {
    sdk_check('2.13.6', \Composer\InstalledVersions::getPrettyVersion('guzzlehttp/psr7'), 'Installed PSR-7 metadata.');
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.invalid/', ['X-Valid' => 'ok']);
    sdk_check('ok', $request->getHeaderLine('X-Valid'), 'Normal headers remain usable.');
    $stream = \GuzzleHttp\Psr7\stream_for('legacy');
    $sink = \GuzzleHttp\Psr7\stream_for('');
    \GuzzleHttp\Psr7\copy_to_stream($stream, $sink);
    sdk_check('legacy', (string)$sink, 'Legacy stream helper delegates to maintained Utils.');
    sdk_check('text/plain', \GuzzleHttp\Psr7\mimetype_from_filename('fixture.txt'), 'Legacy MIME helper.');
    $rejectedPromise = \GuzzleHttp\Promise\rejection_for(new \RuntimeException('fixture'));
    sdk_check('rejected', $rejectedPromise->getState(), 'Legacy rejected promise helper.');
    $rejectedPromise->otherwise(function () {});

    foreach ([
        function () { return new \GuzzleHttp\Psr7\Request('GET', '/', ["X-Test\n" => 'ok']); },
        function () { return new \GuzzleHttp\Psr7\Request('GET', '/', ['X-Test' => "ok\n"]); },
        function () use ($request) { return $request->withHeader("X-Test\n", 'ok'); },
        function () use ($request) { return $request->withHeader('X-Test', "ok\n"); },
        function () use ($request) { return $request->withAddedHeader('X-Test', "ok\r\nInjected: 1"); },
        function () { return new \GuzzleHttp\Psr7\Response(200, ['X-Test' => "ok\n"]); },
    ] as $index => $operation) {
        $rejected = false;
        try { $operation(); } catch (InvalidArgumentException $e) { $rejected = true; }
        sdk_check(true, $rejected, 'Reject HTTP header newline #' . $index);
    }

    //2026公告中的不可信Host/HTTP起始行必须由正式新实现拒绝。
    foreach (['good.example@evil.example', 'example.test:8080', "[::1]:8080]", "example.test\nInjected"] as $host) {
        $rejected = false;
        try { (new \GuzzleHttp\Psr7\Uri('https://example.invalid/'))->withHost($host); }
        catch (\InvalidArgumentException $e) { $rejected = true; }
        sdk_check(true, $rejected, 'Reject ambiguous or unsafe URI host.');
    }
    foreach ([
        function () { return new \GuzzleHttp\Psr7\Request("GET\r\nInjected", 'https://example.invalid/'); },
        function () { return (new \GuzzleHttp\Psr7\Request('GET', '/'))->withProtocolVersion("1.1\r\nInjected"); },
        function () { return new \GuzzleHttp\Psr7\Response(200, [], null, '1.1', "OK\r\nInjected"); },
    ] as $operation) {
        $rejected = false;
        try { $operation(); } catch (\InvalidArgumentException $e) { $rejected = true; }
        sdk_check(true, $rejected, 'Reject HTTP start-line CRLF.');
    }

    $conf = ['downfile_domain' => '', 'downfile_protocol' => 1, 'downfile_type' => 0];
    $cos = new \lib\Storage\Qcloud(['bucket' => 'fixture-12345', 'region' => 'ap-guangzhou', 'secretId' => 'fixture-id', 'secretKey' => 'fixture-key']);
    sdk_check('https', $cos->getClient()->httpClient->getConfig('base_uri')->getScheme(), 'COS transport scheme.');
    sdk_check(true, $cos->getClient()->httpClient->getConfig('verify'), 'COS certificate verification.');
    sdk_check('https', parse_url($cos->getDownUrl('blob', 'test.txt'), PHP_URL_SCHEME), 'COS signed URL scheme.');

    $obs = new \lib\Storage\Obs(['bucket' => 'fixture', 'accessKey' => 'fixture-id', 'secretKey' => 'fixture-key', 'endpoint' => 'http://obs.example.invalid']);
    sdk_check('https', parse_url(sdk_private($obs->getClient(), 'endpoint'), PHP_URL_SCHEME), 'OBS transport scheme.');
    sdk_check(true, sdk_private($obs->getClient(), 'sslVerify'), 'OBS certificate verification.');

    $oss = new \lib\Storage\Oss(['bucket' => 'fixture', 'accessKeyId' => 'fixture-id', 'accessKeySecret' => 'fixture-key', 'endpoint' => 'oss.example.invalid']);
    sdk_check(true, $oss->getClient()->isUseSSL(), 'OSS transport must use HTTPS.');
    $core = new \OSS\Http\RequestCore('https://example.invalid/');
    sdk_check(true, $core->ssl_verification, 'OSS core must verify certificates.');

    $upyun = new \lib\Storage\Upyun(['serviceName' => 'fixture', 'operatorName' => 'fixture', 'operatorPwd' => 'fixture-only']);
    sdk_check(true, sdk_private($upyun->getClient(), 'config')->useSsl, 'Upyun service API HTTPS.');
    $qiniu = new \lib\Storage\Qiniu(['bucket' => 'fixture', 'accessKey' => 'fixture-id', 'secretKey' => 'fixture-key', 'domain' => 'download.example.invalid']);
    sdk_check(true, sdk_private($qiniu, 'sdkConfig')->useHTTPS, 'Qiniu upload/management configuration HTTPS.');
    $GLOBALS['sdk_capture_region'] = true;
    try { \Qiniu\Region::queryRegion('fixture-id', 'fixture'); }
    catch (\RuntimeException $e) { if ($e->getMessage() !== 'captured-region-url') throw $e; }
    finally { unset($GLOBALS['sdk_capture_region']); }
    sdk_check('https://api.qiniu.com/v2/query?ak=fixture-id&bucket=fixture', $GLOBALS['sdk_region_url'] ?? null, 'Qiniu fixed official region discovery HTTPS.');


    if (!extension_loaded('openssl') || !extension_loaded('curl')) throw new RuntimeException('TLS regression requires OpenSSL and cURL.');
    if (!mkdir($fixtureRoot, 0700)) throw new RuntimeException('Unable to create fixture.');
    file_put_contents($fixtureRoot . '/upload.txt', 'abcdef');
    $cosHistory = [];
    $cosStack = $cos->getClient()->httpClient->getConfig('handler');
    $cosStack->setHandler(new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '6', 'Content-Type' => 'text/plain']),
        new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '6'], 'abcdef'),
        new \GuzzleHttp\Psr7\Response(200, ['ETag' => '"fixture"']),
    ]));
    $cosStack->push(\GuzzleHttp\Middleware::history($cosHistory));
    $info = $cos->getinfo('blob');
    sdk_check(6, intval($info['length']), 'COS metadata through new Guzzle Services.');
    sdk_check('text/plain', $info['content_type'], 'COS MIME through new Guzzle Services.');
    $body = $cos->get('blob');
    sdk_check('abcdef', (string)$body, 'COS object response stream.');
    sdk_check(true, $cos->upload('blob', $fixtureRoot . '/upload.txt'), 'COS SDK upload request.');
    sdk_check(3, count($cosHistory), 'COS actual SDK request count.');
    sdk_check('HEAD', $cosHistory[0]['request']->getMethod(), 'COS metadata request method.');
    sdk_check(true, $cosHistory[0]['request']->hasHeader('Authorization'), 'COS actual request must be signed.');
    sdk_check('https', $cosHistory[0]['request']->getUri()->getScheme(), 'COS actual request HTTPS.');
    sdk_check('abcdef', (string)$cosHistory[2]['request']->getBody(), 'COS upload bytes preserved.');
    foreach ($cosHistory as $transaction) $transaction['request']->getBody()->close();

    $obsHistory = [];
    $obsHttp = sdk_private($obs->getClient(), 'httpClient');
    $obsStack = $obsHttp->getConfig('handler');
    $obsStack->setHandler(new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '6', 'Content-Type' => 'text/plain']),
        new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '6', 'Content-Type' => 'text/plain'], 'abcdef'),
        new \GuzzleHttp\Psr7\Response(200, ['ETag' => '"fixture"']),
    ]));
    $obsStack->push(\GuzzleHttp\Middleware::history($obsHistory));
    $info = $obs->getinfo('blob');
    sdk_check(6, intval($info['length']), 'OBS metadata through current PSR interfaces.');
    sdk_check('text/plain', $info['content_type'], 'OBS MIME metadata.');
    sdk_check('abcdef', (string)$obs->get('blob'), 'OBS streamed body response.');
    sdk_check(true, $obs->upload('blob', $fixtureRoot . '/upload.txt'), 'OBS SDK upload request.');
    sdk_check(3, count($obsHistory), 'OBS actual SDK request count.');
    sdk_check(true, $obsHistory[0]['request']->hasHeader('Authorization'), 'OBS actual request must be signed.');
    sdk_check('https', $obsHistory[0]['request']->getUri()->getScheme(), 'OBS actual request HTTPS.');
    sdk_check('abcdef', (string)$obsHistory[2]['request']->getBody(), 'OBS upload bytes preserved.');
    foreach ($obsHistory as $transaction) $transaction['request']->getBody()->close();
    $checkout = new \Obs\Internal\Common\CheckoutStream(\GuzzleHttp\Psr7\Utils::streamFor('abcdef'), 6);
    sdk_check('abc', $checkout->read(3), 'OBS CheckoutStream remains compatible with PSR HTTP Message 1.1.');
    sdk_check('def', $checkout->read(3), 'OBS checked stream preserves remaining bytes.');

    file_put_contents($fixtureRoot . '/openssl.cnf', "[req]\ndistinguished_name=dn\n[dn]\nCN=127.0.0.1\n[v3_req]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
    $options = ['config' => $fixtureRoot . '/openssl.cnf', 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $csr = $key ? openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options) : false;
    $cert = $csr ? openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'v3_req']) : false;
    if (!$key || !$csr || !$cert || !openssl_pkey_export($key, $keyPem, null, $options) || !openssl_x509_export($cert, $certPem)) {
        throw new RuntimeException('Unable to generate temporary TLS fixture certificate: ' . openssl_error_string());
    }
    file_put_contents($fixtureRoot . '/key.pem', $keyPem);
    file_put_contents($fixtureRoot . '/cert.pem', $certPem);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) throw new RuntimeException($errstr);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $socket = null;
    $server = proc_open([PHP_BINARY, __FILE__, '--tls-server', $fixtureRoot, $address], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $fixtureRoot . '/server.out', 'a'], 2 => ['file', $fixtureRoot . '/server.err', 'a'],
    ], $pipes, $projectRoot);
    if (!is_resource($server)) throw new RuntimeException('Unable to launch TLS fixture.');
    for ($i = 0; $i < 100 && !is_file($fixtureRoot . '/ready'); $i++) {
        if (!proc_get_status($server)['running']) break;
        usleep(20000);
    }
    if (!is_file($fixtureRoot . '/ready')) throw new RuntimeException('TLS fixture did not start: ' . file_get_contents($fixtureRoot . '/server.err'));
    putenv('NO_PROXY=127.0.0.1,localhost');
    $url = 'https://' . $address . '/';
    $core = new \OSS\Http\RequestCore($url);
    $core->curlopts = [CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 3];
    $curl = $core->prep_request();
    $body = curl_exec($curl);
    $errno = curl_errno($curl);
    curl_close($curl);
    sdk_check(false, $body, 'OSS must reject an untrusted TLS certificate.');
    sdk_check(60, $errno, 'OSS certificate rejection must be a TLS trust failure.');
    $core = new \OSS\Http\RequestCore($url);
    $core->cacert_location = $fixtureRoot . '/cert.pem';
    $core->curlopts = [CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 3];
    $curl = $core->prep_request();
    $body = curl_exec($curl);
    $errno = curl_errno($curl);
    curl_close($curl);
    sdk_check(0, $errno, 'OSS must accept an explicitly trusted test CA.');
    sdk_check(true, substr($body, -2) === 'OK', 'OSS trusted TLS must return the real response.');

    $reply = \Qiniu\Http\Client::get($url, [], new \Qiniu\Http\RequestOptions(2, null, 3));
    sdk_check(-1, $reply->statusCode, 'Qiniu must reject an untrusted TLS certificate.');
    sdk_check(true, stripos((string)$reply->error, 'certificate') !== false, 'Qiniu TLS error must explain the certificate rejection.');
    $child = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . $fixtureRoot . '/cert.pem', '-d', 'openssl.cafile=' . $fixtureRoot . '/cert.pem', __FILE__, '--qiniu-ca', $url], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $childPipes, $projectRoot);
    if (!is_resource($child)) throw new RuntimeException('Unable to test configured Qiniu CA.');
    $childOutput = stream_get_contents($childPipes[1]);
    $childError = stream_get_contents($childPipes[2]);
    fclose($childPipes[1]); fclose($childPipes[2]);
    $exit = proc_close($child);
    sdk_check(0, $exit, 'Configured-CA subprocess must finish: ' . $childError);
    sdk_check(['status' => 200, 'body' => 'OK'], json_decode($childOutput, true), 'Qiniu must accept the CA from php.ini.');

    $child = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . $fixtureRoot . '/cert.pem', '-d', 'openssl.cafile=' . $fixtureRoot . '/cert.pem', __FILE__, '--upyun-ca', $address, $fixtureRoot . '/upyun.out'], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $childPipes, $projectRoot);
    if (!is_resource($child)) throw new RuntimeException('Unable to launch Upyun TLS compatibility probe.');
    $childOutput = stream_get_contents($childPipes[1]);
    $childError = stream_get_contents($childPipes[2]);
    fclose($childPipes[1]); fclose($childPipes[2]);
    sdk_check(0, proc_close($child), 'Upyun TLS subprocess: ' . $childError);
    sdk_check(['get' => 'OK', 'download' => true, 'file' => 'OK'], json_decode($childOutput, true), 'Upyun actual REST and legacy stream-copy helpers over trusted TLS.');

    $http = new \GuzzleHttp\Client(['proxy' => '', 'timeout' => 3]);
    $rejected = false;
    try { $http->get($url); } catch (\GuzzleHttp\Exception\RequestException $e) { $rejected = ($e->getHandlerContext()['errno'] ?? 0) === 60; }
    sdk_check(true, $rejected, 'Shared Guzzle transport must reject an untrusted certificate.');
    sdk_check('OK', (string)$http->get($url, ['verify' => $fixtureRoot . '/cert.pem'])->getBody(), 'Shared Guzzle transport must accept an explicit CA.');
} catch (Throwable $e) {
    $error = $e;
} finally {
    if (is_resource($socket)) fclose($socket);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($oldNoProxy === false) putenv('NO_PROXY'); else putenv('NO_PROXY=' . $oldNoProxy);
    if (is_dir($fixtureRoot)) {
        foreach (glob($fixtureRoot . '/*') as $file) if (is_file($file)) unlink($file);
        if (!rmdir($fixtureRoot) && !$error) $error = new RuntimeException('Fixture cleanup failed.');
    }
}
if ($error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
echo 'PASS: ' . $checks . " SDK/HTTP/TLS checks; fixture and temporary certificates cleaned.\n";

}
