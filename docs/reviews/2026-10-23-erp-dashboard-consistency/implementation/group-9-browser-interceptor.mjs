import { execFileSync } from 'node:child_process'
import { createInterface } from 'node:readline'
import { appendFileSync } from 'node:fs'
const evidence = process.cwd() + '/docs/reviews/2026-10-23-erp-dashboard-consistency/implementation/group-9-browser-transport.txt'
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
    if(request.method==='PATCH' && url.origin==='http://localhost:3107' && /^\/api\/v1\/erp\/products\/\d+$/.test(url.pathname)) {
      const field = 'name'
      await call('Fetch.fulfillRequest',{requestId,responseCode:422,responseHeaders:[{name:'content-type',value:'application/json'}],body:Buffer.from(JSON.stringify({message:'Confira os dados informados. Resposta sintética de teste.',errors:{[field]:['Valor recusado na validação sintética.']}})).toString('base64')},session)
      log({method:'PATCH',path:url.pathname,status:422,field,synthetic:true,upstreamDispatched:false})
    } else if(['GET','HEAD','OPTIONS'].includes(request.method)) await call('Fetch.continueRequest',{requestId},session)
    else { await call('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'},session); log({method:request.method,path:url.pathname,aborted:true,upstreamDispatched:false}) }
  } catch { await call('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'},session); log({aborted:true,reason:'interceptor failure',upstreamDispatched:false}) }
}
const {targetInfos}=await call('Target.getTargets')
const target=targetInfos.find(target=>target.type==='page' && target.url.startsWith('http://localhost:3107/erp/cadastros/produtos'))
if(!target) throw new Error('Expected Products tab missing')
;({sessionId:session}=await call('Target.attachToTarget',{targetId:target.targetId,flatten:true}))
await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost:3107/api/v1/erp/products*',requestStage:'Request'}]},session)
log({interceptor:'ready',scope:'dev-origin products prefix; only numeric detail PATCH fulfilled, other writes aborted',readRequests:'continued',writeRequests:'synthetically fulfilled or aborted'})
const lines=createInterface({input:process.stdin})
const timeout=setTimeout(()=>lines.close(),600000)
await new Promise(resolve=>{lines.on('line',line=>{if(line.trim()==='stop')lines.close()});lines.on('close',resolve)})
clearTimeout(timeout)
await call('Fetch.disable',{},session)
await call('Target.detachFromTarget',{sessionId:session})
ws.close();log({interceptor:'removed'})
