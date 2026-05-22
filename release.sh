#!/bin/bash
# LRob - Email Toolkit - Release Builder
# Lints, regenerates translations, scans for dead CSS, prints build stats, zips.

set -e

SCRIPT_DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_DIR_NAME="$(basename "$SCRIPT_DIR")"
PLUGIN_SLUG="lrob-email-toolkit"
PLUGIN_NAME="LRob - Email Toolkit"
PLUGIN_FILE="${SCRIPT_DIR}/${PLUGIN_SLUG}.php"
LANGUAGES_DIR="${SCRIPT_DIR}/languages"
RELEASES_DIR="${PARENT_DIR}/releases"

ok()    { echo "[OK] $1"; }
fail()  { echo "[FAIL] $1" >&2; }
warn()  { echo "[WARN] $1"; }
step()  { echo "> $1"; }

need() { command -v "$1" >/dev/null 2>&1 || { fail "missing: $1"; exit 1; }; }

check_deps() {
    step "deps"
    need php; need wp; need msgfmt; need zip; need msgmerge; need msgattrib
    ok "php $(php -r 'echo PHP_VERSION;') / wp $(wp --version | grep -oP '\d+\.\d+\.\d+')"
}

get_version() {
    [ -f "$PLUGIN_FILE" ] || { fail "plugin file not found"; exit 1; }
    grep -oP "Version:\s*\K[\d.]+" "$PLUGIN_FILE"
}

lint_php() {
    step "lint php"
    local errors=0
    while IFS= read -r -d '' f; do
        if ! php -l "$f" >/dev/null 2>&1; then
            fail "${f#$SCRIPT_DIR/}"
            php -l "$f" || true
            errors=$((errors + 1))
        fi
    done < <(find "$SCRIPT_DIR" -type f -name "*.php" \
        ! -path "*/node_modules/*" ! -path "*/vendor/*" ! -path "*/.git/*" -print0)
    [ $errors -gt 0 ] && { fail "$errors file(s) failed lint"; exit 1; }
    ok "all clean"
}

