# CareCheck — release packaging for Nextcloud App Store
# Usage: make release  then  make sign  (with your key/cert)

app_name = carecheck
build_dir = build
release_dir = $(build_dir)/release

# Files/dirs to exclude from release archive (store and security)
# README.md, CHANGELOG.md, LICENSE are included (required by store)
release_exclude = \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='build' \
	--exclude='node_modules' \
	--exclude='/vendor' \
	--exclude='Makefile' \
	--exclude='tests' \
	--exclude='e2e' \
	--exclude='docs' \
	--exclude='.auth' \
	--exclude='test-results' \
	--exclude='playwright-report' \
	--exclude='playwright.config.js' \
	--exclude='.phpunit.result.cache' \
	--exclude='.phpunit.cache' \
	--exclude='.eslintcache' \
	--exclude='phpunit.xml' \
	--exclude='phpunit.integration.xml' \
	--exclude='phpstan.neon*' \
	--exclude='phpstan-bootstrap.php' \
	--exclude='stubs' \
	--exclude='.gitignore' \
	--exclude='BUCHPROJEKT_*' \
	--exclude='CHECKLIST_*' \
	--exclude='INSTALL.txt' \
	--exclude='PERMISSION_*' \
	--exclude='PROTOTYPE_*' \
	--exclude='test-*.html' \
	--exclude='EMERGENCY_FIX.sh' \
	--exclude='.cursor'

.PHONY: release sign clean verify-ui

# UI / l10n gates (ARCH-19) — run before release or audit sign-off
verify-ui:
	cd . && npm run verify:ui

release: clean
	@mkdir -p $(release_dir)
	@staging=$$(mktemp -d) && \
		mkdir -p "$$staging/$(app_name)" && \
		rsync -a $(release_exclude) ./ "$$staging/$(app_name)/" && \
		composer install --no-dev --optimize-autoloader --ignore-platform-reqs --quiet \
			--working-dir="$$staging/$(app_name)" && \
		tar -czf $(release_dir)/$(app_name).tar.gz -C "$$staging" $(app_name) && \
		rm -rf "$$staging"
	@echo "Created $(release_dir)/$(app_name).tar.gz"
	@echo "Add appinfo/signature.json with: make sign"

sign:
	@echo "Sign the app with: php /path/to/nextcloud/occ integrity:sign-app --privateKey=... --certificate=... --path=$(CURDIR)"
	@test -f appinfo/signature.json && echo "appinfo/signature.json already exists." || true

clean:
	@rm -rf $(build_dir)
