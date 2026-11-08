#!/usr/bin/env bash
# Smoke-test the mobile API against a running server with a Doctor account.
#
#   ./smoke_test.sh https://skylark.duobytes.in doctor@example.com
#
# Read-only by default: login, me, masters, pull, logout.
# Set WRITE_VISIT_ID=<visit_details id> to also save a real test prescription on that OPD visit
# (it will appear in the web app; delete it there afterwards).
set -euo pipefail

BASE="${1:?server url}"
EMAIL="${2:?doctor email}"
read -r -s -p "Password for $EMAIL: " PASSWORD; echo
API="${BASE%/}/api/v1"
command -v jq >/dev/null || { echo "Needs jq (brew install jq)"; exit 1; }

step() { printf '\n== %s\n' "$1"; }

step "login"
LOGIN=$(curl -sS -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "$(jq -n --arg e "$EMAIL" --arg p "$PASSWORD" '{email:$e,password:$p,device_id:"smoke-test",device_name:"curl",platform:"cli",app_version:"smoke"}')")
TOKEN=$(echo "$LOGIN" | jq -r '.token // empty')
[ -n "$TOKEN" ] || { echo "$LOGIN" | jq .; exit 1; }
echo "$LOGIN" | jq '{staff: .staff | {id, name, role, modules}, hospital: .hospital.hospital_name}'
H=(-H "X-Auth-Token: $TOKEN" -H 'Content-Type: application/json')

step "me"
curl -sS "${H[@]}" "$API/auth/me" | jq '{api_version, role: .staff.role}'

step "masters"
curl -sS "${H[@]}" "$API/sync/masters" | jq '{medicines: (.medicines|length), dosages: (.dosages|length), intervals: (.intervals|length), durations: (.durations|length), pathology: (.pathology|length), radiology: (.radiology|length), icd: (.icd|length), templates: (.templates|length)}'

step "pull (full)"
PULL=$(curl -sS -X POST "${H[@]}" "$API/sync/pull" -d '{"since":null,"known_patient_ids":[],"pinned_patient_ids":[]}')
echo "$PULL" | jq '{cursor, patients: (.scope_patient_ids|length), appointments: (.appointments|length), data: (.data | map_values(length))}'
CURSOR=$(echo "$PULL" | jq -r .cursor)

step "pull (delta since $CURSOR)"
curl -sS -X POST "${H[@]}" "$API/sync/pull" -d "$(echo "$PULL" | jq -c --arg c "$CURSOR" '{since:$c, known_patient_ids:.scope_patient_ids, pinned_patient_ids:[]}')" \
  | jq '{cursor, data: (.data | map_values(length))}'

if [ -n "${WRITE_VISIT_ID:-}" ]; then
  step "push prescription on visit $WRITE_VISIT_ID"
  MASTERS=$(curl -sS "${H[@]}" "$API/sync/masters")
  MED=$(echo "$MASTERS" | jq '.medicines[0]')
  CAT=$(echo "$MED" | jq '.category_id')
  DOSE=$(echo "$MASTERS" | jq --argjson c "$CAT" '[.dosages[] | select(.category_id==$c)][0].id // .dosages[0].id')
  INT=$(echo "$MASTERS" | jq '.intervals[0].id')
  OP="smoke-$(date +%s)"
  BODY=$(jq -n --arg op "$OP" --argjson v "$WRITE_VISIT_ID" --argjson m "$(echo "$MED" | jq .id)" --argjson d "$DOSE" --argjson i "$INT" --arg date "$(date +%F)" \
    '{ops:[{op_id:$op,type:"prescription.save",payload:{kind:"opd",case_ref_id:$v,date:$date,footer_note:"Smoke test - delete me",items:[{medicine_id:$m,dosage_id:$d,interval_id:$i,instruction:"After food"}],pathology_ids:[],radiology_ids:[],icd_ids:[]}}]}')
  curl -sS -X POST "${H[@]}" "$API/sync/push" -d "$BODY" | jq '.results[0] | {status, server_id, message}'
  step "replay same op (must return the same server_id, not a duplicate)"
  curl -sS -X POST "${H[@]}" "$API/sync/push" -d "$BODY" | jq '.results[0] | {status, server_id}'
fi

step "logout"
curl -sS -X POST "${H[@]}" "$API/auth/logout" -d '{}' | jq .
step "token is now rejected (expect 401 token_invalid)"
curl -sS "${H[@]}" "$API/auth/me" | jq .error
