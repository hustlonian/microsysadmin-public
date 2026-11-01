const fs = require('fs'), path = require('path'), net = require('net'), crypto = require('crypto');
const {spawn, spawnSync} = require('child_process');
const {chromium} = require('playwright');
const source = path.resolve(__dirname, '..'), out = path.resolve(process.argv[2]);
if (fs.existsSync(out)) throw new Error('Preserve existing browser evidence');
fs.mkdirSync(out, {recursive:true, mode:0o700});
const checks = [], screenshots = []; let server, browser, page; const pageErrors=[];
const check = (ok, why) => {if (!ok) throw new Error(why); checks.push(why);};
const hash = data => crypto.createHash('sha256').update(data).digest('hex');
(async()=>{try {
 const conf = spawnSync('/usr/bin/php', ['-r', 'echo json_encode((require $argv[1]));', path.join(source,'config/config.php')], {encoding:'utf8'});
 if (conf.status !== 0) throw new Error('Disposable configuration unavailable');
 const configuration = JSON.parse(conf.stdout);
 if (!/^microsysadmin_test_doctor_[A-Za-z0-9]+$/.test(configuration.database.name)) throw new Error('Disposable database required');
 const setup=spawnSync('/usr/bin/php',['-r',"define('APP_ROOT',$argv[1]);require APP_ROOT.'/classes/bootstrap.php';if(!str_starts_with((string)config_value('database.name'),'microsysadmin_test_doctor_'))exit(90);SysAdmin\\SecurityProfile::set('developer',true);",source],{encoding:'utf8'});
 if(setup.status!==0)throw new Error('Disposable profile setup failed: '+setup.stderr);
 const prefix = (configuration.app.base_url || '').replace(/\/$/, '');
 const router = path.join(out, 'router.php');
 fs.writeFileSync(router, `<?php
 $source=getenv('MICROSYSADMIN_RUNNER_SOURCE'); $c=require $source.'/config/config.php';
 if(!preg_match('/\\Amicrosysadmin_test_doctor_[A-Za-z0-9]+\\z/',$c['database']['name'])){http_response_code(503);exit;}
 $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$base=rtrim($c['app']['base_url'],'/');
 if($base!==''&&str_starts_with($p,$base.'/')){$p=substr($p,strlen($base));}
 if(str_starts_with($p,'/assets/')){
  $file=realpath($source.$p);$ext=pathinfo($p,PATHINFO_EXTENSION);
  if($file&&str_starts_with($file,$source.'/assets/')&&in_array($ext,['js','css','woff','woff2','ttf','svg','png'])){
   header('Content-Type: '.(['js'=>'application/javascript','css'=>'text/css','svg'=>'image/svg+xml'][$ext]??'application/octet-stream'));readfile($file);exit;
  }http_response_code(404);exit;
 }
 if($p==='/api/sandbox.php'){require $source.'/api/sandbox.php';exit;}
 require $source.'/index.php';
 `);
 const reserve=net.createServer();await new Promise((r,j)=>{reserve.once('error',j);reserve.listen(0,'127.0.0.1',r);});const port=reserve.address().port;await new Promise(r=>reserve.close(r));
 const origin='http://127.0.0.1:'+port, base=origin+prefix;
 server=spawn('/usr/bin/php',['-d','display_errors=0','-S','127.0.0.1:'+port,router],{cwd:source,env:{...process.env,MICROSYSADMIN_RUNNER_SOURCE:source},stdio:['ignore','pipe','pipe']});
 server.stderr.on('data',b=>fs.appendFileSync(path.join(out,'server.log'),b));
 for(let n=0;n<100;n++){try{if((await fetch(base+'/login')).status===200)break;}catch{}await new Promise(r=>setTimeout(r,50));}
 browser=await chromium.launch({executablePath:'/opt/google/chrome/chrome',headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-background-networking']});
 const context=await browser.newContext({viewport:{width:1365,height:900}});
 await context.route('**/*',r=>r.request().url().startsWith(origin+'/')?r.continue():r.abort());
 page=await context.newPage();page.on('pageerror',e=>pageErrors.push(e.message));page.on('console',m=>{if(m.type()==='error')pageErrors.push(m.text());});await page.goto(base+'/login');
 const loginToken=await page.locator('[name=_csrf]').first().inputValue();
 const login=await context.request.post(base+'/login',{form:{_csrf:loginToken,username:'runner-owner',password:'Fixture-Only-Owner-Password-74!'},maxRedirects:0});
 check(login.status()===302||login.status()===303,'Disposable owner authenticated');
 await page.goto(base+'/code-runner');check(await page.locator('#sandbox-bash-tab').count()===1,'Visible Bash language tab');
 await page.locator('#sandbox-bash-tab').click();
 await page.locator('#sandboxBashCode').fill('name=browser\nfor n in 1 2; do echo "$name:$n"; done\necho diagnostic >&2');
 await page.getByRole('button',{name:'Run script',exact:true}).click();
 await page.waitForFunction(()=>document.getElementById('sandboxBashOutput').textContent.includes('exit: 0'));
 const text=await page.locator('#sandboxBashOutput').textContent();
 check(text.includes('browser:1')&&text.includes('browser:2'),'Actual UI executes multiline Bash');
 check(text.includes('STDOUT')&&text.includes('STDERR')&&text.includes('diagnostic')&&text.includes('elapsed:'),'UI displays streams, exit and duration');
 check(text.includes('isolated, temporary')&&text.includes('/work'),'UI identifies working directory and isolation');
 const token=await page.locator('#sandboxBashPane [name=_csrf]').inputValue();
 const post=async form=>context.request.post(base+'/api/sandbox.php',{form:{_csrf:token,confirm_real:'1',...form},maxRedirects:0});
 let response=await post({language:'bash',code:'echo no',_csrf:'invalid'});check(response.status()===419||response.status()===403,'Actual API rejects invalid CSRF');
 response=await post({language:'bash',code:'echo no',cwd:'/'});check(response.status()===422,'Actual API rejects host cwd injection');
 response=await post({language:'bash',code:'echo no',timeout:'999'});check(response.status()===422,'Actual API validates timeout');
 response=await post({language:'sudo',code:'id'});check(response.status()===422,'Actual API rejects unsupported interpreter');
 for(const [language,code,expected] of [['php','<?php echo "api-php";','api-php'],['python','print("api-python")','api-python'],['javascript','console.log("api-js")','api-js']]){
  const body=await (await post({language,code})).json();check(body.data?.ok&&body.data.output.trim()===expected,'Actual API preserves '+language);
 }
 let body=await(await post({language:'bash',code:'echo failed >&2; exit 7'})).json();check(body.data?.exit_code===7&&!body.data.ok&&body.data.error.includes('failed'),'Actual API returns nonzero exit and stderr');
 body=await(await post({language:'bash',code:'if then'})).json();check(!body.data?.ok&&body.data?.error.includes('syntax'),'Actual API displays syntax error');
 body=await(await post({language:'bash',code:'sleep 10',timeout:'1'})).json();check(body.data?.timed_out&&body.data?.exit_code===124,'Actual API enforces timeout');
 body=await(await post({action:'terminal-start',language:'bash',code:'id'})).json();check(!body.ok,'Actual API cannot open host shell');
 body=await(await post({language:'bash',code:'test "$(id -u)" != 0 && test ! -e /opt/lampp/htdocs && test -z "${MICROSYSADMIN_RUNNER_SOURCE:-}" && echo isolated'})).json();
 check(body.data?.ok&&body.data?.output.trim()==='isolated','Actual API has non-root identity and no host/app environment');
 const web=await(await post({action:'save-web',html:'<!doctype html><html><head><link rel="stylesheet" href="style.css"><script src="app.js" defer></script></head><body><p id="state">waiting</p></body></html>',css:'#state{color:rgb(1,2,3)}',javascript:'document.getElementById("state").textContent="JS works";try{parent.document.body.dataset.runnerEscaped="yes"}catch{}'})).json();
 check(web.ok,'Browser HTML/CSS/JS files still save');
 const previewResponse=await context.request.get(new URL(web.data.preview_url,origin).href);check(previewResponse.headers()['content-security-policy']?.includes('sandbox allow-scripts'),'Preview response enforces opaque-origin sandbox');check(previewResponse.headers()['x-frame-options']==='SAMEORIGIN','Only preview route permits same-origin framing');
 await page.evaluate(url=>{document.getElementById('sandboxFrame').src=url;},web.data.preview_url);
 await page.locator('#sandbox-web-tab').click();
 await page.frameLocator('#sandboxFrame').locator('#state').waitFor();
 await page.waitForTimeout(250);
 check(await page.frameLocator('#sandboxFrame').locator('#state').textContent()==='JS works','Browser JavaScript preview still executes');
 check(await page.frameLocator('#sandboxFrame').locator('#state').evaluate(el=>getComputedStyle(el).color)==='rgb(1, 2, 3)','Browser CSS preview still applies');
 check(!await page.evaluate(()=>document.body.dataset.runnerEscaped),'Browser preview cannot access application document');
 await page.locator('#sandbox-bash-tab').click();
 for(const [name,viewport] of [['desktop',{width:1365,height:900}],['mobile',{width:390,height:844}]]){
  await page.setViewportSize(viewport);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),name+' runner has no overflow');
  const file=name+'.png';await page.screenshot({path:path.join(out,file),fullPage:true});screenshots.push({path:file,sha256:hash(fs.readFileSync(path.join(out,file)))});
 }
 const files=['classes/IsolatedCodeRunner.php','classes/SandboxService.php','controllers/ApiController.php','views/pages/sandbox.php','assets/js/sysadmin.js','tests/code-runner-browser.cjs'];
 const result={status:'PASS',checks:checks.length,details:checks,screenshots,source:Object.fromEntries(files.map(f=>[f,hash(fs.readFileSync(path.join(source,f)))]))};
 fs.writeFileSync(path.join(out,'result.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({status:'PASS',checks:checks.length,screenshots:screenshots.length}));
}catch(error){if(page){try{fs.writeFileSync(path.join(out,'page-diagnostic.json'),JSON.stringify({errors:pageErrors,output:await page.locator('#sandboxBashOutput').textContent()},null,2));}catch{}}fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({status:'FAIL',error:error.message,checks},null,2));throw error;}finally{if(browser)await browser.close();if(server&&server.exitCode===null)server.kill('SIGTERM');}})().catch(e=>{console.error(e);process.exitCode=1;});
