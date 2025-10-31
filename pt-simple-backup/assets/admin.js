/* Scripts administrativos para o PT Simple Backup. */
(function(){
  const ajaxUrl = window.ajaxurl || (window.PTSB_ADMIN && window.PTSB_ADMIN.ajaxurl);
  if (!ajaxUrl) return;

  function esc(str){
    return String(str ?? '').replace(/[&<>"]+/g, function(ch){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]) || ch;
    });
  }

  function clamp(n, min, max){
    n = parseInt(n, 10) || 0;
    if (n < min) return min;
    if (n > max) return max;
    return n;
  }

  function letterIcon(L){
    const map = {
      'D': 'dashicons-database',
      'P': 'dashicons-admin-plugins',
      'T': 'dashicons-admin-appearance',
      'W': 'dashicons-wordpress-alt',
      'S': 'dashicons-editor-code',
      'M': 'dashicons-admin-media',
      'O': 'dashicons-image-filter'
    };
    const cls = map[L] || 'dashicons-marker';
    return '<span class="ptsb-mini" title="'+esc(L)+'"><span class="dashicons '+cls+'"></span></span>';
  }

  function resetExpiredState(tr){
    tr.classList.remove('ptsb-expired');
    const tag = tr.querySelector('.ptsb-tag.vencido');
    if (tag && tag.parentNode) tag.parentNode.removeChild(tag);
  }

  function renderRetentionCell(tr, keepDays){
    const td = tr.querySelector('.ptsb-col-ret');
    if (!td) return;

    resetExpiredState(tr);

    const kept = tr.getAttribute('data-kept') === '1';
    if (kept){
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      return;
    }

    if (keepDays === null){
      td.textContent = '—';
      return;
    }

    if (!Number.isFinite(keepDays) || keepDays <= 0){
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      return;
    }

    const iso = tr.getAttribute('data-time') || '';
    const created = new Date(iso);
    const now = new Date();
    const elapsedDays = Math.max(0, Math.floor((now - created) / 86400000));
    const x = Math.min(keepDays, elapsedDays + 1);
    const expired = (x >= keepDays);

    td.innerHTML = '<span class="ptsb-ret" title="Dia '+x+' de '+keepDays+'">'+x+'/'+keepDays+'</span>';

    if (expired && !kept){
      tr.classList.add('ptsb-expired');
      const nameCell = tr.querySelector('.ptsb-filename');
      if (nameCell){
        const tag = document.createElement('span');
        tag.className = 'ptsb-tag vencido';
        tag.textContent = 'vencido';
        nameCell.insertAdjacentElement('afterend', tag);
      }
    }
  }

  function hydrateDetails(nonce, rows){
    if (!rows.length || !nonce) return;
    const files = rows.map(tr => tr.getAttribute('data-file')).filter(Boolean);
    if (!files.length) return;

    const body = new URLSearchParams();
    body.set('action', 'ptsb_details_batch');
    body.set('nonce', nonce);
    files.forEach(f => body.append('files[]', f));

    fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r => r.json())
      .then(res => {
        if (!res || !res.success || !res.data) return;
        const data = res.data;
        rows.forEach(tr => {
          const file = tr.getAttribute('data-file');
          if (!file) return;
          const detail = data[file] || {};

          const rotCell = tr.querySelector('.ptsb-col-rotina');
          if (rotCell){
            rotCell.textContent = detail.routine_label || '—';
          }

          const lettersCell = tr.querySelector('.ptsb-col-letters');
          if (lettersCell){
            const letters = Array.isArray(detail.parts_letters) && detail.parts_letters.length
              ? detail.parts_letters.map(x => String(x).toUpperCase())
              : ['D','P','T','W','S','M','O'];
            lettersCell.innerHTML = letters.map(letterIcon).join('');
          }

          const keepRaw = detail.keep_days;
          const keepDays = (keepRaw === null || keepRaw === undefined) ? null : parseInt(keepRaw, 10);
          renderRetentionCell(tr, Number.isNaN(keepDays) ? null : keepDays);
        });
      })
      .catch(() => {});
  }

  function initNext(){
    const root = document.getElementById('ptsb-next-root');
    if (!root) return;
    const table = root.querySelector('[data-role="ptsb-next-table"]');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const pager = root.querySelector('#ptsb-next-pager');
    const dateForm = root.querySelector('#ptsb-next-date-form');
    const perForm = root.querySelector('#ptsb-next-per-form');
    const dateInput = dateForm ? dateForm.querySelector('input[name="next_date"]') : null;
    const perInput = perForm ? perForm.querySelector('input[name="per_next"]') : null;
    const resetBtn = root.querySelector('[data-reset-date]');

    const state = {
      page: clamp(root.dataset.page, 1, 999999) || 1,
      per: clamp(root.dataset.per, 1, 20) || 20,
      date: root.dataset.date || ''
    };

    function setLoading(){
      tbody.innerHTML = '<tr class="ptsb-loading"><td colspan="4"><em>Carregando…</em></td></tr>';
    }

    function renderRows(rows){
      if (!rows.length){
        tbody.innerHTML = '<tr><td colspan="4"><em>Nenhuma execução prevista.</em></td></tr>';
        return;
      }
      const postUrl = root.dataset.post || '';
      const referer = root.dataset.referer || '';
      const nonce = root.dataset.nonce || '';
      const html = rows.map(row => {
        const names = (row.names || []).map(esc).join(' + ');
        const letters = Array.isArray(row.letters) ? row.letters.map(x => String(x).toUpperCase()) : [];
        const lettersHtml = letters.length ? letters.map(letterIcon).join('') : ['D','P','T','W','S','M','O'].map(letterIcon).join('');
        const toggleTitle = row.ignored ? 'Recolocar esta execução' : 'Ignorar esta execução';
        return '<tr>'+
          '<td>'+esc(row.display)+'</td>'+
          '<td>'+names+'</td>'+
          '<td>'+lettersHtml+'</td>'+
          '<td>'+
            '<form method="post" action="'+esc(postUrl)+'" style="display:inline">'+
              '<input type="hidden" name="action" value="ptsb_cycles">'+
              '<input type="hidden" name="do" value="skip_toggle">'+
              '<input type="hidden" name="time" value="'+esc(row.key)+'">'+
              '<input type="hidden" name="_wpnonce" value="'+esc(nonce)+'">'+
              '<input type="hidden" name="_wp_http_referer" value="'+esc(referer)+'">'+
              '<div class="ptsb-keep-toggle">'+
                '<label class="ptsb-switch" title="'+esc(toggleTitle)+'">'+
                  '<input type="checkbox" name="skip" value="1"'+(row.ignored ? ' checked' : '')+' onchange="this.form.submit()">'+
                  '<span class="ptsb-slider" aria-hidden="true"></span>'+
                '</label>'+
                '<span class="ptsb-keep-txt">Ignorar esta execução</span>'+
              '</div>'+
            '</form>'+
          '</td>'+
        '</tr>';
      }).join('');
      tbody.innerHTML = html;
    }

    function setButtonState(btn, disabled){
      if (!btn) return;
      btn.classList.toggle('is-disabled', !!disabled);
      btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    }

    function updatePager(data){
      if (!pager) return;
      const input = pager.querySelector('input.current');
      if (input){
        input.value = data.page;
      }
      const totalSpan = pager.querySelector('.total');
      if (totalSpan){
        totalSpan.textContent = data.total_pages || '';
      }
      setButtonState(pager.querySelector('[data-action="first"]'), data.page <= 1);
      setButtonState(pager.querySelector('[data-action="prev"]'), data.page <= 1);
      setButtonState(pager.querySelector('[data-action="next"]'), !data.has_next);
      const lastBtn = pager.querySelector('[data-action="last"]');
      if (lastBtn){
        const disabled = !data.total_pages || data.page >= data.total_pages;
        setButtonState(lastBtn, disabled);
      }
    }

    function load(){
      const nonce = root.dataset.nonce || '';
      if (!nonce) return;
      setLoading();
      const body = new URLSearchParams();
      body.set('action', 'ptsb_next_list');
      body.set('nonce', nonce);
      body.set('page', state.page);
      body.set('per', state.per);
      if (state.date) body.set('date', state.date);

      fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
        .then(r => r.json())
        .then(res => {
          if (!res || !res.success) throw new Error('fail');
          const data = res.data || {};
          state.page = data.page || state.page;
          state.per = data.per || state.per;
          if ('date' in data) state.date = data.date || '';
          renderRows(Array.isArray(data.rows) ? data.rows : []);
          updatePager({
            page: state.page,
            has_next: !!data.has_next,
            total_pages: data.total_pages || ''
          });
        })
        .catch(() => {
          tbody.innerHTML = '<tr><td colspan="4"><em>Falha ao carregar as execuções.</em></td></tr>';
        });
    }

    if (dateInput){
      dateInput.addEventListener('change', function(){
        state.date = dateInput.value || '';
        state.page = 1;
        load();
      });
    }

    if (resetBtn){
      resetBtn.addEventListener('click', function(){
        if (dateInput) dateInput.value = '';
        state.date = '';
        state.page = 1;
        load();
      });
    }

    if (perInput){
      perInput.addEventListener('change', function(){
        state.per = clamp(perInput.value, 1, 20);
        perInput.value = state.per;
        state.page = 1;
        load();
      });
    }

    if (pager){
      const pagerInput = pager.querySelector('input.current');
      if (pagerInput){
        pagerInput.addEventListener('change', function(){
          const newPage = clamp(pagerInput.value, 1, 999999);
          pagerInput.value = newPage;
          state.page = newPage;
          load();
        });
        pagerInput.addEventListener('keyup', function(ev){ if (ev.key === 'Enter') { ev.preventDefault(); pagerInput.dispatchEvent(new Event('change')); }});
      }
      pager.addEventListener('click', function(ev){
        const btn = ev.target.closest('a[data-action]');
        if (!btn || btn.classList.contains('is-disabled')) return;
        ev.preventDefault();
        const action = btn.getAttribute('data-action');
        if (action === 'first') state.page = 1;
        else if (action === 'prev') state.page = Math.max(1, state.page - 1);
        else if (action === 'next') state.page = state.page + 1;
        else if (action === 'last') {
          const total = parseInt(btn.dataset.totalPages || pager.querySelector('.total')?.textContent || '', 10);
          if (total) state.page = total;
        }
        load();
      });
    }

    load();
  }

  function initLast(){
    const root = document.getElementById('ptsb-last-root');
    if (!root) return;
    const table = root.querySelector('[data-role="ptsb-last-table"]');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const pager = root.querySelector('#ptsb-last-pager');
    const totalSpan = root.querySelector('.ptsb-last-total');
    const filterForm = root.querySelector('#ptsb-last-filter-form');
    const perForm = root.querySelector('#ptsb-last-per-form');
    const perInput = perForm ? perForm.querySelector('input[name="per_last"]') : null;

    const state = {
      page: clamp(root.dataset.page, 1, 999999) || 1,
      per: clamp(root.dataset.per, 1, 20) || 20,
      exp: parseInt(root.dataset.exp, 10) ? 1 : 0,
      ok: parseInt(root.dataset.ok, 10) ? 1 : 0
    };

    function setLoading(){
      tbody.innerHTML = '<tr class="ptsb-loading"><td colspan="6"><em>Carregando…</em></td></tr>';
    }

    function renderRows(rows){
      if (!rows.length){
        tbody.innerHTML = '<tr><td colspan="6"><em>Nenhum backup encontrado.</em></td></tr>';
        return [];
      }
      const nonce = root.dataset.nonce || '';
      const postUrl = root.dataset.post || '';
      const referer = root.dataset.referer || '';
      const html = rows.map(row => {
        return '<tr data-file="'+esc(row.file)+'" data-time="'+esc(row.iso)+'" data-kept="'+(row.kept ? '1' : '0')+'">'+
          '<td>'+esc(row.display)+'</td>'+
          '<td>'+
            '<span class="ptsb-filename">'+esc(row.file)+'</span>'+
            '<form method="post" action="'+esc(postUrl)+'" class="ptsb-rename-form" style="display:inline">'+
              '<input type="hidden" name="action" value="ptsb_do">'+
              '<input type="hidden" name="ptsb_action" value="rename">'+
              '<input type="hidden" name="old_file" value="'+esc(row.file)+'">'+
              '<input type="hidden" name="new_file" value="">'+
              '<input type="hidden" name="_wpnonce" value="'+esc(nonce)+'">'+
              '<input type="hidden" name="_wp_http_referer" value="'+esc(referer)+'">'+
              '<button type="button" class="ptsb-rename-btn" title="Renomear" data-old="'+esc(row.file)+'">'+
                '<span class="dashicons dashicons-edit" aria-hidden="true"></span>'+
                '<span class="screen-reader-text">Renomear</span>'+
              '</button>'+
            '</form>'+
          '</td>'+
          '<td class="ptsb-col-rotina"><span class="description">carregando…</span></td>'+
          '<td class="ptsb-col-letters" aria-label="Partes incluídas"><span class="description">carregando…</span></td>'+
          '<td class="ptsb-col-ret"><span class="description">carregando…</span></td>'+
          '<td>'+esc(row.size_h)+'</td>'+
        '</tr>';
      }).join('');
      tbody.innerHTML = html;
      return Array.from(tbody.querySelectorAll('tr[data-file]'));
    }

    function setButtonState(btn, disabled){
      if (!btn) return;
      btn.classList.toggle('is-disabled', !!disabled);
      btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    }

    function updatePager(data){
      if (!pager) return;
      const input = pager.querySelector('input.current');
      if (input){ input.value = data.page; }
      const totalEl = pager.querySelector('.total');
      if (totalEl){ totalEl.textContent = data.total_pages; }
      setButtonState(pager.querySelector('[data-action="first"]'), data.page <= 1);
      setButtonState(pager.querySelector('[data-action="prev"]'), data.page <= 1);
      setButtonState(pager.querySelector('[data-action="next"]'), data.page >= data.total_pages);
      setButtonState(pager.querySelector('[data-action="last"]'), data.page >= data.total_pages);
    }

    function updateTotals(data){
      if (!totalSpan) return;
      totalSpan.textContent = 'de '+data.total+' execuções — página '+data.page+' de '+data.total_pages;
    }

    function load(){
      const nonce = root.dataset.nonce || '';
      if (!nonce) return;
      setLoading();
      const body = new URLSearchParams();
      body.set('action', 'ptsb_last_list');
      body.set('nonce', nonce);
      body.set('page', state.page);
      body.set('per', state.per);
      body.set('last_exp', state.exp);
      body.set('last_ok', state.ok);

      fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
        .then(r => r.json())
        .then(res => {
          if (!res || !res.success) throw new Error('fail');
          const data = res.data || {};
          state.page = data.page || 1;
          state.per = data.per || state.per;
          state.exp = data.last_exp ? 1 : 0;
          state.ok  = data.last_ok ? 1 : 0;
          const rows = renderRows(Array.isArray(data.rows) ? data.rows : []);
          const total = Math.max(0, parseInt(data.total, 10) || 0);
          const totalPages = Math.max(1, parseInt(data.total_pages, 10) || 1);
          updateTotals({total, page: state.page, total_pages: totalPages});
          updatePager({page: state.page, total_pages: totalPages});
          hydrateDetails(nonce, rows);
        })
        .catch(() => {
          tbody.innerHTML = '<tr><td colspan="6"><em>Falha ao carregar os backups.</em></td></tr>';
          if (totalSpan) totalSpan.textContent = 'Falha ao carregar';
        });
    }

    if (filterForm){
      filterForm.addEventListener('change', function(){
        const expInput = filterForm.querySelector('input[name="last_exp"]');
        const okInput  = filterForm.querySelector('input[name="last_ok"]');
        state.exp = expInput && expInput.checked ? 1 : 0;
        state.ok  = okInput && okInput.checked ? 1 : 0;
        state.page = 1;
        load();
      });
    }

    if (perInput){
      perInput.addEventListener('change', function(){
        state.per = clamp(perInput.value, 1, 20);
        perInput.value = state.per;
        state.page = 1;
        load();
      });
    }

    if (pager){
      const pagerInput = pager.querySelector('input.current');
      if (pagerInput){
        pagerInput.addEventListener('change', function(){
          const newPage = clamp(pagerInput.value, 1, 999999);
          pagerInput.value = newPage;
          state.page = newPage;
          load();
        });
        pagerInput.addEventListener('keyup', function(ev){ if (ev.key === 'Enter'){ ev.preventDefault(); pagerInput.dispatchEvent(new Event('change')); }});
      }
      pager.addEventListener('click', function(ev){
        const btn = ev.target.closest('a[data-action]');
        if (!btn || btn.classList.contains('is-disabled')) return;
        ev.preventDefault();
        const action = btn.getAttribute('data-action');
        if (action === 'first') state.page = 1;
        else if (action === 'prev') state.page = Math.max(1, state.page - 1);
        else if (action === 'next') state.page = state.page + 1;
        else if (action === 'last') {
          const total = parseInt(pager.querySelector('.total')?.textContent || '', 10);
          if (total) state.page = total;
        }
        load();
      });
    }

    load();
  }

  document.addEventListener('DOMContentLoaded', function(){
    initNext();
    initLast();
  });
})();
