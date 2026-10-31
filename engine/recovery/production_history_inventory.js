// Read-only mongosh program. REQUEST is supplied over SSH stdin, never argv.
let stage = 'connect';
try {
  const source = new Mongo(REQUEST.uri).getDB('kiotrad');
  stage = 'roles';
  const roles = source.runCommand({connectionStatus: 1}).authInfo.authenticatedUserRoles;
  if (!roles.length || roles.some(r => r.role !== 'read' || r.db !== 'kiotrad')) {
    throw new Error('read_only_role_required');
  }
  const cutoff = new Date().toISOString().slice(0, 19) + '+00:00';
  print(JSON.stringify({type: 'start', database: 'kiotrad', cutoff, read_only_role_verified: true,
    request_sha256: REQUEST.sha256, seasons: REQUEST.targets.length}));
  const scaled = value => {
    const text = String(value ?? '');
    if (!/^\d{1,4}(?:\.\d{1,4})?$/.test(text)) return null;
    const [whole, decimal = ''] = text.split('.');
    const result = Number(whole) * 10000 + Number(decimal.padEnd(4, '0'));
    return result > 10000 && result <= 10000000 ? result : null;
  };
  let done = 0;
  for (const target of REQUEST.targets) {
    stage = 'inventory';
    const wanted = new Set(target.ids);
    const records = new Map();
    const record = id => {
      if (!records.has(id)) records.set(id, {fixtures: new Map(), prices: new Map(), invalid_quotes: 0});
      return records.get(id);
    };
    const captures = source.provider_captures.find({observed_at: {$lte: cutoff}, $or: [
      {competition: target.league, season_id: target.season},
      {'matches.provider_match_id': {$in: target.ids}},
      {'odds_snapshots.provider_match_id': {$in: target.ids}}
    ]}, {competition: 1, matches: 1, odds_snapshots: 1}).batchSize(2);
    for (const capture of captures) {
      for (const match of capture.matches ?? []) {
        const id = match.provider_match_id;
        if (!wanted.has(id)) continue;
        const fixture = {competition: capture.competition ?? null, season_id: String(match.season_id ?? ''),
          matchday: match.matchday, home_uid: String(match.home_team_uid ?? ''), away_uid: String(match.away_team_uid ?? ''),
          result: match.result ?? null, scheduled_start_at: match.scheduled_start_at ?? null};
        record(id).fixtures.set(JSON.stringify(fixture), fixture);
      }
      for (const quote of capture.odds_snapshots ?? []) {
        const id = quote.provider_match_id;
        if (!wanted.has(id)) continue;
        const price = Object.fromEntries(['H', 'D', 'A'].map(c => [c, scaled(quote.prices?.[c])]));
        if (Object.keys(quote.prices ?? {}).length !== 3 || Object.values(price).some(v => v === null)) {
          record(id).invalid_quotes++;
        } else {
          const item = {competition: capture.competition ?? null, prices: price};
          record(id).prices.set(JSON.stringify(item), item);
        }
      }
    }
    const matches = Object.fromEntries([...records].map(([id, value]) => [id,
      {fixtures: [...value.fixtures.values()], prices: [...value.prices.values()], invalid_quotes: value.invalid_quotes}]));
    print(JSON.stringify({type: 'season', league: target.league, season: target.season,
      progress: ++done, matches}));
  }
  print(JSON.stringify({type: 'complete', seasons: done, request_sha256: REQUEST.sha256,
    completed_at: new Date().toISOString(), applied: false}));
} catch (_) {
  print(JSON.stringify({type: 'error', stage, code: 'production_read_audit_failed'}));
  quit(1);
}
