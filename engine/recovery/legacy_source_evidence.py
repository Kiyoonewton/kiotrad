"""Read selected original package files for independent run source checks."""
import hashlib
import json
import re
from collections import Counter, defaultdict
from decimal import Decimal
from pathlib import Path

from timing_audit import LEAGUES, ROUTE


class SourcePackage:
    def __init__(self, root, progress=None):
        self.progress = progress
        self.root = Path(root).resolve()
        manifest_body = (self.root / 'manifest.json').read_bytes()
        self.manifest_hash = hashlib.sha256(manifest_body).hexdigest()
        manifest = json.loads(manifest_body)
        if manifest.get('package_version') != 'legacy-history-package-1.0.0':
            raise ValueError('Use the completed retained legacy-history package.')
        if hashlib.sha256((self.root / 'plan.json').read_bytes()).hexdigest() != manifest['plan_sha256']:
            raise ValueError('Package plan checksum differs.')
        self.index, self.cache, self.read_hashes = defaultdict(list), {}, set()
        seen = set()
        for item in manifest['files']:
            digest = item['sha256']
            if not re.fullmatch('[a-f0-9]{64}', digest) or item['file'] != digest + '.json' or digest in seen:
                raise ValueError('Invalid or duplicate manifest source identity.')
            seen.add(digest)
            self.index[item['competition'], str(item['season_id'])].append(item)

    def season(self, league, season):
        key = league, str(season)
        if key in self.cache:
            return self.cache[key]
        if not self.index[key]:
            raise ValueError('No retained raw sources for ' + league + ':' + str(season))
        if self.progress:
            self.progress(league, str(season), len(self.index[key]))
        matches = {}
        for item in self.index[key]:
            path = self.root / 'files' / item['file']
            if path.is_symlink() or path.resolve().parent != self.root / 'files' or path.stat().st_size > 20 * 1024 * 1024:
                raise ValueError('Unsafe or oversized raw package file.')
            body = path.read_bytes()
            if hashlib.sha256(body).hexdigest() != item['sha256']:
                raise ValueError('Raw source checksum differs: ' + item['file'])
            self.read_hashes.add(item['sha256'])
            payload = json.loads(body, parse_float=Decimal)
            route = ROUTE.fullmatch(str(payload.get('queryUrl', '')))
            if not route or route.group(1) != key[1] or int(route.group(2)) != item['matchday'] or not 1 <= item['matchday'] <= 30:
                raise ValueError('Raw source route differs from manifest.')
            for event in payload['doc'][0]['data']['odds']:
                if LEAGUES.get(str(event['tournament_id']).split(':')[-1]) != league:
                    raise ValueError('Raw source tournament differs from league.')
                row = self.event(event, item['matchday'])
                identity = row['id']
                existing = matches.get(identity)
                if existing is not None:
                    if any(existing[k] != row[k] for k in ('day', 'home_uid', 'away_uid')):
                        raise ValueError('Conflicting raw fixture identity: ' + identity)
                    if existing['result'] is not None and row['result'] is not None and existing['result'] != row['result']:
                        raise ValueError('Conflicting raw results: ' + identity)
                    if row['result'] is not None:
                        existing['result'] = row['result']
                else:
                    existing = matches[identity] = row
                    existing['sources'] = {}
                existing['sources'][item['sha256']] = {'prices': row['prices'], 'result': row['result']}
        self.cache[key] = matches
        return matches

    def event(self, event, day):
        if not re.fullmatch(r'vf:match:\d+', event['id']):
            raise ValueError('Invalid raw match ID.')
        row = {'id': event['id'], 'day': day}
        for side in ('home', 'away'):
            team = event['teams'][side]
            identity = re.fullmatch(r'sr:competitor:(\d+)', team['id'])
            if not identity or not isinstance(team.get('name'), str) or not team['name'].strip():
                raise ValueError('Missing raw team identity.')
            row[side + '_uid'], row[side + '_name'] = identity.group(1), team['name']
        if row['home_uid'] == row['away_uid']:
            raise ValueError('Raw fixture has identical teams.')
        markets = [m for m in event.get('market', []) if str(m['id']) == '1']
        if len(markets) > 1:
            raise ValueError('Duplicate raw 1X2 markets.')
        prices, winners, seen = {}, [], set()
        for outcome in markets[0].get('outcome', []) if markets else []:
            code = {'1': 'H', '2': 'D', '3': 'A'}.get(str(outcome['id']))
            if code is None or code in seen:
                raise ValueError('Invalid or duplicate raw 1X2 outcomes.')
            seen.add(code)
            odds = str(outcome.get('odds', ''))
            if re.fullmatch(r'\d{1,4}(?:\.\d{1,4})?', odds) and 1 < Decimal(odds) <= 1000:
                prices[code] = int(Decimal(odds) * 10000)
            if str(outcome.get('result', '')) == '1':
                winners.append(code)
        if len(winners) > 1:
            raise ValueError('Multiple winning raw 1X2 outcomes.')
        return {**row, 'prices': prices, 'result': winners[0] if winners else None}


def champion(matches):
    days, appearances, pairs, points, names = Counter(), Counter(), set(), Counter(), {}
    valid = len(matches) == 240
    for m in matches.values():
        days[m['day']] += 1
        pair = m['home_uid'], m['away_uid']
        valid = valid and pair not in pairs and m['result'] in ('H', 'D', 'A')
        pairs.add(pair)
        for side, code in [('home', 'H'), ('away', 'A')]:
            uid = m[side + '_uid']
            appearances[m['day'], uid] += 1
            names[uid] = m[side + '_name']
            points[uid] += 3 if m['result'] == code else 1 if m['result'] == 'D' else 0
    valid = valid and days == Counter({d: 8 for d in range(1, 31)}) and len(names) == 16 and all(n == 1 for n in appearances.values())
    table = [{'team_uid': uid, 'team': names[uid], 'points': value} for uid, value in sorted(points.items(), key=lambda p: (-p[1], p[0]))]
    leaders = [r for r in table if r['points'] == table[0]['points']] if table else []
    return {'complete': valid, 'leader': leaders[0] if valid and len(leaders) == 1 else None, 'top_three': table[:3]}


def first_win(matches, uid):
    pattern = []
    for day in range(1, 31):
        rows = [m for m in matches.values() if m['day'] == day and uid in (m['home_uid'], m['away_uid'])]
        if len(rows) != 1 or rows[0]['result'] not in ('H', 'D', 'A'):
            return None, pattern
        m = rows[0]
        code = 'H' if m['home_uid'] == uid else 'A'
        result = 'W' if m['result'] == code else 'D' if m['result'] == 'D' else 'L'
        pattern.append({'day': day, 'fixture_id': m['id'], 'fixture': m['home_name'] + ' vs ' + m['away_name'], 'result': result})
        if result == 'W':
            return day, pattern
    return None, pattern
