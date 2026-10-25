/* RELOG VETO — shared client */
const RV = (() => {
  const API = 'api.php';
  const MAP_TINT = {
    ancient: '#2f7d4f', anubis: '#c9a24a', cache: '#4d8a5a', dust2: '#d0a15c', inferno: '#c2512d',
    mirage: '#d79a4a', nuke: '#3d7fbf', overpass: '#6a8f7a', train: '#7a7f8a', vertigo: '#6aa0c8',
  };
  const LOGO_URL = 'assets/relog-logo.png';

  let payload = null;
  let version = -1;
  let token = '';
  const setToken = t => { token = t || ''; version = -1; };
  const listeners = [];

  async function get(force) {
    const r = await fetch(API + '?since=' + (force ? -1 : version), { cache: 'no-store', headers: token ? { 'X-Admin-Token': token } : {} });
    const j = await r.json();
    if (j.same) return false;
    set(j);
    return true;
  }

  function set(j) {
    payload = j;
    version = j.state.version;
    listeners.forEach(fn => fn(view()));
  }

  async function post(body) {
    const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({ error: 'Server error' }));
    if (j.state) set(j);
    else if (j.payload) set(j.payload);
    if (!r.ok) throw new Error(j.error || 'Error');
    return j;
  }

  async function upload(form) {
    const r = await fetch(API, { method: 'POST', body: form });
    const j = await r.json().catch(() => ({ error: 'Server error' }));
    if (j.state) set(j);
    else if (j.payload) set(j.payload);
    if (!r.ok) throw new Error(j.error || 'Error');
    return j;
  }

  function poll(fn, ms = 800) {
    listeners.push(fn);
    const conn = document.querySelector('.conn');
    let fails = 0;
    const tick = async () => {
      try { await get(); fails = 0; conn && conn.classList.remove('show'); }
      catch (e) { if (++fails > 2 && conn) conn.classList.add('show'); }
      setTimeout(tick, document.hidden ? 3000 : ms);
    };
    get(true).catch(() => {}).finally(() => setTimeout(tick, ms));
  }

  /** Derive everything a screen needs from raw payload. */
  function view() {
    const { state: s, steps, catalog, mapImages, db, settings } = payload;
    const idx = s.actions.length;
    const current = steps[idx] || null;
    const done = !current;
    const status = {};
    s.pool.forEach(id => status[id] = { state: 'available' });
    let order = 0;
    const played = [];
    s.actions.forEach(a => {
      if (a.type === 'ban') status[a.map] = { state: 'banned', team: a.team, order: ++order };
      else if (a.type === 'pick') { status[a.map] = { state: 'picked', team: a.team, order: ++order }; played.push({ map: a.map, how: 'pick', team: a.team }); }
      else if (a.type === 'decider') { status[a.map] = { state: 'decider', order: ++order }; played.push({ map: a.map, how: 'decider' }); }
      else if (a.type === 'side') {
        const p = played.find(x => x.map === a.map);
        if (p) { p.sideTeam = a.team; p.side = a.side; }
        if (status[a.map]) { status[a.map].sideTeam = a.team; status[a.map].side = a.side; }
      }
    });
    // decider side by knife
    played.forEach(p => { if (p.how === 'decider' && !p.side && s.deciderSide === 'knife') p.knife = true; });

    let sideMap = null;
    if (current && current.type === 'side') {
      for (let i = s.actions.length - 1; i >= 0; i--) {
        if (['pick', 'decider'].includes(s.actions[i].type)) { sideMap = s.actions[i].map; break; }
      }
    }
    const coin = s.coin || null;
    const coinPending = !!(coin && !coin.choice && !s.actions.length);
    return { s, steps, idx, current, done, status, played, catalog, mapImages, sideMap, coin, coinPending, db: db || [], settings: settings || {} };
  }

  const teamName = (v, t) => t ? v.s.teams[t].name : '';
  const mapName = (v, id) => v.catalog[id] || id;
  const sideLabel = x => x === 'ct' ? 'CT' : 'T';

  function mapBg(v, id) {
    const img = v.mapImages[id];
    return img
      ? { cls: 'bg', style: `background-image:url('${img}')`, attr: '' }
      : { cls: 'bg fallback', style: '', attr: `data-name="${esc(mapName(v, id))}"` };
  }

  function teamLogoHTML(v, t) {
    const team = v.s.teams[t];
    return team.logo
      ? `<img src="${esc(team.logo)}" alt="">`
      : `<span class="initial">${esc((team.name || '?').trim().charAt(0).toUpperCase())}</span>`;
  }

  function stepVerb(step) {
    return { ban: 'Ban', pick: 'Pick', side: 'Side', decider: 'Decider' }[step.type];
  }

  function headline(v) {
    const total = v.steps.length;
    if (v.coinPending) {
      const w = `<span class="tn">${esc(teamName(v, v.coin.winner))}</span>`;
      return { tag: 'Coin toss', cls: '', html: `${w} won the toss`, sub: 'Ban first or ban second?' };
    }
    if (v.done) return { tag: `${v.s.format.toUpperCase()} · Veto complete`, cls: '', html: 'Veto <span class="tn">complete</span>', sub: 'Good luck, have fun.' };
    const c = v.current;
    const n = `Step ${v.idx + 1} / ${total}`;
    const tn = `<span class="tn">${esc(teamName(v, c.team))}</span>`;
    if (c.type === 'ban') return { tag: `${n} · Ban`, cls: 'ban', html: `${tn} bans a map`, sub: `${remaining(v)} maps remaining` };
    if (c.type === 'pick') return { tag: `${n} · Pick`, cls: '', html: `${tn} picks a map`, sub: `${remaining(v)} maps remaining` };
    if (c.type === 'side') return { tag: `${n} · Side`, cls: '', html: `${tn} chooses side`, sub: `on ${mapName(v, v.sideMap)}` };
    return { tag: 'Decider', cls: '', html: 'Decider', sub: '' };
  }

  const remaining = v => Object.values(v.status).filter(x => x.state === 'available').length;

  function summaryText(v) {
    const s = v.s, A = s.teams.a.name, B = s.teams.b.name;
    const lines = [`${s.title}`, `${A} vs ${B} — ${s.format.toUpperCase()}`, ''];
    let n = 0;
    s.actions.forEach(a => {
      const t = a.team ? s.teams[a.team].name : '';
      if (a.type === 'ban') lines.push(`${++n}. ${t} banned ${mapName(v, a.map)}`);
      else if (a.type === 'pick') lines.push(`${++n}. ${t} picked ${mapName(v, a.map)}`);
      else if (a.type === 'decider') lines.push(`${++n}. ${mapName(v, a.map)} was left over (decider)`);
      else if (a.type === 'side') lines.push(`   ${t} chose ${sideLabel(a.side)} on ${mapName(v, a.map)}`);
    });
    if (v.done && v.played.length) {
      lines.push('', 'Maps:');
      v.played.forEach((p, i) => {
        let side = '';
        if (p.side) side = ` — ${s.teams[p.sideTeam].name} ${sideLabel(p.side)}`;
        else if (p.knife) side = ' — knife for side';
        lines.push(`Map ${i + 1}: ${mapName(v, p.map)} (${p.how === 'pick' ? s.teams[p.team].name + ' pick' : 'decider'})${side}`);
      });
    }
    return lines.join('\n');
  }

  function esc(x) { return String(x ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  let toastT;
  function toast(msg, err) {
    let el = document.querySelector('.toast');
    if (!el) { el = document.createElement('div'); el.className = 'toast'; document.body.appendChild(el); }
    el.textContent = msg; el.classList.toggle('err', !!err); el.classList.add('show');
    clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('show'), 2600);
  }

  return { setToken, get, post, upload, poll, view, set, teamName, mapName, sideLabel, mapBg, teamLogoHTML, headline, summaryText, stepVerb, esc, toast, MAP_TINT, LOGO_URL, get payload() { return payload; } };
})();
