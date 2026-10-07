// Report 75R-B end-to-end: the real frontend in headless Chrome against a
// throwaway backend on the disposable test database. Drives the browser over
// the DevTools protocol (no extra dependencies).
import { spawn } from 'node:child_process'
import { mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs'

const API = 'http://127.0.0.1:8799/api'
const APP = 'http://127.0.0.1:5277'
const OUT = process.argv[2]
const setup = JSON.parse(readFileSync(process.argv[3], 'utf8'))
const PASSWORD = 'E2e-75rb-pass'
const log = []
const step = (text) => { log.push(text); console.log(text) }
mkdirSync(OUT, { recursive: true })

// ── API (for login, and for the two steps the browser does not drive) ────
async function api(path, { token, method = 'GET', body } = {}) {
  const response = await fetch(API + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined })
  const json = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(`${method} ${path} → ${response.status} ${JSON.stringify(json).slice(0, 400)}`)
  return json
}
const tokens = {}
for (const [role, user] of Object.entries(setup.users)) {
  const login = await api('/login', { method: 'POST', body: { email: user.email, password: PASSWORD } })
  tokens[role] = login.token ?? login.access_token ?? login.data?.token
  if (!tokens[role]) throw new Error(`No token for ${role}: ${JSON.stringify(login).slice(0, 200)}`)
}
step(`Signed in ${Object.keys(tokens).length} people through the real login endpoint.`)

// ── Browser ─────────────────────────────────────────────────────────────
const profile = `/tmp/claude-1000/-home-cosmas-projects/510de9de-02f5-468f-9229-66a74b68aaa7/scratchpad/chrome-e2e-${Date.now()}`
rmSync(profile, { recursive: true, force: true })
const chrome = spawn('google-chrome-stable', ['--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
  '--remote-debugging-port=9377', `--user-data-dir=${profile}`, '--window-size=1440,1100', 'about:blank'], { stdio: 'ignore' })
const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms))
let target
for (let i = 0; i < 50 && !target; i++) {
  await sleep(200)
  target = await fetch('http://127.0.0.1:9377/json').then(r => r.json()).then(list => list.find(t => t.type === 'page')).catch(() => null)
}
const socket = new WebSocket(target.webSocketDebuggerUrl)
await new Promise(resolve => socket.addEventListener('open', resolve))
let seq = 0
const waiting = new Map()
socket.addEventListener('message', (event) => {
  const message = JSON.parse(event.data)
  if (message.id && waiting.has(message.id)) { waiting.get(message.id)(message); waiting.delete(message.id) }
})
const cdp = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++seq
  waiting.set(id, (message) => message.error ? reject(new Error(`${method}: ${message.error.message}`)) : resolve(message.result))
  socket.send(JSON.stringify({ id, method, params }))
})
await cdp('Page.enable'); await cdp('Runtime.enable')

async function js(expression) {
  const result = await cdp('Runtime.evaluate', { expression: `(async () => { ${expression} })()`, awaitPromise: true, returnByValue: true })
  if (result.exceptionDetails) throw new Error(`In page: ${result.exceptionDetails.exception?.description ?? result.exceptionDetails.text}`)
  return result.result.value
}
async function until(expression, what, timeout = 20000) {
  const started = Date.now()
  for (;;) {
    const value = await js(`return (${expression})`).catch(() => null)
    if (value) return value
    if (Date.now() - started > timeout) {
      await shot(`FAILED-${what.replace(/[^a-z0-9]+/gi, '-').slice(0, 40)}`)
      throw new Error(`Timed out waiting for: ${what}\nPage says: ${(await js('return document.body.innerText.slice(0, 1500)')).replace(/\n+/g, ' | ')}`)
    }
    await sleep(150)
  }
}
let viewport = 'desktop'
async function size(kind) {
  viewport = kind
  await cdp('Emulation.setDeviceMetricsOverride', kind === 'mobile'
    ? { width: 390, height: 3400, deviceScaleFactor: 1, mobile: true } : { width: 1440, height: 2300, deviceScaleFactor: 1, mobile: false })
}
let shots = 0
async function shot(name) {
  // A tall viewport rather than a stitched full-page capture, so the app's
  // sticky header appears once, where it really is.
  const { data } = await cdp('Page.captureScreenshot', { format: 'png' })
  const file = `${String(++shots).padStart(2, '0')}-${viewport}-${name}.png`
  writeFileSync(`${OUT}/${file}`, Buffer.from(data, 'base64'))
  return file
}
/** Screenshot the panel at desktop and phone width. */
async function evidence(name) {
  await js(`window.scrollTo(0, 0); document.querySelectorAll('*').forEach(el => { if (el.scrollTop) el.scrollTop = 0 })`)
  await size('desktop'); await sleep(250); const a = await shot(name)
  await size('mobile'); await sleep(350); const b = await shot(name)
  await size('desktop'); await sleep(200)
  step(`   evidence: ${a}, ${b}`)
}

