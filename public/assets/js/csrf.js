(function(){
  const tokenMeta=document.querySelector('meta[name="csrf-token"]');
  const token=tokenMeta?tokenMeta.getAttribute('content')||'':'';
  window.__CSRF_TOKEN__=token;
  if(typeof window.fetch==='function'){
    const originalFetch=window.fetch;
    window.fetch=function(input,init){
      init=init||{};
      const headers=new Headers(init.headers||{});
      if(!headers.has('X-Requested-With')){
        headers.set('X-Requested-With','XMLHttpRequest');
      }
      if(token && !headers.has('X-CSRF-Token')){
        headers.set('X-CSRF-Token',token);
      }
      init.headers=headers;
      const body=init.body;
      if(token && body instanceof FormData && !body.has('_csrf')){
        body.append('_csrf',token);
      }
      return originalFetch.call(this,input,init);
    };
  }
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(!(form instanceof HTMLFormElement)) return;
    if(token && !form.querySelector('input[name="_csrf"]')){
      const input=document.createElement('input');
      input.type='hidden';
      input.name='_csrf';
      input.value=token;
      form.appendChild(input);
    }
  },true);
})();
