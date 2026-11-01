"""Audit captured production availability and integer ledgers, without changing it."""
from datetime import timedelta

from comparison_audit import LedgerAudit, instant


def audit(snapshot, max_age=180):
    if snapshot.get('version') != 'production-readiness-snapshot-1.0.0':
        raise ValueError('Use the captured production readiness snapshot.')
    if type(max_age) is not int or max_age < 1:
        raise ValueError('Use a positive freshness threshold.')
    issues, summaries, rows, daily = [], [], [], []
    settings = snapshot['settings']
    definition = snapshot['definition']
    # JSON encodes whole-number PHP division results as integers; fractional config must fail.
    if type(settings['capital']) is not int or settings['capital'] * 100 != definition['capital_minor']:
        raise ValueError('Production capital is not a whole-naira scenario input.')
    captured = instant(snapshot['captured_at'])
    started = instant(snapshot['started_at'])
    if captured < started:
        raise ValueError('Production capture timestamps are reversed.')

    def check(ok, code, detail=None):
        if not ok:
            issues.append({'code': code, 'detail': detail})

    check(snapshot.get('database') == 'kiotrad', 'unexpected_production_database')
    check(settings.get('strategy') == settings.get('league') == 'all', 'unexpected_scope')
    ready = snapshot['readiness']
    check(ready.get('http_status') == 200 and (ready.get('data') or {}).get('status') == 'ready'
          and ready['data'].get('database') == 'available', 'api_or_database_not_ready')
    for field in ('overview', 'overview_repeat', 'scenario', 'scenario_repeat'):
        response = snapshot[field]
        data = response.get('data') or {}
        check(response.get('http_status') == 200, field + '_http_not_ready', response.get('http_status'))
        if field.startswith('overview'):
            check(data.get('application') == 'KioTrad', field + '_application_missing')
            cache = data.get('dashboard_cache') or {}
            check(cache.get('status') == 'ready', field + '_cache_not_ready', cache.get('status'))
            try:
                generated = instant(data['generated_at'])
                check(started - timedelta(seconds=max_age) <= generated <= captured + timedelta(seconds=5),
                      field + '_snapshot_not_fresh')
            except (ValueError, TypeError, KeyError):
                check(False, field + '_timestamp_invalid')
            view = data.get('shared_simulation') or {}
            check(view.get('qualification_first_win_day_min') == settings['qualification'], field + '_qualification_mismatch')
            # LedgerAudit expects explicit request settings; baseline has config/metadata instead.
            # Attach only to an in-memory copy, while verifying every exposed financial rule.
            view = {**view, 'settings': settings}
        else:
            view = data
            check(view.get('settings') == settings, field + '_settings_mismatch')
            check((view.get('preparation') or {}).get('status') == 'ready', field + '_preparation_not_ready')
        try:
            ledger = LedgerAudit(field)
            summary, run_rows, day_rows = ledger.inspect(view, settings)
            summaries.append(summary)
            rows.extend(run_rows)
            daily.extend(day_rows)
            issues.extend({'code': i['code'], 'detail': i} for i in ledger.issues)
        except (ValueError, KeyError, TypeError, IndexError, AttributeError):
            check(False, field + '_ledger_unavailable_or_invalid')
    for name in ('dashboard', 'prepared'):
        check((snapshot['last_good'].get(name) or {}).get('available') is True, name + '_last_good_unavailable')
    imports = snapshot['imports']
    check(imports.get('complete_packages', 0) > 0 and imports.get('verified_seasons', 0) > 0, 'completed_history_import_missing')
    check(imports.get('failed_seasons') == 0, 'import_materialization_mismatch', imports.get('failed_seasons'))
    check(imports.get('unfinished_records') == 0, 'unfinished_import_records', imports.get('unfinished_records'))
    for actual, expected in [('verified_seasons', 'expected_seasons'), ('new_matches', 'expected_matches'), ('new_prices', 'expected_prices')]:
        check(type(imports.get(expected)) is int and imports.get(actual) == imports[expected], 'import_' + actual + '_mismatch')
    return {'version': 'production-readiness-audit-1.0.0', 'passed': not issues, 'database': snapshot.get('database'),
            'captured_at': snapshot['captured_at'], 'settings': settings, 'imports': imports,
            'summaries': summaries, 'runs': rows, 'daily': daily, 'issues': issues,
            'limits': ['Read-only MongoDB/file checks; ordinary GETs may populate rebuildable Redis scenario caches.',
                       'Repeated healthy reads and current last-good files do not simulate a Redis outage or prove continuous availability.',
                       'Overview and prepared scenarios may have different evidence cutoffs; each is audited independently, not forced to have matching balances.',
                       'Arithmetic and retained import materialization only; no certification of source truth, market availability or modeled dates.',
                       'The captured server scenario is the base benchmark, not a browser-scaled capital projection.']}
