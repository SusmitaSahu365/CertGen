#!/usr/bin/env bash
# End-to-end smoke test: starts PHP's built-in server and walks through the whole workflow with curl.
#   bash tests/smoke.sh
set -u
cd "$(dirname "$0")/.."
PORT=${PORT:-8765}
TMP=$(mktemp -d)
export DB_PATH="$TMP/smoke.sqlite"
php -S 127.0.0.1:$PORT -t public >"$TMP/server.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1
B=http://127.0.0.1:$PORT
J="$TMP/jar"
pass=0; fail=0
ok()  { echo "  ok   $1"; pass=$((pass+1)); }
bad() { echo "  FAIL $1"; fail=$((fail+1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
csrf() { curl -s -b $J -c $J "$B/$1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code() { curl -s -o /dev/null -w '%{http_code}' -b $J -c $J "$@"; }

echo "Auth"
check "unauthenticated dashboard redirects" '[ "$(curl -s -o /dev/null -w %{http_code} $B/index.php)" = 302 ]'
T=$(csrf setup.php)
check "setup page shows a CSRF token" '[ -n "$T" ]'
check "setup rejects bad CSRF" '[ "$(code -X POST $B/setup.php -d username=admin -d password=password123 -d password2=password123 -d _csrf=nope)" = 419 ]'
check "setup rejects short password" 'curl -s -b $J -c $J -X POST $B/setup.php -d "_csrf=$T" -d username=admin -d password=short -d password2=short | grep -q "at least 8"'
code -X POST $B/setup.php -d "_csrf=$T" -d username=admin -d password='Correct-Horse-9' -d password2='Correct-Horse-9' -d org_name='Demo Institute' >/dev/null
check "dashboard reachable after setup" 'curl -s -b $J $B/index.php | grep -q "Dashboard"'
check "setup closed once a user exists" '[ "$(curl -s -o /dev/null -w %{http_code} $B/setup.php)" = 302 ]'
check "templates installed by setup" 'curl -s -b $J $B/templates.php | grep -q "Leaving Certificate" && curl -s -b $J $B/templates.php | grep -q "Bonafide Certificate"'

echo "Login safety"
rm -f $J
T=$(csrf login.php)
check "wrong password refused" 'curl -s -b $J -c $J -X POST $B/login.php -d "_csrf=$T" -d username=admin -d password=wrong | grep -q "Incorrect username or password"'
check "SQL-injection style username refused" 'curl -s -b $J -c $J -X POST $B/login.php -d "_csrf=$T" --data-urlencode "username=admin'"'"' OR 1=1 --" -d password=x | grep -q "Incorrect"'
for i in 1 2 3 4 5; do curl -s -b $J -c $J -X POST $B/login.php -d "_csrf=$T" -d username=admin -d password=bad$i >/dev/null; done
check "lock-out after repeated failures" 'curl -s -b $J -c $J -X POST $B/login.php -d "_csrf=$T" -d username=admin -d password="Correct-Horse-9" | grep -q "Too many failed"'
php -r 'putenv("DB_PATH='"$DB_PATH"'"); require "src/bootstrap.php"; CertGen\Db::run("DELETE FROM login_attempts");'
rm -f $J; T=$(csrf login.php)
code -X POST $B/login.php -d "_csrf=$T" -d username=admin -d password='Correct-Horse-9' >/dev/null
check "correct password signs in" 'curl -s -b $J $B/index.php | grep -q "Dashboard"'

echo "Import workflow"
T=$(csrf batch_new.php)
TPL=$(curl -s -b $J $B/batch_new.php | grep -o '<option value="[0-9]*"[^>]*>Bonafide' | grep -o '[0-9]*' | head -1)
LOC=$(curl -s -b $J -c $J -D - -o /dev/null -X POST $B/batch_new.php -F "_csrf=$T" -F "template=$TPL" -F "name=Smoke batch" -F "sheet=@samples/bonafide_sample.xlsx" | grep -i '^location' | tr -d '\r' | awk '{print $2}')
check "upload redirects to column mapping" '[[ "$LOC" == import.php?t=* ]]'
check "bad file type refused" 'curl -s -b $J -c $J $B/batch_new.php -F "_csrf=$T" -F "template=$TPL" -F "name=x" -F "sheet=@composer.json;filename=evil.php" -L | grep -q "Excel (.xlsx, .xls) or CSV"'
PAGE=$(curl -s -b $J -c $J "$B/$LOC")
check "mapping page auto-matched Full Name" 'echo "$PAGE" | grep -q "selected>Full Name"'
T=$(echo "$PAGE" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
MAPARGS=$(php -r 'putenv("DB_PATH='"$DB_PATH"'"); require "src/bootstrap.php"; $t=CertGen\CertTemplate::find('"$TPL"'); $h=["Full Name"=>"full_name","Gender (M/F)"=>"gender","Enrollment No."=>"enrollment_no","Roll No."=>"roll_no","Exam Seat No."=>"seat_no","Course"=>"course","Year of Study"=>"year_of_study","Academic Year"=>"academic_year","Date of Birth"=>"date_of_birth","Purpose"=>"purpose"]; $i=0; foreach($h as $k){echo "-d map[".$i++."]=$k ";}')
BLOC=$(curl -s -b $J -c $J -D - -o /dev/null -X POST "$B/$LOC" -d "_csrf=$T" $MAPARGS | grep -i '^location' | tr -d '\r' | awk '{print $2}')
check "import redirects to the new batch" '[[ "$BLOC" == batch.php?id=* ]]'
BP=$(curl -s -b $J -c $J "$B/$BLOC")
check "batch shows 12 records" 'echo "$BP" | grep -q "12 records"'
check "search filters records" 'curl -s -b $J "$B/$BLOC&q=Diya" | grep -q "Diya" && ! curl -s -b $J "$B/$BLOC&q=Diya" | grep -q "Aarav"'
check "search treats % literally (no wildcard)" 'curl -s -b $J "$B/$BLOC&q=%25" | grep -q "No records match"'

echo "Generate"
T=$(echo "$BP" | grep -o 'name="_csrf" value="[^"]*"' | tail -1 | sed 's/.*value="//;s/"//')
BID=${BLOC#batch.php?id=}
curl -s -b $J -X POST $B/generate.php -d "_csrf=$T" -d batch=$BID -d scope=all -d issue_date=2026-03-01 -d serial_start=100 -d mode=download -o $TMP/out.pdf
check "combined PDF is a real PDF" 'head -c 4 $TMP/out.pdf | grep -q "%PDF"'
check "PDF has one page per record (12)" '[ "$(pdfinfo $TMP/out.pdf 2>/dev/null | awk "/^Pages/{print \$2}")" = 12 ]'
check "PDF contains record data" 'pdftotext $TMP/out.pdf - | grep -q "AARAV SHARMA"'
check "issue date applied" 'pdftotext $TMP/out.pdf - | grep -q "01/03/2026"'
curl -s -b $J -X POST $B/generate.php -d "_csrf=$T" -d batch=$BID -d scope=all -d issue_date=2026-03-01 -d serial_start=1 -d mode=zip -o $TMP/out.zip
check "ZIP has 12 PDFs" '[ "$(unzip -l $TMP/out.zip | grep -c "\.pdf")" = 12 ]'
check "selected scope with nothing ticked is refused" '[ "$(code -X POST $B/generate.php -d "_csrf=$T" -d batch=$BID -d scope=selected -d mode=view)" = 302 ]'
check "generate refuses bad CSRF" '[ "$(code -X POST $B/generate.php -d _csrf=bad -d batch=$BID -d scope=all)" = 419 ]'
check "issue log updated" 'curl -s -b $J $B/index.php | grep -q "12 ×"'

echo "Admin pages"
check "settings page loads" 'curl -s -b $J $B/settings.php | grep -q "Institution"'
check "template editor loads" 'curl -s -b $J "$B/template_edit.php?id=$TPL" | grep -q "Page layout"'
check "sample sheet downloads (xlsx)" 'curl -s -b $J "$B/template_sample.php?id=$TPL&format=xlsx" | head -c 2 | grep -q PK'
check "sample sheet downloads (csv)" 'curl -s -b $J "$B/template_sample.php?id=$TPL&format=csv" | grep -q "Full Name"'
check "help page loads" 'curl -s -b $J $B/help.php | grep -q "Placeholders"'

echo "Custom template, records, roles"
T=$(csrf template_edit.php)
EDIT=(-d "_csrf=$T" -d "name=Course Completion" -d "f_label[]=Student" -d "f_key[]=" -d "f_type[]=text" -d "f_req[]=1" -d "f_label[]=Completed On" -d "f_key[]=done" -d "f_type[]=date" -d "f_req[]=0" \
      -d "b_type[]=heading" -d "b_a[]=CERTIFICATE OF COMPLETION" -d "b_b[]=" -d "b_align[]=C" -d "b_size[]=22" -d "b_bold[]=1" \
      -d "b_type[]=text" --data-urlencode "b_a[]=Awarded to **{{student|upper}}** on {{done}}." -d "b_b[]=" -d "b_align[]=C" -d "b_size[]=14" -d "b_bold[]=0" \
      -d o_font=Helvetica -d o_base_size=12 -d o_show_header=1 -d o_signatures=Director)
NLOC=$(curl -s -b $J -c $J -D - -o /dev/null -X POST $B/template_edit.php "${EDIT[@]}" | grep -i '^location' | tr -d '\r' | awk '{print $2}')
check "new template saved via editor form" '[[ "$NLOC" == template_edit.php?id=* ]]'
check "template listed" 'curl -s -b $J $B/templates.php | grep -q "Course Completion"'
curl -s -b $J -X POST $B/template_preview.php "${EDIT[@]}" -o $TMP/prev.pdf
check "live preview returns a PDF with sample text" 'pdftotext $TMP/prev.pdf - | grep -q "CERTIFICATE OF COMPLETION"'
check "editor rejects a template with no fields" 'curl -s -b $J -X POST $B/template_edit.php -d "_csrf=$T" -d name=Empty -d "b_type[]=heading" -d "b_a[]=x" | grep -q "Add at least one field"'
NID=${NLOC#template_edit.php?id=}
T=$(csrf batch_new.php)
printf 'Student,Completed On\nAsha Rao,15/08/2026\nBad Row,31/02/2026\n,01/01/2026\n' > $TMP/c.csv
L2=$(curl -s -b $J -c $J -D - -o /dev/null -X POST $B/batch_new.php -F "_csrf=$T" -F "template=$NID" -F "name=CSV batch" -F "sheet=@$TMP/c.csv" | grep -i '^location' | tr -d '\r' | awk '{print $2}')
P2=$(curl -s -b $J -c $J "$B/$L2"); T=$(echo "$P2" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
BL2=$(curl -s -b $J -c $J -D - -o /dev/null -X POST "$B/$L2" -d "_csrf=$T" -d "map[0]=student" -d "map[1]=done" | grep -i '^location' | tr -d '\r' | awk '{print $2}')
BP2=$(curl -s -b $J -c $J "$B/$BL2")
check "CSV import keeps the 1 valid row" 'echo "$BP2" | grep -q "1 record"'
check "skipped rows are reported" 'echo "$BP2" | grep -q "2 row(s) were skipped"'
BID2=${BL2#batch.php?id=}
T=$(echo "$BP2" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b $J -c $J -o /dev/null -X POST "$B/record_edit.php" -d "_csrf=$T" -d batch=$BID2 -d "f[student]=Ravi Menon" -d "f[done]=2026-09-01"
check "record added by hand" 'curl -s -b $J "$B/batch.php?id=$BID2" | grep -q "Ravi Menon"'
check "invalid date rejected on edit" 'curl -s -b $J -X POST "$B/record_edit.php" -d "_csrf=$T" -d batch=$BID2 -d "f[student]=X" -d "f[done]=nonsense" | grep -q "not a valid date"'
T=$(csrf users.php)
curl -s -b $J -c $J -o /dev/null -X POST $B/users.php -d "_csrf=$T" -d action=add -d username=clerk -d password='Clerk-Pass-77' -d role=staff
check "admin can create a staff user" 'curl -s -b $J $B/users.php | grep -q clerk'
check "cannot delete the only administrator" 'curl -s -b $J -c $J -L $B/users.php -d "_csrf=$T" -d action=delete -d id=1 | grep -q "at least one administrator"'
J2="$TMP/jar2"; T2=$(curl -s -c $J2 $B/login.php | grep -o 'name="_csrf" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -s -b $J2 -c $J2 -o /dev/null -X POST $B/login.php -d "_csrf=$T2" -d username=clerk -d password='Clerk-Pass-77'
check "staff can open batches" '[ "$(curl -s -o /dev/null -w %{http_code} -b $J2 $B/batches.php)" = 200 ]'
check "staff blocked from users page" '[ "$(curl -s -o /dev/null -w %{http_code} -b $J2 $B/users.php)" = 403 ]'
check "staff blocked from template editor" '[ "$(curl -s -o /dev/null -w %{http_code} -b $J2 $B/template_edit.php)" = 403 ]'
check "staff blocked from settings" '[ "$(curl -s -o /dev/null -w %{http_code} -b $J2 $B/settings.php)" = 403 ]'
check "template with batches cannot be deleted" 'curl -s -b $J -c $J -L $B/templates.php -d "_csrf=$T" -d action=delete -d id=$TPL | grep -q "still has batches"'
check "deleting a batch removes it" 'curl -s -b $J -c $J $B/batches.php -d "_csrf=$T" -d action=delete -d id=$BID2 -L | grep -q "and its records were deleted" && ! curl -s -b $J $B/batches.php | grep -q "CSV batch"'
check "logout ends the session" 'curl -s -b $J -c $J -X POST $B/logout.php -d "_csrf=$T" -o /dev/null; [ "$(curl -s -o /dev/null -w %{http_code} -b $J $B/index.php)" = 302 ]'

echo "Hardening"
check "source dirs are not web-served" '[ "$(curl -s -o /dev/null -w %{http_code} $B/../src/Db.php)" != 200 ]'
check "security headers set" 'curl -sI $B/login.php | grep -qi "x-frame-options: DENY"'
check "no PHP errors in server log" '! grep -qiE "warning|fatal|notice|deprecated" $TMP/server.log'

echo; echo "passed: $pass  failed: $fail"
[ $fail -eq 0 ]
