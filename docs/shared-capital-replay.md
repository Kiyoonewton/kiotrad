# Shared-capital simulation

This is a separate versioned research replay. It does not change the independent-run dashboard or execute bookmaker trades.

Run against the local snapshot:

```sh
docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  exec -T dashboard-worker php -d memory_limit=512M artisan strategy:replay-shared --capital=50000 \
  > /tmp/kiotrad-shared-capital.json
```

The command assesses stored qualified seasons across all three leagues and both champion strategies. It refreshes exact-venue historical minima incrementally using the existing baseline builder. Only earlier-season minima size a candidate. Missing fixture history is reported rather than replaced with assumed prices. Raw provider evidence is unchanged; replay output is disposable and cached.

## Selection and money

- One active run across every league and strategy.
- At the same scheduled entry instant, calculate each candidate's existing preview using the same current capital. Pick the highest estimated total stake cost **before** its double-cost allowance. Record both estimate and reason. Exact amount ties use the stable `competition:season:strategy` identifier, ascending.
- Freeze that selected run's profit per attempt and double-cost allowance. Actual recorded odds determine actual stakes, rounded upwards to whole naira with a ₦100 minimum stake.
- Never exceed the frozen allowance. Actual prices may be below historical minima; minima are observations, not guaranteed bounds.
- Other entries while active are recorded as skipped overlaps. They add no stakes, payout or profit. They are not re-entered after their original start has passed.
- Release on the recorded winning result confirmation, fourth loss confirmation, season end, or allowance stop. A win confirmed **strictly before** another entry permits that entry with updated capital. Identical confirmation and entry instants conservatively clash.
- Missing results, prices or unresolved market evidence retain the active reservation; they cannot manufacture a release.

`balance_minor` is the settled shared balance; pending stake is listed on the active run and is not yet deducted from this realized-equity series. All money uses integer kobo. `equity` contains only accepted, completed runs. Skipped rows remain in `runs`. Missing fixture/schedule candidates appear in `excluded`.

## Timing limit

`timing_basis=recorded_result_confirmation_conservative` is intentional. Kickoff is not settlement. Old archive captures often confirm a result much later than the actual match finished. They can prove the outcome but cannot prove exactly when capital was reusable; such delayed evidence can conservatively block later runs, including subsequent recovery attempts. The replay reports `unresolved_confirmation_timing` rather than assuming a match duration or assigning backdated confirmation times. This limitation must remain visible before wiring these figures into the main balance graph.

Next interface milestone: display shared-capital equity, accepted/skipped overlap markers, an initial-capital input, and timing-quality indicators. The existing independent chart remains labeled independently until that integration is complete.
