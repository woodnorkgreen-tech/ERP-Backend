const fs = require('fs');
const WebSocket = require('/home/cosmas/projects/ERP-Frontend/node_modules/ws');
(async () => {
 const tabs = await (await fetch('http://127.0.0.1:9224/json')).json();
 const ws = new WebSocket(tabs[0].webSocketDebuggerUrl); await new Promise(r => ws.once('open',r));
 let id = 0; const pending = new Map(); ws.on('message', raw => { const v = JSON.parse(raw); if (v.method === 'Runtime.exceptionThrown') console.log(JSON.stringify(v.params)); if (v.method === 'Runtime.consoleAPICalled') console.log(JSON.stringify(v.params)); if (v.id && pending.has(v.id)) { const [ok, bad] = pending.get(v.id); pending.delete(v.id); v.error ? bad(v.error) : ok(v.result); } });
 const call = (method, params={}) => new Promise((ok,bad) => { pending.set(++id,[ok,bad]); ws.send(JSON.stringify({id,method,params})); });
 const evalJS = async expression => { const r = await call('Runtime.evaluate', { expression: '(() => eval(' + JSON.stringify(expression) + '))()', awaitPromise: true, returnByValue: true }); if(r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text); return r.result.value; };
 const wait = ms => new Promise(r=>setTimeout(r,ms));
 const dir = '/home/cosmas/projects/ERP-Backend/docs/finance-redesign/phase-2/70a-verification'; fs.mkdirSync(dir,{recursive:true});
 const shot = async name => { await wait(250); const s = await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true}); fs.writeFileSync(`${dir}/${name}.png`,Buffer.from(s.data,'base64')); };
 await call('Page.enable'); await call('Runtime.enable'); await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
 await call('Page.navigate',{url:'http://127.0.0.1:4174/tests/fixtures/70a/index.html'});
 for (let i=0;i<40;i++){ if (await evalJS("document.body.innerText.includes('CU-00000001')")) break; await wait(250); }
 if (!await evalJS("document.body.innerText.includes('CU-00000001')")) throw new Error(await evalJS('document.body.innerText'));
 await evalJS("const f=document.querySelector('form'); const s=f.querySelectorAll('select');s[1].value='1';s[1].dispatchEvent(new Event('change',{bubbles:true})); const q=f.querySelectorAll('input');q[0].value='12';q[0].dispatchEvent(new Event('input',{bubbles:true}));q[1].value='Production';q[1].dispatchEvent(new Event('input',{bubbles:true}));s[2].value='9';s[2].dispatchEvent(new Event('change',{bubbles:true}));"); await wait(300);
 await evalJS("const s=document.querySelector('form').querySelectorAll('select'); if(s[3]){s[3].value='90';s[3].dispatchEvent(new Event('change',{bubbles:true}));}");
 await shot('material-detail-issue');
 await evalJS("Array.from(document.querySelectorAll('button')).find(b=>b.textContent.trim()==='CU-00000001').click()"); await wait(300); await shot('unit-detail-count');
 await evalJS("Array.from(document.querySelectorAll('button')).find(b=>b.textContent.trim()==='Add another roll').click()"); await evalJS("const f=document.querySelector('form');const s=f.querySelectorAll('select');s[2].value='2';s[2].dispatchEvent(new Event('change',{bubbles:true}));const q=f.querySelectorAll('input');q[0].value='20';q[0].dispatchEvent(new Event('input',{bubbles:true}));q[1].value='50';q[1].dispatchEvent(new Event('input',{bubbles:true}));");await shot('multi-roll');
 await evalJS("const s=document.querySelector('form select');s.value='return';s.dispatchEvent(new Event('change',{bubbles:true}));"); await evalJS("const b=Array.from(document.querySelectorAll('button')).filter(b=>b.textContent.trim()==='Remove line');if(b[1])b[1].click();");await wait(100);
 await evalJS("const f=document.querySelector('form');const q=f.querySelector('input');q.value='3';q.dispatchEvent(new Event('input',{bubbles:true}));const s=f.querySelectorAll('select');s[2].value='81';s[2].dispatchEvent(new Event('change',{bubbles:true}));s[3].value='recovered_offcut';s[3].dispatchEvent(new Event('change',{bubbles:true}));");await shot('return-offcut');
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true}); await shot('mobile-unit-return');
 const mobile = await evalJS('({viewport: innerWidth, scroll: document.documentElement.scrollWidth})'); console.log('Mobile widths',mobile);
 await call('Page.navigate',{url:'http://127.0.0.1:4174/tests/fixtures/70a/index.html?difference'}); await wait(500); await shot('reconciliation-difference-mobile');
 console.log('Screenshots saved; reconciliation difference visible:',await evalJS("document.body.innerText.includes('DIFFERENCE')")); ws.close();
})().catch(e=>{console.error(e);process.exit(1)});
