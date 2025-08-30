let CSRF = null;
const $ = sel => document.querySelector(sel);
const $$ = sel => Array.from(document.querySelectorAll(sel));

// Utils
async function getCSRF(){ const r = await fetch('../api/csrf'); CSRF = (await r.json()).token; }
function jh(json=false){ const h={}; if(json) h['Content-Type']='application/json'; if(CSRF) h['X-CSRF-Token']=CSRF; return h; }
function api(url,opts={}){ return fetch(url,opts).then(async r=>{ const j = await r.json().catch(()=>({})); if(!r.ok) throw j; return j; }); }
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, ch=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch])); }
function hashHue(str){ let h=0; for(const c of (str||'')) h=(h*31 + c.charCodeAt(0))|0; return Math.abs(h)%360; }
function badge(label){ const hue = hashHue(label); return `<span class="badge" style="background:hsl(${hue} 70% 18% / 0.35); border-color:hsl(${hue} 70% 40% / 0.45)">${escapeHtml(label)}</span>`; }
function isOverdue(dateStr){ if(!dateStr) return false; const d = new Date(dateStr+'T00:00:00'); const today = new Date(); today.setHours(0,0,0,0); return d < today; }

// State
let currentBoardId = null;
let columnsCache = [];
let cardsCache = [];
let draggingCardId = null;
let draggingColumnId = null;

// Boards
async function loadBoards(){
  const j = await api('../api/boards');
  const sel = $('#boardSelect'); sel.innerHTML='';
  j.items.forEach(b=>{ const o=document.createElement('option'); o.value=b.id; o.textContent=b.name; sel.appendChild(o); });
  currentBoardId = Number(sel.value || j.items[0]?.id || 0);
  if(currentBoardId){ sel.value = currentBoardId; }
  return currentBoardId;
}
async function createBoard(){
  const name = $('#boardName').value.trim(); if(!name) return;
  const j = await api('../api/boards',{method:'POST',headers:jh(true),body:JSON.stringify({name})});
  $('#boardName').value=''; currentBoardId=j.item.id; await refreshAll();
}
async function deleteBoard(){
  if(!currentBoardId || !confirm('Excluir este board e tudo dentro?')) return;
  await api(`../api/boards/${currentBoardId}`,{method:'DELETE',headers:jh()});
  await refreshAll();
}

// Columns + Cards
async function loadColumns(){ const j = await api(`../api/columns?board_id=${currentBoardId}`); columnsCache=j.items; return columnsCache; }
async function loadCards(q=''){ const url = `../api/cards?board_id=${currentBoardId}` + (q?`&q=${encodeURIComponent(q)}`:''); const j = await api(url); cardsCache=j.items; return cardsCache; }

function render(){
  const root = $('#columns'); root.innerHTML='';
  const q = $('#search').value.trim().toLowerCase();

  const byCol = {};
  cardsCache.forEach(c => { (byCol[c.column_id] ||= []).push(c); });

  columnsCache.forEach(col => {
    const el = document.createElement('div');
    el.className = 'col';
    el.dataset.id = col.id;
    el.draggable = true;

    el.addEventListener('dragstart', onColumnDragStart);
    el.addEventListener('dragend', onColumnDragEnd);

    const header = document.createElement('div');
    header.className = 'col-header';

    const handle = document.createElement('div');
    handle.className = 'col-handle';
    handle.title = 'Arraste para mover coluna';
    handle.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4h4v2h-4zm0 14h4v2h-4zM4 10h2v4H4zm14 0h2v4h-2z"/></svg>';

    const title = document.createElement('div');
    title.className = 'col-title';
    title.textContent = col.name;

    const count = document.createElement('div');
    count.className = 'col-count';
    count.textContent = (byCol[col.id]||[]).length + ' itens';

    const actions = document.createElement('div');
    actions.className = 'col-actions';
    const btnRename = document.createElement('button'); btnRename.className='btn'; btnRename.textContent='Renomear';
    const btnDel = document.createElement('button'); btnDel.className='btn danger'; btnDel.textContent='Excluir';

    btnRename.onclick = async () => {
      const n = prompt('Novo nome da coluna:', col.name);
      if(!n) return;
      await api(`../api/columns/${col.id}`, { method:'PUT', headers: jh(true), body: JSON.stringify({ name: n }) });
      await refreshAll(currentBoardId);
    };
    btnDel.onclick = async () => {
      if(!confirm('Excluir coluna e seus cartões?')) return;
      await api(`../api/columns/${col.id}`, { method:'DELETE', headers: jh() });
      await refreshAll(currentBoardId);
    };

    header.append(handle, title, count, actions);
    actions.append(btnRename, btnDel);
    el.appendChild(header);

    const list = document.createElement('div');
    list.className = 'card-list';
    list.dataset.columnId = col.id;
    list.addEventListener('dragover', onCardDragOver);
    list.addEventListener('drop', onCardDrop);

    (byCol[col.id]||[]).forEach(c => {
      if(q && !(c.title.toLowerCase().includes(q) || (c.labels||'').toLowerCase().includes(q))) return;
      list.appendChild(renderCard(c));
    });

    const add = document.createElement('div');
    add.className = 'add-card';
    add.innerHTML = `<input placeholder="Novo cartão em ${escapeHtml(col.name)}"><button class="btn primary">Adicionar</button>`;
    add.querySelector('button').onclick = async () => {
      const title = add.querySelector('input').value.trim(); if(!title) return;
      await api('../api/cards',{method:'POST',headers:jh(true),body:JSON.stringify({board_id:currentBoardId,column_id:col.id,title})});
      await refreshAll(currentBoardId);
    };

    el.append(list, add);
    root.appendChild(el);
  });
}

