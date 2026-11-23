<?php
// 原生驱动表单元数据：传统设置页与多实例共用。
return [
	'local' => [
		['name'=>'filepath', 'label'=>'本地存储路径', 'placeholder'=>'默认存储在网站 file 目录', 'tip'=>'不填就存在网站 <b>file</b> 目录下。要改的话只能填以 / 开头的服务器绝对路径，并保证 PHP 对该目录有写权限。'],
	],
	'oss' => [
		['name'=>'oss_ak', 'label'=>'AccessKey Id'],
		['name'=>'oss_sk', 'label'=>'AccessKey Secret', 'type'=>'secret'],
		['name'=>'oss_endpoint', 'label'=>'EndPoint', 'placeholder'=>'oss-cn-hangzhou.aliyuncs.com', 'tip'=>'Bucket 概览页里的「外网访问」域名，本站和 OSS 在同一地域时可以填内网 EndPoint 省流量。'],
		['name'=>'oss_bucket', 'label'=>'Bucket 名称'],
	],
	'qcloud' => [
		['name'=>'qcloud_id', 'label'=>'SecretId'],
		['name'=>'qcloud_key', 'label'=>'SecretKey', 'type'=>'secret'],
		['name'=>'qcloud_region', 'label'=>'存储桶地域', 'placeholder'=>'ap-shanghai', 'tip'=>'填英文名称，例如 ap-shanghai、ap-guangzhou。'],
		['name'=>'qcloud_bucket', 'label'=>'存储桶名称', 'placeholder'=>'BucketName-APPID', 'tip'=>'注意要带上后面的 APPID，格式是 <b>BucketName-APPID</b>。'],
	],
	'obs' => [
		['name'=>'obs_ak', 'label'=>'AccessKeyId'],
		['name'=>'obs_sk', 'label'=>'SecretAccessKey', 'type'=>'secret'],
		['name'=>'obs_endpoint', 'label'=>'EndPoint', 'placeholder'=>'obs.cn-north-4.myhuaweicloud.com'],
		['name'=>'obs_bucket', 'label'=>'桶名称'],
	],
	'upyun' => [
		['name'=>'upyun_name', 'label'=>'云存储服务名称'],
		['name'=>'upyun_user', 'label'=>'操作员名称'],
		['name'=>'upyun_pwd', 'label'=>'操作员密码', 'type'=>'secret'],
	],
	'qiniu' => [
		['name'=>'qiniu_ak', 'label'=>'AccessKey'],
		['name'=>'qiniu_sk', 'label'=>'SecretKey', 'type'=>'secret'],
		['name'=>'qiniu_bucket', 'label'=>'存储空间名称'],
		['name'=>'qiniu_domain', 'label'=>'空间绑定域名', 'tip'=>'七牛的下载必须走绑定域名，这里不填的话文件下不下来。'],
	],
	's3' => [
		['name'=>'s3_ak', 'label'=>'Access Key'],
		['name'=>'s3_sk', 'label'=>'Secret Key', 'type'=>'secret'],
		['name'=>'s3_endpoint', 'label'=>'Endpoint', 'placeholder'=>'https://s3.example.com', 'tip'=>'请填完整地址，支持 AWS S3、MinIO、Cloudflare R2、Backblaze B2 等兼容服务。'],
		['name'=>'s3_region', 'label'=>'Region', 'placeholder'=>'us-east-1', 'default'=>'us-east-1'],
		['name'=>'s3_bucket', 'label'=>'Bucket'],
		['name'=>'s3_prefix', 'label'=>'对象前缀', 'placeholder'=>'file/', 'default'=>'file/', 'tip'=>'留空表示直接存到 Bucket 根目录。'],
		['name'=>'s3_path_style', 'label'=>'地址风格', 'type'=>'select', 'options'=>['0'=>'虚拟主机风格（bucket.endpoint）', '1'=>'路径风格（endpoint/bucket）'], 'tip'=>'MinIO，或者 Bucket 名里带点号的，通常要选路径风格。'],
	],
	'webdav' => [
		['name'=>'webdav_url', 'label'=>'WebDAV 地址', 'placeholder'=>'https://dav.jianguoyun.com/dav/', 'tip'=>'服务商给的 WebDAV 根地址。坚果云是 <b>https://dav.jianguoyun.com/dav/</b>，Nextcloud 一般是 <b>https://域名/remote.php/dav/files/用户名/</b>。'],
		['name'=>'webdav_user', 'label'=>'账号'],
		['name'=>'webdav_pass', 'label'=>'密码', 'type'=>'secret', 'tip'=>'坚果云要用「安全选项」里生成的<b>应用密码</b>，不是登录密码。'],
		['name'=>'webdav_path', 'label'=>'存储目录', 'placeholder'=>'file', 'tip'=>'相对 WebDAV 根地址的子目录，留空默认为 <b>file</b>，目录不存在会自动创建。'],
	],
	'onedrive' => [
		['name'=>'onedrive_type', 'label'=>'账号类型', 'type'=>'select', 'options'=>['common'=>'国际版（个人版 / 商业版）', 'china'=>'世纪互联版（中国区）'], 'tip'=>'改了账号类型要重新走一次授权。'],
		['name'=>'onedrive_client_id', 'label'=>'应用 ID', 'placeholder'=>'Azure 应用的 Application (client) ID'],
		['name'=>'onedrive_client_secret', 'label'=>'应用机密', 'type'=>'secret', 'tip'=>'Azure 里「证书和密码」生成的客户端密码<b>值（Value）</b>，不是密码 ID。'],
		['name'=>'onedrive_path', 'label'=>'存储目录', 'placeholder'=>'pan/file', 'tip'=>'网盘里的目录，留空默认为 <b>pan/file</b>，目录不存在会自动创建。'],
	],
	'openlist' => [
		['name'=>'openlist_url', 'label'=>'OpenList 地址', 'placeholder'=>'https://list.example.com', 'tip'=>'填站点根地址，<b>不要带 /api</b>。自建的没上 HTTPS 就要把 <b>http://</b> 一起写上，例如 <b>http://192.168.1.5:5244</b>。'],
		['name'=>'openlist_user', 'label'=>'账号', 'tip'=>'建议单独建一个只对存储目录有读写权限的用户，别用 admin。'],
		['name'=>'openlist_pass', 'label'=>'密码', 'type'=>'secret'],
		['name'=>'openlist_token', 'label'=>'固定令牌', 'type'=>'secret', 'tip'=>'可不填。填了就直接用它调接口，不再用上面的账号密码登录；OpenList 后台「其他设置」里的令牌就是这个。'],
		['name'=>'openlist_path', 'label'=>'存储目录', 'placeholder'=>'/aliyun/pan/file', 'tip'=>'OpenList 里的完整路径，<b>第一段必须是已经挂载好的存储名</b>，例如挂载路径是 <b>/aliyun</b> 就填 <b>/aliyun/pan/file</b>。留空默认为 <b>/pan/file</b>，目录不存在会自动创建。'],
	],
];
