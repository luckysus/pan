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
  function rowsTable(ctx, rows) {
    var $table = $('<table class="table table-hover"><thead><tr></tr></thead><tbody></tbody></table>');
    [label(ctx, 'old'), label(ctx, 'new'), label(ctx, 'status')].forEach(function (text) {
      $('<th>').text(text).appendTo($table.find('thead tr'));
    });
    (rows || []).forEach(function (row) {
      var $tr = $('<tr>');
      $('<td>').text(row.old || ('#' + row.id)).appendTo($tr);
      $('<td>').text(row.name || '').appendTo($tr);
      $('<td>').text(row.msg || '').appendTo($tr);
      if (row.status === 'skip') $tr.addClass('text-warning');
      $tr.appendTo($table.find('tbody'));
    });
    return $('<div class="table-responsive">').append($table);
  }
  function openLinks(ctx, selected) {
    var loading = layer.load(2);
    request(ctx, 'fileBatchLinks', { ids: selected }).done(function (res) {
      layer.close(loading);
      if (!res || !res.links || !res.links.length) {
        layer.msg((res && res.msg) || label(ctx, 'failed')); return;
      }
      var text = res.links.join('\n');
      layer.open({
        type: 1, title: label(ctx, 'links_title'),
        area: [Math.min(680, $(window).width() - 24) + 'px', Math.min(530, $(window).height() - 48) + 'px'],
        btn: [label(ctx, 'copy'), label(ctx, 'close')], shadeClose: false,
        content: '<div class="uc-dialog"><p class="batch-summary"></p><textarea class="uc-input" readonly rows="9" style="width:100%;resize:vertical"></textarea><div class="batch-skipped"></div></div>',
        success: function (layero) {
          layero.find('.batch-summary').text(res.msg);
          layero.find('textarea').val(text);
          var skipped = (res.rows || []).filter(function (row) { return row.status !== 'ok'; });
          if (skipped.length) layero.find('.batch-skipped').append(rowsTable(ctx, skipped));
        },
        yes: function (index, layero) {
          function fallback() {
            var el = layero.find('textarea')[0]; el.focus(); el.select();
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
    layer.open({
      type: 1, title: label(ctx, 'rename'), shadeClose: false,
      area: [Math.min(760, $(window).width() - 24) + 'px', Math.min(650, $(window).height() - 48) + 'px'],
      btn: [label(ctx, 'preview'), label(ctx, 'close')],
      content: '<div class="uc-dialog file-batch-dialog"><p class="batch-hint"></p>'
        + '<div class="batch-rule"><label class="batch-mode-label"></label><select class="uc-input" name="mode"><option value="replace"></option><option value="affix"></option></select>'
        + '<div class="batch-replace"><label class="batch-find-label"></label><input class="uc-input" name="find" maxlength="120"><label class="batch-replacement-label"></label><input class="uc-input" name="replace" maxlength="120"></div>'
        + '<div class="batch-affix" hidden><label class="batch-prefix-label"></label><input class="uc-input" name="prefix" maxlength="120"><label class="batch-suffix-label"></label><input class="uc-input" name="suffix" maxlength="120"></div></div>'
        + '<button type="button" class="uc-btn batch-reset" hidden></button><p class="batch-summary"></p><div class="batch-results"></div></div>',
      success: function (layero) {
        var $form = layero.find('.file-batch-dialog');
        $form.find('.batch-hint').text(label(ctx, 'hint'));
        $form.find('.batch-mode-label').text(label(ctx, 'mode'));
        $form.find('option[value=replace]').text(label(ctx, 'replace_mode'));
        $form.find('option[value=affix]').text(label(ctx, 'affix_mode'));
        ['find', 'replacement', 'prefix', 'suffix'].forEach(function (key) {
          $form.find('.batch-' + key + '-label').text(label(ctx, key));
        });
        $form.find('.batch-reset').text(label(ctx, 'adjust')).on('click', function () {
          plan = null; $form.find('input,select').prop('disabled', false);
          $form.find('.batch-results,.batch-summary').empty();
          $(this).prop('hidden', true); layero.find('.layui-layer-btn0').text(label(ctx, 'preview'));
        });
        $form.find('[name=mode]').on('change', function () {
          $form.find('.batch-replace').prop('hidden', this.value !== 'replace');
          $form.find('.batch-affix').prop('hidden', this.value !== 'affix');
        });
      },
      yes: function (index, layero) {
        if (busy) return;
        var $form = layero.find('.file-batch-dialog'), data, action;
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
        busy = true; var loading = layer.load(2);
        request(ctx, action, data).done(function (res) {
          if (!res) { layer.msg(label(ctx, 'failed')); return; }
          if (action === 'fileBatchRenamePreview') {
            if (res.code !== 0) { layer.msg(res.msg || label(ctx, 'failed')); return; }
            plan = res; $form.find('input,select').prop('disabled', true);
            $form.find('.batch-reset').prop('hidden', false);
            $form.find('.batch-summary').text(res.msg);
            $form.find('.batch-results').empty().append(rowsTable(ctx, res.rows));
            layero.find('.layui-layer-btn0').text(label(ctx, 'apply'));
          } else {
            layer.close(index);
            if (res.ok && window.PanList) window.PanList.refresh();
            layer.open({
              type: 1, title: label(ctx, 'result'),
              area: [Math.min(700, $(window).width() - 24) + 'px', Math.min(530, $(window).height() - 48) + 'px'],
              btn: [label(ctx, 'close')], content: '<div class="uc-dialog"><p></p><div class="batch-result-rows"></div></div>',
              success: function (resultLayer) {
                resultLayer.find('p').text(res.msg || label(ctx, 'failed'));
                resultLayer.find('.batch-result-rows').append(rowsTable(ctx, res.rows || []));
              }
            });
          }
        }).fail(function () { layer.msg(label(ctx, 'network')); })
          .always(function () { busy = false; layer.close(loading); });
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
