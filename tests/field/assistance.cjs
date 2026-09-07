// Real application endpoints backed by service_assistance_http_assert.php's disposable fixtures.
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const f=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const shots=process.env.N45_ASSISTANCE_SCREENSHOTS||path.resolve(__dirname,'../../.impeccable/review/service-assistance');
fs.mkdirSync(shots,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const errors=[];
 try {
  const author=await browser.newContext({viewport:{width:1440,height:1000}});
  await author.addCookies([{name:'PHPSESSID',value:f.sessions[0],url:f.base}]);
  const desktop=await author.newPage();desktop.on('pageerror',e=>errors.push(e.message));
  await desktop.goto(`${f.base}/agent/followups.php?scope=all&client_id=${f.client}`);
  await desktop.getByRole('heading',{name:'Follow-ups',exact:true}).waitFor();
  assert.ok(await desktop.getByText('Send the branch manager a service update',{exact:true}).count());
  await desktop.screenshot({path:path.join(shots,'desktop-followups.png'),fullPage:true});
  await desktop.goto(`${f.base}/agent/knowledge.php?id=${f.knowledge}`);
  await desktop.getByLabel(/^Article title/).waitFor();
  await desktop.screenshot({path:path.join(shots,'desktop-knowledge-draft.png'),fullPage:true});
  await desktop.getByLabel('Checks and cautions',{exact:true}).fill('Use only the approved resolver configuration. Confirm internal and external names from a branch workstation.');
  await desktop.getByRole('button',{name:'Request review',exact:true}).click();
  await desktop.getByText('Awaiting review by a different authorized technician.',{exact:true}).waitFor();
  const review=await browser.newContext({viewport:{width:1440,height:1000}});
  await review.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const reviewer=await review.newPage();reviewer.on('pageerror',e=>errors.push(e.message));
  await reviewer.goto(`${f.base}/agent/knowledge.php?id=${f.knowledge}`);
  await reviewer.getByRole('button',{name:'Publish internal article',exact:true}).waitFor();
  await reviewer.screenshot({path:path.join(shots,'desktop-knowledge-review.png'),fullPage:true});
  const mobile=await browser.newContext({viewport:{width:393,height:852},isMobile:true,hasTouch:true});
  await mobile.addCookies([{name:'PHPSESSID',value:f.sessions[0],url:f.base}]);
  const page=await mobile.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`${f.base}/agent/field/#followups`);
  await page.getByRole('heading',{name:'Follow-ups',exact:true}).waitFor();
  await page.getByText('Filter follow-ups',{exact:true}).click();
  await page.getByLabel('Client name',{exact:true}).fill('Example Branch Services');
  await page.getByRole('button',{name:'Apply filters',exact:true}).click();
  await page.getByRole('button',{name:'Review follow-up'}).first().waitFor();
  await page.locator('#notice').waitFor({state:'hidden'});
  await page.screenshot({path:path.join(shots,'mobile-followups.png')});
  await page.getByRole('button',{name:'Review follow-up'}).first().click();
  await page.getByLabel(/^Escalate to/).selectOption('0');
  await page.getByLabel('Next step and reason',{exact:true}).fill('Check for the vendor response, then call the branch manager.');
  await page.screenshot({path:path.join(shots,'mobile-followup-plan.png')});
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
  assert.equal(plans.length,2);assert.deepEqual(plans[0],plans[1],'Lost-response retry changed the plan payload');
  await page.goto(`${f.base}/agent/field/#job/${f.ticket}/fixes`);
  await page.getByRole('heading',{name:'Suggested fixes',exact:true}).waitFor();
  await page.getByRole('button',{name:'Read source document',exact:true}).first().click();
  await page.getByRole('button',{name:'View full document',exact:true}).click();
  await page.locator('#sheet iframe').waitFor();
  await page.getByRole('button',{name:'Close',exact:true}).click();
  // Reviewer completes publication inside Field Mode and starts a later revision.
  const rm=await browser.newContext({viewport:{width:393,height:852},isMobile:true,hasTouch:true});
  await rm.addCookies([{name:'PHPSESSID',value:f.sessions[1],url:f.base}]);
  const rp=await rm.newPage();rp.on('pageerror',e=>errors.push(e.message));
  await rp.goto(`${f.base}/agent/field/#knowledge/${f.knowledge}`);
  await rp.getByRole('button',{name:'Publish internal article',exact:true}).waitFor();
  await rp.locator('#notice').waitFor({state:'hidden'});
  await rp.screenshot({path:path.join(shots,'mobile-knowledge-review.png')});
  await rp.getByRole('button',{name:'Publish internal article',exact:true}).scrollIntoViewIfNeeded();
  await rp.screenshot({path:path.join(shots,'mobile-knowledge-actions.png')});
  await rp.getByLabel('Review reason',{exact:true}).fill('Verified the source resolution, client scope and validation checks.');
  await rp.getByRole('button',{name:'Publish internal article',exact:true}).click();
  await rp.getByRole('button',{name:'Start a revision',exact:true}).waitFor();
  await rp.getByRole('button',{name:'Read current client document',exact:true}).click();
  await rp.getByRole('button',{name:'View full document',exact:true}).waitFor();
  await rp.getByRole('button',{name:'Close',exact:true}).click();
  await rp.getByLabel('Revision reason',{exact:true}).fill('Add a more detailed workstation validation example.');
  await rp.getByRole('button',{name:'Start a revision',exact:true}).click();
  await rp.getByLabel('Article title',{exact:true}).waitFor();
  await rp.getByLabel('Resolution steps',{exact:true}).fill('Restore the approved settings. Check both internal and external DNS names, then open the business application.');
  await rp.getByRole('button',{name:'Save draft',exact:true}).click();
  await rp.waitForFunction(()=>document.querySelector('#notice')?.textContent==='Draft saved.');
  // Paginated queue UI must browse past forty articles in Field Mode.
  await rp.route('**/agent/field/api.php?action=knowledge_queue*',async route=>{
    const offset=new URL(route.request().url()).searchParams.get('offset');
    await route.fulfill({json:{data:{items:[{knowledge_id:offset?42:41,knowledge_title:offset?'Second review page':'First review page',client_name:'Pagination fixture',user_name:'Reviewer'}],next:offset?null:40,state:'review'}}});
  });
  await rp.goto(`${f.base}/agent/field/#knowledge`);await rp.getByRole('button',{name:'Load more articles',exact:true}).click();
  await rp.getByRole('link',{name:'Second review page',exact:true}).waitFor();
  assert.equal(await rp.getByRole('button',{name:'Load more articles',exact:true}).count(),0);
  for(const p of [desktop,reviewer,page,rp])assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Responsive surface overflowed');
  const cache=await page.evaluate(async()=>{const a=[];for(const key of await caches.keys())for(const req of await(await caches.open(key)).keys())a.push(req.url);return a;});
  assert.ok(cache.every(u=>!u.includes('api.php')&&!u.includes('/uploads/')),'Private assistance data was cached offline');
  assert.deepEqual(errors,[],'Browser runtime errors');
  console.log('Service assistance browser: desktop submission, Field Mode plan retry, document views, independent publication, revision, pagination and responsive layout passed.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
