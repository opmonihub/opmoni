(() => {
const rect=e=>{if(!e)return null;const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}};
const visible=e=>{const r=rect(e);return r.width>0&&r.height>0&&getComputedStyle(e).visibility!=='hidden'};
const all=s=>[...document.querySelectorAll(s)].filter(visible);
const company=all('[aria-label="Selecionar empresa"]').find(e=>e.closest('[data-slot="header"]'));
const office=all('[aria-label="Selecionar escritório"]').find(e=>e.closest('[data-slot="header"]'));
const header=(company||office)?.closest('[data-slot="header"]');
const sidebar=header?.parentElement;
const heading=all('h1')[0]; const navbar=heading?.closest('[data-slot="root"]');
const search=all('input').find(e=>(e.getAttribute('aria-label')||'').startsWith('Buscar'))||all('input[type=search]')[0]||all('input').find(e=>e.type!=='checkbox'&&e.type!=='hidden');
const table=all('table')[0]; const frame=table?.parentElement;
const pager=all('nav').find(e=>e.getAttribute('aria-label')==='Paginação de registros');
const buttons=all('button').filter(e=>/página|Page/i.test(e.getAttribute('aria-label')||''));
const focus=document.activeElement;const d=document.querySelector('[role=dialog]');
return {path:location.pathname,viewport:{width:innerWidth,height:innerHeight},pageWidth:document.documentElement.scrollWidth,root:getComputedStyle(document.documentElement).fontSize,font:getComputedStyle(document.body).fontFamily,theme:document.documentElement.className,headings:all('h1').map(e=>e.textContent),sidebar:rect(sidebar),header:rect(header),office:rect(office),company:rect(company),navigation:rect(all('a').find(e=>e.textContent.trim()==='Visão geral')),navbar:rect(navbar),toggles:all('button').filter(e=>/Abrir menu ERP|Recolher menu ERP/.test(e.getAttribute('aria-label')||'')).map(e=>({label:e.getAttribute('aria-label'),rect:rect(e)})),search:rect(search),table:rect(table),frame:rect(frame),footer:rect(pager?.parentElement?.parentElement),pager:rect(pager),pageButtons:buttons.map(e=>({label:e.getAttribute('aria-label'),current:e.getAttribute('aria-current'),disabled:e.disabled,rect:rect(e),background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color})),firstRow:table?.querySelector('tbody tr')?.textContent?.trim(),tableHeadColor:table?.querySelector('th')?getComputedStyle(table.querySelector('th')).backgroundColor:null,focused:{tag:focus?.tagName,label:focus?.getAttribute('aria-label'),name:focus?.getAttribute('name'),insideDialog:!!d?.contains(focus)},statuses:all('[role=status],[role=alert]').map(e=>e.textContent.trim()).filter(Boolean)};
})()
