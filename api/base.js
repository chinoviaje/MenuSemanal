// Menú de la Casa: guarda la base en Upstash Redis (Vercel Marketplace). Sin dependencias.
// GET  /api/base?ping=1            -> {"app":"menu-de-la-casa"}   (sin clave)
// GET  /api/base                   -> {"rev":N,"savedAt":T,"state":{...}}   (header X-Menu-Key)
// GET  /api/base?since=N           -> {"rev":N,"same":true} si no cambió
// PUT  /api/base {state, baseRev}  -> {"rev":N+1} | 409 con la versión actual si otro dispositivo guardó antes
const crypto = require("crypto");

const URL_ = process.env.UPSTASH_REDIS_REST_URL || process.env.KV_REST_API_URL;
const TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN || process.env.KV_REST_API_TOKEN;
const CLAVE = process.env.MENU_CLAVE || "";
const K_REV = "menu:rev", K_DOC = "menu:base", COPIAS_DIAS = 30;

async function redis(cmd){
  const r = await fetch(URL_, {method: "POST", headers: {Authorization: "Bearer " + TOKEN, "Content-Type": "application/json"}, body: JSON.stringify(cmd)});
  const j = await r.json().catch(() => ({}));
  if (!r.ok || j.error) throw new Error(j.error || "Redis " + r.status);
  return j.result;
}
// Guarda solo si nadie guardó antes (rev igual), en un paso atómico. Además deja una copia por día.
const SAVE = `
local rev = tonumber(redis.call('GET', KEYS[1]) or '0')
if ARGV[1] ~= '' and tonumber(ARGV[1]) ~= rev then return {0, rev} end
local cur = redis.call('GET', KEYS[2])
if cur then redis.call('SET', KEYS[3], cur, 'NX', 'EX', tonumber(ARGV[4])) end
local nrev = rev + 1
redis.call('SET', KEYS[2], '{"rev":' .. nrev .. ',"savedAt":' .. ARGV[3] .. ',"state":' .. ARGV[2] .. '}')
redis.call('SET', KEYS[1], nrev)
return {1, nrev}`;

const same = (a, b) => { const x = crypto.createHash("sha256").update(String(a)).digest(), y = crypto.createHash("sha256").update(String(b)).digest(); return crypto.timingSafeEqual(x, y); };
const send = (res, code, data) => { res.statusCode = code; res.setHeader("Content-Type", "application/json; charset=utf-8"); res.setHeader("Cache-Control", "no-store"); res.end(typeof data === "string" ? data : JSON.stringify(data)); };

module.exports = async (req, res) => {
  const q = req.query || Object.fromEntries(new URL(req.url, "http://x").searchParams);
  if (req.method === "GET" && q.ping) return send(res, 200, {app: "menu-de-la-casa", ok: true});
  if (!URL_ || !TOKEN) return send(res, 500, {error: "Falta conectar la base Redis (Storage → Upstash) y volver a publicar"});
  if (!CLAVE) return send(res, 500, {error: "Falta la variable MENU_CLAVE en Vercel"});
  if (!same(req.headers["x-menu-key"] || "", CLAVE)) { await new Promise(r => setTimeout(r, 500)); return send(res, 401, {error: "Clave incorrecta"}); }
  try {
    if (req.method === "GET") {
      if (q.since !== undefined) { const rev = Number(await redis(["GET", K_REV]) || 0); if (rev === Number(q.since)) return send(res, 200, {rev, same: true}); }
      const doc = await redis(["GET", K_DOC]);
      return send(res, 200, doc || {rev: 0, state: null});
    }
    if (req.method === "PUT" || req.method === "POST") {
      let body = req.body;
      if (typeof body === "string") body = JSON.parse(body);
      if (!body || typeof body.state !== "object" || body.state === null || Array.isArray(body.state)) return send(res, 400, {error: "Formato inválido"});
      const st = JSON.stringify(body.state);
      const base = body.baseRev === null || body.baseRev === undefined ? "" : String(Number(body.baseRev));
      const day = new Date().toISOString().slice(0, 10);
      const now = Math.floor(Date.now() / 1000);
      const [ok, rev] = await redis(["EVAL", SAVE, "3", K_REV, K_DOC, "menu:copia:" + day, base, st, String(now), String(COPIAS_DIAS * 86400)]);
      if (!ok) return send(res, 409, await redis(["GET", K_DOC]));
      return send(res, 200, {rev, savedAt: now});
    }
    return send(res, 405, {error: "Método no permitido"});
  } catch (e) {
    return send(res, 500, {error: "No pude usar la base: " + e.message});
  }
};
