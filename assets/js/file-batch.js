/*
 * 个人中心和游客“我的文件”共用的批量外链/改名。
 * 所有名称只经 text()/val() 输出；确认只发送服务器的一次性预览令牌。
 */
(function ($) {
  'use strict';
  if (!$ || window.PanFileBatch) return;
  window.PanFileBatch = true;

  function context(button) {
    var $bar = $(button).closest('[data-file-batch-owner]');
    var account = $bar.attr('data-file-batch-owner') === 'account';
    var labels = {};
    try { labels = JSON.parse($bar.attr('data-batch-labels') || '{}'); } catch (ignored) {}
    return {
      labels: labels,
      account: account,
      $list: account ? $('.uc-filelist:not(.guest-filelist)') : $('.guest-filelist'),
      endpoint: account ? './user.php?act=' : './ajax.php?act='
    };
  }
  function label(ctx, key) {
    return String(ctx.labels['batch_' + key] || key);
  }
  function ids(ctx) {
    var list = [];
    ctx.$list.find('tbody ' + (ctx.account ? '.uc-check' : '.guest-check') + ':checked').each(function () {
      list.push($(this).closest('tr').data('id'));
    });
    return list;
  }
  function request(ctx, action, data) {
    data.csrf_token = ctx.account ? window.uc_csrf
      : (window.PAN_FOLDER ? window.PAN_FOLDER.csrf : window.replace_csrf_token);
    return $.ajax({ type: 'POST', url: ctx.endpoint + action, data: data, dataType: 'json' });
  }
  function recenter(index, $layer) {
    layer.style(index, {
      top: Math.max(16, ($(window).height() - $layer.outerHeight()) / 2) + 'px',
      left: Math.max(12, ($(window).width() - $layer.outerWidth()) / 2) + 'px'
    });
  }
  function openDialog(options) {
    var success = options.success, end = options.end, resize;
    options.type = 1;
    options.skin = 'pan-batch-layer ' + (options.skin || '');
    options.area = '860px';
    options.shadeClose = false;
    options.success = function ($layer, index) {
      resize = function () { recenter(index, $layer); };
      $(window).on('resize', resize);
      if (success) success($layer, index);
      resize();
    };
    options.end = function () {
      if (resize) $(window).off('resize', resize);
      // layer 会将传入的 jQuery 节点还原到 body；这些节点为本弹窗新建，关闭后一并移除。
      if (options.content && options.content.jquery) options.content.remove();
      if (end) end();
    };
    // Layer 3 的 jQuery content 通过 wrap 原地包裹，必须先有真实的 DOM 插入点。
    if (options.content && options.content.jquery) options.content.hide().appendTo(document.body);
    return layer.open(options);
  }
  function selectedNote(ctx, selected) {
    return $('<div class="batch-selection">').text(label(ctx, 'selected').replace('{count}', selected.length));
  }
  function rowsTable(ctx, rows, linksOnly) {
    var $table = $('<table class="batch-table"><thead><tr></tr></thead><tbody></tbody></table>');
    var headings = linksOnly ? [label(ctx, 'old'), label(ctx, 'status')]
      : [label(ctx, 'old'), label(ctx, 'new'), label(ctx, 'status')];
    headings.forEach(function (text) {
      $('<th scope="col">').text(text).appendTo($table.find('thead tr'));
    });
    (rows || []).forEach(function (row) {
      var $tr = $('<tr>');
      var values = [row.old || ('#' + row.id)];
      if (!linksOnly) values.push(row.name || '');
      values.push(row.msg || '');
      values.forEach(function (text, index) {
        var $td = $('<td>').attr('data-label', headings[index]).appendTo($tr);
        var $text = $('<span>').text(text).appendTo($td);
        if (index === values.length - 1) $text.addClass('batch-status ' + (row.status === 'skip' ? 'batch-status-skip' : 'batch-status-ok'));
      });
      $tr.appendTo($table.find('tbody'));
    });
    return $('<div class="batch-table-wrap">').append($table);
  }
  function openLinks(ctx, selected) {
    var loading = layer.load(2);
    request(ctx, 'fileBatchLinks', { ids: selected }).done(function (res) {
      layer.close(loading);
      if (!res || !res.links || !res.links.length) {
        layer.msg((res && res.msg) || label(ctx, 'failed')); return;
      }
      var text = res.links.join('\n');
      var $content = $('<div class="pan-batch-dialog batch-links-dialog">').append(selectedNote(ctx, selected));
      $('<p class="batch-summary" role="status">').text(res.msg).appendTo($content);
      var $field = $('<label class="batch-field">').appendTo($content);
      $('<span class="batch-field-label">').text(label(ctx, 'links_label')).appendTo($field);
      $('<textarea class="batch-input batch-links-text" readonly rows="9" spellcheck="false">').val(text).appendTo($field);
      $('<p class="batch-help">').text(label(ctx, 'links_hint')).appendTo($content);
      var skipped = (res.rows || []).filter(function (row) { return row.status !== 'ok'; });
      if (skipped.length) {
        var $skipped = $('<section class="batch-section batch-skipped">').appendTo($content);
        $('<h3 class="batch-section-title">').text(label(ctx, 'skipped')).appendTo($skipped);
        $skipped.append(rowsTable(ctx, skipped, true));
      }
      openDialog({
        title: label(ctx, 'links_title'), skin: 'batch-links-layer',
        btn: [label(ctx, 'copy'), label(ctx, 'close')], content: $content,
        yes: function (index, $layer) {
          function fallback() {
            var el = $layer.find('textarea')[0];
            if (!el || !document.documentElement.contains(el)) return;
            el.focus(); el.select();
            var copied = false;
            try { copied = document.execCommand('copy'); } catch (ignored) {}
            layer.msg(label(ctx, copied ? 'copied' : 'manual_copy'));
          }
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { layer.msg(label(ctx, 'copied')); }, fallback);
          } else fallback();
        }
      });
    }).fail(function () { layer.close(loading); layer.msg(label(ctx, 'network')); });
  }
  function openRename(ctx, selected) {
    var plan = null, busy = false;
    var $form = $('<div class="pan-batch-dialog file-batch-dialog">').append(selectedNote(ctx, selected));
    $('<p class="batch-hint">').text(label(ctx, 'hint')).appendTo($form);
    var $rule = $('<div class="batch-rule">').appendTo($form);
    var $mode = $('<label class="batch-field batch-mode">').appendTo($rule);
    $('<span class="batch-field-label">').text(label(ctx, 'mode')).appendTo($mode);
    var $select = $('<select class="batch-input" name="mode">').appendTo($mode);
    $('<option value="replace">').text(label(ctx, 'replace_mode')).appendTo($select);
    $('<option value="affix">').text(label(ctx, 'affix_mode')).appendTo($select);
    function fields(className, entries, hidden) {
      var $fields = $('<div class="batch-fields">').addClass(className).prop('hidden', hidden).appendTo($rule);
      entries.forEach(function (entry) {
        var $field = $('<label class="batch-field">').appendTo($fields);
        $('<span class="batch-field-label">').text(label(ctx, entry[1])).appendTo($field);
        $('<input type="text" class="batch-input" maxlength="120" autocomplete="off">').attr('name', entry[0]).appendTo($field);
      });
    }
    fields('batch-replace', [['find', 'find'], ['replace', 'replacement']], false);
    fields('batch-affix', [['prefix', 'prefix'], ['suffix', 'suffix']], true);
    var $preview = $('<section class="batch-section batch-preview" hidden>').appendTo($form);
    var $heading = $('<div class="batch-section-heading">').appendTo($preview);
    $('<h3 class="batch-section-title">').text(label(ctx, 'preview_title')).appendTo($heading);
    var $reset = $('<button type="button" class="batch-reset" hidden>').text(label(ctx, 'adjust')).appendTo($heading);
    $('<p class="batch-summary" role="status">').appendTo($preview);
    $('<div class="batch-results">').appendTo($preview);
    openDialog({
      title: label(ctx, 'rename'), btn: [label(ctx, 'preview'), label(ctx, 'close')], content: $form,
      success: function ($layer, index) {
        $reset.on('click', function () {
          if (busy) return;
          plan = null; $form.find('input,select').prop('disabled', false);
          $form.find('.batch-results,.batch-summary').empty();
          $preview.prop('hidden', true); $reset.prop('hidden', true);
          $layer.find('.layui-layer-btn0').text(label(ctx, 'preview'));
          recenter(index, $layer);
        });
        $select.on('change', function () {
          $form.find('.batch-replace').prop('hidden', this.value !== 'replace');
          $form.find('.batch-affix').prop('hidden', this.value !== 'affix');
          recenter(index, $layer);
        });
      },
      yes: function (index, $layer) {
        if (busy) return;
        var data, action;
        if (plan) {
          if (!plan.token || !plan.ready) { layer.msg(label(ctx, 'no_change')); return; }
          data = { token: plan.token }; action = 'fileBatchRenameApply';
        } else {
          data = { ids: selected };
          ['mode', 'find', 'replace', 'prefix', 'suffix'].forEach(function (key) {
            data[key] = $form.find('[name=' + key + ']').val();
          });
          action = 'fileBatchRenamePreview';
        }
        busy = true; $form.attr('aria-busy', 'true'); $layer.addClass('batch-working');
        var loading = layer.load(2);
        request(ctx, action, data).done(function (res) {
          if (!res) { layer.msg(label(ctx, 'failed')); return; }
          if (action === 'fileBatchRenamePreview') {
            if (res.code !== 0) { layer.msg(res.msg || label(ctx, 'failed')); return; }
            plan = res; $form.find('input,select').prop('disabled', true);
            $preview.prop('hidden', false); $reset.prop('hidden', false);
            $form.find('.batch-summary').text(res.msg);
            $form.find('.batch-results').empty().append(rowsTable(ctx, res.rows));
            $layer.find('.layui-layer-btn0').text(label(ctx, 'apply'));
            recenter(index, $layer);
          } else {
            layer.close(index);
            if (res.ok && window.PanList) window.PanList.refresh();
            var $result = $('<div class="pan-batch-dialog batch-result-dialog">');
            $('<p class="batch-summary" role="status">').text(res.msg || label(ctx, 'failed')).appendTo($result);
            $('<div class="batch-result-rows">').append(rowsTable(ctx, res.rows || [])).appendTo($result);
            openDialog({ title: label(ctx, 'result'), btn: [label(ctx, 'close')], content: $result });
          }
        }).fail(function () { layer.msg(label(ctx, 'network')); })
          .always(function () {
            busy = false; $form.attr('aria-busy', 'false'); $layer.removeClass('batch-working'); layer.close(loading);
          });
      }
    });
  }
  $(document).on('click', '[data-file-batch]', function () {
    var ctx = context(this), selected = ids(ctx);
    if (!selected.length) { layer.msg(label(ctx, 'select')); return; }
    if (selected.length > 100) { layer.msg(label(ctx, 'limit')); return; }
    if ($(this).attr('data-file-batch') === 'copy') openLinks(ctx, selected);
    else openRename(ctx, selected);
  });
})(window.jQuery);
