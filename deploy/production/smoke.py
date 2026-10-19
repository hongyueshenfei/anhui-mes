#!/usr/bin/env python3
"""在全新 Compose 项目/临时配置/随机回环端口验证安装和备份恢复。"""
import argparse
import http.client
import json
from pathlib import Path
import secrets
import socket
import ssl
import subprocess
import tempfile

import manage


def port():
    with socket.socket() as connection:
        connection.bind(('127.0.0.1', 0))
        return connection.getsockname()[1]


def request(deployment, path, method='GET', body=None, token=None):
    context = ssl._create_unverified_context()
    connection = http.client.HTTPSConnection('127.0.0.1', int(deployment.values['HTTPS_PORT']), context=context, timeout=30)
    connection.connect = lambda: setattr(connection, 'sock', context.wrap_socket(
        socket.create_connection(('127.0.0.1', int(deployment.values['HTTPS_PORT'])), 30), server_hostname='pc.localhost'))
    headers = {'Host': 'pc.localhost', 'Accept': 'application/json', 'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    connection.request(method, path, body=json.dumps(body) if body else None, headers=headers)
    response = connection.getresponse()
    payload = response.read()
    connection.close()
    return response.status, payload


def php(deployment, code):
    boot = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
    deployment.run('exec', '-T', 'php-fpm', 'php', '-r', boot + code)


def run(args):
    project = 'hy-smoke-' + secrets.token_hex(4)
    if not project.startswith('hy-smoke-'):
        raise RuntimeError('隔离项目名错误')
    with tempfile.TemporaryDirectory(prefix='.smoke-', dir=manage.ROOT / 'deploy/production') as directory:
        folder = Path(directory)
        env = folder / '.env'
        manage.initialize(argparse.Namespace(env_file=env, project=project,
            pc_domain='pc.localhost', mobile_domain='h5.localhost', release=args.release))
        values = manage.read_env(env)
        values.update(BIND_ADDRESS='127.0.0.1', HTTP_PORT=str(port()), HTTPS_PORT=str(port()))
        # 特殊字符必须从 dotenv 经 MySQL、PHP 环境一直保留到运行时。
        values['DB_PASSWORD'] += '$literal#value'
        env.write_text(''.join(f'{key}={manage.quote_env(value)}\n' for key, value in values.items()))
        tls = folder / 'tls'
        subprocess.run(['openssl', 'req', '-x509', '-nodes', '-newkey', 'rsa:2048', '-days', '1',
            '-subj', '/CN=pc.localhost', '-addext', 'subjectAltName=DNS:pc.localhost,DNS:h5.localhost',
            '-keyout', str(tls / 'privkey.pem'), '-out', str(tls / 'fullchain.pem')],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        (tls / 'privkey.pem').chmod(0o600)
        deployment = manage.Deployment(env)
        try:
            if not args.skip_build:
                deployment.run('build')
            deployment.install()
            deployment.check(insecure=True, connect_ip='127.0.0.1')
            deployment.run('exec', '-T', 'php-fpm', 'sh', '-ec',
                           'test ! -e .env; test ! -d tests; test ! -d vendor/phpunit')
            php(deployment, 'foreach (["users","orders","prod_tasks","products","materials"] as $table) { if (Illuminate\\Support\\Facades\\DB::table($table)->count() !== 0) exit(1); }')
            php(deployment, 'if (! Illuminate\\Support\\Facades\\DB::table("roles")->where("name", "admin")->where("guard_name", "web")->exists()) exit(1);')
            password = secrets.token_hex(18)
            deployment.once('php', '/opt/hongyue/ops.php', 'admin',
                            input=json.dumps({'employee_no': 'smoke-admin', 'password': password}))
            try:
                deployment.once('php', '/opt/hongyue/ops.php', 'admin',
                                input=json.dumps({'employee_no': 'smoke-admin', 'password': secrets.token_hex(20)}))
            except subprocess.CalledProcessError:
                pass
            else:
                raise RuntimeError('重复管理员被覆盖')
            status, raw = request(deployment, '/api/auth/login', 'POST',
                                  {'employee_no': 'smoke-admin', 'password': password})
            if status != 200:
                raise RuntimeError('管理员登录失败；响应正文已省略')
            token = json.loads(raw)['data']['token']
            status, _ = request(deployment, '/api/auth/me', token=token)
            if status != 200:
                raise RuntimeError('登录态验证失败')
            php(deployment, 'file_put_contents(storage_path("app/public/deployment-smoke.txt"), "before-backup");')
            status, body = request(deployment, '/storage/deployment-smoke.txt')
            if status != 200 or body != b'before-backup':
                raise RuntimeError('公开附件路由或共享卷失败')
            deployment.run('restart', 'php-fpm', 'api', 'gateway')
            deployment.up()
            if request(deployment, '/storage/deployment-smoke.txt') != (200, b'before-backup'):
                raise RuntimeError('容器重启后附件未持久化')
            deployment.update()
            if request(deployment, '/storage/deployment-smoke.txt') != (200, b'before-backup'):
                raise RuntimeError('无代码差异更新后附件未保留')
            deployment.check(insecure=True, connect_ip='127.0.0.1')
            deployment.once('php', 'artisan', 'queue:work', 'redis', '--once', '--stop-when-empty', '--no-interaction')
            deployment.once('php', 'artisan', 'schedule:list', '--no-interaction')
            # AI 在容器网络内也必须拒绝错误 Token。
            deployment.run('exec', '-T', 'ai-service', 'python', '-c',
                'import urllib.request,urllib.error; '
                'r=urllib.request.Request("http://127.0.0.1:8001/health",headers={"X-AI-Token":"invalid"}); '
                '\ntry: urllib.request.urlopen(r); raise SystemExit(1)'
                '\nexcept urllib.error.HTTPError as e: raise SystemExit(0 if e.code==401 else 1)')
            backup = deployment.backup()
            php(deployment, 'App\\Models\\User::where("employee_no","smoke-admin")->update(["name"=>"changed"]); file_put_contents(storage_path("app/public/deployment-smoke.txt"), "changed");')
            deployment.restore(backup, confirmed=True)
            if request(deployment, '/storage/deployment-smoke.txt') != (200, b'before-backup'):
                raise RuntimeError('附件恢复内容不正确')
            status, raw = request(deployment, '/api/auth/login', 'POST',
                                  {'employee_no': 'smoke-admin', 'password': password})
            if status != 200 or json.loads(raw)['data']['user']['name'] != '系统管理员':
                raise RuntimeError('数据库恢复内容不正确')
            deployment.check(insecure=True, connect_ip='127.0.0.1')
            print('隔离部署 smoke 通过：安装、登录、路由、鉴权、重启持久化、队列/调度入口、备份与真实恢复。')
        finally:
            # 只销毁本脚本创建的随机项目，绝不接收用户已有的生产项目参数。
            deployment.run('down', '--volumes', '--remove-orphans')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release', default='smoke-local', help='仅用于隔离镜像的标签')
    parser.add_argument('--skip-build', action='store_true', help='使用已构建的同标签镜像；H5 构建域名必须为 h5.localhost')
    args = parser.parse_args()
    if not args.release.startswith('smoke-'):
        parser.error('隔离测试镜像标签必须以 smoke- 开头')
    run(args)
