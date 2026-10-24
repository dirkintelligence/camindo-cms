#!/usr/bin/env bash
# Testinstallation für die CI: baut aus dem Skelett (install/draft) ein Projekt, das den
# Core aus diesem Checkout per path-Repository bezieht, führt den Installer
# nicht-interaktiv aus und lässt danach status, upgrade und die Selbsttests laufen.
#
#   CORE_DIR=…  PROJECT_DIR=…  CAMINDO_DB_HOST=… CAMINDO_DB_NAME=… CAMINDO_DB_USER=…
#   CAMINDO_DB_PASS=… CAMINDO_ADMIN_PASS=…  .github/ci/install-test.sh
#
# Läuft auch lokal (im PHP-Container) mit einer leeren Wegwerf-Datenbank.
set -euo pipefail

CORE_DIR=$(cd "${CORE_DIR:?}" && pwd)
PROJECT_DIR=${PROJECT_DIR:?}

version=$(sed -n "s/^define('CAMINDO_VERSION', '\(.*\)');/\1/p" "$CORE_DIR/Core.php")
echo "::group::Projekt aus dem Skelett anlegen (camindo/cms $version)"
rm -rf "$PROJECT_DIR"
cp -R "$CORE_DIR/install/draft" "$PROJECT_DIR"
rm -f "$PROJECT_DIR/composer-dev.json"
cd "$PROJECT_DIR"
# Core aus dem Checkout statt von Packagist; das Plugin kommt von Packagist
php -r '
    $file = "composer.json";
    $json = json_decode(file_get_contents($file), true);
    $json["repositories"] = [[
        "type" => "path",
        "url" => $argv[1],
        "options" => ["symlink" => false, "versions" => ["camindo/cms" => $argv[2]]],
    ]];
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$CORE_DIR" "$version"
composer install --no-interaction --no-progress
echo "::endgroup::"

echo "::group::Installer"
export CAMINDO_ADMIN_NAME=${CAMINDO_ADMIN_NAME:-admin}
export CAMINDO_ADMIN_EMAIL=${CAMINDO_ADMIN_EMAIL:-admin@example.com}
export CAMINDO_ADMIN_LANG=${CAMINDO_ADMIN_LANG:-en_US}
export CAMINDO_TIMEZONE=${CAMINDO_TIMEZONE:-Europe/Berlin}
export CAMINDO_LOCALE=${CAMINDO_LOCALE:-de_DE}
export CAMINDO_HTTPS=${CAMINDO_HTTPS:-n}
export CAMINDO_BACKEND_DOMAIN=${CAMINDO_BACKEND_DOMAIN:-}
php Core/cron/camindo.php install --no-interaction
test -f var/install.lock
echo "::endgroup::"

echo "::group::status und upgrade"
php Core/cron/camindo.php status
php Core/cron/camindo.php upgrade --no-backup
php Core/cron/camindo.php status | grep -q "everything up to date"
echo "::endgroup::"

echo "::group::Befehlsdefinitionen (template commands)"
# every backend language, every snippet of commands.php resolved (no "#template.cmd_…" left)
out=$(mktemp -d)
php Core/cron/camindo.php template commands --all --out="$out"
php -r '$n = 0; foreach (glob($argv[1] . "/commands.*.json") as $f) { $j = json_decode(file_get_contents($f), true);
    if (!isset($j["commands"]["select"]["params"]["filter"])) { fwrite(STDERR, "$f: invalid\n"); exit(1); }
    if (str_contains(file_get_contents($f), "\"#template.cmd")) { fwrite(STDERR, "$f: unresolved snippet\n"); exit(1); }
    $n++; } echo "$n files ok\n"; exit($n >= 2 ? 0 : 1);' "$out"
rm -rf "$out"
echo "::endgroup::"

echo "::group::Testinstanz anlegen"
php "$CORE_DIR/.github/ci/create-instance.php"
echo "::endgroup::"

echo "::group::Selbsttests"
for test in ${CAMINDO_SELF_TESTS:-check_dateparse check_template_params check_nested_select check_tree_positions check_batch_tree check_include_cache check_webauthn check_record_files check_field_trash check_template_trash}; do
    echo "--- $test"
    php "Core/cron/$test.php"
done
echo "::endgroup::"

echo "::group::composer audit"
composer audit --no-interaction
echo "::endgroup::"
