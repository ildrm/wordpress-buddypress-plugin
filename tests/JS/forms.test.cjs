const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function environment(response) {
  const handlers = {};
  let destination;
  const sandbox = {
    wp: { i18n: { __: text => text } },
    bpiConfig: { root: 'https://community.invalid/rest/', nonce: 'nonce' },
    document: { addEventListener: (name, callback) => handlers[name] = callback },
    window: { location: { href: 'https://community.invalid/?bpi_cursor=old', assign: url => destination = url } },
    URL, AbortController, setTimeout, clearTimeout, TypeError,
    FormData: class { constructor(form) { this.form = form; } get(key) { return this.form.values[key] ?? null; } getAll(key) { return [].concat(this.form.values[key] ?? []); } },
    fetch: response
  };
  vm.runInNewContext(fs.readFileSync('assets/community.js','utf8'), sandbox);
  return { run: handlers.submit, click: handlers.click, config: sandbox.bpiConfig, destination: () => destination };
}
function form() {
  const status = { textContent: '' };
  const button = { textContent: 'Save', disabled: false, focus() { this.focused = true; } };
  return { values: { bpi_payload: '{}', bpi_types: '{"interests":"topic_ids","analytics":"checkbox","modules:feed":"checkbox"}', 'input[interests][]': ['1','2'], 'input[analytics]': ['0','1'], 'input[modules:feed]': ['0'] },
    elements: { bpi_path: { value:'preferences' }, bpi_method: { value:'POST' } },
    querySelector: selector => selector === '.bpi-form-status' ? status : button,
    setAttribute() {}, removeAttribute() {}, status, button };
}
test('typed arrays, checkbox and nested payload use authenticated same-origin request', async () => {
  const f = form();
  let options;
  const env = environment(async (_url, request) => { options=request; return { ok:true, json:async()=>({saved:true}) }; });
  await env.run({ target: { closest:()=>f }, preventDefault() {} });
  assert.deepEqual(JSON.parse(options.body), { interests:[1,2], analytics:true, modules:{feed:false} });
  assert.equal(options.headers['X-WP-Nonce'],'nonce');
  assert.equal(options.credentials,'same-origin');
  assert.equal(new URL(env.destination()).searchParams.has('bpi_cursor'),false);
});
test('network failure preserves form, re-enables button and restores keyboard focus', async () => {
  const f=form();
  const env=environment(async()=>{ throw new TypeError('Network failure'); });
  await env.run({ target: { closest:()=>f }, preventDefault() {} });
  assert.equal(f.button.disabled,false);
  assert.equal(f.button.focused,true);
  assert.match(f.status.textContent,/Connection failed/);
  assert.equal(env.destination(),undefined);
});
test('unsafe route and malformed form JSON produce no request', async () => {
  const f=form();
  f.elements.bpi_path.value='../settings';
  let calls=0;
  const env=environment(async()=>{ ++calls; });
  await env.run({ target: { closest:()=>f }, preventDefault() {} });
  assert.equal(calls,0);
  assert.equal(f.button.disabled,false);
});
test('click observations require consent and contain identifiers only', async () => {
  const requests=[];
  const env=environment(async (_url,options)=>{ requests.push(options); });
  const link={ dataset:{ bpiEvent:'recommendation_clicked',bpiType:'member',bpiId:'42' } };
  const event={ target:{ closest:()=>link } };
  env.click(event);
  assert.equal(requests.length,0);
  env.config.analytics=true;
  env.click(event);
  assert.equal(requests.length,1);
  assert.deepEqual(JSON.parse(requests[0].body),{event:'recommendation_clicked',type:'member',id:42});
  assert.equal(requests[0].keepalive,true);
  link.dataset.bpiId='-1';
  env.click(event);
  assert.equal(requests.length,1);
});
test('empty PHP array base still serializes named fields as a JSON object', async () => {
  const f=form();
  f.values.bpi_payload='[]';
  f.values.bpi_types='{"name":"text"}';
  f.values['input[name]']='New topic';
  f.elements.bpi_path.value='topics';
  let payload;
  const env=environment(async (_url,request)=>{ payload=JSON.parse(request.body);return {ok:true,json:async()=>({id:1})}; });
  await env.run({target:{closest:()=>f},preventDefault(){}});
  assert.deepEqual(payload,{name:'New topic'});
});
