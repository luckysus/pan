<?php
namespace { if (PHP_SAPI !== 'cli' && !(PHP_SAPI === 'cli-server' && defined('PAN_UPSTREAM_CORE_FIXTURE') && PAN_UPSTREAM_CORE_FIXTURE === true)) { http_response_code(404); exit; } }
//独立回归：只加载实际目标函数，HTTP夹具不连接业务数据库、云账号或线上站点。
namespace Qiniu\Storage {
    class BucketManager {
        public static $response = ['fsize' => 6, 'mimeType' => 'text/plain'];
        public function __construct($auth) {}
        public function stat($bucket, $name) { return [self::$response, null]; }
    }
}
namespace lib {
    class StorHelper {
        public static $model;
        public static function get($storage) { return self::$model; }
        public static function is_range($storage) { return true; }
        public static function is_direct_down($storage) { return false; }
        public static function uses_down_domain($storage) { return false; }
    }
}
namespace {
    $projectRoot = dirname(__DIR__);
    require_once $projectRoot . '/includes/lib/IStorage.php';
    require_once $projectRoot . '/includes/lib/StorageInstances.php';
    require_once $projectRoot . '/includes/lib/Storage/Local.php';
    require_once $projectRoot . '/includes/lib/Storage/Qiniu.php';

    function load_target_function($name) {
        $tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/includes/functions.php'));
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!isset($tokens[$j][1]) || $tokens[$j][1] !== $name) continue;
            $source = '';
            $depth = 0;
            $opened = false;
            for (; $i < $count; $i++) {
                $token = $tokens[$i];
                $source .= is_array($token) ? $token[1] : $token;
                if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    $opened = true;
                } elseif ($token === '}') {
                    $depth--;
                    if ($opened && $depth === 0) {
                        eval($source);
                        return;
                    }
                }
            }
        }
        throw new \RuntimeException('Missing production function: ' . $name);
    }

    load_target_function('get_file_range');

    //HTTP夹具只替换引导依赖，实际解析、响应及Local字节输出均来自项目源码。
    if (PHP_SAPI === 'cli-server') {
        function file_download_speed_kbps($uid) { return 0; }
        function file_traffic_state($uid) { return null; }
        function is_scriptable_file_type($type) { return false; }
        function start_download_throttle($speed) {}
        function download_output_chunk($data) { echo $data; }
        function minetype($type) { return 'text/plain'; }
        function check_file_pwd($row, $pwd) { return empty($row['pwd']) || $row['pwd'] === $pwd; }
        class FixtureDatabase {
            public function getRow($sql, $params) {
                if (($_GET['case'] ?? '') === 'missing') return false;
                return [
                    'id' => 1, 'token' => 'fixture', 'storage' => 'local', 'uid' => 0,
                    'hash' => ($_GET['case'] ?? '') === 'missingblob' ? 'absent' : 'blob',
                    'type' => 'txt', 'name' => 'fixture.txt', 'size' => 6,
                    'block' => ($_GET['case'] ?? '') === 'blocked' ? 1 : 0,
                    'pwd' => ($_GET['case'] ?? '') === 'password' ? 'secret' : null,
                ];
            }
            public function exec($sql, $params = []) { return 1; }
        }
        $fixtureRoot = realpath($_SERVER['DOCUMENT_ROOT']);
        \lib\StorHelper::$model = new \lib\Storage\Local($fixtureRoot);
        $conf = ['storage' => 'local', 'downfile_domain' => '', 'downfile_type' => 0];
        load_target_function('file_output');
        if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/download') {
            $DB = new FixtureDatabase();
            $siteurl = '/';
            $_SERVER['PATH_INFO'] = '/fixture.txt';
            $source = file_get_contents($projectRoot . '/down.php');
            $source = str_replace('include("./includes/common.php");', '', $source);
            eval('?>' . $source);
            return;
        }
        $blob = isset($_GET['empty']) ? 'empty' : 'blob';
        file_output($blob, 'txt', filesize($fixtureRoot . '/' . $blob), 'fixture.txt', false, false, 'local');
        return;
    }

    $checks = 0;
    function check_same($expected, $actual, $message) {
        global $checks;
        $checks++;
        if ($expected !== $actual) {
            throw new \RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }
    function header_value($headers, $name) {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
        }
        return null;
    }
    function fixture_request($base, $path, $headers = []) {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $headers),
            'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $body = file_get_contents($base . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        if ($body === false || !$responseHeaders) throw new \RuntimeException('Fixture request failed: ' . $path);
        preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match);
        return [intval($match[1]), $body, $responseHeaders];
    }

    $fixtureRoot = sys_get_temp_dir() . '/pan-upstream-core-' . bin2hex(random_bytes(6));
    $process = null;
    $pipes = [];
    $socket = null;
    $error = null;
    try {
        if (!mkdir($fixtureRoot, 0700)) throw new \RuntimeException('Unable to create isolated fixture.');
        file_put_contents($fixtureRoot . '/blob', 'abcdef');
        file_put_contents($fixtureRoot . '/empty', '');
        file_put_contents($fixtureRoot . '/router.php', '<?php define(\'PAN_UPSTREAM_CORE_FIXTURE\', true); require ' . var_export(__FILE__, true) . ';');

        //实际读取与写入验证末尾分隔符，而非只匹配源码字符串。
        $paths = [$fixtureRoot, $fixtureRoot . '/', $fixtureRoot . '///'];
        if (DIRECTORY_SEPARATOR === '\\') $paths[] = str_replace('/', '\\', $fixtureRoot) . '\\';
        foreach ($paths as $path) {
            $local = new \lib\Storage\Local($path);
            check_same('abcdef', $local->get('blob'), 'Custom local path must resolve the real file.');
        }
        $local = new \lib\Storage\Local($fixtureRoot);
        file_put_contents($fixtureRoot . '/incoming', 'saved bytes');
        check_same(true, $local->savefile('saved', $fixtureRoot . '/incoming'), 'Local save should succeed.');
        check_same('saved bytes', $local->get('saved'), 'Local save must remain inside the configured directory.');
        check_same(false, file_exists($fixtureRoot . '/incoming'), 'Local save should clean the temporary source.');
        $info = $local->getinfo('blob');
        check_same(6, $info['length'], 'Local size metadata is incorrect.');
        check_same(true, array_key_exists('content_type', $info), 'Local MIME key is missing.');
        check_same(false, array_key_exists('content-length', $info), 'MIME must not overwrite length metadata.');
        if (function_exists('finfo_open')) check_same('text/plain', $info['content_type'], 'Local MIME should describe the actual bytes.');
        else check_same(null, $info['content_type'], 'Missing fileinfo should return a null MIME.');

        //用SDK边界替身核对字段映射，无网络、无真实密钥。
        $qiniu = (new \ReflectionClass(\lib\Storage\Qiniu::class))->newInstanceWithoutConstructor();
        check_same(['length' => 6, 'content_type' => 'text/plain'], $qiniu->getinfo('blob'), 'Qiniu MIME must come from mimeType, not fsize.');

        $rangeCases = [
            ['bytes=0-0', 6, [0, 0]], ['bytes=2-', 6, [2, 5]], ['bytes=-2', 6, [4, 5]],
            ['bytes=-99', 6, [0, 5]], ['bytes=2-99', 6, [2, 5]],
            ['bytes=6-', 6, null], ['bytes=-0', 6, null], ['bytes=0-0', 0, null],
            ['bytes=4-2', 6, false], ['bytes=0-0,3-3', 6, false],
            ['items=0-1', 6, false], ['bytes=-', 6, false], ['', 6, false],
            ['bytes=999999999999999999999999-', 6, null],
            ['bytes=0-999999999999999999999999', 6, [0, 5]],
            ['bytes=-999999999999999999999999', 6, [0, 5]],
        ];
        foreach ($rangeCases as $case) {
            $_SERVER['HTTP_RANGE'] = $case[0];
            check_same($case[2], get_file_range($case[1]), 'Range parse: ' . $case[0]);
        }
        unset($_SERVER['HTTP_RANGE']);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) throw new \RuntimeException('Unable to reserve fixture port: ' . $errstr);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $socket = null;
        $command = [PHP_BINARY, '-S', $address, '-t', $fixtureRoot, $fixtureRoot . '/router.php'];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $fixtureRoot . '/server.out', 'a'],
            2 => ['file', $fixtureRoot . '/server.err', 'a'],
        ], $pipes, $projectRoot);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start isolated PHP fixture.');
        fclose($pipes[0]);
        $pipes = [];
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
            if ($probe) { fclose($probe); $ready = true; break; }
            if (!proc_get_status($process)['running']) break;
            usleep(20000);
        }
        if (!$ready) throw new \RuntimeException('Fixture failed to start: ' . file_get_contents($fixtureRoot . '/server.err'));

        $base = 'http://' . $address;
        $httpCases = [
            ['/stream', [], 200, 'abcdef', null],
            ['/stream', ['Range: bytes=0-0'], 206, 'a', 'bytes 0-0/6'],
            ['/stream', ['Range: bytes=-2'], 206, 'ef', 'bytes 4-5/6'],
            ['/stream', ['Range: bytes=2-'], 206, 'cdef', 'bytes 2-5/6'],
            ['/stream', ['Range: bytes=2-99'], 206, 'cdef', 'bytes 2-5/6'],
            ['/stream', ['Range: bytes=6-'], 416, '', 'bytes */6'],
            ['/stream', ['Range: bytes=-0'], 416, '', 'bytes */6'],
            ['/stream?empty=1', ['Range: bytes=0-0'], 416, '', 'bytes */0'],
            ['/stream', ['Range: bytes=4-2'], 200, 'abcdef', null],
            ['/stream', ['Range: bytes=0-0,3-3'], 200, 'abcdef', null],
            ['/stream', ['Range: bytes=99-', 'If-Range: "old"'], 200, 'abcdef', null],
            ['/stream', ['Range: bytes=0-0', 'If-Range: "blob"'], 206, 'a', 'bytes 0-0/6'],
            ['/download?case=missing', [], 404, '404 Not Found', null],
            ['/download?case=blocked', [], 404, 'File is blocked!', null],
            ['/download?case=missingblob', [], 404, 'File Not Found', null],
            ['/download', [], 200, 'abcdef', null],
        ];
        foreach ($httpCases as $case) {
            list($status, $body, $headers) = fixture_request($base, $case[0], $case[1]);
            $label = $case[0] . ' ' . implode('; ', $case[1]);
            check_same($case[2], $status, 'HTTP status: ' . $label);
            check_same($case[3], $body, 'HTTP body: ' . $label);
            check_same($case[4], header_value($headers, 'Content-Range'), 'HTTP Content-Range: ' . $label);
        }
        list($status, $body) = fixture_request($base, '/download?case=password');
        check_same(200, $status, 'Password prompt must remain available.');
        check_same(true, strpos($body, 'prompt(') !== false && strpos($body, 'abcdef') === false, 'Password protection must not expose file bytes.');
    } catch (\Throwable $e) {
        $error = $e;
    } finally {
        if (is_resource($socket)) fclose($socket);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        //目录仅由本测试创建，先结束服务再逐个清理，失败时也执行。
        if (is_dir($fixtureRoot)) {
            foreach (glob($fixtureRoot . '/*') as $file) if (is_file($file)) unlink($file);
            if (!rmdir($fixtureRoot) && !$error) $error = new \RuntimeException('Fixture cleanup failed: ' . $fixtureRoot);
        }
    }
    if ($error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
    echo 'PASS: ' . $checks . " upstream core regression checks; HTTP fixture cleaned.\n";
}
