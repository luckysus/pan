<?php
namespace lib;

/**
 * 新实例使用 inst_<id> 作为 storage 引用；旧类型键和远端映射不迁移、不改写。
 * 一个入口负责配置作用域，避免驱动里的全局下载域名和刷新令牌串到其他账号。
 */
class StorageInstances
{
    private static $rows;
    private static $active;

    public static function isReference($key)
    {
        return is_string($key) && preg_match('/^inst_[1-9][0-9]{0,9}$/D', $key);
    }

    public static function all()
    {
        if (self::$rows === null) {
            global $DB;
            self::$rows = [];
            if (is_object($DB)) {
                $rows = $DB->getAll("SELECT * FROM pre_storage_instance ORDER BY id");
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $row['_config_json'] = $row['config'];
                    $cfg = json_decode($row['config'], true);
                    $row['config'] = is_array($cfg) ? $cfg : [];
                    self::$rows['inst_'.$row['id']] = $row;
                }
            }
        }
        return self::$rows;
    }

    public static function reset()
    {
        self::$rows = null;
    }

    public static function row($key)
    {
        $rows = self::all();
        return self::isReference($key) && isset($rows[$key]) ? $rows[$key] : null;
    }

    public static function baseType($key)
    {
        if (!self::isReference($key)) return $key;
        $row = self::row($key);
        return $row ? $row['type'] : '';
    }

    public static function fieldsAll()
    {
        $fields = include dirname(__DIR__).'/storage_fields.php';
        $fields['sae'] = $fields['ace'] = [['name'=>'storagename', 'label'=>'存储空间名称']];
        $fields['onedrive'][] = ['name'=>'onedrive_refresh_token', 'label'=>'Refresh Token', 'type'=>'secret',
            'tip'=>'为这个实例单独授权取得的刷新令牌，不与原有账号共用。'];
        foreach (StorExt::keys() as $type) $fields[$type] = StorExt::meta($type)['fields'];
        return $fields;
    }

    public static function fields($type)
    {
        $all = self::fieldsAll();
        return isset($all[$type]) ? $all[$type] : [];
    }

    private static function keys($type)
    {
        $keys = ['downfile_domain', 'downfile_protocol'];
        if (StorExt::has($type)) return array_merge($keys, ['stor_'.$type]);
        foreach (self::fields($type) as $field) $keys[] = $field['name'];
        if ($type === 'onedrive') $keys = array_merge($keys, ['onedrive_access_token','onedrive_token_expire']);
        if ($type === 'openlist') $keys = array_merge($keys, ['openlist_cache_token','openlist_token_expire']);
        return $keys;
    }

    public static function scope($key, $callback)
    {
        global $conf;
        $row = self::row($key);
        if (!$row) throw new \RuntimeException('存储实例不存在，请联系站长恢复配置');
        $before = $conf;
        $active = self::$active;
        foreach (self::keys($row['type']) as $name) {
            // 未填的实例参数绝不能继承同类型原账号的密钥或缓存令牌。
            $conf[$name] = isset($row['config'][$name]) ? $row['config'][$name] : '';
        }
        if ($row['type'] === 'local' && (empty($conf['filepath']) || !is_dir($conf['filepath']))) {
            $conf = $before;
            throw new \RuntimeException('本地存储实例目录不存在，不能回退到默认目录');
        }
        // 扩展驱动轮换的刷新令牌沿用其现有持久化接口，优先读实例专属配置。
        if (StorExt::has($row['type'])) {
            if (!empty($before['stor_'.$key])) {
                $conf['stor_'.$row['type']] = $before['stor_'.$key];
            } else {
                // saveConfig 会以这个键为基础合并轮换参数，必须先带上完整连接参数。
                $conf['stor_'.$key] = $conf['stor_'.$row['type']];
            }
        }
        self::$active = $key;
        try {
            return $callback($row);
        } finally {
            // 扩展驱动沿用原状态接口，但以实例引用隔离命名空间。
            foreach ($conf as $name=>$value) {
                if ($name === 'stor_'.$key || $name === 'stor_'.$key.'_state') $before[$name] = $value;
            }
            $conf = $before;
            self::$active = $active;
        }
    }

    // 原生 OneDrive/OpenList 调用 saveSetting 刷新令牌时，仅写回当前实例。
    public static function saveScoped($name, $value)
    {
        global $DB;
        if (!self::$active) return null;
        $row = self::row(self::$active);
        if (!$row || !in_array($name, self::keys($row['type']), true)) return null;
        $config = $row['config'];
        $config[$name] = $value;
        $json = json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = $DB->query("UPDATE pre_storage_instance SET config=:c WHERE id=:id AND BINARY config=BINARY :old",
            [':c'=>$json, ':id'=>$row['id'], ':old'=>$row['_config_json']]);
        if (!$stmt) return false;
        if ($stmt->rowCount() === 0 && $DB->getColumn("SELECT config FROM pre_storage_instance WHERE id=:id", [':id'=>$row['id']]) !== $json) return false;
        {
            self::$rows[self::$active]['config'] = $config;
            self::$rows[self::$active]['_config_json'] = $json;
        }
        return true;
    }

    public static function model($key)
    {
        return new StorageInstanceDriver($key);
    }

    public static function markUsed($key)
    {
        if (!self::isReference($key)) return true;
        global $DB;
        $row = self::row($key);
        if (!$row) return false;
        // 驱动可能已按本请求缓存的配置构造。只冻结完全相同的配置；
        // 管理员若在此之前换过目标，必须拒绝，而不是将旧目标的对象记到新目标上。
        $stmt = $DB->query("UPDATE pre_storage_instance SET used_at=NOW() WHERE id=:id AND BINARY config=BINARY :c",
            [':id'=>$row['id'], ':c'=>$row['_config_json']]);
        if (!$stmt) return false;
        if ($stmt->rowCount() === 0) {
            // 同一秒重复准入可能没有改变used_at，不把它误判为配置竞态。
            $fresh = $DB->getRow("SELECT config,used_at FROM pre_storage_instance WHERE id=:id", [':id'=>$row['id']]);
            if (!$fresh || empty($fresh['used_at']) || $fresh['config'] !== $row['_config_json']) return false;
        }
        self::$rows[$key]['used_at'] = date('Y-m-d H:i:s');
        return true;
    }

    public static function capability($key, $method)
    {
        $row = self::row($key);
        if (!$row) return false;
        return self::scope($key, function ($row) use ($method) {
            return call_user_func([StorHelper::class, $method], $row['type']);
        });
    }

    public static function available($key, $tier)
    {
        if (!self::isReference($key)) return true;
        $row = self::row($key);
        return $row && intval($row['enabled']) === 1 && $tier >= intval($row['min_tier']);
    }

    private static function location($type, $config)
    {
        $pick = function ($keys) use ($config) {
            $values = [];
            foreach ($keys as $key) $values[] = isset($config[$key]) ? trim((string)$config[$key], " \t\r\n/") : '';
            return implode('|', $values);
        };
        if ($type === 'local') {
            $path = isset($config['filepath']) && $config['filepath'] !== '' ? $config['filepath'] : ROOT.'file/';
            $real = realpath($path);
            return $real !== false ? 'local|'.(DIRECTORY_SEPARATOR === '\\' ? strtolower($real) : $real) : null;
        }
        // 同一物理命名空间若被两个storage引用使用，单引用删除会误删另一引用的对象。
        $keys = [
            's3'=>['s3_endpoint','s3_bucket','s3_prefix'], 'oss'=>['oss_bucket'],
            'qcloud'=>['qcloud_bucket'], 'obs'=>['obs_bucket'], 'upyun'=>['upyun_name'],
            'qiniu'=>['qiniu_bucket'], 'webdav'=>['webdav_url','webdav_path'],
            'onedrive'=>['onedrive_client_id','onedrive_refresh_token','onedrive_path'],
            'openlist'=>['openlist_url','openlist_path'], 'sae'=>['storagename'], 'ace'=>['storagename']
        ];
        if (isset($keys[$type])) {
            $value = $pick($keys[$type]);
            return trim($value, '|') !== '' ? $type.'|'.hash('sha256', $value) : null;
        }
        if (StorExt::has($type)) {
            $raw = isset($config['stor_'.$type]) ? json_decode($config['stor_'.$type], true) : [];
            if (is_array($raw) && $raw) {
                ksort($raw);
                return $type.'|'.hash('sha256', json_encode($raw));
            }
        }
        return null;
    }

    private static function distinct($type, $config, $id)
    {
        global $conf;
        $location = self::location($type, $config);
        if ($location === null) return true;
        if ($location === self::location($type, $conf)) return false;
        foreach (self::all() as $row) {
            if (intval($row['id']) !== intval($id) && $row['type'] === $type
                && $location === self::location($type, $row['config'])) return false;
        }
        return true;
    }

    public static function save($id, $type, $name, $input, $enabled, $tier)
    {
        global $DB, $conf;
        $name = trim((string)$name);
        $names = StorHelper::names(false);
        if (!isset($names[$type]) || !$name || !preg_match('/^.{1,80}$/usD', $name)) return '名称或存储类型不正确';
        if (!is_array($input)) return '存储参数不正确';
        $old = $id ? self::row('inst_'.intval($id)) : null;
        if ($id && (!$old || $old['type'] !== $type)) return '已有实例的类型不能更改';
        $config = [];
        $values = [];
        foreach (self::fields($type) as $f) {
            $k = $f['name'];
            $v = isset($input[$k]) && is_scalar($input[$k]) ? trim((string)$input[$k]) : '';
            $prior = StorExt::has($type) && $old ? json_decode(isset($old['config']['stor_'.$type]) ? $old['config']['stor_'.$type] : '', true) : ($old ? $old['config'] : []);
            // 编辑密钥为空表示保留，密钥不再回填到网页。
            if ($v === '' && isset($f['type']) && $f['type'] === 'secret' && isset($prior[$k])) $v = $prior[$k];
            if (isset($f['options']) && !array_key_exists($v, $f['options'])) $v = isset($f['default']) ? (string)$f['default'] : (string)array_key_first($f['options']);
            if (strlen($v) > 16384) return '存储参数过长';
            if (!empty($f['required']) && $v === '') return $f['label'].'不能为空';
            $values[$k] = $v;
        }
        if (StorExt::has($type)) $config['stor_'.$type] = json_encode($values, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        else $config = $values;
        $config['downfile_domain'] = isset($input['downfile_domain']) && is_string($input['downfile_domain']) ? trim($input['downfile_domain']) : '';
        if ($config['downfile_domain'] !== '' && !preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/iD', $config['downfile_domain'])) return '下载域名请只填写域名，可带端口';
        $config['downfile_protocol'] = isset($input['downfile_protocol']) && $input['downfile_protocol'] === '0' ? '0' : '1';
        if ($type === 'local') {
            $path = isset($config['filepath']) ? $config['filepath'] : '';
            if ($path === '' || !preg_match('#^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path) || !is_dir($path)) return '本地实例需要单独的、已存在的绝对目录';
            $config['filepath'] = realpath($path);
        }
        if (!self::distinct($type, $config, $id)) return '该存储位置已被其他实例或原存储使用，请选择独立目录、桶或前缀';
        $key = $old ? 'inst_'.$old['id'] : '';
        if ($old) {
            // 地址、桶、目录和凭据变化可能使已有文件指向另一份数据，已有文件时冻结配置。
            $compare = $old['config'];
            foreach (['onedrive_access_token','onedrive_token_expire','openlist_cache_token','openlist_token_expire'] as $k) unset($compare[$k]);
            if ($config != $compare && (!empty($old['used_at']) || intval($DB->getColumn("SELECT COUNT(*) FROM pre_file WHERE storage=:s", [':s'=>$key])) > 0)) return '该实例已经使用，不能修改连接参数；请新建实例';
            if (isset($conf['storage']) && $conf['storage'] === $key && (!$enabled || $tier != 0)) return '默认存储必须启用并对所有人开放';
            if ($config == $compare) $config = $old['config']; // 保留轮换后的令牌缓存
        }
        $params = [':n'=>$name, ':t'=>$type, ':c'=>json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ':e'=>$enabled ? 1 : 0, ':tier'=>max(0,min(2,intval($tier)))];
        if ($old) {
            $params[':id'] = $old['id'];
            if ($config != $old['config']) {
                // 与另一请求开始上传竞态时，以数据库条件写入为准，不能只相信旧缓存。
                $stmt = $DB->query("UPDATE pre_storage_instance SET name=:n,type=:t,config=:c,enabled=:e,min_tier=:tier WHERE id=:id AND used_at IS NULL", $params);
                if (!$stmt || $stmt->rowCount() !== 1) return '实例已开始使用或配置已变化，请刷新后重试';
                $ok = true;
            } else {
                // 仅改展示/权限时不重写config，避免覆盖另一个请求刚轮换的令牌。
                unset($params[':c'], $params[':t']);
                $ok = $DB->exec("UPDATE pre_storage_instance SET name=:n,enabled=:e,min_tier=:tier WHERE id=:id", $params);
            }
        } else {
            $ok = $DB->exec("INSERT INTO pre_storage_instance (name,type,config,enabled,min_tier,addtime) VALUES (:n,:t,:c,:e,:tier,NOW())", $params);
        }
        if ($ok === false) return '实例保存失败，请先完成数据库升级';
        if ($old && $config != $old['config']) {
            \saveSetting('stor_'.$key, '');
            \saveSetting('stor_'.$key.'_state', '');
            unset($conf['stor_'.$key], $conf['stor_'.$key.'_state']);
        }
        self::reset();
        return true;
    }

    public static function remove($id)
    {
        global $DB, $conf;
        $key = 'inst_'.intval($id);
        if (!self::row($key)) return '实例不存在';
        if (isset($conf['storage']) && $conf['storage'] === $key) return '默认存储不能删除，请先切换默认存储';
        // 仅软停用：尚未完成的分块/直传尚无pre_file行，物理删除会丢失其目标配置。
        // 保留连接参数、令牌和映射，使既有链接和已开始的上传仍然指向原存储。
        if ($DB->exec("UPDATE pre_storage_instance SET enabled=0 WHERE id=:id", [':id'=>intval($id)]) === false) return '停用失败';
        self::reset();
        return true;
    }
}
