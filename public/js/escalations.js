/**
 * Escalations — comportamentos da interface (sem atributos inline, por causa da CSP do GLPI):
 *  - recolher/expandir grupos da Tabela principal, envio automático de seletores (data-autosubmit) e confirmação (data-confirm);
 *  - pop-up de escalonamento: consulta front/pending.php a cada poucos segundos e mostra um aviso em destaque com
 *    "Assumir (ACK)", "Abrir ficha" e "Adiar". O aviso fica na tela até o ACK. O alerta sonoro é gerado pelo navegador
 *    (Web Audio) e só toca depois do primeiro clique na página (regra dos navegadores). A consulta para sozinha quando a
 *    sessão expira.
 */
(() => {
    if (window.__escUi) return;
    window.__escUi = true;

    // ---------------------------------------------------------------- interface básica
    document.addEventListener('change', (e) => {
        const el = e.target;
        if (el && el.matches && el.matches('[data-autosubmit]') && el.form) el.form.submit();
    });
    document.addEventListener('click', (e) => {
        const conf = e.target.closest && e.target.closest('[data-confirm]');
        if (conf && !window.confirm(conf.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); return; }
        const btn = e.target.closest && e.target.closest('[data-toggle]');
        if (btn) {
            const grp = btn.closest('.esc-grp');
            if (grp) btn.setAttribute('aria-expanded', grp.classList.toggle('closed') ? 'false' : 'true');
        }
    }, true);

    // modelos de perfil (tela Permissões): preenche as caixas da linha e a marca "somente notificado"
    document.addEventListener('change', (e) => {
        const sel = e.target;
        if (!sel || !sel.matches || !sel.matches('[data-preset-select]') || !sel.value) return;
        const presets = JSON.parse(sel.getAttribute('data-presets') || '{}');
        const p = presets[sel.value];
        const row = sel.closest('tr');
        if (!p || !row) return;
        row.querySelectorAll('input[data-cap]').forEach((cb) => { cb.checked = p.on.indexOf(cb.getAttribute('data-cap')) !== -1; });
        const n = row.querySelector('input[data-notify]');
        if (n) n.checked = !!p.notify;
        sel.value = '';
    });

    // ---------------------------------------------------------------- pop-up de escalonamento
    const root = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
    const base = `${root}/plugins/escalations/front`;
    const cards = new Map(); // id da entrega -> elemento
    let interval = 20;
    let soundOn = true;
    let stopped = false;
    let wrap = null;
    let audio = null;
    let beepTimer = null;

    const csrf = () => (typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const isMuted = () => { try { return localStorage.getItem('esc_mute') === '1'; } catch (e) { return false; } };

    function unlockAudio() {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') audio.resume();
        } catch (e) { /* sem áudio */ }
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, unlockAudio, {passive: true}));

    /** Três tons descendentes, mais insistentes que um aviso comum. */
    function alarm() {
        if (!soundOn || isMuted() || !audio || audio.state !== 'running') return;
        const t = audio.currentTime;
        [[988, 0], [784, 0.2], [988, 0.4]].forEach(([freq, delay]) => {
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.type = 'square';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, t + delay);
            gain.gain.exponentialRampToValueAtTime(0.12, t + delay + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + delay + 0.18);
            osc.connect(gain).connect(audio.destination);
            osc.start(t + delay);
            osc.stop(t + delay + 0.2);
        });
    }

    function container() {
        if (wrap) return wrap;
        wrap = document.createElement('div');
        wrap.className = 'esc-pop-wrap';
        wrap.setAttribute('role', 'alertdialog');
        wrap.setAttribute('aria-label', 'Escalonamento aguardando ACK');
        document.body.appendChild(wrap);
        wrap.addEventListener('click', onClick);
        return wrap;
    }

    function fmtLeft(sec) {
        if (sec === null || sec === undefined) return '';
        const neg = sec < 0;
        const s = Math.abs(sec);
        const t = s < 60 ? `${s} s` : (s < 3600 ? `${Math.floor(s / 60)} min` : `${Math.floor(s / 3600)} h ${Math.floor((s % 3600) / 60)} min`);
        return neg ? `prazo de ACK estourado há ${t}` : `restam ${t} para o prazo de ACK`;
    }

    function build(item) {
        const el = document.createElement('div');
        const isSlo = !item.can_ack;
        el.className = 'esc-pop';
        el.style.setProperty('--c', item.color);
        el.dataset.delivery = item.id;
        el.dataset.esc = item.esc_id;
        const hist = (item.history || []).map((h) => `<li><b>${esc(h.label || ('N' + h.level + ' · ' + h.name))}</b> · ${esc(h.who)} <span>${esc(h.at)}</span></li>`).join('');
        const kindLabel = item.notify_only ? 'Informativo · você só é notificado' : item.kind.startsWith('slo_breach') ? 'SLO estourado' : item.kind.startsWith('slo_warn') ? 'SLO em risco' : item.kind === 'exhausted' ? 'Cadeia esgotada sem ACK' : 'Escalonamento aguardando ACK';
        el.innerHTML = `<div class="esc-pop-head"><i class="ti ${item.notify_only ? 'ti-info-circle' : (isSlo ? 'ti-alert-triangle' : 'ti-bell-ringing')}"></i><div><div class="k">${esc(kindLabel)} · ${esc(item.severity)}</div><div class="r">${esc(item.trace)} · ${esc(item.level)}</div></div></div>
            <div class="esc-pop-body"><h3>${esc(item.title)}</h3>${item.summary ? `<p>${esc(item.summary)}</p>` : ''}
            <div class="esc-pop-meta"><span><i class="ti ti-clock"></i> aberto há ${esc(item.age)}</span>${item.ack_left !== null ? `<span class="${item.ack_left < 0 ? 'late' : ''}"><i class="ti ti-hourglass"></i> ${esc(fmtLeft(item.ack_left))}</span>` : ''}</div>
            ${hist ? `<div class="esc-pop-hist"><div class="h">Histórico das escaladas</div><ul>${hist}</ul></div>` : ''}
            <div class="esc-pop-msg" hidden></div></div>
            <div class="esc-pop-actions">
              ${item.can_ack ? '<button type="button" class="btn btn-danger btn-lg" data-act="ack"><i class="ti ti-hand-stop me-1"></i>Assumir (ACK)</button>' : ''}
              <a class="btn btn-outline-secondary" href="${esc(item.url)}"><i class="ti ti-external-link me-1"></i>Abrir ficha</a>
              <button type="button" class="btn btn-outline-secondary" data-act="${item.can_ack ? 'snooze' : 'dismiss'}">${item.can_ack ? '<i class="ti ti-clock-pause me-1"></i>Adiar' : '<i class="ti ti-check me-1"></i>Ciente'}</button>
              <button type="button" class="btn btn-icon btn-ghost-secondary ms-auto" data-act="mute" title="Silenciar o som neste navegador"><i class="ti ${isMuted() ? 'ti-volume-off' : 'ti-volume'}"></i></button>
            </div>`;
        return el;
    }

    async function post(params) {
        const res = await fetch(`${base}/action.php`, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf(), 'Accept': 'application/json'},
            body: new URLSearchParams(params).toString(),
        });
        return res.json().catch(() => ({ok: false, error: 'Resposta inválida do servidor.'}));
    }

    async function onClick(ev) {
        const btn = ev.target.closest('[data-act]');
        if (!btn) return;
        const card = btn.closest('.esc-pop');
        const act = btn.dataset.act;
        if (act === 'mute') {
            try { localStorage.setItem('esc_mute', isMuted() ? '0' : '1'); } catch (e) { /* sem armazenamento */ }
            btn.querySelector('i').className = `ti ${isMuted() ? 'ti-volume-off' : 'ti-volume'}`;
            return;
        }
        btn.disabled = true;
        const data = await post(act === 'ack' ? {do: 'ack', id: card.dataset.esc} : {do: act, delivery: card.dataset.delivery});
        const msg = card.querySelector('.esc-pop-msg');
        if (data.ok) {
            msg.hidden = false;
            msg.className = 'esc-pop-msg ok';
            msg.textContent = data.message;
            setTimeout(() => remove(card.dataset.delivery), 900);
        } else {
            btn.disabled = false;
            msg.hidden = false;
            msg.className = 'esc-pop-msg err';
            msg.textContent = data.error || 'Não foi possível concluir.';
            if (/reconhecido|encerrado/i.test(data.error || '')) setTimeout(() => remove(card.dataset.delivery), 2500);
        }
    }

    function remove(id) {
        const el = cards.get(String(id));
        if (el) { el.remove(); cards.delete(String(id)); }
        if (!cards.size && wrap) { wrap.remove(); wrap = null; stopBeep(); }
    }

    function stopBeep() { if (beepTimer) { clearInterval(beepTimer); beepTimer = null; } }

    function sync(items) {
        const ids = new Set(items.map((i) => String(i.id)));
        [...cards.keys()].forEach((id) => { if (!ids.has(id)) remove(id); });
        let fresh = false;
        items.forEach((item) => {
            if (cards.has(String(item.id))) return;
            const el = build(item);
            container().appendChild(el);
            cards.set(String(item.id), el);
            fresh = true;
        });
        if (fresh) alarm();
        // enquanto houver pedido de ACK na tela, repete o alerta a cada 30 s
        if (items.some((i) => i.can_ack)) {
            if (!beepTimer) beepTimer = setInterval(alarm, 30000);
        } else {
            stopBeep();
        }
    }

    let pollTimer = null;
    const schedule = (ms) => { clearTimeout(pollTimer); pollTimer = setTimeout(poll, ms); };

    async function poll() {
        if (stopped) return;
        if (document.visibilityState === 'visible') {
            try {
                const res = await fetch(`${base}/pending.php`, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                if (res.status === 401 || res.status === 404) { stopped = true; return; }
                const data = await res.json();
                if (data.disabled) { stopped = true; return; }
                interval = data.interval || 20;
                soundOn = data.sound !== false;
                sync(data.items || []);
            } catch (e) { /* rede indisponível: tenta de novo no próximo ciclo */ }
        }
        schedule(interval * 1000 + Math.floor(Math.random() * 1000));
    }

    // a página do próprio plugin não precisa esperar para mostrar o aviso
    schedule(1500);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && !stopped) schedule(300); });
})();