# Find .lrob-etk-* CSS classes never referenced in PHP/JS/JSON. Heuristic:
#   1. Extract every .lrob-etk-* class token from admin/css/*.css.
#   2. For each, look for a literal-string occurrence in src/admin/assets.
#   3. If not found, peel one trailing -segment and try matching the
#      "prefix-" string — PHP/JS often build classes via concat like
#      `'lrob-etk-nl-status-' . $status`. Suppresses that pattern when
#      the peeled prefix has >=3 hyphens (specific enough to trust).
#   4. Whatever's left is a candidate for human review.
scan_dead_css() {
    step "dead-css scan"
    local css_files=("$SCRIPT_DIR"/admin/css/*.css)
    [ ${#css_files[@]} -eq 0 ] && { warn "no CSS files"; return 0; }

    local css_classes
    css_classes=$(grep -hoE '\.lrob-etk-[a-zA-Z0-9_-]+' "${css_files[@]}" 2>/dev/null \
        | sed 's/^\.//' | sort -u)
    [ -z "$css_classes" ] && { warn "no CSS classes found"; return 0; }

    local search_paths=("$SCRIPT_DIR/src" "$SCRIPT_DIR/admin" "$SCRIPT_DIR/assets" "$SCRIPT_DIR/$PLUGIN_SLUG.php")
    local dead=()
    while IFS= read -r cls; do
        # Direct literal match in any source file.
        if grep -qrn --include="*.php" --include="*.js" --include="*.json" --include="*.html" \
            -- "$cls" "${search_paths[@]}" 2>/dev/null; then
            continue
        fi
        # Try once-peeled prefix for dynamic-concat patterns.
        local prefix="${cls%-*}"
        local hyphens="${prefix//[^-]/}"
        if [ "$prefix" != "$cls" ] && [ "${#hyphens}" -ge 3 ] && \
           grep -qrn --include="*.php" --include="*.js" --include="*.json" --include="*.html" \
               -- "${prefix}-" "${search_paths[@]}" 2>/dev/null; then
            continue
        fi
        dead+=("$cls")
    done <<< "$css_classes"

    local total; total=$(wc -l <<< "$css_classes")
    if [ ${#dead[@]} -eq 0 ]; then
        ok "$total CSS classes, all referenced"
    else
        warn "$total CSS classes, ${#dead[@]} candidate(s) for review:"
        printf '       .%s\n' "${dead[@]}"
    fi
}

generate_pot() {
    step "make-pot"
    mkdir -p "$LANGUAGES_DIR"
    wp i18n make-pot "$SCRIPT_DIR" "$LANGUAGES_DIR/${PLUGIN_SLUG}.pot" \
        --domain="$PLUGIN_SLUG" --package-name="$PLUGIN_NAME" >/dev/null \
        || { fail "make-pot"; exit 1; }
    ok "${PLUGIN_SLUG}.pot"
}

merge_translations() {
    step "msgmerge"
    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob
    [ ${#po_files[@]} -eq 0 ] && { warn "no .po files"; return 0; }
    for po in "${po_files[@]}"; do
        msgmerge --quiet --update --backup=none "$po" "$LANGUAGES_DIR/${PLUGIN_SLUG}.pot" 2>/dev/null
        msgattrib --no-obsolete -o "$po" "$po"
        ok "$(basename "$po")"
    done
}

compile_translations() {
    step "msgfmt"
    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob
    [ ${#po_files[@]} -eq 0 ] && return 0
    for po in "${po_files[@]}"; do
        msgfmt -o "${po%.po}.mo" "$po" 2>/dev/null && ok "$(basename "${po%.po}.mo")"
    done
}

generate_json_translations() {
    step "make-json"
    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob
    [ ${#po_files[@]} -eq 0 ] && return 0
    rm -f "$LANGUAGES_DIR"/*.json
    wp i18n make-json "$LANGUAGES_DIR" --no-purge --pretty-print >/dev/null 2>&1 || { fail "make-json"; return 1; }
    local n; n=$(find "$LANGUAGES_DIR" -maxdepth 1 -name "*.json" | wc -l)
    ok "$n json file(s)"
}

# File-type breakdown + comment-vs-code split for PHP. Display only, never saved.
print_stats() {
    step "stats"
    local tmp; tmp=$(mktemp)
    find "$SCRIPT_DIR" -type f \
        ! -path "*/.git/*" ! -path "*/node_modules/*" ! -path "*/releases/*" \
        ! -path "*/vendor/*" ! -path "*/languages/*.mo" \
        -print0 > "$tmp"

    # Per-extension: count, total bytes, total lines.
    declare -A files_by_ext bytes_by_ext lines_by_ext
    while IFS= read -r -d '' f; do
        local ext="${f##*.}"
        case "$f" in *".${ext}") ;; *) ext="(none)" ;; esac
        local sz; sz=$(stat -c%s "$f" 2>/dev/null || echo 0)
        local ln; ln=$(wc -l <"$f" 2>/dev/null || echo 0)
        files_by_ext[$ext]=$(( ${files_by_ext[$ext]:-0} + 1 ))
        bytes_by_ext[$ext]=$(( ${bytes_by_ext[$ext]:-0} + sz ))
        lines_by_ext[$ext]=$(( ${lines_by_ext[$ext]:-0} + ln ))
    done < "$tmp"
    rm -f "$tmp"

    printf '  %-8s %6s %10s %10s\n' "ext" "files" "lines" "bytes"
    printf '  %-8s %6s %10s %10s\n' "---" "-----" "-----" "-----"
    for ext in $(printf '%s\n' "${!files_by_ext[@]}" | sort); do
        printf '  %-8s %6d %10d %10d\n' \
            ".$ext" "${files_by_ext[$ext]}" "${lines_by_ext[$ext]}" "${bytes_by_ext[$ext]}"
    done

    # PHP: code vs comment vs blank — one awk pass per file, accumulated.
    # Comment detection is line-based: any line whose first non-space token is
    # //, #, /*, *, */ counts. Approximate (won't split mid-line trailing
    # comments) but good enough for headline ratios.
    local php_files
    php_files=$(find "$SCRIPT_DIR" -type f -name "*.php" \
        ! -path "*/.git/*" ! -path "*/node_modules/*" ! -path "*/vendor/*")
    if [ -n "$php_files" ]; then
        local counts; counts=$(awk '
            /^[[:space:]]*$/        { blank++;   next }
            /^[[:space:]]*(\/\/|\/\*|\*|#[^!])/ { comment++; next }
                                    { code++ }
            END { print (code+0), (comment+0), (blank+0) }
        ' $php_files)
        read -r php_code php_comment php_blank <<< "$counts"
        local php_total=$((php_code + php_comment + php_blank))
        [ $php_total -gt 0 ] && printf '  php: %d lines = %d code / %d comments / %d blank (%.1f%% comments)\n' \
            "$php_total" "$php_code" "$php_comment" "$php_blank" \
            "$(awk "BEGIN { printf \"%.1f\", $php_comment * 100.0 / $php_total }")"
    fi
}

create_archive() {
    step "archive"
    local version=$1
    local archive_name="${PLUGIN_SLUG}-${version}.zip"
    local archive_path="${RELEASES_DIR}/${archive_name}"

    mkdir -p "$RELEASES_DIR"
    [ -f "$archive_path" ] && rm "$archive_path"

    local temp_list; temp_list=$(mktemp)
    (cd "$PARENT_DIR" && find "$PLUGIN_DIR_NAME" -type f \
        ! -path "*/.git/*" ! -path "*/.github/*" ! -path "*/.claude/*" \
        ! -path "*/node_modules/*" ! -path "*/releases/*" ! -path "*/vendor/*" \
        ! -name "*.sh" ! -name "*.po" ! -name "*.pot" \
        ! -name ".gitignore" ! -name ".editorconfig" ! -name ".DS_Store" \
        ! -name "CLAUDE.md" ! -name "README.md" \
        ! -name "composer.json" ! -name "composer.lock" \
        ! -name "phpcs.xml" ! -name "phpcs.xml.dist" \
        ! -name "phpstan.neon" ! -name "phpstan.neon.dist" \
        > "$temp_list")
    (cd "$PARENT_DIR" && zip -q -r "$archive_path" -@ < "$temp_list")
    rm "$temp_list"

    local size; size=$(du -h "$archive_path" | cut -f1)
    ok "${archive_name} ($size)"
}

main() {
    check_deps
    VERSION=$(get_version)
    step "version $VERSION"
    lint_php
    scan_dead_css
    generate_pot
    merge_translations
    compile_translations
    generate_json_translations
    print_stats
    create_archive "$VERSION"
    ok "release $VERSION done"
}

main "$@"
