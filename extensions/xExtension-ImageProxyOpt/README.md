# xExtension-ImageProxyOpt

[FreshRSS](https://freshrss.org) 扩展。把条目里的图片地址改写成一个代理地址，解决「FreshRSS 部署在境外、浏览器在墙内 → 图片加载不出来」的问题。

基于 [`FreshRSS/Extensions` 的 xExtension-ImageProxy](https://github.com/FreshRSS/Extensions/tree/master/xExtension-ImageProxy)（Frans de Jonge, MIT）重写。钩子、配置项语义、`data-*-original-*` 兼容逻辑均沿用上游。

## 与上游的差异

| | 上游 ImageProxy | 本扩展 |
|---|---|---|
| 解析方式 | `DOMDocument::loadHTML` + `saveHTML($body)` | 定向正则，只匹配 `<img>`/`<video>` 开标签 |
| 编码 | 依赖 `mb_encode_numericentity` 兜住 UTF-8 | 不重新序列化文档，编码天然无损 |
| `srcset` | 不处理 | 处理 |
| 图源过滤 | 无 | 白名单 / 黑名单 / 关闭 |
| 预热 | 无 | 可选，每篇一次批量 POST |
| 幂等 | 二次改写会套娃 | 已代理的地址不再套娃 |

### 实测（PHP 8.5，10 张图 / ~3KB content，300 次平均）

```
纯解析/序列化（不改写 URL）    DOM 0.044 ms   正则 0.002 ms   ← 22×
含 30 个 URL 的完整改写        DOM 0.106 ms   正则 0.091 ms   ← 只快 14%
mode=allow 且名单不命中                       0.050 ms        ← 过滤再省 38%
```

**不要指望这个扩展靠"变快"解决问题。** 解析确实快了一个数量级，但整条链路的耗时大头是每个 URL 的 `parse_url` + `rawurlencode` + 配置读取，不是 HTML 解析。一页 20 篇从 2.1 ms 降到 1.0 ms，绝对值可以忽略。

真正的收益是另外三件事：

1. **正确性** —— 不重新序列化，中文、emoji、`&amp;`、属性引号、`data-*` 全部原样保留。
2. **`srcset`** —— 上游漏掉它，高分屏设备仍然会去墙外拉图。
3. **图源过滤** —— 只代理真正取不到的图源，直接砍掉代理请求数（和跨境 RTT）。

## 安装

```sh
cp -r xExtension-ImageProxyOpt /path/to/FreshRSS/extensions/
```

然后 设置 → 扩展 → 启用 **Image Proxy Opt**，再点它的齿轮图标配置。

> 与上游 `xExtension-ImageProxy` **不要同时启用**（两者都会改写图片地址）。本扩展对已代理的地址会跳过，所以同时启用不会坏，但没意义。

## 配置项

| 键 | 说明 |
|---|---|
| Proxy URL | 代理地址前缀，原图地址原样接在后面。默认 `https://img.example.com/?url=` |
| 图源过滤模式 | `关闭`（等价上游）/ `白名单`（只代理名单内）/ `黑名单`（除名单外都代理） |
| 图源域名名单 | 一行一个，支持 `*.ytimg.com` 通配，`#` 开头是注释 |
| 代理 HTTP / HTTPS | 默认都开。上游默认关 HTTPS —— 对墙内场景是坑 |
| 代理协议相对地址 | `auto` / `http` / `https` / `-`（不代理） |
| 地址中包含 `http*://` | 关掉后生成 `?url=pbs.twimg.com%2Fx.jpg`（nginx 版代理需要） |
| 对地址进行编码 | 默认开。关掉后是裸地址（部分 nginx 配置需要） |
| 抓取时预热代理缓存 | 见下 |
| 预热接口地址 / 预热令牌 | 见下 |

### 默认生成的地址

```
https://img.example.com/?url=https%3A%2F%2Fpbs.twimg.com%2Fmedia%2Fabc.jpg
```

这个形态同时被 Cloudflare Worker、`wsrv.nl`、`images.weserv.nl` 接受。

## 预热（可选）

开启后，`entry_before_insert` 会对每篇新文章发**一次**批量 POST：

```http
POST https://img.example.com/
Content-Type: application/json

{"access_token": "...", "urls": ["https://pbs.twimg.com/a.jpg", "..."]}
```

对比上游生态里的 ImageCache 插件：它是**每张图一次**同步 curl（connect 5s / total 10s），一篇 15 图的文章跨境就是 1.5~3 秒的抓取阻塞。批量版是每篇一次，且超时 2s/4s。

`worker.js.example` 里有配套的 Worker 实现（含 `caches.default` 写入和 `ALLOWED_HOSTS` 白名单）。

## Worker 部署要点

1. **必须绑自有域名**。`*.workers.dev` 在国内经常连不上，那就白做了。
2. 响应头带 `Cache-Control: public, max-age=604800, immutable`，Cloudflare 边缘会缓存，回源次数 ≈ 图片数而不是访问次数。
3. `ALLOWED_HOSTS` 别留空 —— 开放的图片代理几天内就会被刷爆。
4. 建议在 Zone 里加一条 Cache Rule：`URI Path starts with /` 时 `Edge Cache TTL = 1 month`，把边缘命中率拉满。

## 已知限制

- 只处理 `<img src|srcset>` 和 `<video poster>`。CSS 里的 `background-image`、`<iframe>`、`<source>` 不管。
- 属性值里含 `>` 的畸形标签会被截断（上游用 DOM 时也有等价问题）。
- 相对路径不代理。
- 不改写存储的内容，只在渲染时改写；换代理地址后老文章立即生效。
