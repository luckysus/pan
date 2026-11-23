<?php
namespace lib;

/*
 * 文本预览与原始文本使用相同的访问规则。这里不生成 HTML，也不接收任意 URL，
 * 只能读取数据库中已经登记的文件，避免把预览页变成另一条回源或绕过密码的通道。
 */
class TextPreview
{
    public static function load($token, $password = null)
    {
        global $DB;
        if(!is_string($token) || !preg_match('/^[0-9a-f]{32}$/i', $token)){
            return self::error(404, '文件不存在');
        }
        $row = $DB->getRow('SELECT * FROM pre_file WHERE token=:token LIMIT 1', [':token'=>$token]);
        if(!$row)return self::error(404, '文件不存在');
        if(intval($row['block']) >= 1)return self::error(403, '文件已被拦截或正在审核，暂时不能预览');
        if(!is_editable_file_type($row['type']))return self::error(415, '此文件类型不支持文本预览');
        if(intval($row['size']) > get_editable_file_max_size())return self::error(413, '文件太大，不能在线预览');
        if(!check_file_pwd($row, $password))return self::error(401, '请输入正确的文件访问密码', true);

        $storage = StorHelper::get($row['storage']);
        if(!is_object($storage) || !$storage->exists($row['hash']))return self::error(404, '文件不存在');
        $content = get_storage_content($row['hash'], $row['storage']);
        if($content === false)return self::error(502, '读取文件失败，请稍后再试');
        // 不只相信记录里的长度，避免远端对象被替换后把超大内容交给浏览器。
        if(strlen($content) > get_editable_file_max_size())return self::error(413, '文件太大，不能在线预览');
        $decoded = decode_editable_content($content);
        if($decoded['code'] != 0)return self::error(415, $decoded['msg']);

        // HTML 壳和正文都从统一输出函数传出，按本次实际返回的字节计分享流量。
        file_traffic_guard($row['uid']);
        return ['ok'=>true, 'status'=>200, 'row'=>$row, 'content'=>$decoded['content']];
    }

    private static function error($status, $message, $password = false)
    {
        return ['ok'=>false, 'status'=>$status, 'message'=>$message, 'password'=>$password];
    }

    public static function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function policy()
    {
        return "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'none'; "
            ."connect-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; "
            ."frame-ancestors 'self'; form-action 'self'";
    }
}
