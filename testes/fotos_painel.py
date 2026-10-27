"""Tira fotos do painel (servidor simulado) e procura falhas de layout.
Uso: python3 -I fotos.py SAIDA PLANO.json   — plano: [{"nome","rota","cenario","js":[...], "telas":["desk","cel"], "temas":["light","dark"]}]"""
import asyncio, base64, json, os, subprocess, sys, tempfile, time, urllib.request
import websockets

SAIDA, PLANO = sys.argv[1], json.load(open(sys.argv[2]))
BASE = os.environ.get('BASE', 'http://127.0.0.1:8099/LabFreeImageDeploy/')
TELAS = {'desk': (1366, 900, False), 'cel': (390, 844, True), 'tab': (820, 1100, True)}

# procura problemas de layout na página atual
VERIFICAR = r"""(() => {
  const W = document.documentElement.clientWidth, out = [];
  const nome = (e) => e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).join('.') : '') + ' «' + (e.innerText || '').trim().slice(0, 40).replace(/\s+/g, ' ') + '»';
  if (document.documentElement.scrollWidth > W + 1) out.push('ROLAGEM HORIZONTAL da página: ' + document.documentElement.scrollWidth + ' > ' + W);
  for (const e of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(e);
    if (cs.display === 'none' || cs.visibility === 'hidden' || e.closest('[hidden]')) continue;
    const r = e.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    if (e.closest('.menu,.abas,pre,.progresso')) continue;
    if (r.right > W + 1 && cs.position !== 'fixed') out.push('SAI DA TELA (' + Math.round(r.right) + 'px): ' + nome(e));
    // texto cortado sem reticências
    if ((cs.overflowX === 'hidden' || cs.overflow === 'hidden') && e.scrollWidth > e.clientWidth + 2 && cs.textOverflow !== 'ellipsis' && e.children.length === 0)
      out.push('TEXTO CORTADO: ' + nome(e));
    if (cs.textOverflow === 'ellipsis' && e.scrollWidth > e.clientWidth + 2) out.push('RETICÊNCIAS: ' + nome(e));
    // filhos que vazam do pai
    const p = e.parentElement;
    if (p && !['BODY', 'HTML'].includes(p.tagName)) {
      const pr = p.getBoundingClientRect(), pcs = getComputedStyle(p);
      if (r.right > pr.right + 2 && pcs.overflowX === 'visible' && !['absolute', 'fixed'].includes(cs.position) && !e.closest('.heroi'))
        out.push('VAZA DO PAI (+' + Math.round(r.right - pr.right) + 'px): ' + nome(e) + ' dentro de ' + nome(p));
    }
    // alvo de toque pequeno no celular
    if (W < 500 && (e.tagName === 'BUTTON' || (e.tagName === 'A' && cs.display !== 'inline')) && (r.height < 32 || r.width < 32)) out.push('ALVO PEQUENO ' + Math.round(r.width) + 'x' + Math.round(r.height) + ': ' + nome(e));
  }
  if (/\bnull\b|undefined|NaN|\[object/.test(document.body.innerText)) out.push('TEXTO SUSPEITO: ' + (document.body.innerText.match(/.{0,30}(\bnull\b|undefined|NaN|\[object).{0,30}/) || [''])[0]);
  return [...new Set(out)].slice(0, 25);
})()"""


