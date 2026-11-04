#!/usr/bin/env bash
#
# Route-presence check for a deployed SkillSpan API.
#
#   bash tools/verify_routes.sh                       # the alwaysdata host
#   bash tools/verify_routes.sh http://127.0.0.1:8000
#
# Why this exists: a route can be missing on the host for two very different
# reasons — the code was never pulled, or the code is there but a stale route
# cache is serving the old table. Both look identical from the browser (404),
# and both are invisible in a deploy log that says "Nothing to migrate".
#
# The signal is the status code of an UNAUTHENTICATED request:
#   401 / 403  the route exists and its middleware refused us  -> present
#   404        no such route                                    -> missing
# That is why every probe sends Accept: application/json — without it a guarded
# route answers 302 to a login page instead of 401, and the check would read
# "present" for a route that does not exist.
#
# It only reads. Nothing here writes, and no token is needed.

set -u

BASE="${1:-https://skillspan.alwaysdata.net}"
BASE="${BASE%/}"

ACCEPT='Accept: application/json'
pass=0
fail=0
missing=()

# --- health and a control route -------------------------------------------
# The control matters: if a route that has existed for months also answers 404,
# the problem is the deployment or the host, not this batch of routes, and every
# line below would be a false alarm.

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/up")
if [ "$code" = "200" ]; then
  echo "[ok]    /up -> 200"
  pass=$((pass + 1))
else
  echo "[FAIL]  /up -> $code (host down, or the app is not booting)"
  fail=$((fail + 1))
fi

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H "$ACCEPT" "$BASE/api/v1/profile/me")
if [ "$code" = "401" ] || [ "$code" = "403" ]; then
  echo "[ok]    control GET /api/v1/profile/me -> $code"
  pass=$((pass + 1))
else
  echo "[FAIL]  control GET /api/v1/profile/me -> $code (expected 401)"
  echo "        If this is 404 the whole deployment is behind, not just the new routes."
  fail=$((fail + 1))
fi

# --- the routes ------------------------------------------------------------
# One per line: METHOD PATH. Add a batch here as it lands; the script is meant
# to be the post-deploy checklist, not a one-off.

ROUTES=$(cat <<'LIST'
GET    /api/v1/company/projects
GET    /api/v1/company/projects/1
GET    /api/v1/applications/1
GET    /api/v1/talent/lists
GET    /api/v1/talent/lists/1
GET    /api/v1/talent/candidates/1/notes
POST   /api/v1/projects/1/clone
POST   /api/v1/projects/1/transitions
POST   /api/v1/projects/1/start
POST   /api/v1/projects/1/team/assignments
POST   /api/v1/applications/1/decision
POST   /api/v1/talent/compare
POST   /api/v1/talent/lists
POST   /api/v1/talent/lists/1/candidates
POST   /api/v1/talent/candidates/1/notes
POST   /api/v1/talent/candidates/1/contact
POST   /api/v1/company/profile/logo
DELETE /api/v1/company/profile/logo
LIST
)

echo
while read -r method path; do
  [ -z "${method:-}" ] && continue

  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X "$method" -H "$ACCEPT" "$BASE$path")

  case "$code" in
    401|403)
      printf '[ok]    %-6s %-46s %s\n' "$method" "$path" "$code"
      pass=$((pass + 1))
      ;;
    404)
      printf '[MISS]  %-6s %-46s %s\n' "$method" "$path" "$code"
      fail=$((fail + 1))
      missing+=("$method $path")
      ;;
    *)
      # 405 would mean the path exists under a different verb — still a
      # contract mismatch, so it is counted as a failure.
      printf '[WARN]  %-6s %-46s %s\n' "$method" "$path" "$code"
      fail=$((fail + 1))
      missing+=("$method $path")
      ;;
  esac
done <<< "$ROUTES"

echo
echo "present $pass / problems $fail"

if [ "$fail" -gt 0 ]; then
  echo
  echo "If these are 404 while the control route is 401, the host has the old code:"
  echo "  cd ~/www/skillspan && git pull && php artisan migrate --force \\"
  echo "    && php artisan storage:link && php artisan optimize:clear && php artisan config:cache"
  echo
  echo "Then run: php artisan route:list --path=api/v1 | grep <segment>"
  echo "If artisan sees the route and curl still 404s, it is the route cache, not the code."
  exit 1
fi

exit 0
