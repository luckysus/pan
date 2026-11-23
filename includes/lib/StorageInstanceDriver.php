<?php
namespace lib;

/** 原有驱动的实例作用域适配器，不改变其上传校验/签名/流式读取实现。 */
class StorageInstanceDriver implements IStorage
{
    private $key;
    private $driver;
    private $error = '';

    public function __construct($key)
    {
        $this->key = $key;
        try {
            $this->driver = StorageInstances::scope($key, function ($row) use ($key) {
                if (StorExt::has($row['type'])) {
                    $cls = StorExt::cls($row['type']);
                    return new $cls(StorExt::config($row['type']), $key);
                }
                return StorHelper::getModel($row['type']);
            });
        } catch (\Throwable $e) {
            $this->error = '存储实例加载失败，请联系站长检查配置';
        }
    }

    private function call($method, $args = [])
    {
        if (!$this->driver || !method_exists($this->driver, $method)) return false;
        try {
            return StorageInstances::scope($this->key, function () use ($method, $args) {
                return call_user_func_array([$this->driver, $method], $args);
            });
        } catch (\Throwable $e) {
            $this->error = '存储实例访问失败，请联系站长检查目录、网络与配置';
            return false;
        }
    }
    public function getClient() { return $this->call('getClient'); }
    public function errmsg() { return $this->error ?: $this->call('errmsg'); }
    public function exists($name) { return $this->call('exists', [$name]); }
    public function get($name) { return $this->call('get', [$name]); }
    public function downfile($name, $range = false) {
        if ($range === false && StorageInstances::baseType($this->key) === 'local') {
            $info = $this->getinfo($name);
            $range = [0, max(0, intval($info['length'])-1)];
        }
        return $this->call('downfile', [$name, $range]);
    }
    public function upload($name, $tmpfile, $content_type = null) { return $this->call('upload', [$name,$tmpfile,$content_type]); }
    public function savefile($name, $tmpfile, $content_type = null) { return $this->call('savefile', [$name,$tmpfile,$content_type]); }
    public function getinfo($name) { return $this->call('getinfo', [$name]); }
    public function delete($name) { return $this->call('delete', [$name]); }
    public function getUploadParam($name, $filename, $max_file_size = 0) { return $this->call('getUploadParam', [$name,$filename,$max_file_size]); }
    public function getDownUrl($name, $filename, $content_type = null) { return $this->call('getDownUrl', [$name,$filename,$content_type]); }
    public function downloadTo($name, $tmpfile) { return $this->call('downloadTo', [$name,$tmpfile]); }
    public function publishStaged($key, $hash, $size, $type) { return $this->call('publishStaged', [$key,$hash,$size,$type]); }
    public function filepath($name) { return $this->call('filepath', [$name]); }
    public function test() { return $this->call('test'); }
}
