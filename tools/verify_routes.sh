#!/usr/bin/env bash

set -u

BASE="${1:-https://skillspan.alwaysdata.net}"
BASE="${BASE%/}"

ACCEPT='Accept: application/json'
pass=0
fail=0
missing=()

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
GET    /api/v1/projects/1/team
DELETE /api/v1/projects/1/team/assignments/1
POST   /api/v1/projects/1/team/invitations
GET    /api/v1/team-invitations/1
POST   /api/v1/team-invitations/1/response
PATCH  /api/v1/talent/lists/1
DELETE /api/v1/talent/lists/1
DELETE /api/v1/talent/lists/1/candidates/1
GET    /api/v1/talent/funnel
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
