<?php
/**
 * 旧云 SDK 调用的四个 Guzzle 函数迁移入口。
 * 转交给已维护实现，不复制旧解析器、HTTP 处理器或绕过安全校验。
 */
namespace GuzzleHttp\Psr7 {
    if (!function_exists(__NAMESPACE__ . '\\stream_for')) {
        function stream_for($resource = '', array $options = []) {
            return Utils::streamFor($resource, $options);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\copy_to_stream')) {
        function copy_to_stream(\Psr\Http\Message\StreamInterface $source, \Psr\Http\Message\StreamInterface $dest, $maxLen = -1) {
            return Utils::copyToStream($source, $dest, $maxLen);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\mimetype_from_filename')) {
        function mimetype_from_filename($filename) {
            return MimeType::fromFilename($filename);
        }
    }
}
namespace GuzzleHttp\Promise {
    if (!function_exists(__NAMESPACE__ . '\\rejection_for')) {
        function rejection_for($reason) {
            return Create::rejectionFor($reason);
        }
    }
}