async def main():
    os.makedirs(SAIDA, exist_ok=True)
    perfil = tempfile.mkdtemp(prefix='cdp-', dir=os.path.dirname(os.path.abspath(SAIDA)))
    proc = subprocess.Popen(['google-chrome', '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--remote-debugging-port=9334',
                             f'--user-data-dir={perfil}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(60):
            try:
                pag = [a for a in json.load(urllib.request.urlopen('http://127.0.0.1:9334/json')) if a['type'] == 'page'][0]
                break
            except Exception:
                time.sleep(0.2)
        async with websockets.connect(pag['webSocketDebuggerUrl'], max_size=80_000_000) as ws:
            seq = 0
            erros = []

            def trata(m):
                met = m.get('method')
                if met == 'Runtime.exceptionThrown':
                    d = m['params']['exceptionDetails']
                    erros.append('EXCEÇÃO: ' + d.get('exception', {}).get('description', d.get('text', ''))[:300])
                elif met == 'Runtime.consoleAPICalled' and m['params']['type'] in ('error', 'warning'):
                    erros.append('CONSOLE: ' + ' '.join(str(a.get('value', a.get('description', ''))) for a in m['params']['args'])[:300])
                elif met == 'Log.entryAdded' and m['params']['entry']['level'] in ('error',):
                    erros.append('LOG: ' + m['params']['entry']['text'][:200] + ' ' + m['params']['entry'].get('url', ''))

            async def cmd(metodo, **params):
                nonlocal seq
                seq += 1
                meu = seq
                await ws.send(json.dumps({'id': meu, 'method': metodo, 'params': params}))
                while True:
                    m = json.loads(await ws.recv())
                    if m.get('id') == meu:
                        return m.get('result', {})
                    trata(m)

            async def esperar(seg):
                fim = time.time() + seg
                while time.time() < fim:
                    try:
                        trata(json.loads(await asyncio.wait_for(ws.recv(), timeout=max(0.05, fim - time.time()))))
                    except asyncio.TimeoutError:
                        break

            for x in ('Runtime.enable', 'Log.enable', 'Page.enable', 'Network.enable'):
                await cmd(x)
            for item in PLANO:
                for tela in item.get('telas', ['desk', 'cel']):
                    w, h, mob = TELAS[tela]
                    await cmd('Emulation.setDeviceMetricsOverride', width=w, height=h, deviceScaleFactor=1, mobile=mob)
                    for tema in item.get('temas', ['light', 'dark'] if tela == 'desk' else ['light']):
                        await cmd('Emulation.setEmulatedMedia', features=[{'name': 'prefers-color-scheme', 'value': tema}])
                        await cmd('Network.setCookie', name='cenario', value=item.get('cenario', 'cheio'), domain='127.0.0.1', path='/')
                        antes = len(erros)
                        await cmd('Page.navigate', url='about:blank')
                        await cmd('Page.navigate', url=f"{BASE}#/{item['rota']}")
                        await esperar(1.6)
                        for passo in item.get('js', []):
                            res = await cmd('Runtime.evaluate', expression=passo, returnByValue=True, awaitPromise=True)
                            if 'exceptionDetails' in res:
                                erros.append('PASSO FALHOU: ' + passo[:100] + ' :: ' + str(res['exceptionDetails'].get('exception', {}).get('description', ''))[:200])
                            await esperar(0.9)
                        probs = (await cmd('Runtime.evaluate', expression=VERIFICAR, returnByValue=True))['result'].get('value', [])
                        alt = (await cmd('Runtime.evaluate', expression='Math.max(document.documentElement.scrollHeight, (document.querySelector(".fundo-modal")||{}).scrollHeight||0)', returnByValue=True))['result']['value']
                        modal = (await cmd('Runtime.evaluate', expression='!!document.querySelector(".fundo-modal")', returnByValue=True))['result']['value']
                        altura = h if modal else min(alt, 3200)
                        shot = await cmd('Page.captureScreenshot', format='png', captureBeyondViewport=not modal, clip={'x': 0, 'y': 0, 'width': w, 'height': altura, 'scale': 1})
                        arq = f"{SAIDA}/{item['nome']}-{tela}-{tema}.png"
                        open(arq, 'wb').write(base64.b64decode(shot['data']))
                        novos = erros[antes:]
                        print(f"== {item['nome']} [{tela}/{tema}] {os.path.basename(arq)} altura={alt}")
                        for p in probs + novos:
                            print('   ', p)
    finally:
        proc.terminate()
        proc.wait(timeout=10)
        subprocess.run(['rm', '-rf', '--', perfil])


asyncio.run(main())
