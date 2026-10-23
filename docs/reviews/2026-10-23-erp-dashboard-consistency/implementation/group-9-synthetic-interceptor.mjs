import { execFileSync } from 'node:child_process'
import { createInterface } from 'node:readline'
import { appendFileSync } from 'node:fs'
const evidence = process.cwd() + '/docs/reviews/2026-10-23-erp-dashboard-consistency/implementation/group-9-synthetic-transport.txt'
const log = data => { const line = JSON.stringify(data); appendFileSync(evidence, line + '\n'); console.log(line) }
const wsUrl = execFileSync('agent-browser', ['--session','erp-ui-group1','get','cdp-url'], { encoding:'utf8' }).trim()
const ws = new WebSocket(wsUrl)
await new Promise((resolve,reject) => { ws.onopen=resolve; ws.onerror=reject })
let seq=0; const pending = new Map()
function call(method,params={},sessionId) {
  return new Promise((resolve,reject) => { const id=++seq; pending.set(id,{resolve,reject}); ws.send(JSON.stringify({id,method,params,...sessionId ? {sessionId}: {}})) })
}
let session
ws.onmessage=async ({data}) => {
  const event=JSON.parse(data)
  if(event.id) { const entry=pending.get(event.id); if(!entry) return; pending.delete(event.id); event.error ? entry.reject(new Error(event.error.message)) : entry.resolve(event.result); return }
  if(event.method!=='Fetch.requestPaused') return
  const {requestId,request}=event.params
  try {
    const url = new URL(request.url)
    if(['GET','HEAD','OPTIONS'].includes(request.method)) {
      const body={data:[{id:900001,name:'Produto sintético de aceitação',sku:'SYNTHETIC-ONLY',type:'single',sale_price:'12345678901234567890.1234',status:'active'}],meta:{current_page:1,last_page:1,per_page:15,total:1}}
      await call('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'content-type',value:'application/json'}],body:Buffer.from(JSON.stringify(body)).toString('base64')},session)
      log({method:request.method,path:url.pathname,status:200,synthetic:true,upstreamDispatched:false})
    } else { await call('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'},session); log({method:request.method,path:url.pathname,aborted:true,upstreamDispatched:false}) }
  } catch { await call('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'},session); log({aborted:true,reason:'interceptor failure',upstreamDispatched:false}) }
}
const {targetInfos}=await call('Target.getTargets')
const target=targetInfos.find(target=>target.type==='page' && target.url.startsWith('http://localhost:3107/erp/cadastros/produtos'))
if(!target) throw new Error('Expected Products tab missing')
;({sessionId:session}=await call('Target.attachToTarget',{targetId:target.targetId,flatten:true}))
await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost:3107/api/v1/*',requestStage:'Request'}]},session)
log({interceptor:'ready',scope:'all API requests on owned origin; synthetic GETs only',readRequests:'synthetically fulfilled',writeRequests:'aborted'})
const lines=createInterface({input:process.stdin})
const timeout=setTimeout(()=>lines.close(),600000)
await new Promise(resolve=>{lines.on('line',line=>{if(line.trim()==='stop')lines.close()});lines.on('close',resolve)})
clearTimeout(timeout)
await call('Fetch.disable',{},session)
await call('Target.detachFromTarget',{sessionId:session})
ws.close();log({interceptor:'removed'})
