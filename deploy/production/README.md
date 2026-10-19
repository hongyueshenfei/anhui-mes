# 虹越智造平台 · 单台 ECS 生产部署

本包包含 PC/H5 静态站点、Laravel API、AI、MySQL、Redis、队列和单实例调度器。
使用根仓库的完整代码快照；所有命令在项目根目录执行。密钥和证书由服务器单独生成/安装，Git 中没有可直接使用的密码。

## 1. 服务商先准备

- Linux ECS（优先 x86_64），Docker Engine + Docker Compose 插件 **2.24 或以上**、Python **3.10 或以上**、Git、OpenSSL；使用有 Docker 权限的部署账号。旧 `docker-compose 1.5.2` 不支持本包。
- 两个域名，例如 `mes.company.com` 与 `h5.company.com`，A/AAAA 解析指向 ECS。PC 二维码会编译为 H5 域名，域名改变后需要重建 gateway 镜像。
- 一张同时包含这两个域名的 SAN/通配符证书与私钥（PEM）。本方案由 gateway 终止 TLS，默认直接占用 80/443；服务器已有 Nginx/负载均衡时先协调端口和证书。
- 安全组只开放 80/443 与限定来源的 SSH。**不开放 3306、6379、8001、9000**；Compose 只发布 gateway 的端口。
- 镜像仓库和 Composer/npm/PyPI 下载可达；首次构建会下载依赖。服务器 CPU 架构决定产物架构，不直接复制 Mac ARM 镜像到 x86 ECS。
- 本包使用容器 MySQL/Redis。若改用 RDS/云 Redis，需要另配环境及健康依赖，不能只改宿主机端口。

