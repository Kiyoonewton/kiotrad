import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from sync_credentials import required, save, settings
import sync as runner


class SyncCredentialsTest(unittest.TestCase):
    def test_private_env_round_trips_special_characters_without_shell_expansion(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {}, clear=True):
            root = Path(temporary)
            (root / 'engine/local').mkdir(parents=True)
            password = 'synthetic $() `quotes` " \\ \n password'
            save(root, 'root@example.test', password)
            self.assertEqual(required(root)['KIOTRAD_SYNC_PASSWORD'], password)
            path = root / 'engine/local/.env.sync'
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with patch.dict(os.environ, {'KIOTRAD_SYNC_PASSWORD': 'override'}):
                self.assertEqual(settings(root)['KIOTRAD_SYNC_PASSWORD'], 'override')
            path.chmod(0o644)
            with self.assertRaises(RuntimeError):
                settings(root)

    def test_missing_credentials_unsafe_server_and_symlink_fail(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {}, clear=True):
            root = Path(temporary)
            (root / 'engine/local').mkdir(parents=True)
            with self.assertRaises(RuntimeError):
                required(root)
            for server in ['-oProxyCommand=evil', 'root@host; echo bad']:
                with self.assertRaises(RuntimeError):
                    save(root, server, 'synthetic')
            target = root / 'target'
            target.write_text('unchanged')
            (root / 'engine/local/.env.sync').symlink_to(target)
            with self.assertRaises(RuntimeError):
                save(root, 'root@host', 'synthetic')
            self.assertEqual(target.read_text(), 'unchanged')

    def test_terminal_sync_uses_env_without_prompt_and_noninteractive_ssh_cannot_prompt(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {}, clear=True):
            root = Path(temporary)
            (root / 'engine/local').mkdir(parents=True)
            save(root, 'root@example.test', 'synthetic')
            with patch.object(runner, 'ROOT', root), patch.object(runner, 'terminal_getpass') as prompt:
                self.assertEqual(runner.getpass('Password: '), 'synthetic')
                prompt.assert_not_called()
                (root / 'engine/local/.env.sync').unlink()
                with patch.dict(os.environ, {'KIOTRAD_SYNC_NONINTERACTIVE': '1'}):
                    with self.assertRaises(RuntimeError):
                        runner.getpass('Password: ')
                    self.assertIn('BatchMode=yes', runner.ssh_command('root@example.test', ['true']))
