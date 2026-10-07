const WS=require('/home/cosmas/projects/ERP-Frontend/node_modules/ws');
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function page() {
 const target=await(await fetch('http://127.0.0.1:9228/json/new?about:blank',{method:'PUT'})).json();
 const ws=new WS(target.webSocketDebuggerUrl); await new Promise((resolve,reject)=>{ws.once('open',resolve);ws.once('error',reject)});
 let id=0,pending=new Map(),errors=[];
 ws.on('message',bytes=>{const message=JSON.parse(bytes);if(message.id){const call=pending.get(message.id);if(call){pending.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result)}}if(message.method==='Page.javascriptDialogOpening')send('Page.handleJavaScriptDialog',{accept:true,promptText:'Rehearsal review reason for this correction'}).catch(()=>{});if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.text+': '+JSON.stringify(message.params.exceptionDetails.exception))});
 ws.on('close',()=>{for(const call of pending.values()){clearTimeout(call.timer);call.reject(new Error('Browser page disconnected'))}pending.clear()});
 const send=(method,params={})=>new Promise((resolve,reject)=>{const n=++id;const timer=setTimeout(()=>{pending.delete(n);reject(new Error('CDP timeout: '+method))},15000);pending.set(n,{resolve,reject,timer});ws.send(JSON.stringify({id:n,method,params}))});
 const evaluate=async expression=>(await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result;
 await send('Page.enable');await send('Runtime.enable');
 return {send,evaluate,errors,close:async()=>{ws.close();await fetch('http://127.0.0.1:9228/json/close/'+target.id)}};
}
async function navigate(p,view,width=390,height=844){await p.send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<640});await p.send('Page.navigate',{url:'http://127.0.0.1:4178/tests/fixtures/73a/index.html?view='+view});for(let i=0;i<50;i++){if((await p.evaluate('!!window.__fixtureCalls && document.body.innerText.length>20')).value)break;await delay(200)}await delay(500);}
module.exports={page,navigate,delay};
