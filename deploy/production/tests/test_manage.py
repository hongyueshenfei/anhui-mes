"""验证配置安全边界和生产 Compose，而不是复刻业务实现。"""
import argparse
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('production_manage', Path(__file__).resolve().parents[1] / 'manage.py')
manage = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manage)


class ProductionConfigTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='hy-config-test-')
        self.addCleanup(self.directory.cleanup)
        self.env = Path(self.directory.name) / '.env'
        self.args = argparse.Namespace(env_file=self.env, project='hy-config-test',
            pc_domain='pc.localhost', mobile_domain='h5.localhost', release='config-test')
        with contextlib.redirect_stdout(io.StringIO()):
            manage.initialize(self.args)
        self.values = manage.read_env(self.env)

    def test_new_credentials_and_restrictive_mode(self):
        self.assertEqual(stat.S_IMODE(self.env.stat().st_mode), 0o600)
        self.assertEqual(len(set(self.values[k] for k in ['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'REDIS_PASSWORD', 'AI_SERVICE_TOKEN'])), 4)
        manage.validate(self.values)

    def test_init_refuses_to_replace_keys(self):
        previous = self.env.read_bytes()
        with self.assertRaises(ValueError):
            manage.initialize(self.args)
        self.assertEqual(previous, self.env.read_bytes())

    def test_domains_must_be_distinct_and_have_no_paths(self):
        for bad in ['https://host.test', 'host.test/path', 'host.test:443']:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                manage.validate({**self.values, 'PC_DOMAIN': bad})
        with self.assertRaises(ValueError):
            manage.validate({**self.values, 'MOBILE_DOMAIN': self.values['PC_DOMAIN']})

    def test_scheduler_requires_explicit_integration_confirmation(self):
        with self.assertRaises(ValueError):
            manage.validate({**self.values, 'ENABLE_SCHEDULER': '1'})

    def test_variable_expansion_and_duplicate_keys_rejected(self):
        for content in ["A=$SECRET\n", "A='one'\nA='two'\n"]:
            self.env.write_text(content)
            with self.assertRaises(ValueError):
                manage.read_env(self.env)

    def test_shell_environment_cannot_override_release_or_key(self):
        old = os.environ.get('APP_KEY')
        try:
            os.environ['APP_KEY'] = 'unexpected'
            deployment = manage.Deployment(self.env)
            self.assertNotIn('APP_KEY', deployment.process_env)
        finally:
            if old is None:
                os.environ.pop('APP_KEY', None)
            else:
                os.environ['APP_KEY'] = old

    def test_compose_publishes_only_gateway_and_keeps_literal_secrets(self):
        self.values['DB_PASSWORD'] = 'a' * 40 + '$literal#value'
        self.env.write_text(''.join(f'{k}={manage.quote_env(v)}\n' for k, v in self.values.items()))
        deployment = manage.Deployment(self.env)
        result = deployment.run('config', '--format', 'json', capture=True)
        config = json.loads(result.stdout)
        services = config['services']
        self.assertEqual(set(services), set(manage.CORE))
        self.assertEqual([name for name, value in services.items() if value.get('ports')], ['gateway'])
        # config 为可再次读取的 Compose 文档转义 $；实际容器值由 smoke 的数据库连接验证。
        self.assertEqual(services['php-fpm']['environment']['DB_PASSWORD'].replace('$$', '$'), self.values['DB_PASSWORD'])
        self.assertEqual(services['php-fpm']['environment']['APP_TEST_NOW'], '')
        self.assertEqual(services['php-fpm']['environment']['APP_DEBUG'], 'false')
        self.assertEqual(services['gateway']['build']['args']['MOBILE_APP_URL'], 'https://h5.localhost')

    def test_restore_requires_confirmation_before_touching_services(self):
        deployment = manage.Deployment(self.env)
        with self.assertRaises(ValueError):
            deployment.restore(Path(self.directory.name), False)

    def test_corrupt_backup_rejected_before_replacing_database(self):
        deployment = manage.Deployment(self.env)
        folder = Path(self.directory.name) / 'backup'
        folder.mkdir()
        (folder / 'COMPLETE').touch()
        (folder / 'config.env').write_text('changed')
        (folder / 'manifest.json').write_text(json.dumps({'sha256': {'config.env': 'invalid'}}))
        with self.assertRaisesRegex(ValueError, '校验失败'):
            deployment.restore(folder, True)

    def test_maintenance_clears_http_maintenance_before_restarting_jobs(self):
        deployment = object.__new__(manage.Deployment)
        deployment.values = {'ENABLE_QUEUE': '1', 'ENABLE_SCHEDULER': '1'}
        calls = []
        deployment.run = lambda *args, **kwargs: calls.append(('run', args))
        deployment.artisan = lambda *args, **kwargs: calls.append(('artisan', args))
        deployment.leave_maintenance((False, {'worker', 'scheduler'}))
        self.assertEqual(calls[0], ('run', ('start', '--wait', '--wait-timeout', '240', 'php-fpm', 'ai-service')))
        self.assertEqual(calls[1], ('artisan', ('up',)))
        self.assertEqual(calls[2], ('run', ('start', 'scheduler', 'worker')))

    def test_update_core_only_flag_does_not_start_profile_jobs(self):
        deployment = object.__new__(manage.Deployment)
        deployment.values = {'ENABLE_QUEUE': '1', 'ENABLE_SCHEDULER': '1'}
        deployment.tls_check = lambda: None
        deployment.ensure_images = lambda: None
        deployment.running = lambda: set()
        calls = []
        deployment.run = lambda *args, **kwargs: calls.append(args)
        deployment.up(include_jobs=False)
        self.assertEqual(calls, [
            ('up', '-d', '--no-build', '--wait', '--wait-timeout', '240', *manage.CORE),
        ])


if __name__ == '__main__':
    unittest.main()
