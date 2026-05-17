#!/bin/bash

# LRob - Email Toolkit - Release Builder
# Génère les fichiers de traduction et crée une archive zip installable.

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Paths
SCRIPT_NAME="$(basename "$(readlink -f "${BASH_SOURCE[0]}")")"
SCRIPT_DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_DIR_NAME="$(basename "$SCRIPT_DIR")"

# Configuration
PLUGIN_SLUG="lrob-email-toolkit"
PLUGIN_NAME="LRob - Email Toolkit"
PLUGIN_FILE="${SCRIPT_DIR}/${PLUGIN_SLUG}.php"
LANGUAGES_DIR="${SCRIPT_DIR}/languages"
RELEASES_DIR="${PARENT_DIR}/releases"

# Helpers
print_status()  { echo -e "${BLUE}==>${NC} $1"; }
print_success() { echo -e "${GREEN}✓${NC} $1"; }
print_error()   { echo -e "${RED}✗${NC} $1"; }
print_warning() { echo -e "${YELLOW}!${NC} $1"; }

command_exists() { command -v "$1" >/dev/null 2>&1; }

check_dependencies() {
    print_status "Checking dependencies..."

    local missing_deps=0

    if ! command_exists php; then
        print_error "PHP is not installed"
        echo "  Install with: sudo dnf install php-cli"
        missing_deps=1
    else
        print_success "PHP $(php -r 'echo PHP_VERSION;') found"
    fi

    if ! command_exists wp; then
        print_error "WP-CLI is not installed"
        echo "  Install with: sudo dnf install wp-cli"
        echo "  Or manually: curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
        missing_deps=1
    else
        print_success "WP-CLI $(wp --version | grep -oP '\d+\.\d+\.\d+') found"
    fi

    if ! command_exists msgfmt; then
        print_error "msgfmt (gettext) is not installed"
        echo "  Install with: sudo dnf install gettext"
        missing_deps=1
    else
        print_success "msgfmt $(msgfmt --version | head -1 | grep -oP '\d+\.\d+\.\d+') found"
    fi

    if ! command_exists zip; then
        print_error "zip is not installed"
        echo "  Install with: sudo dnf install zip"
        missing_deps=1
    else
        print_success "zip found"
    fi

    if [ $missing_deps -eq 1 ]; then
        print_error "Missing dependencies. Please install them and try again."
        exit 1
    fi

    echo ""
}

get_current_version() {
    if [ ! -f "$PLUGIN_FILE" ]; then
        print_error "Plugin file not found: $PLUGIN_FILE"
        exit 1
    fi
    grep -oP "Version:\s*\K[\d.]+" "$PLUGIN_FILE"
}

lint_php() {
    print_status "Linting PHP files..."

    local errors=0
    while IFS= read -r -d '' php_file; do
        if ! php -l "$php_file" >/dev/null 2>&1; then
            print_error "Syntax error in: ${php_file#$SCRIPT_DIR/}"
            php -l "$php_file" || true
            errors=$((errors + 1))
        fi
    done < <(find "$SCRIPT_DIR" -type f -name "*.php" \
        ! -path "*/node_modules/*" \
        ! -path "*/vendor/*" \
        ! -path "*/.git/*" \
        -print0)

    if [ $errors -gt 0 ]; then
        print_error "PHP lint failed on $errors file(s)"
        exit 1
    fi

    print_success "All PHP files passed lint"
}

generate_pot() {
    print_status "Generating translation template (.pot)..."

    mkdir -p "$LANGUAGES_DIR"

    # No --skip-js: future Gutenberg blocks may use wp.i18n.__
    wp i18n make-pot "$SCRIPT_DIR" "$LANGUAGES_DIR/${PLUGIN_SLUG}.pot" \
        --domain="$PLUGIN_SLUG" \
        --package-name="$PLUGIN_NAME"

    if [ $? -eq 0 ]; then
        print_success "POT file generated: ${LANGUAGES_DIR}/${PLUGIN_SLUG}.pot"
    else
        print_error "Failed to generate POT file"
        exit 1
    fi
}

