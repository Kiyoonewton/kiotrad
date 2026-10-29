import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("local_sync", Path(__file__).with_name("sync.py"))
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


class LocalSyncTests(unittest.TestCase):
    def test_rejects_server_os_and_remote_docker_before_any_write(self):
        with self.assertRaises(RuntimeError):
            sync.guard_local("linux", {})
        with self.assertRaises(RuntimeError):
            sync.guard_local("darwin", {"DOCKER_HOST": "ssh://production"})
        with patch.object(sync, "run", return_value=subprocess.CompletedProcess([], 0, b"ssh://production")):
            with self.assertRaises(RuntimeError):
                sync.guard_local("darwin", {})

    def test_only_allowlisted_read_commands_can_run_on_source(self):
        command = sync.source_command("root@server", "provider_captures")
        self.assertIn("mongodump", command[-1])
        self.assertIn("--config=/dev/stdin", command[-1])
        self.assertNotIn("mongorestore", command[-1])
        for server in ["-oProxyCommand=evil", "server; rm -rf /"]:
            with self.assertRaises(RuntimeError):
                sync.source_command(server, "provider_captures")
        with self.assertRaises(RuntimeError):
            sync.source_command("server", "system.users")

    def test_rejects_wrong_destination_volume(self):
        info = [{"Config": {"Labels": {"com.docker.compose.project": "kiotrad-local", "kiotrad.local-only": "true"}},
                 "Mounts": [{"Name": "kiotrad_mongo_data", "Destination": "/data/db"}]}]
        with patch.object(sync, "compose", return_value=subprocess.CompletedProcess([], 0, b"container")), \
             patch.object(sync, "run", return_value=subprocess.CompletedProcess([], 0, json.dumps(info).encode())):
            with self.assertRaises(RuntimeError):
                sync.verify_destination()

    def test_failed_download_keeps_active_database_and_services_untouched(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            env = root / ".env.local"
            original = "LOCAL_DATABASE=kiotrad_local_previous\nAPP_KEY=private\n"
            env.write_text(original)
            with patch.object(sync, "ROOT", root), patch.object(sync, "LOCAL", root), patch.object(sync, "ENV", env), \
                 patch.object(sync, "guard_local"), patch.object(sync, "verify_destination"), \
                 patch.object(sync, "getpass", return_value="secret"), patch.object(sync, "compose") as compose, \
                 patch.object(sync, "run", side_effect=RuntimeError("SSH interrupted")), \
                 patch("sys.argv", ["sync.py", "--ssh", "server"]):
                with self.assertRaises(RuntimeError):
                    sync.main()
                self.assertEqual(env.read_text(), original)
                self.assertEqual(compose.call_count, 1)
                self.assertEqual(compose.call_args.args[:2], ("up", "-d"))

    def test_failures_report_safe_stage_without_echoing_passwords(self):
        error = b'SYNC_ERROR:{"stage":"connect","code":18}\nsecret mongodb://user:password@host'
        result = subprocess.CompletedProcess([], 1, stderr=error)
        with patch("subprocess.run", return_value=result):
            with self.assertRaises(RuntimeError) as caught:
                sync.run(["ssh"], phase="source discovery")
        self.assertIn("source stage=connect, code=18", str(caught.exception))
        self.assertNotIn("password", str(caught.exception))
        self.assertNotIn("mongodb://", str(caught.exception))

    def test_concurrent_sync_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(sync, "LOCAL", Path(folder)):
            with sync.local_lock():
                with self.assertRaises(RuntimeError):
                    with sync.local_lock():
                        self.fail("Concurrent sync was allowed")

    def test_activation_rejects_non_snapshot_database_before_any_docker_call(self):
        with patch.object(sync, "compose") as compose:
            with self.assertRaises(RuntimeError):
                sync.activate_snapshot("kiotrad", "now")
            compose.assert_not_called()

    def test_activation_keeps_prewarmed_redis_and_recreates_the_web_upstream(self):
        with tempfile.TemporaryDirectory() as folder:
            env = Path(folder) / '.env.local'
            env.write_text('APP_KEY=private\nLOCAL_DATABASE=kiotrad_local_20261027T000000Z\n')
            with patch.object(sync, 'ENV', env), patch.object(sync, 'compose') as compose:
                sync.activate_snapshot('kiotrad_local_20261029T000000Z', 'now')
            calls = [call.args for call in compose.call_args_list]
            recreations = [call for call in calls if '--force-recreate' in call]
            self.assertEqual(len(recreations), 1)
            self.assertNotIn('redis', recreations[0])
            self.assertIn('web', recreations[0])
            self.assertIn('dashboard-worker', recreations[0])

    def test_environment_switch_is_local_and_preserves_other_settings(self):
        with tempfile.TemporaryDirectory() as folder:
            env = Path(folder) / ".env.local"
            env.write_text("APP_KEY=private\nLOCAL_DATABASE=kiotrad_local\n")
            with patch.object(sync, "ENV", env):
                sync.write_environment("kiotrad_local_20261025T010000Z")
                self.assertIn("APP_KEY=private", env.read_text())
                with self.assertRaises(RuntimeError):
                    sync.write_environment("kiotrad")


if __name__ == "__main__":
    unittest.main()
