'use strict';
(function(global){
  const ALLOWED_TAGS = new Set(['A','P','STRONG','EM','UL','OL','LI','PRE','CODE','BLOCKQUOTE','H1','H2','H3','H4','H5','H6','HR','BR','SPAN']);
  const ALLOWED_ATTRS = {
    A: ['href','title','target','rel'],
    CODE: ['class'],
    PRE: ['class'],
    SPAN: ['class']
  };
  const SAFE_PROTOCOLS = ['http:','https:','mailto:'];

  const escapeHtml = (input)=>{
    if(typeof input !== 'string') return '';
    return input
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  };

  const sanitizeUrl = (url)=>{
    try{
      const parsed=new URL(url, typeof window!=='undefined'?window.location.href:'http://localhost');
      if(!SAFE_PROTOCOLS.includes(parsed.protocol)) return '#';
      return parsed.href;
    }catch(_){
      return '#';
    }
  };

  const sanitizeHtmlTree = (html)=>{
    if(typeof document==='undefined') return String(html||'');
    const template=document.createElement('template');
    template.innerHTML=String(html||'');
    const walker=document.createTreeWalker(template.content, NodeFilter.SHOW_ELEMENT, null);
    const toRemove=[];
    while(walker.nextNode()){
      const node=walker.currentNode;
      if(!ALLOWED_TAGS.has(node.tagName)){
        toRemove.push(node);
        continue;
      }
      const allowed=ALLOWED_ATTRS[node.tagName] || [];
      Array.from(node.attributes).forEach(attr=>{
        if(!allowed.includes(attr.name.toLowerCase())){
          node.removeAttribute(attr.name);
          return;
        }
        if(node.tagName==='A' && attr.name.toLowerCase()==='href'){
          node.setAttribute('href', sanitizeUrl(attr.value));
          node.setAttribute('rel','noreferrer noopener');
        }
        if(node.tagName==='A' && attr.name.toLowerCase()==='target'){
          if(attr.value!=='_blank'){ node.setAttribute('target','_blank'); }
        }
      });
    }
    toRemove.forEach(node=>node.replaceWith(document.createTextNode(node.textContent || '')));
    return template.innerHTML;
  };

  const inlineMarkdown = (input)=>{
    let result = escapeHtml(input);
    result = result.replace(/`([^`]+)`/g, (_,code)=>`<code>${code}</code>`);
    result = result.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    result = result.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    result = result.replace(/\*(?!\s)([^*]+)\*/g, '<em>$1</em>');
    result = result.replace(/_([^_]+)_/g, '<em>$1</em>');
    result = result.replace(/~~([^~]+)~~/g, '<span class="md-del">$1</span>');
    result = result.replace(/\[(.+?)\]\(([^\s)]+)\)/g, (_,label,href)=>{
      const safeHref=sanitizeUrl(href);
      return `<a href="${escapeHtml(safeHref)}" target="_blank" rel="noreferrer noopener">${escapeHtml(label)}</a>`;
    });
    return result;
  };

  const renderMarkdown = (markdown)=>{
    if(typeof markdown !== 'string' || !markdown.trim()) return '';
    const lines = markdown.replace(/\r\n?/g,'\n').split('\n');
    const out=[];
    let listStack=[];
    const closeLists=(depth=0)=>{
      while(listStack.length>depth){
        const tag=listStack.pop();
        out.push(`</${tag}>`);
      }
    };
    lines.forEach(rawLine=>{
      const line=rawLine.replace(/\s+$/,'');
      if(!line.trim()){
        closeLists(0);
        out.push('');
        return;
      }
      const heading=line.match(/^(#{1,6})\s+(.*)$/);
      if(heading){
        closeLists(0);
        const level=heading[1].length;
        out.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`);
        return;
      }
      const blockquote=line.match(/^>\s?(.*)$/);
      if(blockquote){
        closeLists(0);
        out.push(`<blockquote>${inlineMarkdown(blockquote[1])}</blockquote>`);
        return;
      }
      const ol=line.match(/^\s{0,3}(\d+)\.\s+(.*)$/);
      if(ol){
        if(listStack[listStack.length-1] !== 'ol'){
          closeLists(0);
          listStack.push('ol');
          out.push('<ol>');
        }
        out.push(`<li>${inlineMarkdown(ol[2])}</li>`);
        return;
      }
      const ul=line.match(/^\s{0,3}[-+*]\s+(.*)$/);
      if(ul){
        if(listStack[listStack.length-1] !== 'ul'){
          closeLists(0);
          listStack.push('ul');
          out.push('<ul>');
        }
        out.push(`<li>${inlineMarkdown(ul[1])}</li>`);
        return;
      }
      closeLists(0);
      out.push(`<p>${inlineMarkdown(line)}</p>`);
    });
    closeLists(0);
    const html=out.filter(Boolean).join('\n');
    return sanitizeHtmlTree(html);
  };

  const sanitize = (input)=>{
    if(typeof input !== 'string') return '';
    return sanitizeHtmlTree(input);
  };

  const api={
    render: renderMarkdown,
    sanitize,
  };

  global.memoMarkdown = api;
  global.marked = { parse: renderMarkdown };
  global.DOMPurify = { sanitize };
})(typeof window !== 'undefined' ? window : globalThis);
