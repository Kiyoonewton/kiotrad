"""Per-source evidence coverage; absence is never classified as market blockage."""
from collections import Counter, defaultdict
from decimal import Decimal, InvalidOperation

LEAGUES = {"31867": "virtual-football", "34616": "virtual-bundesliga", "14149": "virtual-euro"}


class Coverage:
    def __init__(self):
        self.rows = defaultdict(lambda: {"odds": {}, "fixtures": set(), "days": set(),
                                       "empty_market_responses": Counter()})

    def collect(self, payload, route):
        for doc in payload.get("doc", []):
            if not isinstance(doc, dict) or not isinstance(doc.get("data"), dict):
                continue
            data = doc["data"]
            if route:
                season, day = route.group(1), int(route.group(2))
                if not 1 <= day <= 30:
                    continue
                for event in data.get("odds", []):
                    league = LEAGUES.get(str(event.get("tournament_id", "")).split(":")[-1])
                    identity = event.get("id")
                    if not league or not identity:
                        continue
                    row = self.rows[league, season]
                    row["days"].add(day)
                    evidence = row["odds"].setdefault((day, str(identity)), {"prices": set(), "results": set()})
                    markets = [m for m in event.get("market", []) if str(m.get("id")) == "1"]
                    if not markets or all(not m.get("outcome") for m in markets):
                        row["empty_market_responses"][day] += 1
                    for market in markets:
                        outcomes = market.get("outcome", [])
                        prices = {}
                        winners = []
                        for outcome in outcomes:
                            key = str(outcome.get("id"))
                            if key not in ("1", "2", "3"):
                                continue
                            try:
                                value = Decimal(str(outcome.get("odds", "")))
                                if value.is_finite() and value > 1:
                                    prices[key] = value
                            except InvalidOperation:
                                pass
                            if str(outcome.get("result")) == "1":
                                winners.append(key)
                        if len(prices) == 3:
                            evidence["prices"].add(tuple(prices[k] for k in ("1", "2", "3")))
                        if len(winners) == 1:
                            evidence["results"].add(winners[0])
            if doc.get("event") == "stats_season_fixtures":
                for match in data.get("matches", []):
                    league = LEAGUES.get(str(match.get("_utid", data.get("_utid", ""))))
                    season = str(match.get("_seasonid", data.get("_id", "")))
                    day, identity = match.get("round"), match.get("_id")
                    if league and season.isdigit() and type(day) is int and 1 <= day <= 30 and identity is not None:
                        self.rows[league, season]["fixtures"].add((day, "vf:match:" + str(identity)))

    def summary(self, key):
        row = self.rows.get(key)
        if row is None:
            return {"present": False, "odds_match_ids": 0, "fixture_match_ids": 0}
        odds = row["odds"]
        priced = {k for k, v in odds.items() if v["prices"]}
        settled = {k for k, v in odds.items() if len(v["results"]) == 1}
        complete_days = [day for day in range(1, 31)
                         if sum(k[0] == day for k in odds) == 8
                         and sum(k[0] == day for k in priced & settled) == 8]
        return {"present": True, "odds_match_ids": len(odds), "fixture_match_ids": len(row["fixtures"]),
                "priced_match_ids": len(priced), "odds_result_match_ids": len(settled),
                "conflicting_result_match_ids": sum(len(v["results"]) > 1 for v in odds.values()),
                "multiple_price_match_ids": sum(len(v["prices"]) > 1 for v in odds.values()),
                "complete_odds_result_days": complete_days,
                "missing_or_partial_days": [d for d in range(1, 31) if d not in complete_days],
                "empty_market_event_responses_by_day": dict(sorted(row["empty_market_responses"].items())),
                "coverage": "all_days_priced_with_results" if len(complete_days) == 30 else "partial",
                "interruption_cause": "not_established"}


def compare_sources(legacy, kiotrad):
    rows = []
    for key in sorted(set(legacy.rows) | set(kiotrad.rows), key=lambda k: (k[0], int(k[1]))):
        old, current = legacy.summary(key), kiotrad.summary(key)
        a = legacy.rows.get(key, {}).get("odds", {})
        b = kiotrad.rows.get(key, {}).get("odds", {})
        rows.append({"competition": key[0], "season_id": key[1], "legacy": old, "kiotrad": current,
                     "source_presence": "both" if old["present"] and current["present"] else "legacy_only" if old["present"] else "kiotrad_only",
                     "legacy_priced_ids_without_kiotrad_price": sum(bool(v["prices"]) and not b.get(k, {}).get("prices") for k, v in a.items()),
                     "shared_odds_match_ids": len(set(a) & set(b))})
    return rows
