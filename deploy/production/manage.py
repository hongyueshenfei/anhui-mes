#!/usr/bin/env python3
"""单台 ECS 部署入口；仅使用 Python 标准库，不在命令行传递密码。"""
from __future__ import annotations

import argparse
import base64
import contextlib
import datetime as dt
import fcntl
import getpass
import gzip
import hashlib
import http.client
import ipaddress
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import ssl
import subprocess
import sys
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
DEFAULT_ENV = HERE / '.env'
CORE = ['mysql', 'redis', 'php-fpm', 'ai-service', 'api', 'gateway']
JOBS = ['worker', 'scheduler']


def read_env(path: Path) -> dict[str, str]:
    """接受生成器使用的单行 dotenv 子集；避免 shell 执行或隐式变量替换。"""
    values = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        key, sep, value = line.partition('=')
        if not sep or not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
            raise ValueError('配置文件存在无效的变量行')
        if key in values:
            raise ValueError('配置文件存在重复变量：' + key)
        if value.startswith("'") and value.endswith("'"):
            value = value[1:-1].replace("\\'", "'")
        elif re.search(r"[\s$#\"']", value):
            raise ValueError(key + ' 请使用单引号包围整个值')
        values[key] = value
    return values


def quote_env(value: str) -> str:
    if '\n' in value or '\r' in value:
        raise ValueError('配置不支持多行值')
    return "'" + value.replace("'", "\\'") + "'"