let requisitionId
let current
async function as(role) {
  current = role
  await cdp('Page.navigate', { url: `${APP}/tests/fixtures/75rb-e2e-blank.html` })
  await sleep(300)
  await js(`localStorage.clear(); sessionStorage.clear(); localStorage.setItem('auth_token', ${JSON.stringify(tokens[role])}); localStorage.setItem('isLoggedIn', 'true')`)
  await cdp('Page.navigate', { url: `${APP}/finance/petty-cash/requisitions/${requisitionId}` })
  await until(`document.querySelector('[data-test="receiver-payments"]')`, `${role} sees the requisition`, 45000)
  await sleep(400)
}
/** The receiver card whose heading starts with a name. */
const card = (name) => `[...document.querySelectorAll('[data-test="receiver"]')].find(el => el.querySelector('h4').textContent.trim().startsWith(${JSON.stringify(name)}))`
const text = (selector) => js(`return document.querySelector(${JSON.stringify(selector)})?.innerText ?? ''`)
async function clickIn(name, action) {
  await until(`${card(name)}?.querySelector('[data-test="${action}"]')`, `${action} for ${name} offered to ${current}`)
  await js(`${card(name)}.querySelector('[data-test="${action}"]').click()`)
}
async function fill(selector, value, root = 'document') {
  await until(`${root}.querySelector(${JSON.stringify(selector)})`, `field ${selector}`)
  await js(`const el = ${root}.querySelector(${JSON.stringify(selector)});
    const proto = el.tagName === 'SELECT' ? HTMLSelectElement.prototype : el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(String(value))});
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));`)
}
async function submit(dialog) {
  await js(`document.querySelector('[data-test="${dialog}"]').requestSubmit()`)
  await until(`!document.querySelector('[data-test="${dialog}"]') || document.querySelector('[data-test="${dialog}"] [role="alert"]')`, `${dialog} to finish`)
  const error = await text(`[data-test="${dialog}"] [role="alert"]`)
  if (error) throw new Error(`${dialog} refused: ${error}`)
  await sleep(700)
}
/** The app-wide confirm/prompt dialog: type a reason if asked, then confirm. */
async function confirmDialog(reason) {
  await until(`[...document.querySelectorAll('[role="dialog"], [role="alertdialog"], .fixed')].some(el => /Cancel/.test(el.innerText) && el.querySelectorAll('button').length >= 2 && !el.querySelector('[data-test]'))`, 'confirmation dialog')
  await js(`const box = [...document.querySelectorAll('[role="dialog"], [role="alertdialog"], .fixed')].filter(el => /Cancel/.test(el.innerText) && !el.querySelector('[data-test]')).pop();
    const input = box.querySelector('input');
    if (input && ${JSON.stringify(reason ?? '')}) { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, ${JSON.stringify(reason ?? '')}); input.dispatchEvent(new Event('input', { bubbles: true })); }
    await new Promise(r => setTimeout(r, 150));
    const buttons = [...box.querySelectorAll('button')]; buttons[buttons.length - 1].click();`)
  await sleep(900)
}
const controls = async (role = 'cashier') => (await api(`/finance/petty-cash/requisitions/${requisitionId}`, { token: tokens[role] })).data.controls
const state = async () => { const c = await controls(); return `${c.control_state} · disbursed ${c.disbursed} · confirmed ${c.confirmed_received} · accounted ${c.accounted} · returned ${c.returned} · released ${c.released_unused} · to account ${c.to_account}` }