Docker 安装按服务器发行版采用[官方 Engine 安装文档](https://docs.docker.com/engine/install/)，Ubuntu 使用[官方 APT 仓库步骤](https://docs.docker.com/engine/install/ubuntu/)。安装后确认：

```sh
docker version
docker compose version
python3 --version
openssl version
```

## 2. 拉取代码并固定发布版本

当前 Git 服务地址为 `http://121.196.210.154:30080/hy/anhui-core-biz.git`，部署分支是 **master**。
由服务商配置仓库只读部署凭据；凭据不写进 clone URL、脚本、镜像或聊天。HTTP 无 TLS 加密，正式部署应让 Git 管理员配置 HTTPS/SSH，或确认处于可信受控网络中。

```sh
git clone --branch master http://121.196.210.154:30080/hy/anhui-core-biz.git /opt/hongyue
cd /opt/hongyue
git rev-parse HEAD
```

发布前记录完整 Git 提交号，必要时 `git checkout <已确认提交号>`。后续构建不挂载源码，PHP/PC/H5/AI 都进入带版本号的镜像；不要复用已上线的 `RELEASE_TAG`，不要发布 `latest`。

## 3. 生成独立配置并安装证书

将下面的域名替换为真实域名：

```sh
python3 deploy/production/manage.py init \
  --pc-domain mes.company.com --mobile-domain h5.company.com
```

命令创建 `deploy/production/.env`（600），生成 APP_KEY、数据库、Redis、AI 和小程序入站鉴权密钥，拒绝覆盖已有文件。它不会读取或复制开发环境 `.env`。
`env.example` 仅说明字段，不能直接上线。初始化使用当前 Git 提交作为镜像标签；`IMAGE_PREFIX` 可设成自己的镜像命名空间。
上线后保持 COMPOSE_PROJECT_NAME 与数据库身份稳定；改项目名会选择另一组数据卷。MySQL 密码只在新卷创建用户时生效，已有数据库的密码轮换需先通过数据库账号管理同步修改，不能只改 `.env`。

由服务器管理员把证书放到：

使用实际 PEM 文件；从 Let's Encrypt 的 live 目录复制时用 `cp -L` 解引用，避免跨目录软链接在容器中失效。

```text
deploy/production/tls/fullchain.pem   # 站点证书及中间证书完整链
deploy/production/tls/privkey.pem     # 与证书匹配的私钥
```

```sh
chmod 600 deploy/production/.env deploy/production/tls/privkey.pem
python3 deploy/production/manage.py config
```

`install/up/update` 会验证证书与私钥匹配、证书有效期及两个域名。TLS 目录和备份目录已加入 Git/Docker 忽略。
配置值使用单行单引号格式 `KEY='value'`，不使用 shell 变量引用。Redis 密码保持生成的十六进制值，避免 Redis URL 与配置文件转义歧义。
生产容器固定 `APP_ENV=production`、`APP_DEBUG=false`、`APP_TEST_NOW` 为空；PC 构建固定 `VITE_DEMO_MODE=0`，使用真实 API。

## 4. 构建、首次安装、创建管理员

```sh
python3 deploy/production/manage.py build
python3 deploy/production/manage.py install
python3 deploy/production/manage.py admin --employee admin
python3 deploy/production/manage.py check
```

- `build`：使用 npm/Composer 锁文件构建前后端，安装生产 Python 依赖；宿主机不需要 PHP/Node/Python 业务虚拟环境。
- `install`：等待独立 MySQL/Redis 健康，执行 `migrate --force`，再启动其余核心容器。
- 初始化只建立角色/权限、空表的工序字典和缺失班制参数；**不运行 DatabaseSeeder、AdminSeeder、DemoBusinessSeeder，不导入示例产品/物料/订单，不重置既有账号或权限**。
- `admin`：在终端交互输入至少 12 字符的新密码，通过 stdin 交给容器；密码不进入命令行或输出。账号存在时拒绝覆盖。
- `check`：检查 Nginx 配置、MySQL/Redis、内部 AI Token、OR-Tools、两域名登录页和静态资源、API 健康及未登录 401。它不等于业务全量验收。
- 默认只启动 6 个核心容器，队列和 scheduler 尚未启用。上传文件保存在独立 `app_storage` 卷，数据库和 Redis 各自持久化。日志写入 Docker 日志并限制大小。

正式开放报工/入库等日常业务前应启用队列，保证后台联动及时处理；外部定时同步可在联调完成后再启用。

PC 地址为 `https://mes.company.com`，H5 为 `https://h5.company.com`。两站点的 `/api/*`、`/mobile/*` 都转给 Laravel，`/storage/*` 只读取公开附件；其他路径分别由各自 SPA 提供。

## 5. 确认集成后启用队列与调度

默认金蝶地址指向容器自身关闭端口，`K3_MOCK=false`；未配置小程序推送、企微、微信或 DeepSeek 密钥。系统首次启动不会自动连接真实外部业务接口。

在受控编辑器修改服务器 `deploy/production/.env`：

- 金蝶：`K3_BASE_URL`、`K3_ACCT_ID`、`K3_USERNAME`、`K3_PASSWORD`，以及核对后的仓库/单据/制单人参数。沙箱可用 `K3_MOCK=true` 配合明确的模拟端点；正式账套保持 false。
- 小程序：与对方约定 `MP_APP_KEY`、`MP_APP_SECRET`、`MP_PUSH_URL`。两端共享的 HMAC 密钥保密传递，不能继续使用源码默认 demo 密钥。
- 企微/微信：按实际授权填写 `WECOM_*`、`WX_*` 并核对 HTTPS 回调；仍可使用账号密码登录。
- DeepSeek：可选 `DEEPSEEK_API_KEY`；未填写时不启用 LLM，规则报价与 OR-Tools 不依赖它。
- `ENABLE_QUEUE='1'` 启动 Redis worker。worker timeout=120 秒，retry_after=180 秒；停止宽限 150 秒，给正在执行的任务留出退出时间。
- 完成沙箱/正式接口确认后设 `EXTERNAL_INTEGRATIONS_CONFIRMED='1'`、`ENABLE_SCHEDULER='1'`，只运行一个 scheduler。勿再额外添加宿主机 cron，勿扩容 scheduler。

```sh
python3 deploy/production/manage.py up
python3 deploy/production/manage.py artisan schedule:list
python3 deploy/production/manage.py artisan queue:failed
```

调度会每 10 分钟拉金蝶订单、每日 03:00 同步物料、每 30 分钟回写小程序。启用前须完成接口对账。把 enable 标志改回 0 后执行 `up` 会停止对应的既有后台容器。

## 6. 更新与回滚

先在发布环境完成当前版本的自动化/业务验收。保留旧镜像，记录原 RELEASE_TAG；更新时不重建数据卷。

```sh
git fetch
git checkout <新版本完整提交号>
# 在服务器 .env 中把 RELEASE_TAG 改成新版本唯一标签；保留所有密钥。
python3 deploy/production/manage.py build
python3 deploy/production/manage.py update
python3 deploy/production/manage.py check
```

`update` 进入维护模式、停止后台任务，先备份旧数据库/附件及运行镜像信息，再用新镜像迁移并更新容器。成功后按此前状态退出维护；失败保持维护和后台停止，便于处理。

若迁移兼容旧应用：把 RELEASE_TAG 改回旧标签，运行 `up`、`artisan up`、`check`。不要对旧标签重新执行 build。
`up` 要求对应镜像已存在，不会按新源码重建缺失的旧版本标签；旧镜像缺失时先从已归档的镜像库恢复。
若数据库已不兼容：先选择对应旧镜像和原 APP_KEY，再按下一节恢复更新前备份。不要盲目执行 `migrate:rollback` 或 `migrate:fresh`。

## 7. 备份、恢复、证书续期

```sh
python3 deploy/production/manage.py backup
```

备份期间进入维护、停止后台任务并以 SIGQUIT 等待在途 PHP 请求退出，再保存数据库与附件，完成后恢复原状态；期间 API 暂不可用，请安排维护窗口。备份目录含数据库 gzip、上传文件 tar、运行镜像清单、校验和、**包含 APP_KEY 的 config.env** 和 COMPLETE 标记。
备份目录 700、密钥/数据文件 600；该目录须加密复制到异机/对象存储。只存同一 ECS 磁盘不能防磁盘损坏，APP_KEY 丢失可能使既有加密数据不可恢复。
部署、更新、备份和恢复命令使用同一配置文件旁的操作锁，避免同时执行；勿用其他配置文件绕过同一项目的锁。
本包不替服务商开通云备份或监控，服务商应实际执行异机备份和恢复演练，并按业务量安排维护窗口。

恢复命令会验证完整标记/校验和/APP_KEY/附件路径，先备份当前数据，然后替换数据库与上传文件并清理默认待执行队列；须显式指定确认参数：

```sh
python3 deploy/production/manage.py restore /安全目录/备份时间 --confirm-replace
python3 deploy/production/manage.py check
```

恢复不恢复 Redis 登录会话，并会清理默认队列和专用 Redis 第 2 库的 AI 临时 job、重启 AI，避免旧任务引用已回退的数据；需对照集成日志确认是否重新发起失败推送。恢复失败保持 API/后台停止，不自动对外提供部分恢复的数据。
恢复到新服务器时，先使用备份原 APP_KEY 与原数据库密码准备环境，启动核心容器/迁移后再恢复，并采用与备份相容的应用镜像。

证书续期替换 TLS_DIR 下文件后：

```sh
python3 deploy/production/manage.py compose exec -T gateway nginx -t
python3 deploy/production/manage.py compose exec -T gateway nginx -s reload
```

监控至少覆盖公网 HTTPS/API、各服务健康状态、磁盘/数据卷空间、备份时间、证书到期和队列失败数。服务崩溃会按 restart 策略重启；仅变为 unhealthy 不会自动重启，需接服务商告警处理。

## 8. 本地验证与排查

```sh
python3 -m unittest discover -s deploy/production/tests -v
python3 deploy/production/smoke.py
```

smoke 使用新项目名、临时密钥、自签证书、回环随机端口和全新数据卷；检查安装、PC/H5/API、管理员登录、持久化和备份恢复，完成后只删除自己的隔离容器/卷。公网验收使用可信证书，不使用 `--insecure`。

```sh
python3 deploy/production/manage.py status
python3 deploy/production/manage.py compose logs --tail 100 api php-fpm ai-service
```

`compose config` 会展开密钥；需要语法检查请用 `manage.py config`，不要把完整配置或日志中的业务数据贴到聊天。
禁止对有数据的环境使用 `down -v`。重启/升级不能重新运行 `init` 或生成新的 APP_KEY。

参考：[Laravel 12 部署](https://laravel.com/docs/12.x/deployment)、[队列超时与重试](https://laravel.com/docs/12.x/queues)、[Compose 健康依赖](https://docs.docker.com/compose/how-tos/startup-order/)。
