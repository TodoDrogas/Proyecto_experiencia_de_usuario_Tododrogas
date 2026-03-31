/**
 * geo-sede.js — Geolocalización de sede más cercana · Tododrogas CIA SAS
 * ────────────────────────────────────────────────────────────────────────
 * Uso: incluir con <script src="geo-sede.js"></script> en:
 *   - pqr_encuesta.html
 *   - radicar-pqr.php  (o el formulario que lo incluya)
 *
 * API pública:
 *   GeoSede.init(sbUrl, sbAnon, onSedeReady)
 *     → Detecta GPS, carga sedes, resuelve la más cercana.
 *       onSedeReady(sede) se llama con el objeto sede al resolverse.
 *
 *   GeoSede.getSede()
 *     → Devuelve la sede activa (manual o detectada). null si ninguna.
 *
 *   GeoSede.openModal()
 *     → Abre el modal de selección manual.
 *
 *   GeoSede.renderBadge(containerId)
 *     → Inyecta el badge "📍 Sede · Cambiar" en el elemento con ese ID.
 */

(function (global) {
  'use strict';

  /* ── Claves sessionStorage ─────────────────────────────────────── */
  const KEY_MANUAL   = 'geo_sede_manual';   // sede elegida a mano (JSON)
  const KEY_DETECTED = 'geo_sede_detected'; // sede detectada por GPS (JSON)

  /* ── Estado interno ────────────────────────────────────────────── */
  let _sedes        = [];     // array de sedes cargadas de Supabase
  let _sedeActiva   = null;   // sede en uso (manual > detectada)
  let _callbacks    = [];     // onSedeReady listeners
  let _sbUrl        = '';
  let _sbAnon       = '';
  let _modalEl      = null;
  let _initialized  = false;

  /* ══════════════════════════════════════════════════════════════════
     HAVERSINE — distancia en metros entre dos puntos GPS
  ══════════════════════════════════════════════════════════════════ */
  function haversine(lat1, lng1, lat2, lng2) {
    const R = 6371000; // metros
    const toRad = d => d * Math.PI / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 +
              Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
              Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  /* ══════════════════════════════════════════════════════════════════
     SUPABASE — carga sedes activas
  ══════════════════════════════════════════════════════════════════ */
  async function fetchSedes() {
    if (!_sbUrl || !_sbAnon) return [];
    try {
      const r = await fetch(
        `${_sbUrl}/rest/v1/sedes?activa=eq.true&select=id,nombre,ciudad,direccion,telefono,lat,lng,radio_m&order=nombre.asc`,
        { headers: { apikey: _sbAnon, Authorization: 'Bearer ' + _sbAnon } }
      );
      if (!r.ok) return [];
      return await r.json();
    } catch (e) {
      console.warn('[GeoSede] fetchSedes:', e);
      return [];
    }
  }

  /* ══════════════════════════════════════════════════════════════════
     GPS — pide posición y busca sede más cercana
  ══════════════════════════════════════════════════════════════════ */
  function detectarSedeCercana(sedes, lat, lng) {
    if (!sedes.length) return null;
    let mejor = null, mejorDist = Infinity;
    for (const s of sedes) {
      if (!s.lat || !s.lng) continue;
      const dist = haversine(lat, lng, parseFloat(s.lat), parseFloat(s.lng));
      if (dist < mejorDist) { mejorDist = dist; mejor = { ...s, _dist: Math.round(dist) }; }
    }
    // Solo asigna si está dentro del radio (o si el radio es 0 → siempre asigna la más cercana)
    if (!mejor) return null;
    const radio = parseInt(mejor.radio_m) || 300;
    return (radio === 0 || mejorDist <= radio) ? mejor : mejor; // siempre retorna la más cercana
  }

  function pedirGPS() {
    return new Promise((resolve) => {
      if (!navigator.geolocation) { resolve(null); return; }
      navigator.geolocation.getCurrentPosition(
        pos => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
        ()  => resolve(null),
        { timeout: 8000, maximumAge: 60000 }
      );
    });
  }

  /* ══════════════════════════════════════════════════════════════════
     RESOLUCIÓN — prioridad: manual > detectada > primera activa
  ══════════════════════════════════════════════════════════════════ */
  function resolverSedeActiva() {
    // 1) Manual (elegida por el usuario en esta sesión)
    try {
      const m = sessionStorage.getItem(KEY_MANUAL);
      if (m) { _sedeActiva = JSON.parse(m); return; }
    } catch (_) {}

    // 2) Detectada por GPS en esta sesión
    try {
      const d = sessionStorage.getItem(KEY_DETECTED);
      if (d) { _sedeActiva = JSON.parse(d); return; }
    } catch (_) {}

    // 3) Sin dato: null (se mostrará selector)
    _sedeActiva = null;
  }

  function notificar() {
    _callbacks.forEach(cb => { try { cb(_sedeActiva); } catch (e) {} });
    actualizarBadges();
  }

  /* ══════════════════════════════════════════════════════════════════
     MODAL — selección manual de sede
  ══════════════════════════════════════════════════════════════════ */
  function inyectarEstilosModal() {
    if (document.getElementById('_geo_sede_css')) return;
    const st = document.createElement('style');
    st.id = '_geo_sede_css';
    st.textContent = `
    #_geo_modal_overlay{
      position:fixed;inset:0;z-index:9999;
      background:rgba(8,13,26,.72);backdrop-filter:blur(8px);
      display:flex;align-items:center;justify-content:center;
      opacity:0;pointer-events:none;transition:opacity .25s;
    }
    #_geo_modal_overlay.open{opacity:1;pointer-events:auto;}
    #_geo_modal_box{
      background:#fff;border-radius:18px;
      width:min(480px,94vw);max-height:82vh;
      display:flex;flex-direction:column;
      box-shadow:0 32px 80px rgba(8,13,26,.35);
      transform:scale(.93) translateY(20px);
      transition:transform .32s cubic-bezier(.34,1.4,.64,1);
      overflow:hidden;
    }
    #_geo_modal_overlay.open #_geo_modal_box{transform:scale(1) translateY(0);}
    ._geo_mhead{
      padding:20px 22px 16px;
      border-bottom:1px solid #eee;
      display:flex;align-items:center;justify-content:space-between;
      flex-shrink:0;
    }
    ._geo_mhead h3{font-size:16px;font-weight:700;color:#0a0e1a;margin:0;}
    ._geo_mhead p{font-size:12px;color:#8892a4;margin:3px 0 0;}
    ._geo_mclose{
      background:#f2f4f8;border:none;border-radius:8px;
      width:32px;height:32px;cursor:pointer;font-size:18px;
      color:#3a4258;display:flex;align-items:center;justify-content:center;
      transition:background .15s;flex-shrink:0;
    }
    ._geo_mclose:hover{background:#e2e6f0;}
    ._geo_msearch{
      padding:12px 22px;flex-shrink:0;
    }
    ._geo_msearch input{
      width:100%;background:#f2f4f8;border:1.5px solid #dde1eb;
      border-radius:10px;padding:10px 14px;font-size:13px;
      font-family:inherit;color:#0a0e1a;outline:none;transition:border-color .18s;
    }
    ._geo_msearch input:focus{border-color:#2563eb;background:#fff;}
    ._geo_msearch input::placeholder{color:#8892a4;}
    ._geo_mlist{overflow-y:auto;padding:0 12px 16px;flex:1;}
    ._geo_mlist::-webkit-scrollbar{width:4px;}
    ._geo_mlist::-webkit-scrollbar-thumb{background:#dde1eb;border-radius:99px;}
    ._geo_sede_item{
      display:flex;align-items:center;gap:12px;
      padding:13px 12px;border-radius:12px;cursor:pointer;
      transition:background .15s;border:1.5px solid transparent;
      margin-bottom:6px;
    }
    ._geo_sede_item:hover{background:#eff4ff;border-color:rgba(37,99,235,.2);}
    ._geo_sede_item.selected{background:#eff4ff;border-color:#2563eb;}
    ._geo_sede_pin{
      width:38px;height:38px;border-radius:10px;flex-shrink:0;
      background:linear-gradient(135deg,#1d4ed8,#2563eb);
      display:flex;align-items:center;justify-content:center;
      font-size:18px;
    }
    ._geo_sede_info{flex:1;min-width:0;}
    ._geo_sede_nombre{font-size:13px;font-weight:700;color:#0a0e1a;margin-bottom:2px;}
    ._geo_sede_dir{font-size:11px;color:#8892a4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    ._geo_sede_dist{font-size:10px;font-weight:600;color:#2563eb;
      background:#eff4ff;border-radius:99px;padding:2px 8px;flex-shrink:0;}
    ._geo_mfooter{
      padding:14px 22px;border-top:1px solid #eee;
      display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;
    }
    ._geo_btn{
      padding:9px 20px;border-radius:9px;font-size:13px;font-weight:600;
      cursor:pointer;border:none;font-family:inherit;transition:all .18s;
    }
    ._geo_btn_cancel{background:#f2f4f8;color:#3a4258;}
    ._geo_btn_cancel:hover{background:#e2e6f0;}
    ._geo_btn_ok{background:#2563eb;color:#fff;}
    ._geo_btn_ok:hover{background:#1d4ed8;transform:translateY(-1px);}

    /* Badge */
    ._geo_badge{
      display:inline-flex;align-items:center;gap:7px;
      background:#eff4ff;border:1.5px solid rgba(37,99,235,.3);
      border-radius:99px;padding:6px 14px 6px 10px;
      font-size:12px;font-weight:600;color:#2563eb;
      cursor:pointer;transition:all .2s;user-select:none;
    }
    ._geo_badge:hover{background:#dbeafe;border-color:#2563eb;transform:translateY(-1px);}
    ._geo_badge .pin_ico{font-size:14px;}
    ._geo_badge .sede_name{color:#0a0e1a;font-weight:700;}
    ._geo_badge .change_lbl{font-size:10px;font-weight:600;
      background:rgba(37,99,235,.12);border-radius:99px;padding:1px 7px;margin-left:2px;}
    ._geo_badge_loading{
      display:inline-flex;align-items:center;gap:8px;
      background:#f2f4f8;border:1.5px solid #dde1eb;
      border-radius:99px;padding:6px 14px 6px 10px;
      font-size:12px;color:#8892a4;
    }
    ._geo_spinner{
      width:14px;height:14px;border:2px solid #dde1eb;
      border-top-color:#2563eb;border-radius:50%;
      animation:_geo_spin .7s linear infinite;
    }
    @keyframes _geo_spin{to{transform:rotate(360deg)}}

    @media(prefers-color-scheme:dark){
      #_geo_modal_box{background:#13161e;}
      ._geo_mhead h3{color:#f0f2f8;}
      ._geo_mhead p{color:#5a6180;}
      ._geo_mclose{background:#1a1e2a;color:#9ba3be;}
      ._geo_mclose:hover{background:#252b3a;}
      ._geo_msearch input{background:#1a1e2a;border-color:#2a3148;color:#f0f2f8;}
      ._geo_msearch input:focus{border-color:#4f8ef7;background:#1e2330;}
      ._geo_sede_item:hover{background:rgba(79,142,247,.1);border-color:rgba(79,142,247,.2);}
      ._geo_sede_item.selected{background:rgba(79,142,247,.12);border-color:#4f8ef7;}
      ._geo_sede_nombre{color:#f0f2f8;}
      ._geo_sede_dir{color:#5a6180;}
      ._geo_sede_dist{background:rgba(79,142,247,.15);color:#7db4fc;}
      ._geo_mfooter{border-top-color:#2a3148;}
      ._geo_mhead{border-bottom-color:#2a3148;}
      ._geo_btn_cancel{background:#1a1e2a;color:#9ba3be;}
      ._geo_btn_cancel:hover{background:#252b3a;}
      ._geo_badge{background:rgba(79,142,247,.12);border-color:rgba(79,142,247,.3);color:#4f8ef7;}
      ._geo_badge:hover{background:rgba(79,142,247,.2);border-color:#4f8ef7;}
      ._geo_badge .sede_name{color:#f0f2f8;}
      ._geo_badge_loading{background:#1a1e2a;border-color:#2a3148;color:#5a6180;}
    }
    `;
    document.head.appendChild(st);
  }

  function crearModal() {
    if (_modalEl) return;
    inyectarEstilosModal();
    const ov = document.createElement('div');
    ov.id = '_geo_modal_overlay';
    ov.innerHTML = `
      <div id="_geo_modal_box">
        <div class="_geo_mhead">
          <div>
            <h3>📍 Selecciona tu sede</h3>
            <p>Elige la farmacia Tododrogas más cercana a ti</p>
          </div>
          <button class="_geo_mclose" id="_geo_mclose_btn" title="Cerrar">✕</button>
        </div>
        <div class="_geo_msearch">
          <input type="text" id="_geo_search_inp" placeholder="Buscar sede por nombre o ciudad…" autocomplete="off">
        </div>
        <div class="_geo_mlist" id="_geo_mlist"></div>
        <div class="_geo_mfooter">
          <button class="_geo_btn _geo_btn_cancel" id="_geo_btn_cancel">Cancelar</button>
          <button class="_geo_btn _geo_btn_ok" id="_geo_btn_ok">✓ Confirmar sede</button>
        </div>
      </div>`;
    document.body.appendChild(ov);
    _modalEl = ov;

    // Cierre
    ov.addEventListener('click', e => { if (e.target === ov) closeModal(); });
    document.getElementById('_geo_mclose_btn').addEventListener('click', closeModal);
    document.getElementById('_geo_btn_cancel').addEventListener('click', closeModal);
    document.getElementById('_geo_btn_ok').addEventListener('click', confirmarSeleccion);
    document.getElementById('_geo_search_inp').addEventListener('input', e => filtrarLista(e.target.value));
  }

  let _selectedInModal = null;

  function renderLista(sedes, busqueda) {
    const list = document.getElementById('_geo_mlist');
    if (!list) return;
    const q = (busqueda || '').toLowerCase().trim();
    const filtradas = q
      ? sedes.filter(s =>
          (s.nombre || '').toLowerCase().includes(q) ||
          (s.ciudad || '').toLowerCase().includes(q) ||
          (s.direccion || '').toLowerCase().includes(q)
        )
      : sedes;

    if (!filtradas.length) {
      list.innerHTML = `<div style="text-align:center;padding:32px;font-size:13px;color:#8892a4;">Sin resultados para "<strong>${q}</strong>"</div>`;
      return;
    }

    list.innerHTML = filtradas.map(s => {
      const activa = _sedeActiva && _sedeActiva.id === s.id;
      const distLabel = s._dist != null ? `${s._dist < 1000 ? s._dist + ' m' : (s._dist/1000).toFixed(1) + ' km'}` : '';
      return `
        <div class="_geo_sede_item${activa ? ' selected' : ''}" data-id="${s.id}" onclick="_geoSelectItem(this,'${s.id}')">
          <div class="_geo_sede_pin">📍</div>
          <div class="_geo_sede_info">
            <div class="_geo_sede_nombre">${esc(s.nombre)}</div>
            <div class="_geo_sede_dir">${esc(s.direccion || '')}${s.ciudad ? ', ' + esc(s.ciudad) : ''}</div>
          </div>
          ${distLabel ? `<span class="_geo_sede_dist">${distLabel}</span>` : ''}
        </div>`;
    }).join('');
  }

  global._geoSelectItem = function (el, id) {
    document.querySelectorAll('._geo_sede_item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    _selectedInModal = _sedes.find(s => s.id === id) || null;
  };

  function filtrarLista(q) { renderLista(_sedesOrdenadas(), q); }

  function _sedesOrdenadas() {
    return [..._sedes].sort((a, b) => {
      if (a._dist != null && b._dist != null) return a._dist - b._dist;
      return (a.nombre || '').localeCompare(b.nombre || '');
    });
  }

  function openModal() {
    crearModal();
    _selectedInModal = _sedeActiva;
    const inp = document.getElementById('_geo_search_inp');
    if (inp) inp.value = '';
    renderLista(_sedesOrdenadas(), '');
    requestAnimationFrame(() => {
      _modalEl.classList.add('open');
      if (inp) setTimeout(() => inp.focus(), 200);
    });
  }

  function closeModal() {
    if (_modalEl) _modalEl.classList.remove('open');
  }

  function confirmarSeleccion() {
    if (!_selectedInModal) { closeModal(); return; }
    sessionStorage.setItem(KEY_MANUAL, JSON.stringify(_selectedInModal));
    _sedeActiva = _selectedInModal;
    closeModal();
    notificar();
  }

  /* ══════════════════════════════════════════════════════════════════
     BADGE — muestra sede activa con botón cambiar
  ══════════════════════════════════════════════════════════════════ */
  function renderBadge(containerId) {
    const cont = document.getElementById(containerId);
    if (!cont) return;

    if (!_sedeActiva) {
      cont.innerHTML = `
        <span class="_geo_badge_loading">
          <span class="_geo_spinner"></span>
          Detectando sede cercana…
        </span>`;
      // Permite forzar apertura del modal si no hay sede aún
      const loadBadge = cont.querySelector('._geo_badge_loading');
      if (loadBadge) loadBadge.addEventListener('click', openModal);
    } else {
      cont.innerHTML = `
        <span class="_geo_badge" title="Toca para cambiar de sede" onclick="GeoSede.openModal()">
          <span class="pin_ico">📍</span>
          <span class="sede_name">${esc(_sedeActiva.nombre)}</span>
          ${_sedeActiva.ciudad ? `<span style="color:#8892a4;font-weight:400">${esc(_sedeActiva.ciudad)}</span>` : ''}
          <span class="change_lbl">Cambiar</span>
        </span>`;
    }
  }

  function actualizarBadges() {
    // Re-renderiza todos los badges registrados
    document.querySelectorAll('[data-geo-badge]').forEach(el => {
      renderBadge(el.id || (el.id = '_geo_b_' + Math.random().toString(36).slice(2)));
    });
  }

  /* ══════════════════════════════════════════════════════════════════
     INIT — punto de entrada principal
  ══════════════════════════════════════════════════════════════════ */
  async function init(sbUrl, sbAnon, onSedeReady) {
    if (_initialized) {
      if (onSedeReady) _callbacks.push(onSedeReady);
      if (onSedeReady && _sedeActiva) onSedeReady(_sedeActiva);
      return;
    }
    _initialized = true;
    _sbUrl  = sbUrl  || '';
    _sbAnon = sbAnon || '';
    if (onSedeReady) _callbacks.push(onSedeReady);

    inyectarEstilosModal();

    // Intentar recuperar desde sessionStorage antes del GPS
    resolverSedeActiva();
    if (_sedeActiva) {
      // Ya hay datos de sesión: solo recarga la lista de sedes en background
      notificar();
      fetchSedes().then(s => { _sedes = s || []; });
      return;
    }

    // Sin datos de sesión: carga sedes + GPS en paralelo
    const [sedes, gps] = await Promise.all([fetchSedes(), pedirGPS()]);
    _sedes = sedes || [];

    if (gps && _sedes.length) {
      // Inyectar distancias
      _sedes = _sedes.map(s => ({
        ...s,
        _dist: (s.lat && s.lng)
          ? Math.round(haversine(gps.lat, gps.lng, parseFloat(s.lat), parseFloat(s.lng)))
          : null
      }));
      const cercana = detectarSedeCercana(_sedes, gps.lat, gps.lng);
      if (cercana) {
        sessionStorage.setItem(KEY_DETECTED, JSON.stringify(cercana));
        _sedeActiva = cercana;
      }
    }

    // Si sigues sin sede (GPS denegado, sin sedes), toma la primera activa
    if (!_sedeActiva && _sedes.length) {
      _sedeActiva = _sedes[0];
    }

    notificar();
  }

  /* ══════════════════════════════════════════════════════════════════
     HELPER — limpiar sesión (útil para tests)
  ══════════════════════════════════════════════════════════════════ */
  function resetSesion() {
    sessionStorage.removeItem(KEY_MANUAL);
    sessionStorage.removeItem(KEY_DETECTED);
    _sedeActiva = null;
    _initialized = false;
  }

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ══════════════════════════════════════════════════════════════════
     API PÚBLICA
  ══════════════════════════════════════════════════════════════════ */
  global.GeoSede = {
    init,
    getSede:    () => _sedeActiva,
    openModal,
    renderBadge,
    resetSesion,
  };

})(window);

