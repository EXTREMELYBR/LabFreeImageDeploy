"""Cliente de linha de comando da API do painel, para testes de ponta a ponta numa VM com o sistema instalado.
Guarda o cookie e o token da sessão em ~/.labfree-api.json.
Uso:  python3 -I api_cli.py entrar USUARIO SENHA
      python3 -I api_cli.py get ACAO [param=valor ...]
      python3 -I api_cli.py post ACAO '{"json": "..."}'
Base: variável LABFREE_URL (padrão https://127.0.0.1/LabFreeImageDeploy/api.php). Certificado próprio aceito."""
import http.cookiejar
import json
import os
import ssl
import sys
import urllib.parse
import urllib.request

URL = os.environ.get('LABFREE_URL', 'https://127.0.0.1/LabFreeImageDeploy/api.php')
ESTADO = os.path.expanduser('~/.labfree-api.json')
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE


def carregar():
    try:
        return json.load(open(ESTADO))
    except (OSError, ValueError):
        return {}


def pedido(metodo, acao, corpo=None, params=None):
    est = carregar()
    q = {'a': acao, **(params or {})}
    req = urllib.request.Request(URL + '?' + urllib.parse.urlencode(q), method=metodo,
                                 data=json.dumps(corpo or {}).encode() if metodo == 'POST' else None)
    req.add_header('Content-Type', 'application/json')
    if est.get('cookie'):
        req.add_header('Cookie', est['cookie'])
    if est.get('token'):
        req.add_header('X-Painel-Token', est['token'])
    try:
        r = urllib.request.urlopen(req, context=ctx, timeout=900)
        codigo, texto, cab = r.status, r.read().decode(), r.headers
    except urllib.error.HTTPError as e:
        codigo, texto, cab = e.code, e.read().decode(), e.headers
    for c in cab.get_all('Set-Cookie') or []:
        par = c.split(';', 1)[0]
        if par.split('=', 1)[0] == 'PHPSESSID' or 'PHPSESSID' not in (est.get('cookie') or ''):
            est['cookie'] = par
    try:
        dados = json.loads(texto)
    except ValueError:
        dados = {'bruto': texto[:500]}
    if isinstance(dados, dict) and dados.get('token'):
        est['token'] = dados['token']
    json.dump(est, open(ESTADO, 'w'))
    return codigo, dados


if __name__ == '__main__':
    op = sys.argv[1]
    if op == 'entrar':
        if os.path.exists(ESTADO):
            os.remove(ESTADO)
        c, d = pedido('POST', 'entrar', {'usuario': sys.argv[2], 'senha': sys.argv[3]})
    elif op == 'get':
        c, d = pedido('GET', sys.argv[2], params=dict(a.split('=', 1) for a in sys.argv[3:]))
    else:
        c, d = pedido('POST', sys.argv[2], json.loads(sys.argv[3]) if len(sys.argv) > 3 else {})
    print(c, json.dumps(d, ensure_ascii=False)[:4000])
    sys.exit(0 if c < 400 else 1)
