#!/usr/bin/env bash
# ─────────────────────────────────────────────────────
#   project · Stacks
#   module  · web/report/report.sh — tell a human what exactly failed in a Web Stack run
#   author  · Jiří "8B1T" Lhotský
# ─────────────────────────────────────────────────────
# GitHub's own failure e-mail says only "All jobs have failed". This posts the
# failed steps and their FAIL / error lines where an e-mail follows:
#   pull request         → comment on it
#   main / nightly       → one open issue labelled "kontrola", closed on the next green run on main
#   other branch         → comment on its PR, else the same issue
# Env: GH_TOKEN, RESULT (failure|success), PR_NUMBER (pull_request only).

set -euo pipefail

repo=$GITHUB_REPOSITORY
run_url="$GITHUB_SERVER_URL/$repo/actions/runs/$GITHUB_RUN_ID"
label=kontrola
title="Kontrola webu selhala"

open_issue() {
  gh issue list --repo "$repo" --label "$label" --state open --json number,title \
    --jq "[.[]|select(.title==\"$title\")][0].number // empty"
}

# ── SUCCESS ────────────────────────────────────────────

if [ "$RESULT" != failure ]; then
  if [ "$GITHUB_EVENT_NAME" != pull_request ] && [ "$GITHUB_REF_NAME" = main ]; then
    issue=$(open_issue)
    if [ -n "$issue" ]; then
      gh issue close "$issue" --repo "$repo" --comment "Kontrola na \`main\` znovu prošla. Běh: $run_url"
      echo "closed issue #$issue"
    fi
  fi
  exit 0
fi

# ── WHAT FAILED ────────────────────────────────────────

report=$(mktemp)
{
  echo "**Kontrola webu selhala** · ${GITHUB_EVENT_NAME} na \`${GITHUB_REF_NAME}\` · $(TZ=Europe/Prague date '+%-d. %-m. %H:%M')"
  echo
  gh api "repos/$repo/actions/runs/$GITHUB_RUN_ID/jobs" \
    --jq '.jobs[]|select(.conclusion=="failure")|"\(.id)\t\(.name)\t\([.steps[]|select(.conclusion=="failure")|.name]|join(", "))"' |
  while IFS=$'\t' read -r id name steps; do
    echo "- **$name** → krok: ${steps:-?}"
    echo
    echo '```'
    # a finished job's log can lag behind its conclusion; retry, then say so
    log=$(mktemp)
    for attempt in 1 2 3 4 5 6; do
      gh api --allow-escape-sequences "repos/$repo/actions/jobs/$id/logs" > "$log" 2>&1 && [ -s "$log" ] && grep -q 'Z ' "$log" && break
      sleep 10
    done
    # our checks print "FAIL …", the runner "##[error]…"; timestamps cut off
    lines=$(sed -E 's/\x1b\[[0-9;]*m//g; s/^[^ ]*[0-9]Z //' "$log" |
      grep -E '^(FAIL |##\[error\]|::error)' | grep -v 'Process completed with exit code' |
      sed -E 's/^##\[error\]//' | head -30 || true)
    if [ -n "$lines" ]; then
      echo "$lines"
    else
      echo "(řádky s chybou se z logu nepodařilo vyčíst: $(head -c 200 "$log" | tr '\n' ' '))"
    fi
    echo '```'
    echo
  done
  echo "Běh: $run_url"
} > "$report"
cat "$report"

# ── WHERE IT GOES ──────────────────────────────────────

pr=${PR_NUMBER:-}
if [ -z "$pr" ] && [ "$GITHUB_REF_NAME" != main ]; then
  pr=$(gh pr list --repo "$repo" --head "$GITHUB_REF_NAME" --state open --json number --jq '.[0].number // empty')
fi

if [ -n "$pr" ]; then
  gh pr comment "$pr" --repo "$repo" --body-file "$report"
  exit 0
fi

issue=$(open_issue)
if [ -n "$issue" ]; then
  gh issue comment "$issue" --repo "$repo" --body-file "$report"
else
  gh label create "$label" --repo "$repo" --color fbca04 --description "Sdílená kontrola webu selhala (Stacks)" --force
  gh issue create --repo "$repo" --label "$label" --title "$title" --body-file "$report"
fi
