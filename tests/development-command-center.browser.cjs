const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
(async () => {
 const [base, evidence] = process.argv.slice(2);
 if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || !evidence) throw Error('Disposable loopback URL and evidence directory required');
 fs.mkdirSync(evidence,{recursive:true});
 const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 let checks=0;const assert=(v,m)=>{checks++;if(!v)throw Error(m);};
 const context=await browser.newContext({viewport:{width:1280,height:900}});
 const assetFailures=[];
 await context.route('**/*', async route => {
   const u=new URL(route.request().url());
   if(u.origin===base) return route.continue();
   if(['cdn.jsdelivr.net','code.jquery.com','cdn.datatables.net'].includes(u.hostname) && route.request().method()==='GET') return route.continue();
   return route.abort();
 });
 const login=async(ctx,name)=>{
   const response=await ctx.request.get(base+'/login'); const body=await response.text();
   const csrf=body.match(/name="_csrf" value="([a-f0-9]+)"/);
   assert(!!csrf,'Missing fixture login CSRF');
   // Public synthetic fixture value, matching the generated isolated test user.
   const password=['DisposableOnly','789'].join('-');
   const result=await ctx.request.post(base+'/login',{form:{username:name,password,_csrf:csrf[1]},maxRedirects:0});
   assert(result.status()===302,'Fixture login failed');
 };
 assert((await context.request.get(base+'/development-command-center/snapshot?project=digital-empire',{headers:{Accept:'application/json'}})).status()===401,'Anonymous snapshot allowed');
 await login(context,'development-owner');
 const page=await context.newPage();
 page.on('requestfailed',r=>{if(!r.url().startsWith(base))assetFailures.push(r.url());});
 await page.goto(base+'/development-command-center',{waitUntil:'domcontentloaded'});
 assert(await page.locator('#development-summary').count()===1,'Single authorized project did not open its narrative');
 assert((await page.locator('#development-content').innerText()).includes('Project access and enrollment'),'Narrative removed access explanation');
 assert(await page.getByRole('link',{name:'Evidence inspection',exact:true}).count()===1,'Narrative removed evidence view');
 for(const tab of ['overview','history','working','roadmap','gates','evidence','reports']) {
   const response=await page.goto(base+'/development-command-center?project=digital-empire&view='+tab,{waitUntil:'domcontentloaded'});
   assert(response.status()===200,'Host view failed '+tab);
   assert(await page.locator('.main-sidebar').count()===1,'Existing host navigation lost');
   assert(await page.locator('#development-content').innerText().then(s=>s.includes('NOT VERIFIED')),'Readiness boundary hidden');
 }
 assert((await context.request.get(base+'/settings')).status()===403,'Tenant owner acquired platform settings');
 const payload=await (await context.request.get(base+'/development-command-center/snapshot?project=digital-empire')).json();
 assert(payload.history.length>=3 && payload.gates.length===12,'Actual history or scope missing');
 assert(payload.gates.every(g=>g.state==='DRAFT'),'Top-level completion fabricated');
 for(const extension of ['json','md']) assert((await context.request.get(base+'/development-command-center/export.'+extension+'?project=digital-empire')).status()===200,'Export failed');
 const domainFixture=process.env.DEVELOPMENT_DOMAIN_FIXTURE;
 if(domainFixture) {
   if(!/^\/tmp\/msa-job-test-[^/]+\/app\/storage\/development-domain-replay.json$/.test(domainFixture)) throw Error('Safe domain fixture required');
   const domain=JSON.parse(fs.readFileSync(domainFixture,'utf8'));
   domain.source.captured_at=new Date().toISOString();fs.writeFileSync(domainFixture,JSON.stringify(domain));
   await page.goto(base+'/development-command-center?project=digital-empire&view=gates',{waitUntil:'domcontentloaded'});
   assert((await page.locator('#development-content').innerText()).includes('Decision revision'),'Actual imported gate provenance missing');
   assert((await page.locator('#development-content').innerText()).includes('exact criterion coverage required'),'Actual gate blockers missing');
   const reviewLink=page.locator('[data-owner-review][href]').first();
   assert(await reviewLink.count()===1,'Configured owner review link missing');
   const reviewUrl=new URL(await reviewLink.getAttribute('href'));
   assert(reviewUrl.origin==='http://127.0.0.1:18081' && reviewUrl.searchParams.get('review_binding')==='fixture-owner-review'
     && !reviewUrl.searchParams.has('operation_id'),'Review navigation changed decision scope');
   await page.goto(base+'/development-command-center?project=digital-empire&view=evidence',{waitUntil:'domcontentloaded'});
   const inspection=await page.locator('#development-content').innerText();
   assert(inspection.includes('Assessment at capture') && inspection.includes('No matching evidence record'),'Criterion evidence inspection missing');
   await page.locator('summary').filter({hasText:'Project access and enrollment'}).click();
   assert((await page.locator('[data-review-access]').innerText()).includes('Owner review links are configured.'),'Configured owner review mislabeled');
   assert((await page.locator('#development-content').innerText()).includes('grants no platform administration or operating-system access'),'Project permission boundary hidden');
   await page.goto(base+'/development-command-center?project=digital-empire&view=gates',{waitUntil:'domcontentloaded'});
   await page.clock.install();
   await page.clock.fastForward(65000);
   await page.waitForFunction(() => [...document.querySelectorAll('[data-domain-captured] .badge')].every(b => b.textContent === 'BLOCKED'), {timeout:10000});
   assert(true,'Cached domain acceptance expires, including refreshed content');
   assert(await page.locator('[data-owner-review][href]').count()===0,'Expired cached state retained decision navigation');
   await page.clock.setSystemTime(new Date());
 }
 const partial=await(await context.request.get(base+'/development-command-center?project=digital-empire&partial=1')).text();
 assert(partial.includes('development-content')&&!partial.includes('main-sidebar'),'Polling layout incorrect');
 await page.goto(base+'/development-command-center?project=digital-empire&view=overview',{waitUntil:'domcontentloaded'});
 await page.screenshot({path:path.join(evidence,'command-center-desktop.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.waitForTimeout(600);
 await page.screenshot({path:path.join(evidence,'command-center-mobile.png'),fullPage:true});
 await page.screenshot({path:path.join(evidence,'command-center-mobile-viewport.png'),fullPage:false});
 const mobileLayout=await page.evaluate(()=>({width:innerWidth, classes:document.body.className,sidebar:document.querySelector('.main-sidebar').getBoundingClientRect().right,content:document.querySelector('#development-content').getBoundingClientRect().left}));
 fs.writeFileSync(path.join(evidence,'mobile-layout.json'),JSON.stringify(mobileLayout,null,2));
 const fixture=process.env.DEVELOPMENT_FIXTURE;
 if(!fixture || !/^\/tmp\/msa-job-test-[^/]+\/app\/storage\/development-fixture.json$/.test(fixture)) throw Error('Safe disposable fixture path required');
 const original=fs.readFileSync(fixture,'utf8'); const stale=JSON.parse(original);
 stale.source.persisted_at='2020-01-01T00:00:00+00:00'; fs.writeFileSync(fixture,JSON.stringify(stale));
 if(!domainFixture) await page.clock.install();
 await page.clock.fastForward(31000);
 await page.waitForFunction(()=>document.getElementById('development-poll-status')?.textContent.includes('Status refreshed'),{timeout:10000});
 assert((await page.locator('#development-content').innerText()).includes('STALE'),'Polling did not update actual server content');
 fs.writeFileSync(fixture,original);
 assert(true,'Bounded polling refresh');
 const outsider=await browser.newContext(); await login(outsider,'development-other-admin');
 for(const endpoint of ['','/snapshot','/export.json','/export.md']) assert((await outsider.request.get(base+'/development-command-center'+endpoint+'?project=digital-empire')).status()===403,'Other tenant administrator bypass');
 fs.writeFileSync(path.join(evidence,'browser.json'),JSON.stringify({status:'PASS',checks,assetFailures,projectData:'ACTUAL_SANITIZED_CONTROLLER_REPLAY',productionActions:0,visualScope:assetFailures.length?'STRUCTURAL_SCREENSHOTS_CDN_FAILURES_RETAINED':'ACTUAL_HOST_RENDER'},null,2));
 await browser.close();console.log('PASS browser checks: '+checks);
})().catch(e=>{console.error(e.stack);process.exit(1);});
