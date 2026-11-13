/* CertGen front-end behaviour. Vanilla JS, no build step. */
(function () {
  'use strict';

  // ---- confirm dialogs: <button data-confirm="Are you sure?"> -------------
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
    }
  });

  // ---- record selection on the batch page --------------------------------
  var master = document.querySelector('.js-select-all');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.js-row-check'));
  var counter = document.getElementById('selCount');
  var needSel = document.querySelectorAll('.needs-selection');

  function refreshSelection() {
    var n = 0;
    checks.forEach(function (c) {
      var tr = c.closest('tr');
      if (tr) tr.classList.toggle('selected', c.checked);
      if (c.checked) n++;
    });
    if (counter) counter.textContent = n ? n + ' selected' : 'None selected';
    needSel.forEach(function (b) { b.disabled = n === 0; });
    if (master) master.checked = n > 0 && n === checks.length;

    // keep the "Generate" panel's Selected / All choice in step with the ticks
    var rs = document.getElementById('scopeSelected'), ra = document.getElementById('scopeAll'), sn = document.getElementById('selN');
    if (rs && ra && sn) {
      rs.disabled = n === 0;
      sn.textContent = n;
      if (n > 0) rs.checked = true; else ra.checked = true;
    }
  }

  // the generate form is separate from the table form, so copy the ticked ids across on submit
  var gen = document.getElementById('generate-form');
  if (gen) {
    gen.addEventListener('submit', function () {
      gen.querySelectorAll('input[data-copied]').forEach(function (i) { i.remove(); });
      checks.forEach(function (c) {
        if (!c.checked) return;
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'ids[]'; h.value = c.value; h.setAttribute('data-copied', '1');
        gen.appendChild(h);
      });
    });
  }
  if (checks.length) {
    checks.forEach(function (c) { c.addEventListener('change', refreshSelection); });
    if (master) {
      master.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = master.checked; });
        refreshSelection();
      });
    }
    refreshSelection();
  }

  // ---- template editor: dynamic rows --------------------------------------
  function slug(s) {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  }

  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add]');
    if (add) {
      var tpl = document.getElementById(add.getAttribute('data-add'));
      var target = document.querySelector(add.getAttribute('data-into'));
      if (tpl && target) {
        target.appendChild(tpl.content.cloneNode(true));
        var last = target.lastElementChild;
        var first = last && last.querySelector('input, select');
        if (first) first.focus();
      }
      return;
    }
    var rm = e.target.closest('[data-remove]');
    if (rm) {
      var row = rm.closest('.editor-row');
      if (row) row.remove();
      return;
    }
    var mv = e.target.closest('[data-move]');
    if (mv) {
      var r = mv.closest('.editor-row');
      if (!r) return;
      if (mv.getAttribute('data-move') === 'up' && r.previousElementSibling) {
        r.parentNode.insertBefore(r, r.previousElementSibling);
      } else if (mv.getAttribute('data-move') === 'down' && r.nextElementSibling) {
        r.parentNode.insertBefore(r.nextElementSibling, r);
      }
      return;
    }
    var chip = e.target.closest('[data-insert]');
    if (chip && lastTarget) {
      var text = chip.getAttribute('data-insert');
      var s = lastTarget.selectionStart, en = lastTarget.selectionEnd, v = lastTarget.value;
      lastTarget.value = v.slice(0, s) + text + v.slice(en);
      lastTarget.focus();
      lastTarget.selectionStart = lastTarget.selectionEnd = s + text.length;
    }
  });

  // remember which layout input was last focused, so placeholder chips know where to insert
  var lastTarget = null;
  document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches('.ph-target')) lastTarget = e.target;
  });

  document.addEventListener('change', function (e) {
    if (e.target.matches('.b-type')) {
      e.target.closest('.editor-row').setAttribute('data-type', e.target.value);
    }
  });

  // auto-fill a field's key from its label until the user edits the key by hand
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.matches('.f-label')) {
      var key = t.closest('.editor-row').querySelector('.f-key');
      if (key && key.getAttribute('data-auto') !== '0') key.value = slug(t.value);
    } else if (t.matches('.f-key')) {
      t.setAttribute('data-auto', '0');
    }
  });

  // ---- new batch: keep "download sample sheet" links in step with the chosen template ----
  var sel = document.getElementById('tplSelect');
  if (sel) {
    var links = document.querySelectorAll('.sample-link');
    var update = function () {
      links.forEach(function (a) {
        a.href = 'template_sample.php?id=' + encodeURIComponent(sel.value) + '&format=' + a.getAttribute('data-format');
        a.classList.toggle('disabled', !sel.value);
      });
    };
    sel.addEventListener('change', update);
    update();
  }
})();
