import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from plan_production_history import inventory, ssh, validate_inputs
from production_history_plan import assess_season, final_plan, strategy_alignment


class ProductionHistoryPlanTest(unittest.TestCase):
    def incoming(self):
        return {'vf:match:1': {'id': 'vf:match:1', 'day': 1, 'home_uid': '1', 'away_uid': '2',
                               'home_name': 'A', 'away_name': 'B', 'result': 'H',
                               'sources': {'a' * 64: {'prices': {'H': 20000, 'D': 30000, 'A': 40000}, 'result': 'H'}}}}

    def stored(self, price=20000):
        return {'vf:match:1': {'fixtures': [{'competition': 'virtual-euro', 'season_id': '100', 'matchday': 1,
                                           'home_uid': '1', 'away_uid': '2', 'result': 'H',
                                           'scheduled_start_at': '2026-10-18T10:00:00+00:00'}],
                               'prices': [{'competition': 'virtual-euro', 'prices': {'H': price, 'D': 30000, 'A': 40000}}], 'invalid_quotes': 0}}

    def test_append_candidates_never_add_estimates_or_financial_eligibility(self):
        summary, rows = assess_season('virtual-euro', '100', self.incoming(), {})
        self.assertEqual(rows[0]['fixture_action'], 'append_fixture_candidate')
        self.assertEqual(rows[0]['price_action'], 'append_price_candidate')
        self.assertEqual(rows[0]['timing_action'], 'remain_untimed')
        self.assertFalse(summary['full_result_integrity'])
        self.assertFalse(summary['financial_eligibility_added'])

    def test_existing_times_and_different_market_prices_are_preserved(self):
        for price, expected in [(20000, 'matching_price_skip'), (21000, 'different_production_price_preserve')]:
            summary, rows = assess_season('virtual-euro', '100', self.incoming(), self.stored(price))
            self.assertFalse(summary['blocked'])
            self.assertEqual(rows[0]['price_action'], expected)
            self.assertEqual(rows[0]['timing_action'], 'preserve_recorded_schedule')
            self.assertEqual(rows[0]['fixture_action'], 'preserve_existing_fixture')

    def test_identity_or_result_conflict_blocks_entire_season(self):
        for field, value in [('competition', 'virtual-football'), ('home_uid', '99'), ('result', 'A')]:
            stored = self.stored()
            stored['vf:match:1']['fixtures'][0][field] = value
            summary, rows = assess_season('virtual-euro', '100', self.incoming(), stored)
            self.assertTrue(summary['blocked'])
            self.assertEqual(rows[0]['price_action'], 'blocked_season_review_required')

    def test_ambiguous_source_prices_are_raw_only_not_arbitrarily_chosen(self):
        source = self.incoming()
        source['vf:match:1']['sources']['b' * 64] = {'prices': {'H': 21000, 'D': 30000, 'A': 40000}, 'result': 'H'}
        _, rows = assess_season('virtual-euro', '100', source, {})
        self.assertEqual(rows[0]['price_action'], 'ambiguous_source_price_preserve_raw_only')

    def test_conflicting_production_times_and_foreign_quotes_require_review(self):
        stored = self.stored()
        different = dict(stored['vf:match:1']['fixtures'][0], scheduled_start_at='2026-10-18T11:00:00+00:00')
        stored['vf:match:1']['fixtures'].append(different)
        stored['vf:match:1']['prices'][0]['competition'] = 'virtual-football'
        summary, rows = assess_season('virtual-euro', '100', self.incoming(), stored)
        self.assertEqual(summary['disposition'], 'blocked_conflict')
        self.assertIn('production_timing_conflict', rows[0]['conflicts'])
        self.assertIn('quote_competition', rows[0]['conflicts'])

    def test_missing_source_prices_and_existing_unconfirmed_results_stay_explicit(self):
        incoming = self.incoming()
        incoming['vf:match:1']['sources']['a' * 64]['prices'] = {}
        stored = self.stored()
        stored['vf:match:1']['fixtures'][0]['result'] = None
        summary, rows = assess_season('virtual-euro', '100', incoming, stored)
        self.assertEqual(rows[0]['price_action'], 'source_price_missing')
        self.assertTrue(rows[0]['existing_unconfirmed_result'])
        self.assertEqual(summary['disposition'], 'raw_preservation_only_candidate')

    def test_strategy_mismatch_and_capital_difference_stay_visible_without_authorizing_apply(self):
        snapshot = {'schema_version': 1, 'capital_minor': 700000, 'replay_version': 'old', 'code_hashes': {'A.php': 'old'}}
        alignment = strategy_alignment(snapshot, {'A.php': 'new'}, 'new', 5000000)
        self.assertFalse(alignment['code_matches'])
        self.assertFalse(alignment['benchmark_capital_matches'])
        result = final_plan([], [], alignment, {})
        self.assertFalse(result['applied'])
        self.assertFalse(result['production_write_authorized'])
        self.assertFalse(result['timing_policy']['apply_estimated_dates'])

    def test_passed_audits_must_pin_the_same_comparison_and_package(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            comparison = root / 'comparison.json'
            comparison.write_text(json.dumps({'version': 'estimated-history-comparison-1.0.0', 'benchmark_eligible': False, 'research_only': True, 'difference': {'accepted_only_with_estimates': []}}))
            digest = hashlib.sha256(comparison.read_bytes()).hexdigest()
            ledger, source = root / 'ledger.json', root / 'source.json'
            ledger.write_text(json.dumps({'version': 'comparison-ledger-audit-1.0.0', 'passed': True, 'source_sha256': digest, 'issues': []}))
            source.write_text(json.dumps({'version': 'added-run-source-audit-1.0.0', 'passed': True,
                                          'comparison_sha256': digest, 'manifest_sha256': 'package', 'issues': [], 'runs': []}))
            package = type('Package', (), {'manifest_hash': 'package'})()
            self.assertEqual(validate_inputs(comparison, ledger, source, package)[1]['comparison_sha256'], digest)
            comparison.write_text(comparison.read_text() + '\n')
            with self.assertRaisesRegex(ValueError, 'Ledger audit'):
                validate_inputs(comparison, ledger, source, package)

    def test_ssh_never_accepts_arbitrary_arguments(self):
        with self.assertRaises(ValueError):
            ssh('-oProxyCommand=bad', ['docker', 'exec'])
        command = ssh('root@example.test', ['docker', 'exec', '-i', 'kiotrad-mongodb-1'])
        self.assertIn('BatchMode=yes', command)
        self.assertNotIn('password', ' '.join(command))

    def test_inventory_requires_header_every_season_and_completion_marker(self):
        targets = [{'league': 'virtual-euro', 'season': '100', 'ids': ['vf:match:1']}]
        digest = hashlib.sha256(json.dumps(targets, sort_keys=True).encode()).hexdigest()
        messages = [{'type': 'start', 'database': 'kiotrad', 'read_only_role_verified': True, 'request_sha256': digest, 'seasons': 1},
                    {'type': 'season', 'league': 'virtual-euro', 'season': '100', 'progress': 1, 'matches': {}},
                    {'type': 'complete', 'applied': False, 'request_sha256': digest, 'seasons': 1}]
        class Process:
            def __init__(self, messages):
                self.stdin = io.BytesIO()
                self.stdout = io.BytesIO(b''.join((json.dumps(m) + '\n').encode() for m in messages))
            def wait(self):
                return 0
            def poll(self):
                return 0
        with tempfile.TemporaryDirectory() as tmp:
            seen = []
            with patch('plan_production_history.subprocess.Popen', return_value=Process(messages)) as launch:
                result = inventory('root@example.test', 'hidden-password', targets, Path(tmp), seen.append)
                self.assertEqual(len(seen), 1)
                self.assertNotIn('hidden-password', repr(launch.call_args))
                self.assertNotIn('hidden-password', (Path(tmp) / 'production-inventory.jsonl').read_text())
                self.assertFalse(result['inventory_completion']['applied'])
            with patch('plan_production_history.subprocess.Popen', return_value=Process(messages[:-1])):
                with self.assertRaisesRegex(RuntimeError, 'did not complete'):
                    inventory('root@example.test', 'hidden-password', targets, Path(tmp), seen.append)

    def test_mongosh_program_performs_only_read_queries_and_rejects_write_roles(self):
        program = Path(__file__).with_name('production_history_inventory.js').read_text()
        harness = '''
const REQUEST={uri:'test',sha256:'hash',targets:[{league:'virtual-euro',season:'100',ids:['vf:match:1']}]};
const lines=[];
const print=value=>lines.push(JSON.parse(value));
const quit=code=>{throw new Error('quit');};
const fixture={competition:'virtual-euro',matches:[{provider_match_id:'vf:match:1',season_id:'100',matchday:1,home_team_uid:'1',away_team_uid:'2',result:'H'}],odds_snapshots:[{provider_match_id:'vf:match:1',prices:{H:'1.7500',D:'3.00',A:'4.00'}}]};
function Mongo(){this.getDB=()=>({runCommand:()=>({authInfo:{authenticatedUserRoles:ROLES}}),provider_captures:{find:()=>({batchSize:()=>[fixture]})}});}
try { PROGRAM } catch (_) {}
process.stdout.write(JSON.stringify(lines));
'''
        for role, expected in [('read', 'complete'), ('readWrite', 'error')]:
            script = harness.replace('ROLES', json.dumps([{'role': role, 'db': 'kiotrad'}])).replace('PROGRAM', program)
            result = subprocess.run(['node', '-e', script], check=True, stdout=subprocess.PIPE)
            messages = json.loads(result.stdout)
            self.assertEqual(messages[-1]['type'], expected)
            if role == 'read':
                self.assertEqual(messages[1]['matches']['vf:match:1']['prices'][0]['prices']['H'], 17500)


if __name__ == '__main__':
    unittest.main()
