/**
 * 虹越智造平台 · 演示版静态托管服务（零依赖）
 * 目的：history 路由的 SPA 需要 fallback 到 index.html，否则直接访问/刷新子路由会 404。
 * 约束：仅监听 $PORT、绑定 0.0.0.0；不引入任何第三方依赖。
 */
const http = require('node:http')
const fs = require('node:fs')
const path = require('node:path')

const ROOT = __dirname
const PORT = Number(process.env.PORT || 3000)
const HOST = '0.0.0.0'

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.map': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
}

function sendFile(res, filePath) {
  const ext = path.extname(filePath).toLowerCase()
  const type = MIME[ext] || 'application/octet-stream'
  // 带 hash 的构建产物可长缓存；index.html 必须每次校验，避免演示时拿到旧壳
  const cache = ext === '.html' ? 'no-cache' : 'public, max-age=31536000, immutable'
  fs.readFile(filePath, (err, buf) => {
    if (err) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' })
      res.end('Internal Server Error')
      return
    }
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': cache })
    res.end(buf)
  })
}

const server = http.createServer((req, res) => {
  let pathname = '/'
  try {
    pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname)
  } catch {
    pathname = '/'
  }

  // 防路径穿越：解析后必须仍在 ROOT 内
  const target = path.normalize(path.join(ROOT, pathname))
  if (target !== ROOT && !target.startsWith(ROOT + path.sep)) {
    res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' })
    res.end('Forbidden')
    return
  }

  fs.stat(target, (err, stat) => {
    if (!err && stat.isFile()) {
      sendFile(res, target)
      return
    }
    // 形似静态资源（带扩展名）却不存在时返回真 404，不能把 HTML 当 JS 吐回去
    if (path.extname(pathname) !== '') {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
      res.end('Not Found')
      return
    }
    // 其余路径交给前端路由（history fallback）
    sendFile(res, path.join(ROOT, 'index.html'))
  })
})

server.listen(PORT, HOST, () => {
  console.log(`[hongyue-demo] serving ${ROOT} on http://${HOST}:${PORT}`)
})
