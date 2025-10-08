'use strict';
const memoData=window.memoData||{};
const $=s=>document.querySelector(s); const $$=s=>Array.from(document.querySelectorAll(s));
const throttle=(fn,ms)=>{let t=0;return (...a)=>{const n=Date.now();if(n-t>ms){t=n;fn(...a);} }};
const newMenuToggle=document.getElementById('btn-new-menu');
const newMenu=document.getElementById('new-menu');
if(newMenuToggle && newMenu){
  const openMenu=()=>{ newMenu.dataset.open='true'; newMenuToggle.setAttribute('aria-expanded','true'); };
  const closeMenu=()=>{ newMenu.dataset.open='false'; newMenuToggle.setAttribute('aria-expanded','false'); };
  newMenuToggle.addEventListener('click',e=>{ e.preventDefault(); const isOpen=newMenu.dataset.open==='true'; if(isOpen){ closeMenu(); }else{ openMenu(); const first=newMenu.querySelector('a'); if(first){ first.focus(); } }});
  newMenuToggle.addEventListener('keydown',e=>{ if(e.key==='ArrowDown'){ e.preventDefault(); openMenu(); const first=newMenu.querySelector('a'); if(first){ first.focus(); } }});
  newMenu.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeMenu(); newMenuToggle.focus(); }});
  document.addEventListener('click',e=>{ if(!newMenu.contains(e.target) && e.target!==newMenuToggle){ closeMenu(); }});
}
window.addEventListener('keydown',e=>{
  if(e.key==='/' && !/input|textarea|select/i.test(document.activeElement.tagName)){
    e.preventDefault(); const q=document.querySelector('input[name="q"]'); if(q){ q.focus(); q.select(); }
  }
});
if(memoData.isMindmapCategory){
(function(){
  const mapsDataElement=document.getElementById('mind-maps-data');
  let mapsData=[];
  try{
    mapsData=mapsDataElement ? JSON.parse(mapsDataElement.textContent||'[]') : [];
  }catch(_){ mapsData=[]; }
  const mapsById=new Map((mapsData||[]).map(item=>[String(item.id), item]));
  const mindImportInput=document.getElementById('mind-import-file');
  const mindImportButton=document.getElementById('btn-import-map');
  const mindExportButton=document.getElementById('btn-export-maps');
  const mindImportModal=document.getElementById('map-import-modal');
  const mindImportFileName=document.getElementById('import-file-name');
  const mindImportTargetSelect=document.getElementById('import-target-select');
  let pendingImport=null;
  let pendingImportName='';
  if(mindImportTargetSelect){
    if(mapsData.length){
      mindImportTargetSelect.disabled=false;
      mindImportTargetSelect.value=mindImportTargetSelect.value || String(mapsData[0].id);
    }else{
      mindImportTargetSelect.disabled=true;
    }
  }
  if(mindImportModal){
    const targetButtons=Array.from(mindImportModal.querySelectorAll('[data-requires-target="true"]'));
    if(!mapsData.length){ targetButtons.forEach(btn=>btn.disabled=true); }
  }
  function openImportModal(){
    if(!mindImportModal) return;
    mindImportModal.dataset.open='true';
    if(mindImportFileName){ mindImportFileName.textContent=pendingImportName || '未选择文件'; }
  }
  function closeImportModal(){
    if(mindImportModal){ mindImportModal.dataset.open='false'; }
    pendingImport=null;
    pendingImportName='';
    if(mindImportInput){ mindImportInput.value=''; }
  }
  function resolveImportTitle(data,fallback){
    const metaName=data?.meta && typeof data.meta.name==='string' ? data.meta.name.trim() : '';
    const topicName=data?.data && typeof data.data.topic==='string' ? data.data.topic.trim() : '';
    if(metaName) return metaName;
    if(topicName) return topicName;
    if(typeof fallback==='string' && fallback.trim()) return fallback.trim();
    return '未命名导图';
  }
  function enforceRightOrientationFromRoot(node, depth=0){
    if(!node || typeof node!=='object') return;
    node.direction=depth===0?'center':'right';
    if(Array.isArray(node.children)){
      node.children=node.children.map(child=>{
        if(child && typeof child==='object'){ enforceRightOrientationFromRoot(child, depth+1); return child; }
        return null;
      }).filter(Boolean);
    }else{
      node.children=[];
    }
  }
  function cloneImportSubtree(source){
    if(!source || typeof source!=='object') return null;
    const cloned={
      id:'node-'+Math.random().toString(36).slice(2,10),
      topic:typeof source.topic==='string' && source.topic.trim()?source.topic.trim():'导入节点',
      data:source.data?JSON.parse(JSON.stringify(source.data)):{},
      expanded:source.expanded!==false,
      direction:'right',
      children:[],
    };
    if(source.meta){ cloned.meta=JSON.parse(JSON.stringify(source.meta)); }
    if(source.style){ cloned.style=JSON.parse(JSON.stringify(source.style)); }
    if(Array.isArray(source.children)){
      cloned.children=source.children.map(child=>cloneImportSubtree(child)).filter(Boolean);
    }
    return cloned;
  }
  async function saveMindmapRequest(id,title,data){
    const fd=new FormData();
    fd.append('action','save_mindmap');
    fd.append('id', String(id||0));
    fd.append('title', title || '未命名导图');
    fd.append('content', JSON.stringify(data));
    const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
    if(!res.ok) throw new Error('网络异常');
    const json=await res.json();
    if(!json.ok) throw new Error(json.error||'保存失败');
    return json;
  }
  async function handleImportMode(mode){
    if(!pendingImport){
      alert('请先选择导入文件');
      return;
    }
    const requiresTarget=mode==='replace' || mode==='append';
    const targetId=mindImportTargetSelect ? mindImportTargetSelect.value : '';
    if(requiresTarget){
      if(!targetId){ alert('请选择目标导图'); return; }
      if(!mapsById.has(String(targetId))){ alert('目标导图不存在'); return; }
    }
    try{
      if(mode==='new'){
        const payload=JSON.parse(JSON.stringify(pendingImport));
        if(payload && payload.data) enforceRightOrientationFromRoot(payload.data);
        const title=resolveImportTitle(payload,'');
        await saveMindmapRequest(0,title,payload);
      }else if(mode==='replace'){
        const target=mapsById.get(String(targetId));
        if(!target) throw new Error('目标导图不存在');
        const payload=JSON.parse(JSON.stringify(pendingImport));
        if(payload && payload.data) enforceRightOrientationFromRoot(payload.data);
        const title=resolveImportTitle(payload,target.title||'');
        await saveMindmapRequest(target.id,title,payload);
      }else if(mode==='append'){
        const target=mapsById.get(String(targetId));
        if(!target) throw new Error('目标导图不存在');
        let base;
        try{ base=JSON.parse(target.content); }
        catch(_){ throw new Error('目标导图内容无法解析'); }
        if(!base || typeof base!=='object' || !base.data){ throw new Error('目标导图缺少数据'); }
        const subtree=cloneImportSubtree(pendingImport.data || pendingImport);
        if(!subtree){ throw new Error('导入文件中缺少节点数据'); }
        if(!Array.isArray(base.data.children)){ base.data.children=[]; }
        base.data.children.push(subtree);
        enforceRightOrientationFromRoot(base.data);
        const title=target.title || resolveImportTitle(pendingImport,'');
        await saveMindmapRequest(target.id,title,base);
      }else{
        return;
      }
      alert('导入成功');
      closeImportModal();
      location.reload();
    }catch(err){
      alert(err instanceof Error ? err.message : '导入失败');
    }
  }
  function handleImportFile(event){
    const file=event.target.files && event.target.files[0];
    if(!file) return;
    const reader=new FileReader();
    reader.onload=evt=>{
      try{
        const json=JSON.parse(evt.target.result);
        if(!json || typeof json!=='object' || !json.data){ throw new Error('文件格式不兼容'); }
        pendingImport=json;
        pendingImportName=file.name;
        if(mindImportTargetSelect && mapsData.length){
          mindImportTargetSelect.disabled=false;
          if(!mindImportTargetSelect.value){ mindImportTargetSelect.value=String(mapsData[0].id); }
        }
        openImportModal();
      }catch(err){
        pendingImport=null;
        pendingImportName='';
        alert(err instanceof Error ? err.message : '无法解析导图文件');
      }finally{
        if(mindImportInput){ mindImportInput.value=''; }
      }
    };
    reader.onerror=()=>{
      pendingImport=null;
      pendingImportName='';
      alert('读取文件失败');
      if(mindImportInput){ mindImportInput.value=''; }
    };
    reader.readAsText(file,'utf-8');
  }
  function buildTimestamp(){
    const now=new Date();
    const pad=n=>String(n).padStart(2,'0');
    return `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
  }
  function exportAllMaps(){
    if(!mapsData.length){
      alert('暂无导图可导出');
      return;
    }
    const payload=mapsData.map(map=>{
      let content;
      try{ content=JSON.parse(map.content); }
      catch(_){ content=map.content; }
      return {
        id: map.id,
        title: map.title,
        created_at: map.created_at,
        updated_at: map.updated_at,
        content,
      };
    });
    const blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;
    a.download=`mindmaps-${buildTimestamp()}.json`;
    a.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  if(mindImportButton && mindImportInput){
    mindImportButton.addEventListener('click',()=>{
      pendingImport=null;
      pendingImportName='';
      mindImportInput.click();
    });
  }
  if(mindImportInput){
    mindImportInput.addEventListener('change',handleImportFile);
  }
  if(mindExportButton){
    mindExportButton.addEventListener('click',exportAllMaps);
  }
  if(mindImportModal){
    mindImportModal.addEventListener('click',e=>{ if(e.target===mindImportModal){ closeImportModal(); }});
    mindImportModal.querySelectorAll('[data-mode]').forEach(btn=>{
      btn.addEventListener('click',e=>{ e.preventDefault(); handleImportMode(btn.dataset.mode); });
    });
    const cancelBtn=mindImportModal.querySelector('[data-action="cancel"]');
    if(cancelBtn){ cancelBtn.addEventListener('click',e=>{ e.preventDefault(); closeImportModal(); }); }
  }
  const mindSearchInput=document.getElementById('mindmap-search');
  const mindGrid=document.getElementById('mindmap-grid');
  const mindCards=mindGrid?Array.from(mindGrid.querySelectorAll('.mindmap-card')):[];
  const emptyFilter=document.getElementById('mindmap-empty-filter');
  const applyFilter=()=>{
    if(!mindSearchInput || !mindGrid) return;
    const q=mindSearchInput.value.trim().toLowerCase();
    let visibleCount=0;
    mindCards.forEach(card=>{
      const text=(card.dataset.title+' '+card.dataset.outline).toLowerCase();
      const match = q==='' || text.includes(q);
      card.style.display = match ? '' : 'none';
      if(match) visibleCount++;
    });
    if(emptyFilter){ emptyFilter.style.display = visibleCount===0 ? '' : 'none'; }
  };
  if(mindSearchInput){
    mindSearchInput.addEventListener('input',applyFilter);
    if(mindSearchInput.value.trim()!==''){ applyFilter(); }
  }
})();
}
const itemsContainer=document.getElementById('items');
const currentCategoryFilter=String(memoData.currentCategoryFilter ?? 'all');
const memoImportButton=document.getElementById('btn-import-items');
const memoImportInput=document.getElementById('memo-import-input');
const toastContainer=document.getElementById('toast-container');
const densityButtons=$$('.density-option');
const deleteForms=$$('.form-delete-item');
const DENSITY_KEY='memo-density';
function safeStorageGet(key){ try{ return window.localStorage.getItem(key); }catch(_){ return null; }}
function safeStorageSet(key,value){ try{ window.localStorage.setItem(key,value); }catch(_){ }}
function applyDensity(mode,{persist=true}={}){
  const value=mode==='compact'?'compact':'comfortable';
  document.body.dataset.density=value;
  densityButtons.forEach(btn=>{ btn.classList.toggle('active', btn.dataset.density===value); });
  if(persist){ safeStorageSet(DENSITY_KEY,value); }
}
const initialDensity=safeStorageGet(DENSITY_KEY);
applyDensity(initialDensity==='compact'?'compact':'comfortable',{persist:false});
densityButtons.forEach(btn=>{ btn.addEventListener('click',()=>applyDensity(btn.dataset.density)); });
function showToast(message, actions=[]){
  if(!toastContainer) return;
  const toast=document.createElement('div');
  toast.className='toast';
  const text=document.createElement('div');
  text.className='toast-message';
  text.textContent=message;
  toast.appendChild(text);
  actions.forEach(action=>{
    const btn=document.createElement('button');
    btn.type='button';
    btn.className='btn btn-ghost btn-small';
    btn.textContent=action && action.label ? action.label : '操作';
    btn.addEventListener('click',()=>{
      try{ if(action && typeof action.onClick==='function'){ action.onClick(); } }catch(_){ }
      toast.remove();
    });
    toast.appendChild(btn);
  });
  toastContainer.appendChild(toast);
  setTimeout(()=>{ if(toast.isConnected){ toast.remove(); } },5000);
}
async function undoDelete(token){
  if(!token) return;
  const fd=new FormData(); fd.append('action','undo_delete_item'); fd.append('token', token);
  try{
    const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
    const data=await res.json().catch(()=>null);
    if(!res.ok || !data || !data.ok){ throw new Error((data && data.error) ? data.error : `撤销失败（${res.status}）`); }
    window.location.reload();
  }catch(err){
    alert(err instanceof Error ? err.message : '撤销失败');
  }
}
deleteForms.forEach(form=>{
  form.addEventListener('submit',ev=>{ ev.preventDefault(); handleDeleteForm(form); });
});
async function handleDeleteForm(form){
  if(form.dataset.loading==='1') return;
  form.dataset.loading='1';
  const fd=new FormData(form);
  const card=form.closest('article.item');
  const title=card ? (card.dataset.title || '备忘录') : '备忘录';
  try{
    const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
    const data=await res.json().catch(()=>null);
    if(!res.ok || !data || !data.ok){
      const msg=(data && data.error) ? data.error : `删除失败（${res.status}）`;
      throw new Error(msg);
    }
    if(card){
      card.classList.add('item-removing');
      setTimeout(()=>{ if(card.isConnected){ card.remove(); ensureItemsEmptyState(); } },220);
    }
    showToast(`已删除 · ${title}`, data.undo_token ? [{label:'撤销', onClick:()=>undoDelete(data.undo_token)}] : []);
    try{
      const {cats,counts,total,mindmap_total}=await fetchCats();
      refreshSidebarCats(cats,counts,total,mindmap_total);
    }catch(_){ }
  }catch(err){
    alert(err instanceof Error ? err.message : '删除失败');
  }finally{
    form.dataset.loading='';
  }
}
if(memoImportButton && memoImportInput){
  const importButtonLabel=memoImportButton.textContent;
  memoImportButton.addEventListener('click',()=>{ memoImportInput.click(); });
  memoImportInput.addEventListener('change',async ()=>{
    const file=memoImportInput.files && memoImportInput.files[0];
    if(!file){ return; }
    const confirmMessage=`确定要导入“${file.name}”吗？导入的条目将追加到当前列表。`;
    if(!window.confirm(confirmMessage)){ memoImportInput.value=''; return; }
    memoImportButton.disabled=true;
    memoImportButton.textContent='导入中…';
    try{
      const fd=new FormData();
      fd.append('action','import_items');
      fd.append('file',file);
      const res=await fetch(window.location.pathname + window.location.search,{
        method:'POST',
        body:fd,
        headers:{'X-Requested-With':'XMLHttpRequest'}
      });
      const data=await res.json().catch(()=>null);
      if(!res.ok || !data || !data.ok){
        const errorMessage=(data && data.error) ? data.error : `导入失败（${res.status}）`;
        throw new Error(errorMessage);
      }
      const summaryParts=[`成功导入 ${data.imported ?? 0} 条备忘录`];
      if(data.skipped){ summaryParts.push(`跳过 ${data.skipped} 条`); }
      if(Array.isArray(data.created_categories) && data.created_categories.length){
        summaryParts.push(`新增分类：${data.created_categories.join('、')}`);
      }
      alert(summaryParts.join('，'));
      window.location.reload();
    }catch(err){
      alert(err instanceof Error ? err.message : '导入失败');
    }finally{
      memoImportInput.value='';
      memoImportButton.disabled=false;
      memoImportButton.textContent=importButtonLabel;
    }
  });
}
function ensureItemsEmptyState(){
  if(!itemsContainer) return;
  const hasCard=itemsContainer.querySelector('article.item');
  const placeholder=itemsContainer.querySelector('.item-empty');
  if(hasCard){
    if(placeholder) placeholder.remove();
    return;
  }
  if(!placeholder){
    const empty=document.createElement('div');
    empty.className='item item-empty';
    empty.textContent='没有条目 · No items';
    itemsContainer.appendChild(empty);
  }
}
function getColumnCount(){
  if(window.matchMedia('(max-width: 920px)').matches) return 1;
  if(window.matchMedia('(max-width: 1200px)').matches) return 2;
  return 3;
}
function moveCard(id, direction){
  const grid=$('#items'); if(!grid) return;
  const cards=$$('article.item');
  const index=cards.findIndex(card=>card.dataset.id===String(id));
  if(index===-1) return;
  const columns=getColumnCount();
  let offset=0;
  switch(direction){
    case 'left': {
      if(index===0) return;
      offset=-1;
      break;
    }
    case 'right': {
      if(index===cards.length-1) return;
      offset=1;
      break;
    }
    case 'up': {
      offset=-columns;
      break;
    }
    case 'down': {
      offset=columns;
      break;
    }
    default: {
      if(typeof direction==='number' && direction!==0){ offset=direction; }
      break;
    }
  }
  if(offset===0) return;
  const targetIndex=index+offset;
  if(targetIndex<0 || targetIndex>=cards.length) return;
  const card=cards[index];
  const target=cards[targetIndex];
  if(offset>0){
    grid.insertBefore(card, target.nextElementSibling);
  }else{
    grid.insertBefore(card, target);
  }
  sendOrder();
}
function sendOrder(){
  const ids=$$('article.item').map(x=>x.dataset.id).join(',');
  const fd=new FormData(); fd.append('action','reorder_items'); fd.append('order', ids);
  fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
}
const catModal = document.getElementById('cat-modal');
document.getElementById('btn-cat-mgr').onclick = openCatModal;
function openCatModal(){ catModal.style.display='flex'; renderCatRowsFromDOM(); }
function closeCatModal(){ catModal.style.display='none'; }
function renderCatRows(cats, counts){
  const box=document.getElementById('cat-rows'); box.innerHTML='';
  cats.forEach(c=>{
    const row=document.createElement('div');
    row.innerHTML=`\
      <form onsubmit="return saveCat(event, ${c.id})" class="modal-form">\
        <input type="text" name="name" value="${escapeHtml(c.name)}" class="modal-input"/>\
        <span class="modal-count">共 ${counts[c.id]||0}</span>\
        <button class="btn btn-outline btn-small">保存</button>\
        <button class="btn btn-danger btn-small" onclick="return delCat(${c.id}, '${escapeHtml(c.name)}')">删除</button>\
      </form>`;
    box.appendChild(row);
  });
}
function renderCatRowsFromDOM(){ fetchCats().then(({cats,counts,total,mindmap_total})=>{renderCatRows(cats,counts); refreshSidebarCats(cats,counts,total,mindmap_total);}); }
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"'"}[m])); }
async function fetchCats(){
  const fd=new FormData(); fd.append('action','ping_cats');
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(!j.ok) throw new Error('加载分类失败'); return j;
}
async function addCat(ev){
  ev.preventDefault();
  const name=document.getElementById('new-cat-name').value.trim(); if(!name) return false;
  const fd=new FormData(); fd.append('action','add_category'); fd.append('name', name);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ document.getElementById('new-cat-name').value=''; renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total,j.mindmap_total); }
  return false;
}
async function saveCat(ev, id){
  ev.preventDefault();
  const name=new FormData(ev.target).get('name');
  const fd=new FormData(); fd.append('action','edit_category'); fd.append('id', id); fd.append('name', name);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total,j.mindmap_total); }
  return false;
}
async function delCat(id, name){
  if(!confirm(`确认删除分类【${name}】？该分类下条目将移入“其他”。`)) return false;
  const fd=new FormData(); fd.append('action','delete_category'); fd.append('id', id);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total,j.mindmap_total); }
  return false;
}
function refreshSidebarCats(cats, counts, total, mindmapTotal){
  const qParam=new URL(location.href).searchParams.get('q')||'';
  const urlCat=(new URL(location.href)).searchParams.get('cat')||'all';
  const list=document.getElementById('cat-list'); list.innerHTML='';
  const all=document.createElement('a');
  all.className='cat'+(urlCat==='all'?' active':'');
  all.href='?cat=all&q='+encodeURIComponent(qParam);
  all.innerHTML='<span class="name">全部 · All</span><span class="count">'+(total??0)+'</span>';
  list.appendChild(all);
  cats.forEach(c=>{
    const link=document.createElement('a');
    link.className='cat'+(String(urlCat)===String(c.id)?' active':'');
    link.dataset.id=c.id;
    link.href='?cat='+c.id+'&q='+encodeURIComponent(qParam);
    link.innerHTML=`<span class="name">${escapeHtml(c.name)}</span><span class="count">${counts[c.id]||0}</span>`;
    list.appendChild(link);
  });
  const mindLink=document.createElement('a');
  mindLink.className='cat'+(urlCat==='mindmaps'?' active':'');
  mindLink.dataset.id='mindmaps';
  mindLink.href='?cat=mindmaps&q='+encodeURIComponent(qParam);
  mindLink.innerHTML='<span class="name">思维导图</span><span class="count">'+(mindmapTotal??0)+'</span>';
  list.appendChild(mindLink);
}
function fmt(ts){ const d=new Date(ts*1000); const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; }
if(itemsContainer){
  itemsContainer.addEventListener('change', async (e)=>{
    const t=e.target;
    if(t.classList.contains('item-toggle')){
      const card=t.closest('article.item'); if(!card) return;
      const id=card.dataset.id; const done=t.checked?1:0;
      const fd=new FormData(); fd.append('action','toggle_done'); fd.append('id', id); fd.append('done', done);
      try{
        const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
        if(j && j.ok){
          const newCategoryId=(j.category_id===null || typeof j.category_id==='undefined') ? '' : String(j.category_id);
          card.dataset.categoryId=newCategoryId;
          card.classList.toggle('done', !!j.done);
          const badge=card.querySelector('.js-updated'); if(badge&&j.updated_at) badge.textContent='更新 '+fmt(j.updated_at);
          const categoryBadge=card.querySelector('.meta .badge:not(.js-updated)');
          if(categoryBadge && j.category_label){ categoryBadge.textContent=j.category_label; }
          if(currentCategoryFilter==='all'){
            if(j.done){
              card.remove();
              ensureItemsEmptyState();
            }
          } else if(currentCategoryFilter!==newCategoryId){
            card.remove();
            ensureItemsEmptyState();
          }
          try{
            const {cats,counts,total,mindmap_total}=await fetchCats();
            refreshSidebarCats(cats,counts,total,mindmap_total);
          }catch(_){ }
        }
      }catch(_){ }
    }
    if(t.classList.contains('step-toggle')){
      const row=t.closest('.tlrow'); if(!row) return;
      const stepId=row.querySelector('input[name="id"]').value;
      const done=t.checked?1:0;
      const fd=new FormData(); fd.append('action','toggle_step'); fd.append('id', stepId); fd.append('done', done);
      try{
        const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
        if(j && j.ok){
          row.classList.toggle('done', !!done);
          const card=row.closest('article.item'); const badge=card && card.querySelector('.js-updated');
          if(badge && j.updated_at) badge.textContent='更新 '+fmt(j.updated_at);
        }
      }catch(_){ }
    }
  });
}
