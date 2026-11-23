<?php
$nosession = true;
$nosecu = true;
include("./includes/common.php");

$token = isset($_GET['hash']) && is_string($_GET['hash']) ? trim($_GET['hash']) : '';
$password = isset($_GET['pwd']) && is_string($_GET['pwd']) ? $_GET['pwd'] : null;
$result = \lib\TextPreview::load($token, $password);
http_response_code($result['status']);
header('Content-Type: text/html; charset=UTF-8');
header('Content-Security-Policy: '.\lib\TextPreview::policy());
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, no-cache, must-revalidate');

if(!$result['ok']){
    $title = $result['message'];
    $data = ['error'=>true, 'message'=>$result['message'], 'password_required'=>$result['password'], 'token'=>$token];
}else{
    $row = $result['row'];
    $title = html_entity_decode($row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $suffix = $password !== null && $password !== '' ? '&pwd='.rawurlencode($password) : '';
    $download = 'down.php/'.$row['token'].'.'.rawurlencode($row['type']);
    if($password !== null && $password !== '')$download .= '&'.rawurlencode($password);
    $data = [
        'error'=>false,
        'name'=>$title,
        'type'=>strtolower($row['type']),
        'content'=>$result['content'],
        'raw_url'=>'text.php?hash='.$row['token'].$suffix,
        'file_url'=>'file.php?hash='.$row['token'].$suffix,
        'download_url'=>$download,
        'size'=>size_format($row['size']),
    ];
    if(!isset($_GET['preview'])){
        $DB->exec('UPDATE pre_file SET lasttime=NOW(),count=count+1 WHERE id=:id', [':id'=>intval($row['id'])]);
    }
}

// 外壳只加载本地预览资源，文件内容永远作为转义后的文本传给卡片。
// 正文仍由主题布局渲染，站长可以在页面布局编辑器里调整预览卡片。
$body = theme_render_layout('text-preview', $data, 'front');
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo \lib\TextPreview::escape($title); ?> - 文本预览</title>
<link rel="stylesheet" href="assets/css/text-preview.css?v=<?php echo VERSION; ?>">
<?php if($result['ok']){ ?>
<script defer src="assets/vendor/text-preview/markdown-it-15.0.3.min.js"></script>
<script defer src="assets/vendor/text-preview/highlight-11.12.0.min.js"></script>
<script defer src="assets/js/text-preview.js?v=<?php echo VERSION; ?>"></script>
<?php } ?>
</head>
<body class="text-preview-page">
<?php echo $body; ?>
</body>
</html>
<?php
$html = ob_get_clean();
if($result['ok'])download_output_chunk($html);
else echo $html;
