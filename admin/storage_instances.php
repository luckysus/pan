<?php
define('IN_ADMIN', true);
include('../includes/common.php');
if ($islogin != 1) { header('Location: ./login.php'); exit; }
include_once(SYSTEM_ROOT.'vendor/autoload.php');
$title = '多存储实例';
if (empty($_SESSION['storage_instance_csrf'])) $_SESSION['storage_instance_csrf'] = bin2hex(random_bytes(24));
$message = '';
$ok = false;
$id = isset($_GET['edit']) && is_scalar($_GET['edit']) ? intval($_GET['edit']) : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
    if (!checkRefererHost() || !hash_equals($_SESSION['storage_instance_csrf'], $token)) {
        http_response_code(403);
        exit('请求校验失败，请刷新页面重试');
    }
    $id = isset($_POST['id']) && is_scalar($_POST['id']) ? intval($_POST['id']) : 0;
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $key = 'inst_'.$id;
    if ($action === 'save') {
        $ret = \lib\StorageInstances::save($id,
            isset($_POST['type']) && is_string($_POST['type']) ? $_POST['type'] : '',
            isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '',
            isset($_POST['cfg']) && is_array($_POST['cfg']) ? $_POST['cfg'] : [],
            !empty($_POST['enabled']), isset($_POST['min_tier']) && is_scalar($_POST['min_tier']) ? intval($_POST['min_tier']) : 0);
    } elseif ($action === 'delete') {
        $ret = \lib\StorageInstances::remove($id);
        if ($ret === true) $id = 0;
    } elseif ($action === 'default') {
        $row = \lib\StorageInstances::row($key);
        $ret = $row && intval($row['enabled']) === 1 && intval($row['min_tier']) === 0
            ? (saveSetting('storage', $key) !== false ? true : '设置失败')
            : '默认存储必须启用并对所有人开放';
        if ($ret === true) $conf['storage'] = $key;
    } elseif ($action === 'test') {
        // 只测试已保存实例；随机对象不会覆盖业务文件，finally清理本地与远端。
        $model = \lib\StorHelper::getModel($key);
        $tmp = tempnam(sys_get_temp_dir(), 'pan_conn_');
        $object = 'pan-test-'.bin2hex(random_bytes(20));
        $ret = '连接测试失败，请检查参数及服务器扩展';
        try {
            session_write_close();
            if ($tmp && $model) {
                file_put_contents($tmp, 'pan storage connection test');
                if ($model->savefile($object, $tmp, 'text/plain') && $model->exists($object)
                    && $model->get($object) === 'pan storage connection test') $ret = true;
            }
        } catch (\Throwable $e) {
            $ret = '连接测试失败，请检查参数、网络和依赖扩展';
        } finally {
            if ($model) { try { $model->delete($object); } catch (\Throwable $ignored) {} }
            if ($tmp && is_file($tmp)) unlink($tmp);
        }
    } else {
        $ret = '不支持的操作';
    }
    $ok = $ret === true;
    $message = $ok ? ($action === 'test' ? '连接正常，读写测试通过' : '操作成功') : $ret;
}
$rows = \lib\StorageInstances::all();
$row = $id ? \lib\StorageInstances::row('inst_'.$id) : null;
$types = \lib\StorHelper::names(false);
$type = $row ? $row['type'] : (isset($_GET['type']) && is_string($_GET['type']) && isset($types[$_GET['type']]) ? $_GET['type'] : 's3');
$values = $row ? $row['config'] : [];
if (\lib\StorExt::has($type)) {
    $raw = isset($values['stor_'.$type]) ? json_decode($values['stor_'.$type], true) : [];
    $values = array_merge($values, is_array($raw) ? $raw : []);
}
$e = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$tiers = [0=>'所有人',1=>'仅登录用户',2=>'仅限会员（可用全部存储）'];
include('./head.php');
theme_panel_begin('instances', '多存储实例');
?>
<div class="panel panel-default"><div class="panel-heading">多存储实例 <a class="pull-right" href="./set_stor.php">返回存储设置</a></div><div class="panel-body">
<p>同一种存储可以配置多个桶、目录或账号。旧存储参数及历史文件保持原样。开启「同时开放多个存储」后，已启用且符合权限的实例会出现在上传选择中。</p>
<?php if ($message !== '') { ?><div role="alert" class="alert alert-<?php echo $ok ? 'success':'danger'; ?>"><?php echo $e($message); ?></div><?php } ?>
<div class="table-responsive"><table class="table"><thead><tr><th>名称</th><th>类型</th><th>上传权限</th><th>状态</th><th>操作</th></tr></thead><tbody>
<?php foreach ($rows as $ref=>$one) { ?>
<tr><td><?php echo $e($one['name']); ?></td><td><?php echo $e(isset($types[$one['type']]) ? $types[$one['type']] : $one['type']); ?></td><td><?php echo $e($tiers[intval($one['min_tier'])]); ?></td>
<td><?php echo $conf['storage'] === $ref ? '默认存储' : (intval($one['enabled']) ? '已启用':'停止新上传'); ?></td>
<td><a class="btn btn-default btn-xs" href="?edit=<?php echo intval($one['id']); ?>">编辑</a>
<?php foreach (['test'=>'连接测试','default'=>'设为默认','delete'=>'停止新上传'] as $action=>$label) { ?>
<form method="post" style="display:inline" <?php if ($action === 'delete') { ?>onsubmit="return confirm('停止向这个实例新上传？已有文件和进行中的上传将继续使用原配置。')"<?php } ?>>
<input type="hidden" name="id" value="<?php echo intval($one['id']); ?>"><input type="hidden" name="token" value="<?php echo $e($_SESSION['storage_instance_csrf']); ?>"><input type="hidden" name="action" value="<?php echo $action; ?>"><button class="btn btn-default btn-xs" type="submit"><?php echo $label; ?></button></form>
<?php } ?></td></tr><?php } ?>
</tbody></table></div>
<a class="btn btn-default" href="./storage_instances.php">新建实例</a>
<hr><h4><?php echo $row ? '编辑实例':'新建实例'; ?></h4>
<?php if (!$row) { ?><form method="get" class="form-inline"><label>存储类型 <select name="type" class="form-control" onchange="this.form.submit()"><?php foreach ($types as $k=>$name) { ?><option value="<?php echo $e($k); ?>"<?php echo $k === $type ? ' selected':''; ?>><?php echo $e($name); ?></option><?php } ?></select></label><noscript><button type="submit">选择</button></noscript></form><br><?php } ?>
<form method="post" class="form-horizontal">
<input type="hidden" name="id" value="<?php echo $row ? intval($row['id']) : 0; ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="type" value="<?php echo $e($type); ?>"><input type="hidden" name="token" value="<?php echo $e($_SESSION['storage_instance_csrf']); ?>">
<div class="form-group"><label class="col-sm-3 control-label">实例名称</label><div class="col-sm-9"><input required maxlength="80" class="form-control" name="name" value="<?php echo $e($row ? $row['name']:''); ?>" placeholder="例如：图片桶、备用账号"></div></div>
<?php
$fields = \lib\StorageInstances::fields($type);
$fields[] = ['name'=>'downfile_domain','label'=>'独立下载域名（可选）'];
$fields[] = ['name'=>'downfile_protocol','label'=>'下载协议','type'=>'select','default'=>'1','options'=>['1'=>'HTTPS','0'=>'HTTP']];
foreach ($fields as $f) {
    $field = $f['name'];
    $value = isset($values[$field]) ? $values[$field] : (isset($f['default']) ? $f['default'] : '');
    $ft = isset($f['type']) ? $f['type'] : 'text';
?>
<div class="form-group"><label class="col-sm-3 control-label"><?php echo $e($f['label']); ?></label><div class="col-sm-9">
<?php if ($ft === 'select') { ?><select class="form-control" name="cfg[<?php echo $e($field); ?>]"><?php foreach ($f['options'] as $v=>$label) { ?><option value="<?php echo $e($v); ?>"<?php echo (string)$value === (string)$v ? ' selected':''; ?>><?php echo $e($label); ?></option><?php } ?></select>
<?php } elseif ($ft === 'textarea') { ?><textarea class="form-control" rows="3" name="cfg[<?php echo $e($field); ?>]"><?php echo $e($value); ?></textarea>
<?php } else { ?><input class="form-control" type="<?php echo $ft === 'secret' ? 'password':'text'; ?>" autocomplete="off" name="cfg[<?php echo $e($field); ?>]" value="<?php echo $ft === 'secret' ? '' : $e($value); ?>" placeholder="<?php echo $ft === 'secret' && $row ? '留空保留原值' : $e(isset($f['placeholder']) ? $f['placeholder']:''); ?>">
<?php } ?>
<?php if (!empty($f['tip'])) { ?><p class="help-block"><?php echo $f['tip']; ?></p><?php } ?></div></div>
<?php } ?>
<div class="form-group"><label class="col-sm-3 control-label">上传权限</label><div class="col-sm-9"><select name="min_tier" class="form-control"><?php foreach ($tiers as $n=>$label) { ?><option value="<?php echo $n; ?>"<?php echo $row && intval($row['min_tier']) === $n ? ' selected':''; ?>><?php echo $e($label); ?></option><?php } ?></select></div></div>
<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><label><input type="checkbox" name="enabled" value="1"<?php echo !$row || intval($row['enabled']) ? ' checked':''; ?>> 允许新上传（关闭后已有文件仍可读取）</label><p class="help-block">开始使用后连接参数会被锁定，避免历史链接或进行中的上传失效；需要换桶或账号时请新建实例。停用会保留配置，可随时重新启用。密钥留空表示保留。</p><button class="btn btn-primary" type="submit">保存实例</button></div></div>
</form></div></div>
<?php theme_panel_end(); echo theme_render_layout('admin/storage_instances', [], 'admin', $site_theme); ?>
</body></html>
