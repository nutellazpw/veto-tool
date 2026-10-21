/* Shared renderers for tablet + admin (relog.gg markup) */
const UI = (() => {
  const { esc } = RV;

  function teams(el, v) {
    const active = v.current && v.current.team;
    el.innerHTML = ['a', 'b'].map((t, i) => `
      ${i === 1 ? '<div class="vs">VS</div>' : ''}
      <div class="team ${t} ${active === t ? 'active' : ''}">
        <div class="logo-box">${RV.teamLogoHTML(v, t)}</div>
        <div class="info">
          <div class="label">Team ${t.toUpperCase()}${v.s.teams[t].seed ? ` · Seed #${v.s.teams[t].seed}` : ''}</div>
          <div class="name">${esc(v.s.teams[t].name)}</div>
          <div class="turn"><span class="live-badge"><img src="assets/live-icon.png" alt="">On turn</span></div>
        </div>
      </div>`).join('');
  }

  function banner(el, v) {
    const h = RV.headline(v);
    const prog = v.steps.map((_, i) => `<span class="${i < v.idx ? 'done' : i === v.idx ? 'now' : ''}"></span>`).join('');
    el.innerHTML = `
      <div class="step-cat ${h.cls}">${esc(h.tag)}</div>
      <div class="step-title">${h.html}</div>
      ${h.sub ? `<div class="step-sub">${esc(h.sub)}</div>` : ''}
      <div class="progress">${prog}</div>`;
  }

  /** Step strip: one box per ban / pick / decider, side choice folded into its map. */
  function timeline(el, v) {
    const boxes = [];
    let n = 0;
    v.steps.forEach((st, i) => {
      if (st.type === 'side') return;
      const act = v.s.actions[i];
      const next = v.steps[i + 1];
      const sideIdx = next && next.type === 'side' ? i + 1 : -1;
      const sideAct = sideIdx > -1 ? v.s.actions[sideIdx] : null;
      const isNow = v.idx === i || (sideIdx > -1 && v.idx === sideIdx);
      let value = 'Waiting', state = 'wait';
      if (act) { value = RV.mapName(v, act.map); state = 'done'; }
      else if (isNow) { value = st.type === 'decider' ? 'Last map' : (st.type === 'ban' ? 'Banning…' : 'Picking…'); state = 'now'; }
      let side = '';
      if (sideAct) side = `<div class="tl-side ${sideAct.side}">${esc(RV.teamName(v, sideAct.team))} · ${RV.sideLabel(sideAct.side)}</div>`;
      else if (sideIdx > -1 && v.idx === sideIdx) side = `<div class="tl-side pending">${esc(RV.teamName(v, next.team))} picks side…</div>`;
      else if (st.type === 'decider' && act && v.s.deciderSide === 'knife' && v.s.sidePick) side = '<div class="tl-side">Knife for side</div>';
      else if (st.type === 'decider' && act && !v.s.sidePick) side = '';
      const who = st.type === 'decider' ? 'Decider' : RV.teamName(v, st.team);
      boxes.push(`
        <div class="tl ${st.type} ${state}${isNow ? ' current' : ''}">
          <div class="tl-top"><span class="tl-n">${++n}</span><span class="tl-type">${st.type}</span></div>
          <div class="tl-who">${esc(who)}</div>
          <div class="tl-val">${esc(value)}</div>
          ${side}
        </div>`);
    });
    el.style.setProperty('--cols', boxes.length);
    el.innerHTML = boxes.join('');
  }

  function sideTag(v, team, side) {
    return `<span class="tag side ${side}">${esc(RV.teamName(v, team))} · ${RV.sideLabel(side)}</span>`;
  }

  function mapTile(v, id, clickable) {
    const st = v.status[id] || { state: 'available' };
    const bg = RV.mapBg(v, id);
    const cur = v.current;
    const canClick = clickable && st.state === 'available' && cur && (cur.type === 'ban' || cur.type === 'pick');
    let status = '';
    if (st.state === 'banned') status = `<span class="tag ban">Banned</span><span class="tag by">${esc(RV.teamName(v, st.team))}</span>`;
    else if (st.state === 'picked') status = `<span class="tag pick">Picked</span><span class="tag by">${esc(RV.teamName(v, st.team))}</span>`;
    else if (st.state === 'decider') status = `<span class="tag decider">Decider</span>`;
    else if (canClick) status = `<span class="hint">Tap to ${cur.type}</span>`;
    else status = `<span class="hint">Available</span>`;
    const side = st.side ? `<div class="status">${sideTag(v, st.sideTeam, st.side)}</div>` : '';
    return `
      <button class="map ${st.state} ${canClick ? 'clickable' : ''}" data-map="${id}" ${canClick ? '' : 'tabindex="-1"'}>
        <div class="img"><div class="${bg.cls}" style="${bg.style}" ${bg.attr}></div>${st.order ? `<span class="ord">#${st.order}</span>` : ''}</div>
        <div class="body">
          <div class="mname">${esc(RV.mapName(v, id))}</div>
          <div class="status">${status}</div>
          ${side}
        </div>
      </button>`;
  }

  function maps(el, v, clickable, onPick) {
    el.innerHTML = v.s.pool.map(id => mapTile(v, id, clickable)).join('');
    if (clickable) el.querySelectorAll('.map.clickable').forEach(b => b.addEventListener('click', () => onPick(b.dataset.map)));
  }

  function sidePicker(el, v, onSide) {
    el.innerHTML = `
      <button class="sidebtn ct" data-side="ct"><div class="big">CT</div><div class="small">Counter-Terrorists</div></button>
      <button class="sidebtn t" data-side="t"><div class="big">T</div><div class="small">Terrorists</div></button>`;
    el.querySelectorAll('.sidebtn').forEach(b => b.addEventListener('click', () => onSide(b.dataset.side)));
  }

  function results(el, v) {
    el.innerHTML = v.played.map((p, i) => {
      let side = '<span class="tag side" style="color:var(--grey);border-color:var(--line-soft)">Side TBD</span>';
      if (p.side) side = sideTag(v, p.sideTeam, p.side).replace(' · ', ' starts ');
      else if (p.knife) side = '<span class="tag side knife">Knife for side</span>';
      return `
        <div class="played-row">
          <div class="num">Map ${i + 1}</div>
          <div>
            <div class="mname">${esc(RV.mapName(v, p.map))}</div>
            <div class="how">${p.how === 'pick' ? `${esc(RV.teamName(v, p.team))} pick` : 'Decider'}</div>
          </div>
          <div class="right">${side}</div>
        </div>`;
    }).join('');
  }

  /** Confirm dialog for map choice. Returns a Promise<boolean>. */
  function confirmMap(v, id, verb) {
    return new Promise(resolve => {
      let bgEl = document.getElementById('confirm');
      if (!bgEl) {
        bgEl = document.createElement('div');
        bgEl.id = 'confirm'; bgEl.className = 'overlay-bg';
        document.body.appendChild(bgEl);
      }
      const bg = RV.mapBg(v, id);
      const team = RV.teamName(v, v.current.team);
      bgEl.innerHTML = `
        <div class="sheet">
          <div class="img"><div class="${bg.cls}" style="${bg.style}" ${bg.attr}></div></div>
          <div class="body">
            <div class="step-cat ${verb === 'ban' ? 'ban' : ''}">${esc(team)} · confirm ${verb}</div>
            <div class="q">${verb === 'ban' ? 'Ban' : 'Pick'} <span class="tn">${esc(RV.mapName(v, id))}</span>?</div>
            <div class="btns">
              <button class="btn ghost" data-r="0">Cancel</button>
              <button class="btn ${verb === 'ban' ? 'ban' : ''}" data-r="1">${verb === 'ban' ? 'Ban' : 'Pick'} map</button>
            </div>
          </div>
        </div>`;
      bgEl.classList.add('open');
      const close = r => { bgEl.classList.remove('open'); resolve(r); };
      bgEl.querySelectorAll('[data-r]').forEach(b => b.onclick = () => close(b.dataset.r === '1'));
      bgEl.onclick = e => { if (e.target === bgEl) close(false); };
    });
  }

  return { teams, banner, timeline, maps, sidePicker, results, confirmMap };
})();
