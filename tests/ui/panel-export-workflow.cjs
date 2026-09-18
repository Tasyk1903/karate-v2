const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 const page=await browser.newPage({viewport:{width:1440,height:900}});
 page.setDefaultTimeout(8000);
 const errors=[],unexpected=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{if(!localStorage.getItem('kr-locale'))localStorage.setItem('kr-locale','en')});
 const id='12345678-1234-4321-8123-123456789abc';
 let status='queued', kind='tournament', selected=[];
 const meta={current_page:1,last_page:1,total:0,per_page:10};
 const coaches=[];
 await page.route('**/api/**',async route=>{
  const req=route.request(),url=new URL(req.url()),path=url.pathname;
  const json=data=>route.fulfill({json:data});
  if(path==='/api/auth/user')return json({user:{id:1,name:'Federation',roles:['Organization'],capabilities:{team_sections:['trainers','students']},unread_notifications:0,agreements_required:false}});
  if(path==='/api/panel/tasks/'+id)return json({task:{id,kind,status,download_url:status==='ready'&&kind!=='generate'?'/api/panel/tasks/'+id+'/file':null,return_url:'/panel/tournaments/28/items/57/edit'}});
  if(path.endsWith('/attach-options/coaches')){
   const p=+(url.searchParams.get('page')||1);
   const data=url.searchParams.get('search')?[{id:99,name:'Search result trainer',subtitle:'DOJO'}]:[{id:p,name:'Trainer page '+p,subtitle:'International club with a long descriptive name'}];
   return json({data,meta:{current_page:p,last_page:2,total:2}});
  }
  if(path.endsWith('/coaches')&&req.method()==='POST'){selected=req.postDataJSON().coach_ids;coaches.push(...selected.map(id=>({id,name:'Attached '+id,club:'DOJO'})));return json({detail:{coaches}});}
  if(path.endsWith('/brackets/generate')){kind='generate';status='queued';return route.fulfill({status:202,json:{task:{id}}});}
  if(path==='/api/panel/tournaments/28/items/57')return json({championship:{id:28,name:'Championship',can_manage:true},tournament:{id:57,name:'Tournament',tournament_type:1,can_manage:true,can_generate_all_brackets:true,downloads:{brackets:'/api/panel/tournaments/28/items/57/downloads/brackets'},clubs:[],documents:[]},detail:{students:{data:[],meta},coaches,lists:[]},create_options:{regions:[],scales:[]}});
  unexpected.push(path);return route.fulfill({status:404,json:{}});
 });
 try{
  await page.goto('http://127.0.0.1:8080/panel/tournaments/28/items/57/edit?tab=coaches');
  await page.getByRole('button',{name:'Add trainer',exact:true}).click();
  const modal=page.locator('.modal-backdrop').filter({has:page.getByRole('heading',{name:'Add trainer'})});
  await modal.getByRole('checkbox').check();
  await modal.getByRole('button',{name:'Next page',exact:true}).click();
  await modal.getByText('Trainer page 2',{exact:false}).waitFor();
  await modal.getByRole('checkbox').check();
  await modal.getByRole('button',{name:'Previous page',exact:true}).click();
  await modal.getByText('Trainer page 1',{exact:false}).waitFor();
  assert(await modal.getByRole('checkbox').isChecked());
  await modal.locator('input.modal-search-input').fill('Search');
  await modal.getByText('Search result trainer',{exact:false}).waitFor();
  await modal.getByRole('checkbox').check();
  await page.screenshot({path:'/tmp/kr-export-picker.png',fullPage:true});
  await modal.getByRole('button',{name:'Add',exact:true}).click();
  await modal.waitFor({state:'hidden'});
  assert.deepEqual(selected,[1,2,99]);
  await page.getByText('Attached 99',{exact:true}).waitFor();
  await page.goto('http://127.0.0.1:8080/panel/tournaments/28/items/57/edit?tab=lists');
  await page.getByRole('button',{name:'Generate pools',exact:true}).click();
  await page.waitForURL('**/panel/tasks/'+id);
  await page.getByRole('heading',{name:'Generating brackets'}).waitFor();
  status='ready';await page.getByText('Ready',{exact:true}).waitFor();
  for(const [locale,title]of[['en','Preparing file'],['ru','Подготовка файла']]){
   for(const theme of ['light','dark']){
    await page.evaluate(([l,t])=>{localStorage.setItem('kr-locale',l);localStorage.setItem('kr-panel-theme',t)},[locale,theme]);
    kind='tournament';await page.setViewportSize({width:390,height:844});await page.reload();
    await page.getByRole('heading',{name:title,exact:true}).waitFor();
    const link=page.locator('.panel-task a').first();await link.waitFor();
    assert.equal(await link.getAttribute('href'),'/api/panel/tasks/'+id+'/file');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
    await page.screenshot({path:'/tmp/kr-export-'+locale+'-'+theme+'.png',fullPage:true});
   }
  }
  status='failed';await page.reload();await page.locator('.panel-task p').waitFor();assert.equal(await page.locator('.panel-task a[href$="/file"]').count(),0);
  assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);
  console.log('PASS: paged/search selection persists, immediate update, queued generation, task polling, RU/EN, mobile themes, failure without download');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
