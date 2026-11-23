<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Exercise the actual card/layout engine for every built-in theme, without a DB.
define('ROOT', dirname(__DIR__).'/');
define('SYSTEM_ROOT', ROOT.'includes/');
define('IN_CRONLITE', true);
$conf = ['version'=>0, 'title'=>'Preview regression'];
$DB = null;
require SYSTEM_ROOT.'mustache/autoload.php';
require SYSTEM_ROOT.'theme_engine.php';
$themes = theme_registry();
$checks = 0;
function preview_expect($condition, $label){
    global $checks;
    $checks++;
    if(!$condition)throw new RuntimeException($label);
}
$definition = block_get('text_preview.main');
preview_expect(is_array($definition), 'preview card is registered');
preview_expect(!empty($definition['required']), 'preview card cannot be removed');
$pages = layout_editor_pages_fixed();
preview_expect(isset($pages['text-preview']), 'preview page is configurable');
preview_expect($pages['text-preview'][1] === 'front', 'preview belongs to frontend');
foreach($themes as $id=>$theme){
    $source = '<script>alert("unsafe")</script>';
    $html = theme_render_layout('text-preview', [
        'error'=>false, 'name'=>'Test.md', 'type'=>'md', 'size'=>'20 B',
        'content'=>$source, 'raw_url'=>'text.php?hash=test', 'file_url'=>'file.php?hash=test',
        'download_url'=>'down.php/test',
    ], 'front', $id);
    preview_expect(strpos($html, 'data-text-preview') !== false, $id.' renders preview card');
    preview_expect(strpos($html, '&lt;script&gt;') !== false, $id.' escapes source');
    preview_expect(strpos($html, $source) === false, $id.' never emits source HTML');
    preview_expect(layout_check_required('text-preview', [], $id) !== true, $id.' required card enforced');
}
echo count($themes)." themes, ".$checks." preview layout checks passed\n";
