/**
 * Image proxy for xExtension-ImageProxyOpt (Cloudflare Worker).
 *
 * Contract:
 *   GET  /?url=<rawurlencoded absolute URL>   -> the image (edge-cached 7 days)
 *   POST /                                    -> pre-warm the cache, body:
 *                                                {"access_token": "...", "urls": ["...", "..."]}
 *
 * Configuration (set in wrangler.jsonc `vars`, or `wrangler secret put` for the token):
 *   ALLOWED_HOSTS       comma-separated. Exact host, or ".example.com" for a suffix match.
 *                       This list is the ONLY protection on GET, so keep it tight.
 *   IMAGE_PROXY_TOKEN   shared secret for POST. Empty/absent disables the token check,
 *                       which you should never do on a public worker.
 *
 * Then in the FreshRSS extension settings:
 *   proxy_url   = https://<your-worker-domain>/?url=
 *   warm_url    = https://<your-worker-domain>/
 *   warm_token  = the same string as IMAGE_PROXY_TOKEN
 */

const CACHE_TTL = 604800;			// 7 days
const MAX_BYTES = 20 * 1024 * 1024;	// stay below the Cloudflare cache limit
const MAX_WARM_URLS = 50;
const TIMEOUT_MS = 15000;

export default {
	async fetch(request, env, ctx) {
		const url = new URL(request.url);
		if (request.method === 'POST') {
			return warm(request, env, ctx);
		}
		if (request.method !== 'GET' && request.method !== 'HEAD') {
			return new Response('Method Not Allowed', { status: 405 });
		}
		const target = url.searchParams.get('url');
		if (!target) {
			return new Response('Missing ?url=', { status: 400 });
		}
		return proxy(target, request, env, ctx);
	},
};

// Parsed once per isolate; `vars` are immutable at runtime so this cannot go stale.
const hostCache = new Map();
function hostList(env) {
	const raw = String(env.ALLOWED_HOSTS || '');
	let list = hostCache.get(raw);
	if (list === undefined) {
		list = raw.split(',').map((h) => h.trim().toLowerCase()).filter((h) => h !== '');
		hostCache.set(raw, list);
	}
	return list;
}

function allowed(target, env) {
	let host;
	try {
		host = new URL(target).hostname.toLowerCase();
	} catch {
		return false;
	}
	return hostList(env).some((h) => (h.startsWith('.') ? host === h.slice(1) || host.endsWith(h) : host === h));
}

function cacheKey(request, target) {
	const u = new URL(request.url);
	u.search = '?url=' + encodeURIComponent(target);
	return new Request(u.toString(), { method: 'GET' });
}

async function fetchUpstream(target) {
	return fetch(target, {
		headers: {
			// Some CDNs 403 on unknown user agents.
			'User-Agent': 'Mozilla/5.0 (compatible; freshrss-image-proxy/1.0)',
			'Accept': 'image/avif,image/webp,image/*,*/*;q=0.8',
		},
		redirect: 'follow',
		cf: { cacheEverything: true, cacheTtl: CACHE_TTL },
		signal: AbortSignal.timeout(TIMEOUT_MS),
	});
}

function passThrough(res) {
	const out = new Response(res.body, {
		status: res.status,
		statusText: res.statusText,
		headers: res.headers,
	});
	out.headers.set('Cache-Control', 'public, max-age=' + CACHE_TTL + ', immutable');
	out.headers.set('Access-Control-Allow-Origin', '*');
	out.headers.delete('Set-Cookie');
	return out;
}

async function proxy(target, request, env, ctx) {
	if (!/^https?:\/\//i.test(target) || !allowed(target, env)) {
		return new Response('Forbidden host', { status: 403 });
	}

	const key = cacheKey(request, target);
	const cache = caches.default;
	const hit = await cache.match(key);
	if (hit) {
		const out = new Response(hit.body, hit);
		out.headers.set('X-Proxy-Cache', 'hit');
		return out;
	}

	let res;
	try {
		res = await fetchUpstream(target);
	} catch (e) {
		return new Response('Upstream fetch failed: ' + e.message, { status: 502 });
	}
	if (!res.ok) {
		return new Response('Upstream ' + res.status, { status: res.status });
	}

	const len = Number(res.headers.get('content-length') || 0);
	if (len > MAX_BYTES) {
		return new Response('Too large', { status: 502 });
	}

	const out = passThrough(res);
	out.headers.set('X-Proxy-Cache', 'miss');
	ctx.waitUntil(cache.put(key, out.clone()));
	return out;
}

async function warm(request, env, ctx) {
	let body;
	try {
		body = await request.json();
	} catch {
		return json({ error: 'invalid json' }, 400);
	}
	const token = env.IMAGE_PROXY_TOKEN || '';
	if (token === '' || body.access_token !== token) {
		return json({ error: 'invalid token' }, 403);
	}
	const urls = Array.isArray(body.urls) ? body.urls.slice(0, MAX_WARM_URLS) : [];
	const cache = caches.default;
	const results = await Promise.allSettled(urls.map(async (target) => {
		if (typeof target !== 'string' || !/^https?:\/\//i.test(target) || !allowed(target, env)) {
			throw new Error('forbidden: ' + target);
		}
		const key = cacheKey(request, target);
		if (await cache.match(key)) {
			return 'cached';
		}
		const res = await fetchUpstream(target);
		if (!res.ok) {
			throw new Error('upstream ' + res.status);
		}
		await cache.put(key, passThrough(res));
		return 'warmed';
	}));
	return json({
		warmed: results.filter((r) => r.status === 'fulfilled').length,
		failed: results.filter((r) => r.status === 'rejected').map((r) => String(r.reason)),
	});
}

function json(data, status = 200) {
	return new Response(JSON.stringify(data), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}
