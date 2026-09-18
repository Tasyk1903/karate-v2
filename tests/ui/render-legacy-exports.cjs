const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs/promises');
const path = require('node:path');
(async()=>{
 const dir=path.resolve('storage/framework/testing/export-qa');
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try {
  for(const name of (await fs.readdir(dir)).filter(n=>n.startsWith('old-')&&n.endsWith('.html'))) {
   const page=await browser.newPage();
   const errors=[];page.on('requestfailed',r=>errors.push(r.url()+': '+r.failure()?.errorText));
   await page.setContent(await fs.readFile(path.join(dir,name),'utf8'),{waitUntil:'networkidle'});
   await page.evaluate(()=>document.fonts.ready);
   await page.pdf({path:path.join(dir,name.replace('.html','.pdf')),format:'A4',printBackground:true,preferCSSPageSize:true});
   console.log(name,errors.length?JSON.stringify(errors):'OK');
   await page.close();
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
