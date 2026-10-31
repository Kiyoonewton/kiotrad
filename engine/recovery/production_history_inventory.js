// Read-only mongosh program. REQUEST is supplied over SSH stdin, never argv.
let stage = 'connect';
try {
  const source = new Mongo(REQUEST.uri).getDB('kiotrad');
  stage = 'roles';
  const roles = source.runCommand({connectionStatus: 1}).authInfo.authenticatedUserRoles;
  if (!roles.length || roles.some(r => r.role !== 'read' || r.db !== 'kiotrad')) throw new Error('read_only_role_required');
  const cutoff = new Date().toISOString().slice(0, 19) + '+00:00';
  const ids = REQUEST.targets.flatMap(t => t.ids);
  print(JSON.stringify({type: 'start', database: 'kiotrad', cutoff, read_only_role_verified: true,
    request_sha256: REQUEST.sha256, seasons: REQUEST.targets.length, target_fixtures: ids.length}));
  const scaled = value => {
    const text = String(value ?? '');
    if (!/^\d{1,4}(?:\.\d{1,4})?$/.test(text)) return null;
    const [whole, decimal = ''] = text.split('.');
    const result = Number(whole) * 10000 + Number(decimal.padEnd(4, '0'));
    return result > 10000 && result <= 10000000 ? result : null;
  };
  let fixtures = 0, prices = 0;
  stage = 'fixtures';
  const fixtureCursor = source.provider_captures.aggregate([
    {$match: {observed_at: {$lte: cutoff}, 'matches.provider_match_id': {$in: ids}}},
    {$project: {competition: 1, matches: 1}}, {$unwind: '$matches'},
    {$match: {'matches.provider_match_id': {$in: ids}}},
    {$group: {_id: '$matches.provider_match_id', variants: {$addToSet: {
      competition: '$competition', season_id: '$matches.season_id', matchday: '$matches.matchday',
      home_uid: '$matches.home_team_uid', away_uid: '$matches.away_team_uid',
      result: '$matches.result', scheduled_start_at: '$matches.scheduled_start_at'
    }}}}
  ], {allowDiskUse: true, cursor: {batchSize: 50}});
  for (const group of fixtureCursor) {
    const variants = group.variants.map(v => ({...v, season_id: String(v.season_id ?? ''),
      home_uid: String(v.home_uid ?? ''), away_uid: String(v.away_uid ?? ''),
      result: v.result ?? null, scheduled_start_at: v.scheduled_start_at ?? null}));
    print(JSON.stringify({type: 'fixtures', id: group._id, variants}));
    fixtures++;
  }
  print(JSON.stringify({type: 'phase_complete', phase: 'fixtures', records: fixtures}));
  stage = 'prices';
  const priceCursor = source.provider_captures.aggregate([
    {$match: {observed_at: {$lte: cutoff}, 'odds_snapshots.provider_match_id': {$in: ids}}},
    {$project: {competition: 1, odds_snapshots: 1}}, {$unwind: '$odds_snapshots'},
    {$match: {'odds_snapshots.provider_match_id': {$in: ids}}},
    {$group: {_id: '$odds_snapshots.provider_match_id', variants: {$addToSet: {competition: '$competition', prices: '$odds_snapshots.prices'}}}}
  ], {allowDiskUse: true, cursor: {batchSize: 50}});
  for (const group of priceCursor) {
    const variants = new Map();
    let invalid = 0;
    for (const quote of group.variants) {
      const price = Object.fromEntries(['H', 'D', 'A'].map(c => [c, scaled(quote.prices?.[c])]));
      if (Object.keys(quote.prices ?? {}).length !== 3 || Object.values(price).some(v => v === null)) invalid++;
      else {
        const item = {competition: quote.competition ?? null, prices: price};
        variants.set(JSON.stringify(item), item);
      }
    }
    print(JSON.stringify({type: 'prices', id: group._id, variants: [...variants.values()], invalid_quotes: invalid}));
    prices++;
  }
  print(JSON.stringify({type: 'phase_complete', phase: 'prices', records: prices}));
  print(JSON.stringify({type: 'complete', seasons: REQUEST.targets.length, fixtures, prices, request_sha256: REQUEST.sha256,
    completed_at: new Date().toISOString(), applied: false}));
} catch (_) {
  print(JSON.stringify({type: 'error', stage, code: 'production_read_audit_failed'}));
  quit(1);
}
