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
console.log(`CHECKS ${checks}`);
