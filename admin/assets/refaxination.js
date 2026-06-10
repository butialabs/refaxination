/* Refaxination admin */
(function () {
    'use strict';

    var __ = wp.i18n.__;

    function rfxPost(callback) {
        var body = new URLSearchParams({
            action: 'refaxination_status_poll',
            _ajax_nonce: refaxinationAdmin.nonce,
        });
        fetch(refaxinationAdmin.ajaxurl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(callback)
            .catch(function () { callback(null); });
    }

    var phaseLabels = {
        counting: __('Counting files…', 'refaxination'),
        indexing: __('Indexing', 'refaxination'),
        thumbnails: __('Detecting thumbnails…', 'refaxination'),
    };

    // live operation banner (rendered below h1, visible on all tabs)
    if (document.getElementById('rfx-live-operation')) {
        var elProcessed = document.getElementById('rfx-live-processed');
        var elTotal = document.getElementById('rfx-live-total');
        var elPct = document.getElementById('rfx-live-pct');
        var elBar = document.getElementById('rfx-progress-bar');
        var elPhase = document.getElementById('rfx-phase-label');

        (function pollOp() {
            rfxPost(function (res) {
                if (!res || !res.success || !res.data) {
                    setTimeout(function () { location.reload(); }, 800);
                    return;
                }
                var d = res.data;
                var phase = d.phase || 'running';

                if (elPhase && phaseLabels[phase]) elPhase.textContent = phaseLabels[phase];
                if (elProcessed) elProcessed.textContent = d.items_processed.toLocaleString();
                if (elTotal) elTotal.textContent = d.items_total > 0 ? d.items_total.toLocaleString() : '?';
                if (elPct) elPct.textContent = d.pct + '%';
                if (elBar) elBar.style.width = d.pct + '%';

                if (d.status === 'running') {
                    setTimeout(pollOp, 3000);
                } else {
                    setTimeout(function () { location.reload(); }, 800);
                }
            });
        }());
    }

    // image preview tooltip
    var rfxTooltip = (function () {
        var el = null;
        var img = null;

        function init() {
            el = document.createElement('div');
            el.className = 'rfx-preview-tooltip';
            el.setAttribute('hidden', '');
            img = document.createElement('img');
            img.alt = '';
            el.appendChild(img);
            document.body.appendChild(el);
        }

        function position(x, y) {
            var tw = 270, th = 270;
            var left = x + 14;
            var top  = y + 14;
            if (left + tw > window.innerWidth)  left = x - tw - 14;
            if (top  + th > window.innerHeight) top  = y - th - 14;
            el.style.left = left + 'px';
            el.style.top  = top  + 'px';
        }

        return {
            show: function (url, x, y) {
                if (!el) init();
                img.src = url;
                el.removeAttribute('hidden');
                position(x, y);
            },
            hide: function () {
                if (el) el.setAttribute('hidden', '');
            },
            move: function (x, y) {
                if (el && !el.hasAttribute('hidden')) position(x, y);
            },
        };
    }());

    document.addEventListener('mouseover', function (e) {
        var link = e.target.closest('[data-preview]');
        if (link) {
            rfxTooltip.show(link.dataset.preview, e.clientX, e.clientY);
        } else {
            rfxTooltip.hide();
        }
    });

    document.addEventListener('mousemove', function (e) {
        rfxTooltip.move(e.clientX, e.clientY);
    });

    // thumbnail row toggle (files tab)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.rfx-thumb-toggle');
        if (!btn) return;
        var row = document.getElementById(btn.dataset.target);
        if (!row) return;
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        var icon = btn.querySelector('.dashicons');
        if (icon) {
            icon.classList.toggle('dashicons-arrow-right', expanded);
            icon.classList.toggle('dashicons-arrow-down', !expanded);
        }
        if (expanded) {
            row.setAttribute('hidden', '');
        } else {
            row.removeAttribute('hidden');
        }
    });

    // command builder
    if (!document.getElementById('rfx-cli-gen')) return;

    var CMDS = {
        'scan-files': {
            build: function () {
                var parts = ['wp refaxination scan files'];
                var batchChk = document.getElementById('sf-batch-chk');
                var resetChk = document.getElementById('sf-reset-chk');
                var resumeChk = document.getElementById('sf-resume-chk');
                if (batchChk.checked) {
                    parts.push('--batch=' + (parseInt(document.getElementById('sf-batch-val').value, 10) || 100));
                }
                if (resetChk.checked) parts.push('--reset');
                if (resumeChk.checked) parts.push('--resume');
                var note = (resetChk.checked && resumeChk.checked)
                    ? __('⛔ --reset and --resume are mutually exclusive. Remove one of them.', 'refaxination')
                    : '';
                return { cmd: parts.join(' '), note: note };
            },
        },
        'scan-refs': {
            build: function () {
                var parts = ['wp refaxination scan refs'];
                var batchChk = document.getElementById('sr-batch-chk');
                var resumeChk = document.getElementById('sr-resume-chk');
                var srcChk = document.getElementById('sr-source-chk');
                if (batchChk.checked) {
                    parts.push('--batch=' + (parseInt(document.getElementById('sr-batch-val').value, 10) || 100));
                }
                if (resumeChk.checked) parts.push('--resume');
                if (srcChk.checked) {
                    var checked = Array.from(document.querySelectorAll('.rfx-source-input:checked'))
                        .map(function (el) { return el.value; });
                    if (checked.length > 0 && checked.length < 8) {
                        parts.push('--source=' + checked.join(','));
                    }
                }
                return { cmd: parts.join(' '), note: '' };
            },
        },
        'quarantine': {
            build: function () {
                var parts = ['wp refaxination quarantine'];
                var dryChk = document.getElementById('q-dryrun-chk');
                var batchChk = document.getElementById('q-batch-chk');
                var library_onlyChk = document.getElementById('q-library_only-chk');
                if (dryChk.checked) parts.push('--dry-run');
                if (batchChk.checked) {
                    parts.push('--batch=' + (parseInt(document.getElementById('q-batch-val').value, 10) || 100));
                }
                if (library_onlyChk.checked) parts.push('--include-wp-only');
                var note = dryChk.checked
                    ? __('✔ Dry run active, no files will be moved.', 'refaxination')
                    : __('⚠️ Live run, files will be moved to orphans/.', 'refaxination');
                return { cmd: parts.join(' '), note: note };
            },
        },
        'restore': {
            build: function () {
                var parts = ['wp refaxination restore'];
                var allChk = document.getElementById('r-all-chk');
                var fileidChk = document.getElementById('r-fileid-chk');
                var dryChk = document.getElementById('r-dryrun-chk');
                var note = '';
                if (allChk.checked && fileidChk.checked) {
                    note = __('⛔ --all and --file-id are mutually exclusive. Remove one of them.', 'refaxination');
                } else if (allChk.checked) {
                    parts.push('--all');
                } else if (fileidChk.checked) {
                    var v = document.getElementById('r-fileid-val').value;
                    if (v) parts.push('--file-id=' + parseInt(v, 10));
                    else note = __('⚠️ Enter the file ID.', 'refaxination');
                } else {
                    note = __('⚠️ Select --all or --file-id to specify what to restore.', 'refaxination');
                }
                if (dryChk.checked) parts.push('--dry-run');
                return { cmd: parts.join(' '), note: note };
            },
        },
        'report': {
            build: function () {
                var parts = ['wp refaxination report'];
                var fmtChk = document.getElementById('rp-format-chk');
                var statusChk = document.getElementById('rp-status-chk');
                var groupChk = document.getElementById('rp-group-chk');
                if (fmtChk.checked) {
                    var v = document.getElementById('rp-format-val').value;
                    if (v !== 'table') parts.push('--format=' + v);
                }
                var note = '';
                if (groupChk.checked && statusChk.checked) {
                    note = __('⚠️ --group-by=type ignores --status. Use one or the other.', 'refaxination');
                }
                if (groupChk.checked) {
                    parts.push('--group-by=type');
                } else if (statusChk.checked) {
                    parts.push('--status=' + document.getElementById('rp-status-val').value);
                }
                if (fmtChk.checked && document.getElementById('rp-format-val').value === 'csv') {
                    note = (note ? note + ' ' : '') + __('💡 Redirect output: append > report.csv to the command.', 'refaxination');
                }
                return { cmd: parts.join(' '), note: note };
            },
        },
        'reset': {
            build: function () {
                var parts = ['wp refaxination reset'];
                var tablesChk = document.getElementById('rs-tables-chk');
                var confirmChk = document.getElementById('rs-confirm-chk');
                if (tablesChk.checked) parts.push('--tables');
                if (confirmChk.checked) parts.push('--confirm');
                var note = tablesChk.checked
                    ? __('All plugin data, including the move audit log, will be deleted.', 'refaxination')
                    : __('Scan data will be cleared (moves table preserved).', 'refaxination');
                return { cmd: parts.join(' '), note: note };
            },
        },
        'status': {
            build: function () { return { cmd: 'wp refaxination status', note: '' }; },
        },
    };

    var activeCmd = 'scan-files';
    var tabs = Array.from(document.querySelectorAll('.rfx-cmd-tab'));
    var panels = Array.from(document.querySelectorAll('.rfx-cmd-panel'));
    var output = document.getElementById('rfx-generated-cmd');
    var noteEl = document.getElementById('rfx-output-note');
    var copyBtn = document.getElementById('rfx-copy-btn');
    var copyLabel = document.getElementById('rfx-copy-label');

    function wireToggle(chkId, rowId, warnId) {
        var chk = document.getElementById(chkId);
        var row = rowId ? document.getElementById(rowId) : null;
        var warn = warnId ? document.getElementById(warnId) : null;
        if (!chk) return;
        function refresh() {
            if (row) row.style.display = chk.checked ? 'flex' : 'none';
            if (warn) warn.style.display = chk.checked ? 'block' : 'none';
            rebuild();
        }
        chk.addEventListener('change', refresh);
        if (row) row.style.display = chk.checked ? 'flex' : 'none';
        if (warn) warn.style.display = chk.checked ? 'block' : 'none';
    }

    document.querySelectorAll('.rfx-source-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var input = chip.querySelector('input');
            input.checked = !input.checked;
            chip.classList.toggle('checked', input.checked);
            rebuild();
        });
    });

    wireToggle('sf-batch-chk', 'sf-batch-row', null);
    wireToggle('sf-reset-chk', null, 'sf-reset-warn');
    wireToggle('sr-batch-chk', 'sr-batch-row', null);
    wireToggle('sr-source-chk', 'sr-sources', null);
    wireToggle('q-batch-chk', 'q-batch-row', null);
    wireToggle('q-library_only-chk', null, 'q-library_only-warn');
    wireToggle('r-fileid-chk', 'r-fileid-row', null);
    wireToggle('rs-tables-chk', null, 'rs-tables-warn');
    wireToggle('rp-format-chk', 'rp-format-row', null);
    wireToggle('rp-status-chk', 'rp-status-row', null);

    document.getElementById('rfx-cli-gen').addEventListener('input', rebuild);
    document.getElementById('rfx-cli-gen').addEventListener('change', rebuild);

    function rebuild() {
        var def = CMDS[activeCmd];
        if (!def) return;
        var result = def.build();
        output.textContent = result.cmd;
        noteEl.textContent = result.note;
        noteEl.style.color = result.note.indexOf('⛔') === 0 ? '#c62828' :
            result.note.indexOf('⚠️') === 0 ? '#e65100' : '#888';
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activeCmd = tab.dataset.cmd;
            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            panels.forEach(function (p) { p.style.display = p.dataset.panel === activeCmd ? '' : 'none'; });
            rebuild();
        });
    });

    copyBtn.addEventListener('click', function () {
        var text = output.textContent;
        if (!navigator.clipboard) {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        } else {
            navigator.clipboard.writeText(text);
        }
        copyBtn.classList.add('copied');
        copyLabel.textContent = __('Copied!', 'refaxination');
        setTimeout(function () {
            copyBtn.classList.remove('copied');
            copyLabel.textContent = __('Copy', 'refaxination');
        }, 2000);
    });

    rebuild();
}());