try {
  // 1. Create, assigning the verifier (API, as the creator — see the report for why).
  const created = await api('/finance/petty-cash/requisitions', { token: tokens.creator, method: 'POST', body: {
    responsible_verifier_id: setup.users.verifier.id, department_id: setup.department_id, category: setup.type_name, requisition_type_id: setup.type_id,
    purpose: 'NCBA Event', items: [
      { description: 'Site facilitation', amount: '15000.00', payee_id: setup.employee_id, payee_name: 'Steve Otieno' },
      { description: 'Casual support', amount: '5000.00', payee_id: setup.employee_id, payee_name: 'Steve Otieno' },
      { description: 'Transport', amount: '30000.00', payee_name: 'Timothy Mwangi', other_recipient_reference: 'ID-22334455' },
      { description: 'Materials', amount: '25000.00', supplier_id: setup.supplier_id, payee_name: 'Winnie Supplies' },
    ] } })
  requisitionId = created.data.id
  step(`1. Created ${created.data.requisition_number} for KES ${created.data.total_amount} as ${setup.users.creator.name}; verifier assigned: ${setup.users.verifier.name}.`)

  // 2. Verify — in the browser, as the assigned verifier.
  await as('verifier')
  await js(`document.querySelector('[data-test="verify"]').click()`)
  await until(`document.querySelector('[data-test="verification-status"]')?.innerText === 'VERIFIED'`, 'verified')
  step('2. Verified in the browser by the assigned verifier.')
  await evidence('verified')

  // 3. Approve (API, as the approver).
  await api(`/finance/petty-cash/requisitions/${requisitionId}/approve`, { token: tokens.approver, method: 'POST', body: {} })
  step(`3. Approved as ${setup.users.approver.name}. ${await state()}`)

  // 4. Pay three receivers — in the browser, as the cashier. Timothy in two instalments from two accounts.
  await as('cashier')
  const pay = async (name, amount, sourceIndex, reference) => {
    await clickIn(name, 'process-payment')
    await fill('#receiver-payment-amount', amount)
    await fill('#receiver-payment-source', setup.sources[sourceIndex].id)
    await fill('#receiver-payment-expense', setup.expense_code_id)
    await fill('#receiver-payment-reference', reference)
    if (name === 'Timothy' && amount === '20000') await evidence('payment-dialog')
    await submit('payment-dialog')
    step(`4. Paid ${name} KES ${amount} from ${setup.sources[sourceIndex].name}.`)
  }
  await pay('Steve', '20000', 0, 'TRX-STEVE-1')
  await pay('Timothy', '20000', 0, 'TRX-TIM-1')
  await pay('Timothy', '10000', 1, 'TRX-TIM-2')
  await pay('Winnie', '15000', 0, 'TRX-WIN-1')
  step(`   ${await state()}`)
  await evidence('paid-awaiting-confirmation')

  // 5. Confirm separately. Steve for himself; the requester for the two who do not sign in.
  await as('steve')
  await clickIn('Steve', 'confirm-receipt')
  await evidence('steve-confirms-own-receipt')
  await submit('confirm-dialog')
  step('5. Steve confirmed his own receipt, signed in as himself.')
  await as('creator')
  for (const [name, code] of [['Timothy', 'MPESA-TIM-778'], ['Winnie', 'BANK-ADV-4410']]) {
    await clickIn(name, 'confirm-receipt')
    await fill('[data-test="evidence-reference"]', code)
    if (name === 'Timothy') await evidence('requester-confirms-on-behalf')
    await submit('confirm-dialog')
    step(`5. ${setup.users.creator.name} confirmed for ${name} on their behalf, evidence ${code}.`)
  }
  step(`   ${await state()}`)

  // 6. Account separately.
  const spend = async (index, purpose, description, amount) => {
    const row = `document.querySelectorAll('[data-test="spend-row"]')[${index}]`
    if (index > 0) { await js(`document.querySelector('[data-test="add-spend"]').click()`); await sleep(200) }
    await js(`const select = ${row}.querySelector('select'); const option = [...select.options].find(o => o.textContent.trim() === ${JSON.stringify(purpose)});
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, option.value); select.dispatchEvent(new Event('change', { bubbles: true }));`)
    await fill(`[aria-label="Description for receipt ${index + 1}"]`, description)
    await fill(`[aria-label="Amount for receipt ${index + 1}"]`, amount)
    await fill(`[aria-label="Expense type for receipt ${index + 1}"]`, setup.expense_code_id)
  }
  const giveBack = async (purpose, amount, paymentLabel) => {
    await js(`document.querySelector('[data-test="add-return"]').click()`); await sleep(200)
    await js(`const select = document.querySelector('[aria-label="Requisition line for return 1"]'); const option = [...select.options].find(o => o.textContent.trim() === ${JSON.stringify(purpose)});
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, option.value); select.dispatchEvent(new Event('change', { bubbles: true }));`)
    await fill('[aria-label="Amount for return 1"]', amount)
    if (paymentLabel) {
      await until(`document.querySelector('[data-test="return-payment"]')`, 'payment choice for the return')
      await js(`const select = document.querySelector('[data-test="return-payment"]'); const option = [...select.options].find(o => o.textContent.includes(${JSON.stringify(paymentLabel)}));
        Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, option.value); select.dispatchEvent(new Event('change', { bubbles: true }));`)
    }
  }
  await as('steve')
  await clickIn('Steve', 'account')
  await spend(0, 'Site facilitation', 'Hall and site set-up', '14500')
  await spend(1, 'Casual support', 'Casual labour, two days', '5000')
  await giveBack('Site facilitation', '500')
  await evidence('steve-accounts-with-return')
  await submit('accountability-dialog')
  step('6. Steve accounted for 19,500 across his two lines and returned 500.')

  await as('creator')
  await clickIn('Timothy', 'account')
  await spend(0, 'Transport', 'Vehicle hire, first leg', '18000')
  await submit('accountability-dialog')
  step('6. Timothy: first stage, 18,000 accounted.')
  await clickIn('Winnie', 'account')
  await spend(0, 'Materials', 'Materials delivered', '14000')
  await giveBack('Materials', '1000')
  await submit('accountability-dialog')
  step('6. Winnie: 14,000 accounted and 1,000 returned.')
  step(`   ${await state()}`)

  // 7. Finance reconciles each, in the browser.
  await as('cashier')
  await evidence('accountability-with-finance')
  const reconcile = async (name) => {
    await clickIn(name, 'reconcile')
    await confirmDialog()
    await until(`!${card(name)}.querySelector('[data-test="reconcile"]')`, `${name} reconciled`)
    step(`7. Reconciled ${name}'s surrender as ${setup.users.cashier.name}.`)
  }
  await reconcile('Steve'); await reconcile('Timothy'); await reconcile('Winnie')
  step(`   ${await state()}`)
  await evidence('partially-accounted')

  // 6b/7b. Timothy's second stage: 10,000 spent, 2,000 returned against the second instalment.
  await as('creator')
  await clickIn('Timothy', 'account')
  await spend(0, 'Transport', 'Vehicle hire, return leg', '10000')
  await giveBack('Transport', '2000', 'D03')
  await evidence('timothy-second-stage-return-against-payment')
  await submit('accountability-dialog')
  step('6. Timothy: second stage, 10,000 accounted and 2,000 returned against D03.')
  await as('cashier')
  await reconcile('Timothy')
  step(`   ${await state()}`)
  const blocked = await text('[data-test="closure-blockers"]')
  step(`   Cannot close yet, as the screen says: ${blocked.replace(/\n+/g, ' / ')}`)
  await evidence('unused-balance-requires-release')

  // 8. Release the approved remainder that will not be paid.
  await as('controller')
  await clickIn('Winnie', 'release-unused')
  await fill('[data-test="release-amount"]', '10000')
  await fill('[data-test="release-reason"]', 'Supplier delivered less than ordered; balance not needed')
  await evidence('release-unused-dialog')
  await submit('release-dialog')
  step(`8. ${setup.users.controller.name} released KES 10,000 of Winnie's approved allocation.`)
  step(`   ${await state()}`)

  // 9. Close.
  await as('cashier')
  await until(`document.querySelector('[data-test="close-requisition"]')`, 'close offered')
  await evidence('ready-to-close')
  await js(`document.querySelector('[data-test="close-requisition"]').click()`)
  await confirmDialog()
  await until(`document.querySelector('[data-test="closed"]')`, 'closed')
  step(`9. Closed in the browser by ${setup.users.cashier.name}.`)
  await js(`document.querySelectorAll('[aria-label="Requisition verification and receiver position"] details').forEach(node => { node.open = true })`)
  await evidence('closed')

  const final = await controls()
  const figures = Object.fromEntries(['approved', 'disbursed', 'confirmed_received', 'accounted', 'returned', 'released_unused', 'to_disburse', 'to_account', 'control_state', 'closure_status']
    .map(key => [key, final[key]]))
  const checks = {
    'approved = disbursed + released': Number(final.approved) === Number(final.disbursed) + Number(final.released_unused),
    'disbursed = accounted + returned': Number(final.disbursed) === Number(final.accounted) + Number(final.returned),
    'nothing left to account': Number(final.to_account) === 0,
    'closed': final.control_state === 'closed',
  }
  step(`Final: ${JSON.stringify(figures)}`)
  step(`Checks: ${JSON.stringify(checks)}`)
  writeFileSync(`${OUT}/result.json`, JSON.stringify({ requisition: created.data.requisition_number, figures, checks, story: final.story, steps: log }, null, 2))
  if (Object.values(checks).some(ok => !ok)) throw new Error('The end state does not reconcile.')
  step('END-TO-END PASSED')
} catch (error) {
  step(`END-TO-END FAILED: ${error.message}`)
  writeFileSync(`${OUT}/result.json`, JSON.stringify({ failed: error.message, steps: log }, null, 2))
  process.exitCode = 1
} finally {
  socket.close(); chrome.kill()
  await sleep(800)
  try { rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 }) } catch { /* left in the scratch directory */ }
}
