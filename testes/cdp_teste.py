"""Abre o painel no Chrome headless, percorre as telas, coleta erros e tira screenshots."""
import asyncio, base64, json, os, subprocess, sys, tempfile, time, urllib.request
import websockets

SAIDA = os.path.dirname(os.path.abspath(__file__)) + "/shots"
SID = sys.argv[1]
ROTAS = sys.argv[2].split(";;") if len(sys.argv) > 2 else ["inicio"]
TEMAS_DESK = os.environ.get("TEMAS", "light,dark").split(",")
LARGURAS = [tuple(x.split(":")[:2]) + (x.split(":")[2],) for x in os.environ.get("TELAS", "1366:900:desk,390:844:cel").split(",")]
LARGURAS = [(int(a), int(b), c) for a, b, c in LARGURAS]
BASE = os.environ.get("BASE", "https://192.168.31.1/LabFreeImageDeploy/")
DOMINIO = BASE.split("/")[2].split(":")[0]


async def main():
    os.makedirs(SAIDA, exist_ok=True)
    perfil = tempfile.mkdtemp(prefix="cdp-", dir=os.path.dirname(SAIDA))
    proc = subprocess.Popen(["google-chrome", "--headless=new", "--no-sandbox", "--disable-gpu",
                             "--ignore-certificate-errors", "--remote-debugging-port=9333",
                             f"--user-data-dir={perfil}", "about:blank"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                alvos = json.load(urllib.request.urlopen("http://127.0.0.1:9333/json"))
                pag = [a for a in alvos if a["type"] == "page"][0]
                break
            except Exception:
                time.sleep(0.2)
        async with websockets.connect(pag["webSocketDebuggerUrl"], max_size=50_000_000) as ws:
            seq = 0
            erros = []

            async def cmd(metodo, **params):
                nonlocal seq
                seq += 1
                meu = seq
                await ws.send(json.dumps({"id": meu, "method": metodo, "params": params}))
                while True:
                    m = json.loads(await ws.recv())
                    if m.get("id") == meu:
                        return m.get("result", {})
                    trata(m)

            pendentes = []

            def trata(m):
                met = m.get("method")
                if met == "Fetch.requestPaused":
                    pendentes.append(m["params"])
                    return
                if met == "Runtime.exceptionThrown":
                    d = m["params"]["exceptionDetails"]
                    erros.append("EXCEÇÃO: " + d.get("exception", {}).get("description", d.get("text", "")))
                elif met == "Runtime.consoleAPICalled" and m["params"]["type"] in ("error", "warning"):
                    erros.append("CONSOLE: " + " ".join(str(a.get("value", a.get("description", ""))) for a in m["params"]["args"]))
                elif met == "Log.entryAdded" and m["params"]["entry"]["level"] in ("error", "warning"):
                    erros.append("LOG: " + m["params"]["entry"]["text"] + " " + m["params"]["entry"].get("url", ""))

            async def esperar(seg):
                fim = time.time() + seg
                while time.time() < fim:
                    while pendentes:
                        pr = pendentes.pop(0)
                        chave = pr["request"]["url"].split("a=")[-1]
                        corpo = open(mocks[chave], "rb").read()
                        await ws.send(json.dumps({"id": 999999, "method": "Fetch.fulfillRequest", "params": {"requestId": pr["requestId"], "responseCode": 200,
                            "responseHeaders": [{"name": "Content-Type", "value": "application/json"}], "body": base64.b64encode(corpo).decode()}}))
                    try:
                        trata(json.loads(await asyncio.wait_for(ws.recv(), timeout=max(0.05, fim - time.time()))))
                    except asyncio.TimeoutError:
                        break

            await cmd("Runtime.enable")
            await cmd("Log.enable")
            await cmd("Network.enable")
            await cmd("Page.enable")
            mocks = dict(x.split("=", 1) for x in os.environ.get("MOCKS", "").split(",") if "=" in x)
            if mocks:
                await cmd("Fetch.enable", patterns=[{"urlPattern": f"*api.php?a={k}"} for k in mocks])
            if SID != "-":
                await cmd("Network.setCookie", name="PHPSESSID", value=SID, domain=DOMINIO, path="/", secure=True, httpOnly=True)
            for largura, altura, rot in LARGURAS:
                await cmd("Emulation.setDeviceMetricsOverride", width=largura, height=altura, deviceScaleFactor=1, mobile=(rot == "cel"))
                for tema in TEMAS_DESK if rot == "desk" else ("light",):
                    await cmd("Emulation.setEmulatedMedia", features=[{"name": "prefers-color-scheme", "value": tema}])
                    for r in ROTAS:
                        r, _, js = r.partition("|")
                        await cmd("Page.navigate", url=f"{BASE}#/{r}")
                        await esperar(2.5)
                        for passo in [x for x in js.split("@@") if x]:
                            res = await cmd("Runtime.evaluate", expression=passo, returnByValue=True)
                            if "exceptionDetails" in res:
                                erros.append("PASSO FALHOU: " + passo[:80])
                            await esperar(1.2)
                        r = r + ("-" + str(abs(hash(js)) % 1000) if js else "")
                        texto = (await cmd("Runtime.evaluate", expression="document.querySelector('#conteudo,#app').innerText.slice(0,300)", returnByValue=True))["result"].get("value", "")
                        alt = (await cmd("Runtime.evaluate", expression="document.documentElement.scrollHeight", returnByValue=True))["result"]["value"]
                        larg = (await cmd("Runtime.evaluate", expression="document.documentElement.scrollWidth", returnByValue=True))["result"]["value"]
                        shot = await cmd("Page.captureScreenshot", format="png", captureBeyondViewport=True,
                                         clip={"x": 0, "y": 0, "width": largura, "height": min(alt, 2600), "scale": 1})
                        nome = f"{SAIDA}/{r.replace('?', '_').replace('=', '-').replace('&', '_')}-{rot}-{tema}.png"
                        open(nome, "wb").write(base64.b64decode(shot["data"]))
                        rolagem = " ROLAGEM HORIZONTAL!" if larg > largura else ""
                        print(f"[{rot}/{tema}] {r}: {nome.split('/')[-1]}{rolagem} :: {' '.join(texto.split())[:140]}")
            await esperar(0.5)
            print("ERROS:", json.dumps(sorted(set(erros)), ensure_ascii=False, indent=1) if erros else "nenhum")
    finally:
        proc.terminate()
        proc.wait(timeout=10)
        subprocess.run(["rm", "-rf", "--", perfil])


asyncio.run(main())
