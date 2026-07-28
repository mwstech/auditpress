# CCD Prompt: AuditPress Phase 4

wordpress.org health enrichment. The last of the three external sources.

Prerequisite: the coverage-transparency PR is merged.

Paste everything below the line into CCD.

---

Phase 3 is merged. The persistent cache backend and the enrichment interface are both proven. This phase is the third and final external source, and it is the one that fetches per plugin at full estate scale, so the caching and politeness work done in Phase 3 gets its real test here.

Branch: `phase-4-wporg-health`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## A naming decision to make first

The spec calls for an `abandoned` flag. That is an inference dressed as a fact, and it violates the facts-not-verdicts rule the whole product rests on. A plugin untouched for three years might be finished rather than abandoned.

Use mechanically defined flag names with documented thresholds instead:

- `not_updated_2y` — last wordpress.org update is more than 730 days ago
- `not_updated_4y` — more than 1460 days ago
- `untested_current_wp` — the plugin's tested-up-to value is more than two minor WordPress versions behind the current release
- `no_wporg_record` — the wordpress.org lookup did not return a plugin record

Every threshold gets stated explicitly in `get_capabilities` so a client knows what the flag means rather than guessing. The model can then call something abandoned if it wants to. That is its job, not ours.

Record this in `docs/DECISIONS.md`.

## Scope

### A. wordpress.org client

`includes/enrichment/class-wporg-client.php`, implementing the existing enrichment interface, using the persistent cache backend from Phase 3.

Endpoint:

```
https://api.wordpress.org/plugins/info/1.2/
  ?action=plugin_information
  &request[slug]={slug}
  &request[fields][sections]=false
```

Fields used: `last_updated`, `tested`, `requires`, `requires_php`, `active_installs`, `rating`, `num_ratings`, `support_threads`, `support_threads_resolved`, `version`.

Cache 24 hours.

This runs one request per installed plugin, which is 45 on the seeded site. Same politeness requirements as Phase 3: identifying `User-Agent`, capped concurrency rather than 45 simultaneous connections, short timeouts, silent degradation, and never re-fetching an unexpired cache entry.

### B. The failed-lookup problem

A non-success response can mean the plugin is closed, was removed, was never on wordpress.org, or is premium or custom. **These are not reliably distinguishable from the API response alone.**

Flag as `no_wporg_record` and stop there. Do not assert `closed_on_wporg` unless the API returns something that unambiguously says so. Verify against the live API what a genuinely closed plugin actually returns before deciding whether that flag can exist at all. If it cannot be determined reliably, drop the flag and say so in the decisions file.

The seeded site has both a closed plugin and a no-record plugin, so both cases are testable.

### C. Flags on `list_plugins` compact rows

Add the flags above to the existing `flags` array. Flags only, no numbers, per the Phase 1 decision.

`list_plugins` must still return correctly with these flags simply absent when wordpress.org is unreachable, with `sources_unavailable` populated. Same guarantee as the `has_vulnerability` flag.

### D. Numbers in detail mode

`detail: true` is capped at 10 rows and currently runs about 4.8 KB. Add the raw figures there: last updated date, tested-up-to, active installs, rating, rating count, support threads, and resolved ratio.

Ten rows with seven added fields should stay well under 20 KB. Measure it and report the number. If it does not, cut fields rather than raising the cap.

### E. Coverage transparency

Apply the same coverage object built for `check_vulnerabilities` to `list_plugins` whenever wordpress.org enrichment is partial. Naming the unchecked slugs matters more than counting them.

### F. Update `get_capabilities`

Document every flag with its exact threshold. A client reading the capabilities document should be able to work out what `not_updated_2y` means without seeing the code.

## Out of scope

No autoload, cron, database, or usage analysis. No `get_plugin_details`. No theme data. No bundling of wordpress.org data into `check_vulnerabilities`.

## Verification checklist

Report back when I can do all of these:

1. Call `list_plugins` on the seeded site and see flags correctly applied across all 45 plugins.
2. Confirm the closed plugin and the no-record plugin are distinguished correctly, or confirm they cannot be and that the flag was dropped accordingly.
3. Read the wall-clock time for a cold-cache full-estate call, and the time for a warm-cache call. Report both.
4. Confirm the second call reads entirely from the persistent option cache with the object-cache drop-in fixture in place.
5. Block outbound HTTP and confirm `list_plugins` returns normally with no wordpress.org flags and `sources_unavailable` populated.
6. Read the byte size of the compact response for 45 plugins and the detail response for 10, and confirm both are under 20 KB.
7. CI passes on the pull request.

Then deploy to the live site. Seven plugins, all real. Report the wall-clock time on a cold cache over public HTTPS, because that is the number a stranger installing this from wordpress.org will actually experience on their first call.