merge_translations() {
    # Sync each .po file's #: source references with the freshly-generated .pot.
    # Without this, manually-added msgids stay reference-less and `wp i18n make-json`
    # can't associate them with the right JS file → JSON files miss those entries.
    print_status "Merging POT references into .po files..."

    if ! command_exists msgmerge; then
        print_warning "msgmerge not found, skipping merge"
        return 0
    fi

    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob

    if [ ${#po_files[@]} -eq 0 ]; then
        print_warning "No .po files to merge"
        return 0
    fi

    for po_file in "${po_files[@]}"; do
        if msgmerge --quiet --update --backup=none "$po_file" "$LANGUAGES_DIR/${PLUGIN_SLUG}.pot" 2>/dev/null; then
            print_success "Merged: $(basename "$po_file")"
        else
            print_error "Failed to merge: $(basename "$po_file")"
        fi
    done
}

compile_translations() {
    print_status "Compiling translations (.po → .mo)..."

    local compiled=0

    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob

    if [ ${#po_files[@]} -eq 0 ]; then
        print_warning "No .po files found to compile"
        return 0
    fi

    for po_file in "${po_files[@]}"; do
        mo_file="${po_file%.po}.mo"

        if msgfmt -o "$mo_file" "$po_file" 2>/dev/null; then
            print_success "Compiled: $(basename "$mo_file")"
            compiled=$((compiled + 1))
        else
            print_error "Failed to compile: $(basename "$po_file")"
        fi
    done

    if [ $compiled -gt 0 ]; then
        print_success "Compiled $compiled translation file(s)"
    fi
}

generate_json_translations() {
    print_status "Generating JS translation files (.json) for Gutenberg blocks..."

    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob

    if [ ${#po_files[@]} -eq 0 ]; then
        print_warning "No .po files found, skipping JSON generation"
        return 0
    fi

    # Remove stale JSON files so renamed source paths don't leave orphans
    rm -f "$LANGUAGES_DIR"/*.json

    # --no-purge keeps JS strings in the .po as the single source of truth
    if wp i18n make-json "$LANGUAGES_DIR" --no-purge --pretty-print >/dev/null 2>&1; then
        local count
        count=$(find "$LANGUAGES_DIR" -maxdepth 1 -name "*.json" | wc -l)
        if [ "$count" -gt 0 ]; then
            print_success "Generated $count JSON file(s)"
        else
            print_warning "No JS strings found to extract"
        fi
    else
        print_error "Failed to generate JSON translation files"
    fi
}

create_archive() {
    local version=$1
    local archive_name="${PLUGIN_SLUG}-${version}.zip"
    local archive_path="${RELEASES_DIR}/${archive_name}"

    print_status "Creating release archive..."

    mkdir -p "$RELEASES_DIR"

    [ -f "$archive_path" ] && rm "$archive_path"

    local temp_list
    temp_list=$(mktemp)

    (cd "$PARENT_DIR" && find "$PLUGIN_DIR_NAME" -type f \
        ! -path "*/.git/*" \
        ! -path "*/.github/*" \
        ! -path "*/.claude/*" \
        ! -path "*/node_modules/*" \
        ! -path "*/releases/*" \
        ! -path "*/vendor/*" \
        ! -name "*.sh" \
        ! -name "*.po" \
        ! -name "*.pot" \
        ! -name ".gitignore" \
        ! -name ".editorconfig" \
        ! -name ".DS_Store" \
        ! -name "CLAUDE.md" \
        ! -name "README.md" \
        ! -name "composer.json" \
        ! -name "composer.lock" \
        ! -name "phpcs.xml" \
        ! -name "phpcs.xml.dist" \
        ! -name "phpstan.neon" \
        ! -name "phpstan.neon.dist" \
        > "$temp_list")

    (cd "$PARENT_DIR" && zip -q -r "$archive_path" -@ < "$temp_list")
    local zip_result=$?

    rm "$temp_list"

    if [ $zip_result -eq 0 ]; then
        local size
        size=$(du -h "$archive_path" | cut -f1)
        print_success "Archive created: ${RELEASES_DIR}/${archive_name} ($size)"
    else
        print_error "Failed to create archive"
        exit 1
    fi
}

main() {
    echo ""
    echo "╔════════════════════════════════════════════╗"
    echo "║  LRob - Email Toolkit - Release Builder    ║"
    echo "╚════════════════════════════════════════════╝"
    echo ""

    print_status "Script directory: $SCRIPT_DIR"
    print_status "Releases directory: $RELEASES_DIR"
    echo ""

    check_dependencies

    VERSION=$(get_current_version)
    print_status "Current version: $VERSION"
    echo ""

    lint_php
    generate_pot
    merge_translations
    compile_translations
    generate_json_translations
    create_archive "$VERSION"

    echo ""
    print_success "Release $VERSION completed successfully! 🎉"
    echo ""
    echo "Next steps:"
    echo "  1. Test the plugin: unzip ${RELEASES_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
    echo "  2. Upload to WordPress"
    echo ""
}

main "$@"
