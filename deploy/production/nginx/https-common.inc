ssl_certificate /etc/nginx/tls/fullchain.pem;
ssl_certificate_key /etc/nginx/tls/privkey.pem;
ssl_protocols TLSv1.2 TLSv1.3;
ssl_session_cache shared:TLS:10m;
ssl_session_timeout 1d;
server_tokens off;
charset utf-8;
client_max_body_size 25m;
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
location = /api { include /etc/nginx/snippets/proxy.inc; }
location /api/ { include /etc/nginx/snippets/proxy.inc; }
location = /mobile { include /etc/nginx/snippets/proxy.inc; }
location /mobile/ { include /etc/nginx/snippets/proxy.inc; }
location /storage/ { include /etc/nginx/snippets/proxy.inc; }
location /assets/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, immutable";
}
location = /index.html { add_header Cache-Control no-store; }
location ~ /\. { deny all; }
location ~ \.php$ { return 404; }
