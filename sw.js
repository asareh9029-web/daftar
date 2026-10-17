const C='daftar-v1';const SHELL=['./','index.html','manifest.webmanifest','apple-touch-icon.png','icon-192.png','icon-512.png'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(SHELL)));self.skipWaiting()});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(x=>x!==C).map(x=>caches.delete(x)))));self.clients.claim()});
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(e.request.method!=='GET')return;
  if(u.origin===location.origin){ // network first so updates and fresh prices arrive; cache as offline fallback
    e.respondWith(fetch(e.request).then(r=>{const cp=r.clone();caches.open(C).then(c=>c.put(e.request,cp));return r}).catch(()=>caches.match(e.request,{ignoreSearch:u.pathname.endsWith('prices.json')}).then(r=>r||caches.match('index.html'))))}
  else e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request).then(res=>{if(u.hostname.includes('fonts.')){const cp=res.clone();caches.open(C).then(c=>c.put(e.request,cp))}return res})))});
