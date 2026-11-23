<?php
if(!defined('IN_CRONLITE'))exit();
return [
    'name'=>'文本与代码预览',
    'group'=>'文本预览',
    'scope'=>'front',
    'pages'=>['text-preview'],
    'required'=>true,
    'description'=>'安全展示 Markdown 与代码；文件密码、审核和分享流量规则不能关闭',
    'schema'=>[
        ['key'=>'heading', 'type'=>'text', 'label'=>'页面标题', 'default'=>'文本与代码预览'],
        ['key'=>'render_text', 'type'=>'text', 'label'=>'Markdown 页签', 'default'=>'阅读'],
        ['key'=>'source_text', 'type'=>'text', 'label'=>'源码页签', 'default'=>'源码'],
        ['key'=>'lines_text', 'type'=>'text', 'label'=>'行号开关', 'default'=>'显示行号'],
        ['key'=>'wrap_text', 'type'=>'text', 'label'=>'换行开关', 'default'=>'自动换行'],
        ['key'=>'raw_text', 'type'=>'text', 'label'=>'原文按钮', 'default'=>'原始文本'],
        ['key'=>'download_text', 'type'=>'text', 'label'=>'下载按钮', 'default'=>'下载文件'],
        ['key'=>'back_text', 'type'=>'text', 'label'=>'返回按钮', 'default'=>'文件详情'],
        ['key'=>'privacy_text', 'type'=>'text', 'label'=>'预览说明', 'default'=>'文件中的 HTML 和脚本不会执行，远程图片不会加载。'],
        ['key'=>'password_label', 'type'=>'text', 'label'=>'密码标签', 'default'=>'文件访问密码'],
        ['key'=>'password_submit', 'type'=>'text', 'label'=>'密码确认按钮', 'default'=>'查看文件'],
    ],
    'data'=>function($cfg, &$ctx){
        return $ctx['data'];
    },
];