def validate(values: dict[str, str]) -> None:
    for key in ['COMPOSE_PROJECT_NAME', 'RELEASE_TAG', 'PC_DOMAIN', 'MOBILE_DOMAIN', 'APP_KEY',
                'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'REDIS_PASSWORD', 'AI_SERVICE_TOKEN',
                'MP_APP_KEY', 'MP_APP_SECRET', 'TLS_DIR', 'BACKUP_DIR']:
        if not values.get(key) or '由初始化' in values[key] or '请替换' in values[key]:
            raise ValueError('配置未完成：' + key)
    for key in ['PC_DOMAIN', 'MOBILE_DOMAIN']:
        if not re.fullmatch(r'(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?', values[key]):
            raise ValueError(key + ' 必须是域名，不含协议、端口或路径')
    if values['PC_DOMAIN'] == values['MOBILE_DOMAIN']:
        raise ValueError('PC 和 H5 需要两个不同的域名')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]*', values['COMPOSE_PROJECT_NAME']):
        raise ValueError('COMPOSE_PROJECT_NAME 格式无效')
    if not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}', values['RELEASE_TAG']):
        raise ValueError('RELEASE_TAG 格式无效')
    if values['RELEASE_TAG'] == 'latest':
        raise ValueError('发布镜像请使用唯一版本号')
    for key in ['DB_DATABASE', 'DB_USERNAME']:
        if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,31}', values.get(key, '')):
            raise ValueError(key + ' 格式无效')
    try:
        if not values['APP_KEY'].startswith('base64:') or len(base64.b64decode(values['APP_KEY'][7:], validate=True)) != 32:
            raise ValueError()
    except ValueError:
        raise ValueError('APP_KEY 必须是生成的 32 字节 Laravel 密钥') from None
    for key in ['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'REDIS_PASSWORD', 'AI_SERVICE_TOKEN', 'MP_APP_SECRET']:
        if len(values[key]) < 32:
            raise ValueError(key + ' 至少需要 32 字符')
    # Redis 同时进入 URL 和 redis.conf，限定为无歧义的强随机十六进制密码。
    if not re.fullmatch(r'[0-9a-fA-F]{32,}', values['REDIS_PASSWORD']):
        raise ValueError('REDIS_PASSWORD 使用至少 32 位随机十六进制值')
    for key in ['HTTP_PORT', 'HTTPS_PORT']:
        if not values.get(key, '').isdigit() or not 1 <= int(values[key]) <= 65535:
            raise ValueError(key + ' 端口无效')
    if values['HTTP_PORT'] == values['HTTPS_PORT']:
        raise ValueError('HTTP 与 HTTPS 端口不能相同')
    ipaddress.ip_address(values.get('BIND_ADDRESS', '0.0.0.0'))
    for key in ['ENABLE_QUEUE', 'ENABLE_SCHEDULER', 'EXTERNAL_INTEGRATIONS_CONFIRMED']:
        if values.get(key) not in ('0', '1'):
            raise ValueError(key + ' 只能为 0 或 1')
    if values.get('K3_MOCK') not in ('true', 'false'):
        raise ValueError('K3_MOCK 只能为 true 或 false')
    if values['ENABLE_SCHEDULER'] == '1' and values['EXTERNAL_INTEGRATIONS_CONFIRMED'] != '1':
        raise ValueError('启用调度前须确认外部测试/正式接口，并设置 EXTERNAL_INTEGRATIONS_CONFIRMED=1')


def initialize(args) -> None:
    target = args.env_file.resolve()
    if target.exists():
        raise ValueError('配置文件已存在，拒绝覆盖已有密钥')
    values = read_env(HERE / 'env.example')
    revision = subprocess.check_output(['git', 'rev-parse', '--short=12', 'HEAD'], cwd=ROOT, text=True).strip()
    values.update(COMPOSE_PROJECT_NAME=args.project, PC_DOMAIN=args.pc_domain,
                  MOBILE_DOMAIN=args.mobile_domain, RELEASE_TAG=args.release or revision,
                  TLS_DIR=str(target.parent / 'tls'), BACKUP_DIR=str(target.parent / 'backups'))
    for key in ['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'REDIS_PASSWORD', 'AI_SERVICE_TOKEN', 'MP_APP_SECRET']:
        values[key] = secrets.token_hex(32)
    values['MP_APP_KEY'] = secrets.token_hex(16)
    values['APP_KEY'] = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
    validate(values)
    target.parent.mkdir(parents=True, exist_ok=True)
    with open(target, 'x', opener=lambda path, flags: os.open(path, flags, 0o600)) as handle:
        handle.write('# 生产密钥文件：只保存在服务器，不提交 Git。\n')
        for key, value in values.items():
            handle.write(f'{key}={quote_env(value)}\n')
    for name in ['TLS_DIR', 'BACKUP_DIR']:
        Path(values[name]).mkdir(mode=0o700, parents=True, exist_ok=True)
    print('已生成独立生产配置（权限 600），没有输出密钥：' + str(target))
    print('请把覆盖两个域名的证书放入 TLS_DIR/fullchain.pem 和 privkey.pem。')


class Deployment:
    def __init__(self, env_file: Path):
        self.path = env_file.resolve()
        if self.path.stat().st_mode & 0o077:
            raise ValueError('生产配置权限过宽，请先 chmod 600 配置文件')
        self.values = read_env(self.path)
        validate(self.values)
        # shell 中同名变量会优先于 --env-file；显式清除它们以保证使用指定配置。
        self.process_env = {k: v for k, v in os.environ.items()
                            if k not in self.values and not k.startswith('COMPOSE_')}
        self.base = ['docker', 'compose', '--env-file', str(self.path), '--project-name',
                     self.values['COMPOSE_PROJECT_NAME'], '-f', str(ROOT / 'compose.production.yaml')]
        for key, profile in [('ENABLE_QUEUE', 'queue'), ('ENABLE_SCHEDULER', 'scheduler')]:
            if self.values[key] == '1':
                self.base += ['--profile', profile]

    def run(self, *args, capture=False, input=None, check=True):
        return subprocess.run(self.base + list(args), cwd=ROOT, env=self.process_env,
                              check=check, input=input, text=True,
                              stdout=subprocess.PIPE if capture else None)

    def artisan(self, *args, capture=False):
        return self.run('exec', '-T', 'php-fpm', 'php', 'artisan', *args, capture=capture)

    def once(self, *args, input=None):
        return self.run('run', '--rm', '--no-deps', '-T', 'php-fpm', *args, input=input)

    def ensure_images(self):
        for name in ['app', 'api', 'ai', 'gateway']:
            image = self.values.get('IMAGE_PREFIX', 'hongyue') + '/' + name + ':' + self.values['RELEASE_TAG']
            result = subprocess.run(['docker', 'image', 'inspect', image], env=self.process_env,
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if result.returncode:
                raise ValueError('缺少发布镜像 ' + image + '；请先 build 或恢复已归档镜像')

    def tls_check(self):
        folder = Path(self.values['TLS_DIR'])
        if not folder.is_absolute():
            folder = ROOT / folder
        cert, key = folder / 'fullchain.pem', folder / 'privkey.pem'
        if not cert.is_file() or not key.is_file():
            raise ValueError('请先安装 TLS_DIR/fullchain.pem 和 privkey.pem')
        if cert.is_symlink() or key.is_symlink():
            raise ValueError('请用 cp -L 复制实际 PEM 文件，避免证书软链接在容器内失效')
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(str(cert), str(key))
        for domain in [self.values['PC_DOMAIN'], self.values['MOBILE_DOMAIN']]:
            subprocess.run(['openssl', 'x509', '-in', str(cert), '-noout', '-checkhost', domain],
                           check=True, stdout=subprocess.DEVNULL)
        subprocess.run(['openssl', 'x509', '-in', str(cert), '-noout', '-checkend', '0'],
                       check=True, stdout=subprocess.DEVNULL)

    def running(self):
        return set(self.run('ps', '--services', '--filter', 'status=running', capture=True).stdout.split())

    def up(self, include_jobs=True):
        self.tls_check()
        self.ensure_images()
        running = self.running()
        for name, flag in [('worker', 'ENABLE_QUEUE'), ('scheduler', 'ENABLE_SCHEDULER')]:
            if self.values[flag] != '1' and name in running:
                self.run('stop', name)
        services = [] if include_jobs else CORE
        self.run('up', '-d', '--no-build', '--wait', '--wait-timeout', '240', *services)

    def install(self):
        self.tls_check()
        self.ensure_images()
        self.run('up', '-d', '--wait', '--wait-timeout', '240', 'mysql', 'redis')
        self.once('php', 'artisan', 'migrate', '--force', '--no-interaction')
        self.once('php', '/opt/hongyue/ops.php', 'bootstrap')
        self.up()
        print('服务安装完成。请继续运行 admin 创建管理员，再运行 check。')

    def admin(self, employee):
        password = getpass.getpass('管理员新密码（至少 12 字符）：')
        if len(password) < 12 or password != getpass.getpass('再次输入：'):
            raise ValueError('两次密码不一致或长度不足')
        self.once('php', '/opt/hongyue/ops.php', 'admin',
                  input=json.dumps({'employee_no': employee, 'password': password}))

    def check(self, insecure=False, connect_ip=None):
        self.run('ps')
        self.run('exec', '-T', 'gateway', 'nginx', '-t')
        self.run('exec', '-T', 'api', 'nginx', '-t')
        self.run('exec', '-T', 'php-fpm', 'php', '/opt/hongyue/ops.php', 'health')
        for domain in [self.values['PC_DOMAIN'], self.values['MOBILE_DOMAIN']]:
            for path in ['/login', '/api/health', '/api/auth/me']:
                status, body = self.request(domain, path, insecure, connect_ip)
                expected = 401 if path.endswith('/me') else 200
                if status != expected:
                    raise ValueError(f'{domain}{path} 状态异常：{status}')
                if path == '/api/health' and json.loads(body).get('code') != 0:
                    raise ValueError('API 健康响应异常')
            # 随机深层路由也必须由各自 SPA 返回，且资源文件能加载。
            _, page = self.request(domain, '/login', insecure, connect_ip)
            assets = re.findall(r'(?:src|href)="(/assets/[^\"]+)"', page.decode())
            if not assets:
                raise ValueError('静态页面缺少构建资源')
            for asset in assets:
                status, _ = self.request(domain, asset, insecure, connect_ip)
                if status != 200:
                    raise ValueError('静态构建资源无法加载')
        print('PC/H5 登录页、静态资源、两域名 API 和未登录权限：通过。')

    def request(self, domain, path, insecure=False, connect_ip=None):
        # --connect-ip 仅用于隔离联调；真实部署默认使用 DNS 和证书链校验。
        context = ssl._create_unverified_context() if insecure else ssl.create_default_context()
        connection = http.client.HTTPSConnection(domain, int(self.values['HTTPS_PORT']), timeout=20, context=context)
        if connect_ip:
            import socket
            connection.connect = lambda: setattr(connection, 'sock', context.wrap_socket(
                socket.create_connection((connect_ip, int(self.values['HTTPS_PORT'])), 20), server_hostname=domain))
        connection.request('GET', path, headers={'Host': domain, 'Accept': 'application/json' if path.startswith('/api/') else 'text/html'})
        response = connection.getresponse()
        body = response.read()
        connection.close()
        return response.status, body

    def enter_maintenance(self):
        existing = self.run('exec', '-T', 'php-fpm', 'php', '-r',
                            'exit(is_file("storage/framework/down") ? 0 : 1);', check=False,
                            capture=True).returncode == 0
        jobs = self.running() & set(JOBS)
        self.artisan('down', '--retry=60')
        if jobs:
            self.run('stop', *sorted(jobs))
        # SIGQUIT 等待已有 PHP 请求退出，避免 SQL/附件快照期间仍有请求写入。
        self.run('stop', 'php-fpm')
        return existing, jobs

    def leave_maintenance(self, state):
        existing, jobs = state
        jobs = {name for name in jobs if self.values['ENABLE_QUEUE' if name == 'worker' else 'ENABLE_SCHEDULER'] == '1'}
        self.run('start', '--wait', '--wait-timeout', '240', 'php-fpm', 'ai-service')
        if not existing:
            self.artisan('up')
        if jobs:
            self.run('start', *sorted(jobs))

    def stream_save(self, args, target: Path, compress=False):
        # 二进制输出直接写入受限文件，不在终端打印数据库或文件内容。
        with tempfile.TemporaryFile() as err, target.open('xb') as out:
            target.chmod(0o600)
            process = subprocess.Popen(self.base + args, cwd=ROOT, env=self.process_env,
                                       stdout=subprocess.PIPE, stderr=err)
            if compress:
                with gzip.GzipFile(fileobj=out, mode='wb') as zipped:
                    shutil.copyfileobj(process.stdout, zipped)
            else:
                shutil.copyfileobj(process.stdout, out)
            if process.wait() != 0:
                target.unlink(missing_ok=True)
                raise RuntimeError('备份子进程失败；备份未完成')

    def backup_data(self):
        folder = Path(self.values['BACKUP_DIR'])
        if not folder.is_absolute():
            folder = ROOT / folder
        destination = folder / dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
        destination.mkdir(mode=0o700, parents=True)
        status = self.run('ps', '--all', '--format', 'json', capture=True).stdout.strip()
        running = json.loads(status) if status.startswith('[') else [json.loads(line) for line in status.splitlines()]
        manifest = {'release': self.values['RELEASE_TAG'], 'project': self.values['COMPOSE_PROJECT_NAME'],
                    'running': [{'service': row['Service'], 'image': row['Image']} for row in running]}
        shutil.copyfile(self.path, destination / 'config.env')
        (destination / 'config.env').chmod(0o600)
        self.stream_save(['exec', '-T', 'mysql', 'sh', '-ec',
            'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces --set-gtid-purged=OFF --routines --triggers "$MYSQL_DATABASE"'],
            destination / 'database.sql.gz', compress=True)
        self.stream_save(['run', '--rm', '--no-deps', '-T', '--entrypoint', 'tar', 'php-fpm',
                          '-czf', '-', '-C', '/var/www/html/storage', 'app'],
                         destination / 'uploads.tar.gz')
        manifest['sha256'] = {name: file_hash(destination / name)
                              for name in ['config.env', 'database.sql.gz', 'uploads.tar.gz']}
        with (destination / 'manifest.json').open('x') as handle:
            json.dump(manifest, handle, ensure_ascii=False, indent=2)
        (destination / 'COMPLETE').touch(mode=0o600)
        print('备份已完成（含密钥文件，目录须受控保存）：' + str(destination))
        return destination

    def backup(self):
        self.ensure_images()
        state = self.enter_maintenance()
        try:
            return self.backup_data()
        finally:
            self.leave_maintenance(state)

    def update(self):
        self.tls_check()
        self.ensure_images()
        state = self.enter_maintenance()
        try:
            self.backup_data()
            self.once('php', 'artisan', 'migrate', '--force', '--no-interaction')
            self.up(include_jobs=False)
            self.leave_maintenance(state)
        except Exception:
            self.run('stop', *JOBS, check=False)
            print('更新失败：保持维护模式。请按文档回退镜像或恢复备份。', file=sys.stderr)
            raise

    def restore(self, folder: Path, confirmed: bool):
        if not confirmed:
            raise ValueError('恢复会替换数据库/上传文件并清理默认队列，须显式加 --confirm-replace')
        if not (folder / 'COMPLETE').is_file():
            raise ValueError('备份不完整，拒绝恢复')
        manifest = json.loads((folder / 'manifest.json').read_text())
        for name in ['config.env', 'database.sql.gz', 'uploads.tar.gz']:
            if manifest.get('sha256', {}).get(name) != file_hash(folder / name):
                raise ValueError('备份校验失败：' + name)
        if read_env(folder / 'config.env').get('APP_KEY') != self.values['APP_KEY']:
            raise ValueError('当前 APP_KEY 与备份不同；先在受控配置中恢复原 APP_KEY')
        with tarfile.open(folder / 'uploads.tar.gz', 'r:gz') as archive:
            for item in archive:
                path = PurePosixPath(item.name)
                if path.is_absolute() or '..' in path.parts or not path.parts or path.parts[0] != 'app' or item.issym() or item.islnk() or not (item.isdir() or item.isfile()):
                    raise ValueError('上传文件备份含不安全路径或链接')
        self.ensure_images()
        state = self.enter_maintenance()
        try:
            self.backup_data()
            with gzip.open(folder / 'database.sql.gz', 'rb') as handle:
                self.stream_restore(['exec', '-T', 'mysql', 'sh', '-ec',
                    'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u "$MYSQL_USER" "$MYSQL_DATABASE"'], handle)
            with (folder / 'uploads.tar.gz').open('rb') as handle:
                self.stream_restore(['run', '--rm', '--no-deps', '-T', '--entrypoint', 'sh', 'php-fpm', '-ec',
                    'rm -rf storage/restore_tmp; mkdir -p storage/restore_tmp; tar -xzf - -C storage/restore_tmp; rm -rf storage/app; mv storage/restore_tmp/app storage/app; rmdir storage/restore_tmp'], handle)
            self.once('php', 'artisan', 'cache:clear')
            self.once('php', 'artisan', 'queue:clear', 'redis', '--queue=default', '--force')
            self.run('exec', '-T', 'ai-service', 'python', '-c',
                     'import os,redis; r=redis.Redis.from_url(os.environ["REDIS_URL"]); r.flushdb()')
            self.run('restart', 'ai-service')
            self.leave_maintenance(state)
        except Exception:
            print('恢复失败：保持维护模式和后台任务停止。', file=sys.stderr)
            raise

    def stream_restore(self, args, source):
        with tempfile.TemporaryFile() as err:
            process = subprocess.Popen(self.base + args, cwd=ROOT, env=self.process_env,
                                       stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=err)
            try:
                shutil.copyfileobj(source, process.stdin)
                process.stdin.close()
            except BrokenPipeError:
                pass
            if process.wait() != 0:
                raise RuntimeError('恢复子进程失败')


def file_hash(path):
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


@contextlib.contextmanager
def operation_lock(path):
    lock = path.with_name(path.name + '.lock')
    with open(lock, 'a', opener=lambda name, flags: os.open(name, flags, 0o600)) as handle:
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ValueError('另一个部署/备份操作正在执行，请等其结束') from None
        yield


def execute(deployment, args):
    if args.action == 'config':
        print('生产配置及 Compose 语法通过；未输出密钥。')
    elif args.action == 'build':
        deployment.run('build')
    elif args.action == 'status':
        deployment.run('ps')
    elif args.action == 'admin':
        deployment.admin(args.employee)
    elif args.action == 'check':
        deployment.check(args.insecure, args.connect_ip)
    elif args.action == 'restore':
        deployment.restore(args.folder.resolve(), args.confirm_replace)
    elif args.action in ['artisan', 'compose']:
        if not args.arguments:
            raise ValueError('需要指定子命令')
        (deployment.artisan if args.action == 'artisan' else deployment.run)(*args.arguments)
    else:
        getattr(deployment, args.action)()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--env-file', type=Path, default=DEFAULT_ENV)
    commands = parser.add_subparsers(dest='action', required=True)
    init = commands.add_parser('init', help='生成服务器专用密钥，拒绝覆盖已有文件')
    init.add_argument('--pc-domain', required=True)
    init.add_argument('--mobile-domain', required=True)
    init.add_argument('--project', default='hongyue-prod')
    init.add_argument('--release')
    for name in ['config', 'build', 'install', 'up', 'status', 'backup', 'update']:
        commands.add_parser(name)
    admin = commands.add_parser('admin')
    admin.add_argument('--employee', default='admin')
    check = commands.add_parser('check')
    check.add_argument('--insecure', action='store_true', help='仅用于隔离测试的自签证书')
    check.add_argument('--connect-ip', help='本地联调时保留域名/SNI并连接指定 IP')
    restore = commands.add_parser('restore')
    restore.add_argument('folder', type=Path)
    restore.add_argument('--confirm-replace', action='store_true')
    commands.add_parser('artisan').add_argument('arguments', nargs=argparse.REMAINDER)
    commands.add_parser('compose').add_argument('arguments', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    try:
        if args.action == 'init':
            initialize(args)
            return
        deployment = Deployment(args.env_file)
        deployment.run('config', '--quiet')
        mutating = args.action in ['build', 'install', 'up', 'admin', 'backup', 'update', 'restore']
        with operation_lock(deployment.path) if mutating else contextlib.nullcontext():
            execute(deployment, args)
    except (ValueError, OSError, subprocess.CalledProcessError, RuntimeError) as error:
        # 不回显 subprocess 命令的 stdin 或配置内容。
        print('操作失败：' + (str(error) if isinstance(error, (ValueError, RuntimeError)) else type(error).__name__), file=sys.stderr)
        raise SystemExit(1)


if __name__ == '__main__':
    main()
