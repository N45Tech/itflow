// Real endpoints and disposable records from service_assistance_http_assert.php.
const {chromium,devices}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const shots=process.env.N45_ASSISTANCE_SCREENSHOTS||path.resolve(__dirname,'../../.impeccable/review/ticket-workflow');
fs.mkdirSync(shots,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const errors=[];
 const context=async(options,actor=1)=>{const c=await browser.newContext(options);await c.addCookies([{name:'PHPSESSID',value:f.sessions[actor],url:f.base}]);return c;};
 try {
  const admin=await context({viewport:{width:1440,height:1000}},0), desk=await context({viewport:{width:1440,height:1000}});
  const administration=await admin.newPage();administration.on('pageerror',e=>errors.push(e.message));
  await administration.goto(f.base+'/admin/canned_responses.php');
  await administration.getByRole('button',{name:/New Canned Response/}).waitFor();
  await administration.getByRole('link',{name:'Vendor status update',exact:true}).waitFor();
  await administration.locator('#itflowFlashToast').waitFor({state:'hidden'});
  await administration.screenshot({path:path.join(shots,'admin-canned-responses.png')});
  const desktop=await desk.newPage();desktop.on('pageerror',e=>errors.push(e.message));
  await desktop.goto(f.base+'/agent/tickets.php');
  await desktop.getByRole('button',{name:/Queues/}).click();
  await desktop.getByRole('link',{name:/Follow-ups due/}).click();
  assert.equal(new URL(desktop.url()).searchParams.get('queue'),'followups');
  await desktop.getByRole('link',{name:'Restore branch DNS resolution',exact:true}).waitFor();
  assert.equal(await desktop.getByRole('link',{name:'Field Mode',exact:true}).count(),0);
  assert.equal(await desktop.getByRole('link',{name:'Resolution knowledge',exact:true}).count(),0);
  await desktop.screenshot({path:path.join(shots,'desktop-ticket-filter.png')});
  await desktop.goto(`${f.base}/agent/ticket.php?ticket_id=${f.ticket}`);
  await desktop.locator('label[for="public_reply_type_opt0"]').click();
  await desktop.waitForFunction(()=>window.tinymce?.get('ticket_reply')?.initialized);
  await desktop.evaluate(()=>tinymce.get('ticket_reply').setContent('<p>Existing reply. </p>'));
  let desktopPosts=0;desktop.on('request',r=>{if(r.method()==='POST')desktopPosts++;});
  await desktop.getByLabel('Canned response',{exact:true}).selectOption(String(f.response));
  await desktop.waitForFunction(()=>tinymce.get('ticket_reply').getContent().includes('will update you shortly'));
  assert.match(await desktop.evaluate(()=>tinymce.get('ticket_reply').getContent()),/Existing reply/);
  assert.equal(desktopPosts,0,'Selecting a canned response sent a desktop reply');
  assert.equal(await desktop.getByText('Suggested fixes',{exact:true}).count(),0);
  assert.equal(await desktop.getByRole('link',{name:'Open Field Mode',exact:true}).count(),0);
  await desktop.locator('#replyComposer').scrollIntoViewIfNeeded();
  await desktop.screenshot({path:path.join(shots,'desktop-canned-response.png')});
  await desktop.goto(`${f.base}/agent/field/?ticket_id=${f.solved}#job/${f.ticket}/overview`);
  await desktop.waitForURL(`**/agent/ticket.php?ticket_id=${f.ticket}`);
  // Resizing a desktop browser must not change its application.
  await desktop.setViewportSize({width:393,height:852});await desktop.reload();
  assert.ok(desktop.url().includes('/agent/ticket.php'));
  const mobile=await context({...devices['Pixel 7'],viewport:{width:393,height:852}});
  const page=await mobile.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`${f.base}/agent/tickets.php?queue=followups`);
  await page.waitForURL('**/agent/field/#jobs?*');
  await page.getByRole('heading',{name:'Find work',exact:true}).waitFor();
  assert.equal(await page.getByLabel('Follow-ups',{exact:true}).inputValue(),'followups');
  await page.getByRole('link',{name:'Restore branch DNS resolution',exact:true}).waitFor();
  await page.locator('#notice').waitFor({state:'hidden'});
  await page.screenshot({path:path.join(shots,'mobile-ticket-filter.png')});
  await page.goto(`${f.base}/agent/ticket.php?ticket_id=${f.ticket}#followups`);
  await page.getByRole('button',{name:'Review follow-up',exact:true}).first().click();
  await page.getByLabel('Next step and reason',{exact:true}).fill('Check the vendor response, then update the branch manager.');
  let lost=false;const plans=[];
  await page.route('**/agent/field/api.php?*',async route=>{
    const req=route.request();
    if(req.method()==='POST'&&req.postDataJSON().action==='followup_plan'){
      plans.push(req.postDataJSON());
      if(!lost){lost=true;const response=await route.fetch();assert.equal(response.status(),200);await route.fulfill({status:500,json:{error:'The save response was lost. Retry this form.'}});return;}
    }
    await route.continue();
  });
  await page.getByRole('button',{name:'Save follow-up plan',exact:true}).click();
  await page.locator('#assist-plan-save .error-text').waitFor();
  await page.getByRole('button',{name:'Save follow-up plan',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('#sheet').open);
  assert.equal(plans.length,2);assert.deepEqual(plans[0],plans[1],'Lost response changed retry payload');
  await page.goto(`${f.base}/agent/field/#job/${f.ticket}/conversation`);
  await page.getByRole('button',{name:'Write an update',exact:true}).click();
  await page.getByLabel('Message',{exact:true}).fill('Existing note. ');
  let replies=0;page.on('request',r=>{if(r.method()==='POST'&&r.postData()?.includes('"action":"reply"'))replies++;});
  await page.getByLabel('Canned response',{exact:true}).selectOption(String(f.response));
  await page.getByText('Response inserted. Review and edit before saving.',{exact:true}).waitFor();
  assert.match(await page.getByLabel('Message',{exact:true}).inputValue(),/Existing note/);
  assert.match(await page.getByLabel('Message',{exact:true}).inputValue(),/will update you shortly/);
  assert.equal(replies,0,'Selecting a canned response sent a mobile reply');
  await page.screenshot({path:path.join(shots,'mobile-canned-response.png')});
  await page.getByLabel('Message',{exact:true}).fill('Reviewed fixture update. We will check again this afternoon.');
  await page.getByRole('button',{name:'Save update',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('#sheet').open);
  await page.getByText('Reviewed fixture update. We will check again this afternoon.',{exact:true}).waitFor();
  assert.equal(replies,1);
  await page.setViewportSize({width:852,height:393});await page.reload();
  await page.getByRole('button',{name:'Write an update',exact:true}).waitFor();assert.ok(page.url().includes('/agent/field/'));
  await page.setViewportSize({width:393,height:852});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Mobile overflowed');
  const cache=await page.evaluate(async()=>{const keys=await caches.keys();return (await Promise.all(keys.map(async k=>(await (await caches.open(k)).keys()).map(r=>r.url)))).flat();});
  assert.ok(!cache.some(url=>/api\.php|uploads\//.test(url)),'Private ticket data entered offline cache');
  // iPadOS may report a Mac user agent; touch capability still selects the mobile UI.
  const ipad=await context({viewport:{width:1024,height:768},hasTouch:true,userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15'});
  await ipad.addInitScript(()=>{Object.defineProperty(navigator,'platform',{get:()=> 'MacIntel'});Object.defineProperty(navigator,'maxTouchPoints',{get:()=>5});});
  const tablet=await ipad.newPage();await tablet.goto(`${f.base}/agent/ticket.php?ticket_id=${f.ticket}`);await tablet.waitForURL(url=>url.pathname==='/agent/field/'&&url.hash===`#job/${f.ticket}/overview`);
  assert.deepEqual(errors,[],'Browser runtime error');
  console.log('Ticket workflow browser: admin responses, ticket filter, editable insertion, exact retry, mobile auto-routing, desktop redirect, landscape and iPad routing passed.');
 } catch(error) {
  for (const c of browser.contexts()) for (const p of c.pages()) {console.error('Failed browser page',p.url(),(await p.locator('body').innerText()).slice(0,900));}
  throw error;
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
