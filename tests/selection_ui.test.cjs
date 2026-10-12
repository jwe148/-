// Node DOM stub checks client validation behavior; this does not replace browser rendering QA.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../Page/assets/js/selection.js'), 'utf8');
let checks = 0;
function check(name, action) { action(); checks++; console.log(`PASS ${name}`); }
function field() {
  return {value: '', selectedOptions: [], attrs: {}, validity: '',
    setCustomValidity(value) { this.validity = value; },
    setAttribute(key, value) { this.attrs[key] = value; },
    removeAttribute(key) { delete this.attrs[key]; }};
}
function choose(select, id, code) { select.value = id; select.selectedOptions = id ? [{dataset:{courseCode:code}}] : []; }
check('script accepts pages without selection form', () => vm.runInNewContext(source, {document:{getElementById:()=>null}}));
const primary = Array.from({length:4}, field), backup = Array.from({length:2}, field);
const counts = {'primary-count':{}, 'backup-count':{}, 'selection-duplicate':{}};
let change;
const form = {querySelectorAll:selector => selector.includes('primary') ? primary : backup,
  addEventListener(type, listener) { assert.equal(type,'change'); change = listener; }};
vm.runInNewContext(source, {document:{getElementById:id => id === 'selection-form' ? form : counts[id]}});
check('empty form initializes both counts and hides duplicate warning', () => {
  assert.equal(counts['primary-count'].textContent,'首选 0/4'); assert.equal(counts['backup-count'].textContent,'备选 0/2');
  assert.equal(counts['selection-duplicate'].hidden,true);
});
primary.forEach((select,i)=>choose(select,`P${i}`,`C${i}`)); backup.forEach((select,i)=>choose(select,`B${i}`,`C${i+4}`)); change();
check('six unique courses display complete counts and valid fields', () => {
  assert.equal(counts['primary-count'].textContent,'首选 4/4'); assert.equal(counts['backup-count'].textContent,'备选 2/2');
  assert.ok([...primary,...backup].every(select=>select.validity === ''));
});
choose(backup[0],'OTHER-CLASS','C0'); change();
check('different classes of same course invalidate both choices', () => {
  assert.ok(primary[0].validity); assert.ok(backup[0].validity);
  assert.equal(primary[0].attrs['aria-invalid'],'true'); assert.equal(backup[0].attrs['aria-invalid'],'true');
  assert.equal(counts['selection-duplicate'].hidden,false);
});
choose(backup[1],'SAME-AS-PRIMARY','C0'); change();
check('three duplicate choices all remain invalid', () => assert.ok([primary[0],backup[0],backup[1]].every(select=>select.validity)));
choose(backup[0],'B0','C4'); choose(backup[1],'B1','C5'); change();
check('correcting duplicates clears validity, aria and warning', () => {
  assert.ok([...primary,...backup].every(select=>!select.validity && !select.attrs['aria-invalid']));
  assert.equal(counts['selection-duplicate'].hidden,true); assert.equal(counts['selection-duplicate'].textContent,'');
});
choose(primary[3],'',''); change();
check('clearing choice decrements count without duplicate warning', () => {
  assert.equal(counts['primary-count'].textContent,'首选 3/4'); assert.equal(counts['selection-duplicate'].hidden,true);
});