function renderCard(c){
  const el = document.createElement('div');
  el.className = 'card';
  el.draggable = true;
  el.dataset.id = c.id;
  el.addEventListener('dragstart', onCardDragStart);

  const labels = (c.labels||'').split(',').map(s=>s.trim()).filter(Boolean);
  const labelsHtml = labels.map(badge).join(' ');
  const due = c.due_date ? `<span class="badge date ${isOverdue(c.due_date)?'overdue':''}">🗓 ${c.due_date}</span>` : '';
  el.innerHTML = `<div class="title">${escapeHtml(c.title)}</div>
    ${c.description?`<div class="desc">${escapeHtml(c.description)}</div>`:''}
    <div class="meta">${labelsHtml} ${due}</div>`;

  el.onclick = () => openCard(c);
  return el;
}

// Card drag and drop
function onCardDragStart(ev){ draggingCardId = Number(ev.currentTarget.dataset.id); ev.dataTransfer.effectAllowed='move'; }
function onCardDragOver(ev){
  ev.preventDefault();
  const list = ev.currentTarget;
  const after = getAfterElement(list, ev.clientY);
  const draggingEl = document.querySelector('.card[aria-dragging="true"]');
  if(!draggingEl){} // noop
}
function getAfterElement(container, y){
  const els = [...container.querySelectorAll('.card:not(.dragging)')];
  let closest = null; let closestOffset = Number.NEGATIVE_INFINITY;
  for(const el of els){
    const box = el.getBoundingClientRect();
    const offset = y - box.top - box.height/2;
    if(offset < 0 && offset > closestOffset){ closestOffset = offset; closest = el; }
  }
  return closest;
}
async function onCardDrop(ev){
  ev.preventDefault(); if(!draggingCardId) return;
  const list = ev.currentTarget;
  const children = [...list.querySelectorAll('.card')];
  const rects = children.map(el=>el.getBoundingClientRect().top);
  let to_position = 0;
  const y = ev.clientY;
  for(let i=0;i<children.length;i++){ if(y > rects[i]) to_position = i+1; }
  const to_column_id = Number(list.dataset.columnId);
  await api(`../api/cards/${draggingCardId}/move`,{method:'POST',headers:jh(true),body:JSON.stringify({to_column_id,to_position})});
  await refreshAll(currentBoardId);
  draggingCardId = null;
}

// Column drag
function onColumnDragStart(ev){
  draggingColumnId = Number(ev.currentTarget.dataset.id);
  ev.currentTarget.classList.add('dragging');
  ev.dataTransfer.effectAllowed='move';
  $('#columns').addEventListener('dragover', onColumnDragOver);
  $('#columns').addEventListener('drop', onColumnDrop);
}
function onColumnDragEnd(ev){
  ev.currentTarget.classList.remove('dragging');
  $('#columns').removeEventListener('dragover', onColumnDragOver);
  $('#columns').removeEventListener('drop', onColumnDrop);
}
function onColumnDragOver(ev){
  ev.preventDefault();
  const container = $('#columns');
  const cols = [...container.querySelectorAll('.col:not(.dragging)')];
  let index = cols.length;
  for(let i=0;i<cols.length;i++){
    const box = cols[i].getBoundingClientRect();
    if(ev.clientX < box.left + box.width/2){ index = i; break; }
  }
  let ph = container.querySelector('.col-placeholder');
  if(!ph){ ph = document.createElement('div'); ph.className='col-placeholder'; container.appendChild(ph); }
  const ref = cols[index] || null;
  container.insertBefore(ph, ref);
}
async function onColumnDrop(ev){
  ev.preventDefault();
  const container = $('#columns');
  const ph = container.querySelector('.col-placeholder');
  const to_position = [...container.children].indexOf(ph);
  if(ph) ph.remove();
  const id = draggingColumnId; draggingColumnId = null;
  await api(`../api/columns/${id}/move`, { method:'POST', headers: jh(true), body: JSON.stringify({ to_position }) });
  await refreshAll(currentBoardId);
}

// Card dialog
function openCard(c){
  const dlg = $('#dlgCard'); const f = $('#formCard');
  f.id.value = c.id;
  f.title.value = c.title || '';
  f.description.value = c.description || '';
  f.labels.value = c.labels || '';
  f.due_date.value = c.due_date || '';
  dlg.showModal();
}
async function saveCard(ev){
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const id = fd.get('id');
  const payload = Object.fromEntries(fd.entries());
  delete payload.id;
  await api(`../api/cards/${id}`,{method:'PUT',headers:jh(true),body:JSON.stringify(payload)});
  $('#dlgCard').close();
  await refreshAll(currentBoardId);
}

// Search
async function onSearch(){
  await loadCards($('#search').value.trim());
  render();
}

// Boot
function bindUI(){
  $('#btnCreateBoard').onclick = createBoard;
  $('#btnDeleteBoard').onclick = deleteBoard;
  $('#boardSelect').onchange = async e => { currentBoardId = Number(e.target.value); await refreshAll(currentBoardId); };
  $('#btnAddColumn').onclick = async () => {
    const name = prompt('Nome da nova coluna:');
    if(!name) return;
    await api('../api/columns',{method:'POST',headers:jh(true),body:JSON.stringify({board_id:currentBoardId,name})});
    await refreshAll(currentBoardId);
  };
  $('#btnCloseCard').onclick = () => $('#dlgCard').close();
  $('#formCard').addEventListener('submit', saveCard);
  $('#search').addEventListener('input', onSearch);
}

async function refreshAll(){
  await loadBoards();
  await Promise.all([loadColumns(), loadCards($('#search').value.trim())]);
  render();
}

(async function init(){
  await getCSRF();
  bindUI();
  await refreshAll();
})();
