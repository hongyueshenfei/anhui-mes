#!/bin/sh
set -eu
php -r '
if (getenv("APP_ENV") !== "production" || getenv("APP_DEBUG") !== "false" || getenv("APP_TEST_NOW")) {
    fwrite(STDERR, "拒绝使用非生产配置或冻结测试时钟启动。\n"); exit(1);
}
foreach (["APP_KEY", "DB_PASSWORD", "REDIS_PASSWORD", "AI_SERVICE_TOKEN"] as $name) {
    if (!getenv($name)) { fwrite(STDERR, "缺少必要的生产密钥：".$name."\n"); exit(1); }
}
'
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan package:discover --no-interaction >/dev/null
php artisan config:cache --no-interaction >/dev/null
exec "$@"
