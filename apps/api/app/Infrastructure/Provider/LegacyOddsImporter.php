<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LegacyOddsImporter
{
    private array $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    public function __construct(private LegacyOddsSource $decoder, private OddsNormalizer $odds, private MongoCollectionStore $store) {}

    public function import(string $path, bool $apply, ?string $sourceFilename = null): array
    {
        $database = config('database.connections.mongodb.database');
        if ($database !== 'kiotrad' && ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', (string) $database)) {
            throw new RuntimeException('Legacy import requires the KioTrad database or an isolated local snapshot.');
        }
        if (! is_file($path) || is_link($path) || filesize($path) > 20971520) {
            throw new RuntimeException('Expected a regular JSON response under 20 MiB.');
        }
        $source = $this->decoder->decode(file_get_contents($path), $sourceFilename ?? basename($path), now('UTC')->toIso8601String());
        $db = DB::connection('mongodb')->getDatabase();
        $hash = $source['provenance']['source_sha256'];
        $base = ['source_sha256' => $hash, 'file' => basename($path), 'competition' => $source['competition'],
            'season_id' => $source['season_id'], 'matchday' => $source['matchday']];
        $saved = $db->legacy_odds_imports->findOne(['_id' => $hash], $this->options);
        if ($saved !== null) {
            return [...$base, 'status' => 'already_imported'];
        }
        $query = ['competition' => $source['competition'], 'season_id' => $source['season_id']];
        $matches = [];
        $known = [];
        foreach ($db->provider_captures->find($query, [...$this->options, 'sort' => ['observed_at' => 1], 'batchSize' => 5]) as $capture) {
            foreach ($capture['matches'] ?? [] as $match) {
                $matches[$match['provider_match_id']] = $match;
            }
            foreach ($capture['odds_snapshots'] ?? [] as $quote) {
                $prices = $quote['prices'] ?? [];
                if (count(array_filter(['H', 'D', 'A'], fn ($o) => preg_match('/^\d{1,4}(?:\.\d{1,4})?$/D', (string) ($prices[$o] ?? '')) && (float) $prices[$o] > 1 && (float) $prices[$o] <= 1000)) === 3) {
                    $known[$quote['provider_match_id']][] = $prices;
                }
            }
        }
        if (count(array_filter($matches, fn ($m) => ($m['matchday'] ?? null) === $source['matchday'])) !== 8) {
            return [...$base, 'status' => 'missing_round_fixtures'];
        }
        $prices = $this->odds->normalize($source['feed'], array_values($matches),
            config('provider.competitions.'.$source['competition'].'.tournament_uid'), $source['matchday']);
        foreach ($prices as $quote) {
            foreach ($quote['prices'] as $price) {
                if (! preg_match('/^\d{1,4}(?:\.\d{1,4})?$/D', $price) || (float) $price > 1000) {
                    throw new RuntimeException('Legacy odds exceed supported precision or range.');
                }
            }
        }
        $missing = [];
        $conflicts = [];
        foreach ($prices as $quote) {
            $existing = $known[$quote['provider_match_id']] ?? [];
            if ($existing === []) {
                $missing[] = $quote;
            } elseif (! array_filter($existing, fn ($old) => $this->equalPrices($old, $quote['prices']))) {
                $conflicts[] = $quote['provider_match_id'];
            }
        }
        $result = [...$base, 'status' => $prices === [] ? 'no_usable_odds' : ($missing === [] ? 'no_missing_prices' : 'recoverable'),
            'valid_price_matches' => count($prices), 'missing_price_matches' => count($missing), 'conflicting_match_ids' => $conflicts];
        if (! $apply || $prices === []) {
            return $result;
        }
        // Archive exact source bytes before saving derived records. Unknown request/receipt stay null.
        $source['provenance']['accepted_match_ids'] = array_column($missing, 'provider_match_id');
        $envelope = json_encode($this->decoder->envelope($source), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $rawPath = 'provider-evidence/raw/'.now('UTC')->format('Y/m/d').'/'.hash('sha256', $envelope).'.json.gz';
        $compressed = gzencode($envelope, 6);
        if ($compressed === false || ! Storage::disk('local')->put($rawPath, $compressed)) {
            throw new RuntimeException('Legacy evidence archive failed; import not recorded.');
        }
        $captureId = null;
        if ($missing !== []) {
            $captureId = $this->store->save([...$query, 'observed_at' => $source['provenance']['imported_at'],
                'scope' => 'legacy_odds_import', 'matches' => array_values($matches), 'odds_snapshots' => $missing,
                'odds_matchday' => $source['matchday'], 'provenance' => $source['provenance']]);
        }
        // Also invalidate on rerun after an interrupted save, when matching quotes already exist.
        $db->season_checkpoints->updateOne(['_id' => $source['competition'].':'.$source['season_id']],
            ['$inc' => ['odds_evidence_revision' => 1]]);
        $db->legacy_odds_imports->updateOne(['_id' => $hash], ['$setOnInsert' => [...$result,
            'raw_path' => $rawPath, 'capture_id' => $captureId, 'provenance' => $source['provenance']]], ['upsert' => true]);

        return [...$result, 'status' => 'imported', 'raw_path' => $rawPath];
    }

    private function equalPrices(array $a, array $b): bool
    {
        foreach (['H', 'D', 'A'] as $outcome) {
            if ((float) ($a[$outcome] ?? 0) !== (float) ($b[$outcome] ?? 0)) {
                return false;
            }
        }

        return true;
    }
}
