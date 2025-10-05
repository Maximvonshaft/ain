(function(){
  if (window.OfflineManager) {
    return;
  }
  const QUEUE_HEADER = 'X-Offline-Queued';
  const STORAGE_PREFIX = 'memo-offline::';
  const PING_ENDPOINT = (window.__MEMO_PING_ENDPOINT__ || 'ping.json');
  const HEARTBEAT_INTERVAL = 5000;
  const OFFLINE_THRESHOLD = 3;
  class OfflineManager {
    constructor(){
      this.online = navigator.onLine;
      this.offlineCount = 0;
      this.queueProcessing = false;
      this.dbPromise = this.initDB();
      this.queueCache = null;
      this.snapshotFallback = {};
      this.banner = this.createBanner();
      this.insertStyles();
      window.addEventListener('online', ()=>this.handleBrowserOnline());
      window.addEventListener('offline', ()=>this.handleBrowserOffline());
      document.addEventListener('visibilitychange', ()=>{
        if(document.visibilityState === 'visible'){ this.checkConnectivity(); }
      });
      this.startHeartbeat();
      this.restoreInitialStatus();
      this.processQueue();
    }
    insertStyles(){
      if(document.getElementById('offline-manager-style')) return;
      const style=document.createElement('style');
      style.id='offline-manager-style';
      style.textContent=`
        #offline-status-banner{position:fixed;top:0;left:0;right:0;z-index:9999;display:none;align-items:center;justify-content:center;font:600 12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;padding:10px 16px;background:linear-gradient(135deg,rgba(209,75,75,.9),rgba(122,38,38,.92));color:#ffecec;box-shadow:0 12px 28px rgba(0,0,0,.45)}
        #offline-status-banner[data-state="offline"]{display:flex}
        #offline-status-banner .desc{margin-left:12px;font-size:11px;letter-spacing:.1em;text-transform:none}
        #offline-status-banner .actions{margin-left:16px;display:flex;gap:10px}
        #offline-status-banner button{background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.35);color:inherit;padding:6px 12px;border-radius:999px;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}
        #offline-status-banner button:hover{background:rgba(0,0,0,.35)}
      `;
      document.head.appendChild(style);
    }
    createBanner(){
      const banner=document.createElement('div');
      banner.id='offline-status-banner';
      banner.innerHTML=`<span class="label">⚠ 离线模式</span><span class="desc">正在使用本地缓存，联网后自动同步。</span><span class="actions"><button type="button" data-action="retry">立即同步</button></span>`;
      banner.addEventListener('click', (event)=>{
        const action=(event.target && event.target.dataset && event.target.dataset.action) ? event.target.dataset.action : '';
        if(action==='retry'){
          this.checkConnectivity(true);
          this.processQueue();
        }
      });
      if(document.body){ document.body.appendChild(banner); }
      else{ document.addEventListener('DOMContentLoaded',()=>document.body && document.body.appendChild(banner), {once:true}); }
      return banner;
    }
    restoreInitialStatus(){
      try{
        const last = localStorage.getItem(STORAGE_PREFIX+'status');
        if(last){
          const parsed=JSON.parse(last);
          if(parsed && parsed.offline){
            this.setOffline(true);
          }
        }
      }catch(_){ }
    }
    statusElement(){ return this.banner; }
    setOffline(force){
      if(force){
        this.online=false;
        this.statusElement().dataset.state='offline';
        try{ localStorage.setItem(STORAGE_PREFIX+'status', JSON.stringify({offline:true, ts:Date.now()})); }catch(_){ }
      } else {
        this.online=true;
        this.statusElement().dataset.state='';
        try{ localStorage.setItem(STORAGE_PREFIX+'status', JSON.stringify({offline:false, ts:Date.now()})); }catch(_){ }
      }
    }
    handleBrowserOnline(){
      this.offlineCount=0;
      this.setOffline(false);
      this.processQueue();
    }
    handleBrowserOffline(){
      this.offlineCount=OFFLINE_THRESHOLD;
      this.setOffline(true);
    }
    startHeartbeat(){
      setInterval(()=>this.checkConnectivity(), HEARTBEAT_INTERVAL);
      this.checkConnectivity();
    }
    async checkConnectivity(force){
      let online=navigator.onLine;
      if(online || force){
        try{
          const controller=new AbortController();
          const timer=setTimeout(()=>controller.abort(), 3000);
          const res=await fetch(PING_ENDPOINT,{cache:'no-store',signal:controller.signal});
          clearTimeout(timer);
          online=res.ok;
        }catch(_){
          online=false;
        }
      }
      if(online){
        this.offlineCount=0;
        this.setOffline(false);
        this.processQueue();
      } else {
        if(!navigator.onLine){
          this.offlineCount=OFFLINE_THRESHOLD;
        } else {
          this.offlineCount=Math.min(this.offlineCount+1, OFFLINE_THRESHOLD);
        }
        if(this.offlineCount>=OFFLINE_THRESHOLD){
          this.setOffline(true);
        }
      }
      return online;
    }
    isProbablyOnline(){
      return this.online && this.offlineCount===0;
    }
    async initDB(){
      if(!('indexedDB' in window)){
        return null;
      }
      return new Promise((resolve)=>{
        const request=indexedDB.open('memo-offline-cache',1);
        request.onupgradeneeded=(event)=>{
          const db=event.target.result;
          if(!db.objectStoreNames.contains('snapshots')){
            db.createObjectStore('snapshots',{keyPath:'key'});
          }
          if(!db.objectStoreNames.contains('queue')){
            db.createObjectStore('queue',{keyPath:'id',autoIncrement:true});
          }
        };
        request.onerror=()=>resolve(null);
        request.onsuccess=()=>resolve(request.result);
      });
    }
    async withStore(name, mode, callback){
      const db=await this.dbPromise;
      if(!db){
        return callback(null);
      }
      return new Promise((resolve,reject)=>{
        const tx=db.transaction(name, mode);
        const store=tx.objectStore(name);
        const result=callback(store);
        tx.oncomplete=()=>resolve(result);
        tx.onerror=()=>reject(tx.error);
      });
    }
    async saveSnapshot(key, value){
      const record={key,value,updatedAt:Date.now()};
      try{
        await this.withStore('snapshots','readwrite',(store)=>store.put(record));
      }catch(_){
        this.snapshotFallback[key]=record;
      }
    }
    async deleteSnapshot(key){
      try{
        await this.withStore('snapshots','readwrite',(store)=>store.delete(key));
      }catch(_){
        delete this.snapshotFallback[key];
      }
    }
    async addQueue(record){
      const entry={...record, createdAt:Date.now()};
      try{
        await this.withStore('queue','readwrite',(store)=>store.add(entry));
      }catch(_){
        if(!Array.isArray(this.queueCache)) this.queueCache=[];
        this.queueCache.push({...entry,id:Date.now()+Math.random()});
      }
    }
    async getQueue(){
      try{
        return await this.withStore('queue','readonly',(store)=>store.getAll());
      }catch(_){
        return Array.isArray(this.queueCache)?this.queueCache.slice():[];
      }
    }
    async removeQueue(id){
      try{
        await this.withStore('queue','readwrite',(store)=>store.delete(id));
      }catch(_){
        if(Array.isArray(this.queueCache)){
          this.queueCache=this.queueCache.filter(item=>item.id!==id);
        }
      }
    }
    formEntries(formData){
      return Array.from(formData.entries()).map(([key,value])=>{
        if(value instanceof File){
          return [key,{type:'file',name:value.name,size:value.size}];
        }
        return [key,{type:'text',value:String(value)}];
      });
    }
    rebuildForm(entries){
      const form=new FormData();
      entries.forEach(([key,item])=>{
        if(item && item.type==='text'){
          form.append(key,item.value);
        }
      });
      return form;
    }
    hasFile(entries){
      return entries.some(([,item])=>item && item.type==='file');
    }
    createOfflineResponse(meta){
      const body=JSON.stringify({ok:true,queued:true,meta:meta||null});
      const response=new Response(body,{status:202,headers:{'Content-Type':'application/json', [QUEUE_HEADER]:'1'}});
      response.offlineQueued=true;
      return response;
    }
    isOfflineResponse(res){
      return !!(res && (res.offlineQueued || (res.headers && res.headers.get(QUEUE_HEADER)==='1')));
    }
    notifyQueued(meta){
      window.dispatchEvent(new CustomEvent('offline:queued',{detail:meta||null}));
    }
    async processQueue(){
      if(this.queueProcessing) return;
      if(!this.isProbablyOnline()) return;
      this.queueProcessing=true;
      try{
        const items=await this.getQueue();
        for(const item of items){
          if(!this.isProbablyOnline()) break;
          const ok=await this.sendQueueItem(item);
          if(ok){
            await this.removeQueue(item.id);
          }else{
            break;
          }
        }
      }finally{
        this.queueProcessing=false;
      }
    }
    async sendQueueItem(item){
      const entries=item.body||[];
      const form=this.rebuildForm(entries);
      let response;
      try{
        response=await fetch(item.url,{method:item.method||'POST',body:form,headers:item.headers||{}});
      }catch(_){
        this.setOffline(true);
        return false;
      }
      if(!response.ok){
        return false;
      }
      const payload=await this.safeJson(response.clone());
      await this.handleSyncSuccess(item.meta||null,payload,response.clone());
      window.dispatchEvent(new CustomEvent('offline:sync-success',{detail:{meta:item.meta||null,response:payload,status:response.status}}));
      return true;
    }
    async safeJson(response){
      if(!response) return null;
      const type=response.headers && response.headers.get('content-type');
      if(type && type.includes('application/json')){
        try{ return await response.json(); }
        catch(_){ return null; }
      }
      return null;
    }
    async handleSyncSuccess(meta,payload,response){
      if(!meta) return;
      if(meta.snapshotKey && meta.snapshotData){
        await this.saveSnapshot(meta.snapshotKey, meta.snapshotData);
      }
      if(meta.kind==='mindmap-save'){
        const newId=payload && payload.id ? parseInt(payload.id,10) : (meta.mapId||0);
        if(meta.snapshotData){
          const snapshot={...meta.snapshotData,id:newId,updatedAt:Date.now()};
          await this.saveSnapshot(this.mindmapKey(newId), snapshot);
          if(meta.snapshotKey && meta.snapshotKey!==this.mindmapKey(newId)){
            await this.deleteSnapshot(meta.snapshotKey);
          }
        }
      }
      if(meta.kind==='memo-save'){
        const itemId=meta.itemId || (payload && payload.id) || null;
        if(itemId && meta.snapshotData){
          const snapshot={...meta.snapshotData,id:itemId,updatedAt:Date.now()};
          await this.saveSnapshot(this.memoKey(itemId), snapshot);
        }
      }
    }
    mindmapKey(id){
      return `${STORAGE_PREFIX}mindmap:${id||'draft'}`;
    }
    memoKey(id){
      return `${STORAGE_PREFIX}memo:${id}`;
    }
    async saveMindmapDraft(mapId, snapshot){
      if(!snapshot) return;
      const key=this.mindmapKey(mapId||snapshot.id||'draft');
      const normalized={...snapshot,id:mapId||snapshot.id||0,updatedAt:Date.now()};
      await this.saveSnapshot(key, normalized);
    }
    async saveMemoDraft(itemId, snapshot){
      if(!snapshot) return;
      const key=this.memoKey(itemId||snapshot.id||'draft');
      const normalized={...snapshot,id:itemId||snapshot.id||0,updatedAt:Date.now()};
      await this.saveSnapshot(key, normalized);
    }
    async sendForm(url, formData, meta){
      const entries=this.formEntries(formData);
      if(this.hasFile(entries)){
        return {status:'unsupported'};
      }
      const headers=meta && meta.headers ? meta.headers : undefined;
      if(meta && meta.snapshotKey && meta.snapshotData){
        await this.saveSnapshot(meta.snapshotKey, meta.snapshotData);
      }
      if(this.isProbablyOnline()){
        try{
          const response=await fetch(url,{method:'POST',body:formData,headers});
          if(response.ok){
            const payload=await this.safeJson(response.clone());
            await this.handleSyncSuccess(meta||null,payload,response.clone());
            return {status:'success',response};
          }
          return {status:'error',response};
        }catch(_){
          this.setOffline(true);
        }
      }
      await this.addQueue({url,method:'POST',headers,body:entries,meta});
      this.notifyQueued(meta||null);
      return {status:'queued',response:this.createOfflineResponse(meta||null)};
    }
  }
  window.OfflineManager=new OfflineManager();
  window.offlineManager=window.OfflineManager;
  window.isOfflineQueueResponse=(res)=>window.OfflineManager.isOfflineResponse(res);
})();
