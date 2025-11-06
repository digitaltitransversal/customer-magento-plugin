phpstan:
	vendor/bin/phpstan analyse api --level 5

.PHONY: zip-plugin

zip-plugin:
	$(eval VERSION=$(shell jq -r '.version' composer.json))
	@zip -r digital_femsa_payments-$(VERSION).zip . -x "*.git*" "*.idea*" "vendor/*" "composer.lock" ".DS_Store"

update-version:
	@if [ -z "$(VERSION)" ]; then \
		echo "Uso: make update-version VERSION=1.0.10"; \
		exit 1; \
	fi
	@echo "Actualizando versión a $(VERSION)..."

	@echo "$(VERSION)" > VERSION
	@echo "[VERSION] -> $(VERSION)"

	@sed -i '' -E 's/"version": *"[0-9]+\.[0-9]+\.[0-9]+"/"version": "$(VERSION)"/' composer.json
	@echo "[composer.json] version -> $(VERSION)"

	@sed -i '' -E 's/\(v[0-9]+\.[0-9]+\.[0-9]+\)/\(v$(VERSION)\)/' etc/adminhtml/system.xml
	@echo "[system.xml] (v...) -> v$(VERSION)"

	@sed -i '' -E 's|(<plugin_version><!\[CDATA\[)[^]]+(]]></plugin_version>)|\1$(VERSION)\2|' etc/config.xml
	@echo "[config.xml] plugin_version -> $(VERSION)"

	@sed -i '' -E 's|(setup_version=")[0-9]+\.[0-9]+\.[0-9]+(")|\1$(VERSION)\2|' etc/module.xml
	@echo "[module.xml] setup_version -> $(VERSION)"

	@echo "Versión actualizada a $(VERSION) correctamente."