// Run the actual refresh code with controlled responses and timers.
function livePage() {
  const ids = ['A','B','C','D','E','F'];
  const primary = Array.from({length:4}, () => {
    const select = field();
    select.options = [{value:''}, ...ids.map(id => ({value:id, disabled:false,
      dataset:{courseCode:id, courseName:`课程${id}`, availabilityLabel:`课程${id}`}, textContent:`课程${id}`}))];
    select.disabled = false;
    return select;
  });
  const backup = Array.from({length:2}, () => {
    const select = field();
    select.options = [{value:''}, ...ids.map(id => ({value:id, disabled:false,
      dataset:{courseCode:id, courseName:`课程${id}`, availabilityLabel:`课程${id}`}, textContent:`课程${id}`}))];
    select.disabled = false;
    return select;
  });
  const fields = [...primary,...backup];
  function chooseLive(select, id) {select.value=id; select.selectedOptions=id ? [select.options.find(o=>o.value===id)] : [];}
  fields.forEach((select,i)=>chooseLive(select,ids[i]));
  const buttons = [{disabled:false},{disabled:false}];
  const elements = Object.fromEntries(['primary-count','backup-count','selection-duplicate','selection-availability-warning','selection-refresh-status'].map(id=>[id,{textContent:'',hidden:false}]));
  const listeners = {}, timers = new Map();let timerId=0;
  elements['selection-refresh'] = {disabled:false, addEventListener(type, fn) {listeners['refresh-'+type]=fn;}};
  const form = {dataset:{availabilityUrl:'selection_availability.php'},
    querySelectorAll(selector) {return selector.includes('button') ? buttons : selector.includes('primary') ? primary : backup;},
    addEventListener(type,fn) {listeners['form-'+type]=fn;}};
  const page = {primary,backup,fields,buttons,elements,listeners,timers,choose:chooseLive,calls:[],status:200,
    response:{open:true,offerings:Object.fromEntries(ids.map(id=>[id,{remaining:3,owned:false,status:'open'}]))}};
  page.document = {hidden:false,getElementById:id=>id==='selection-form'?form:elements[id],
    addEventListener(type,fn) {listeners['document-'+type]=fn;}};
  vm.runInNewContext(source, {document:page.document,window:{addEventListener(type,fn) {listeners['window-'+type]=fn;}},
    AbortController,
    setTimeout(fn,delay) {const id=++timerId;timers.set(id,{fn,delay});return id;},
    clearTimeout(id) {timers.delete(id);},
    fetch(url,options) {page.calls.push({url,options});return page.error ? Promise.reject(page.error) : Promise.resolve({status:page.status,ok:page.status===200,json:async()=>page.response});}});
  return page;
}
const settle = () => new Promise(resolve=>setImmediate(resolve));
async function refresh(page) {await page.listeners['refresh-click']();await settle();}
async function runAvailabilityChecks() {
  const page=livePage();
  page.listeners['refresh-click']();page.listeners['window-focus']();
  await settle();
  check('refresh starts once with authenticated no-cache request',()=>{
    assert.equal(page.calls.length,1);assert.equal(page.calls[0].url,'selection_availability.php');
    assert.equal(page.calls[0].options.credentials,'same-origin');assert.equal(page.calls[0].options.cache,'no-store');
  });
  check('refresh schedules next request after 15 seconds and shows counts',()=>{
    assert.equal([...page.timers.values()].filter(t=>t.delay===15000).length,1);
    assert.equal(page.primary[0].selectedOptions[0].textContent,'课程A（剩余3人）');
  });
  const original=page.fields.map(f=>f.value).join(',');
  page.response.offerings.A.remaining=0;page.response.offerings.E.remaining=0;
  await refresh(page);
  check('newly full selected primary is preserved and warned before submit',()=>{
    assert.equal(page.primary[0].value,'A');assert.ok(page.primary[0].validity.includes('已满'));
    assert.equal(page.elements['selection-availability-warning'].hidden,false);
    assert.ok(page.elements['selection-availability-warning'].textContent.includes('课程A已满'));
  });
  check('full backup remains selectable while unselected full primary is disabled',()=>{
    assert.equal(page.backup[0].value,'E');assert.equal(page.backup[0].validity,'');
    assert.equal(page.backup[0].selectedOptions[0].disabled,false);
    assert.equal(page.primary[0].options.find(o=>o.value==='E').disabled,true);
  });
  page.response.offerings.A.remaining=2;page.response.offerings.E.remaining=1;
  await refresh(page);
  check('released seat clears stale warning and re-enables primary option',()=>{
    assert.equal(page.primary[0].validity,'');assert.equal(page.elements['selection-availability-warning'].hidden,true);
    assert.equal(page.primary[0].options.find(o=>o.value==='E').disabled,false);
  });
  page.response.offerings.A.remaining=0;page.response.offerings.A.owned=true;
  await refresh(page);
  check('own seat in full class stays valid and available for reordering',()=>{
    assert.equal(page.primary[0].validity,'');
    assert.equal(page.primary[1].options.find(o=>o.value==='A').disabled,false);
    assert.ok(page.primary[0].selectedOptions[0].textContent.includes('已选'));
  });
  page.choose(page.backup[0],'A');page.listeners['form-change']();await refresh(page);
  check('duplicate course validation survives availability refresh',()=>{
    assert.ok(page.primary[0].validity.includes('同一门课程'));assert.ok(page.backup[0].validity.includes('同一门课程'));
  });
  page.choose(page.backup[0],'E');page.listeners['form-change']();
  check('refresh keeps every choice and its order',()=>assert.equal(page.fields.map(f=>f.value).join(','),original));
  const label=page.primary[0].selectedOptions[0].textContent;
  page.error=new Error('network');await refresh(page);
  check('network failure preserves values and last counts with retry message',()=>{
    assert.equal(page.fields.map(f=>f.value).join(','),original);assert.equal(page.primary[0].selectedOptions[0].textContent,label);
    assert.ok(page.elements['selection-refresh-status'].textContent.includes('刷新失败'));
  });
  page.error=null;page.response={open:true,offerings:{A:{remaining:-1,owned:false,status:'open'}}};await refresh(page);
  check('invalid response does not partially update fields',()=>{
    assert.equal(page.primary[0].selectedOptions[0].textContent,label);
    assert.ok(page.elements['selection-refresh-status'].textContent.includes('刷新失败'));
  });
  page.document.hidden=true;const calls=page.calls.length;await refresh(page);
  check('hidden page pauses refresh',()=>assert.equal(page.calls.length,calls));
  page.document.hidden=false;page.listeners['document-visibilitychange']();await settle();
  check('visible page refreshes immediately',()=>assert.equal(page.calls.length,calls+1));
  page.status=401;await refresh(page);
  check('expired session stops polling and preserves inputs',()=>{
    assert.equal([...page.timers.values()].filter(t=>t.delay===15000).length,0);
    assert.ok(page.buttons.every(b=>b.disabled));assert.equal(page.fields.map(f=>f.value).join(','),original);
    assert.ok(page.elements['selection-refresh-status'].textContent.includes('登录已失效'));
  });
  const closed=livePage();await settle();closed.response.open=false;await refresh(closed);
  check('registration closure disables writes without clearing choices',()=>{
    assert.ok(closed.fields.every(f=>f.disabled));assert.ok(closed.buttons.every(b=>b.disabled));
    assert.equal(closed.fields.map(f=>f.value).join(','),original);
    assert.ok(closed.elements['selection-availability-warning'].textContent.includes('选课已结束'));
  });
  console.log(`CHECKS ${checks}`);
}
runAvailabilityChecks().catch(error=>{console.error(error);process.exitCode=1;});
