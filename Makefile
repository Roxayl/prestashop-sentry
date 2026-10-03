-include .env
-include .env.local

PS						?= 1.7.8
PS_VERSION_TAG			:= $(PS)

-include .env.$(PS_VERSION_TAG)
-include .env.$(PS_VERSION_TAG).local

HOST_UID 				?= $(shell id -u)
HOST_GID				?= $(shell id -g)

export

ARGS					?=

COMPOSE_PROJECT_NAME	:= prestashop-senty-$(subst .,-,$(PS_VERSION_TAG))
COMPOSE 				:= docker compose --project-name $(COMPOSE_PROJECT_NAME)
COMPOSE_MODULE			:= $(COMPOSE) run --rm --user=www-data --workdir=/var/www/html/modules/extsentry prestashop

module_name=extsentry
module_dir=$(CURDIR)/../$(module_name)
build_dir=$(module_name)
version=$(shell grep '\->version =' $(module_name).php | grep -oe "[0-9]\+\.[0-9]\+\.[0-9]\+")

.DEFAULT_GOAL	:= help
.PHONY: \
	help \
	build up down down-hard logs ps shell console \
	phpcs phpcs-fix phpmd php-cs-fixer test-deps test composer-dev composer-prod autoindex clean build archive release release-git

## —— 🌟 Makefile ——————————————————————————————————————————————————————————————

help: ## Show this help
	@grep -hE '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— 🐧 PrestaShop ————————————————————————————————————————————————————————————

build: ## Build the PrestaShop image
	@$(COMPOSE) build \
		--pull \
		$(ARGS)

up: ## Start the PrestaShop container
	@mkdir -p "./.prestashop/$(PS_VERSION_TAG)"
	@$(COMPOSE) up \
		--wait \
		--detach \
		$(ARGS)

down: ## Stop and remove the PrestaShop container
	@$(COMPOSE) down \
		--remove-orphans \
		$(ARGS)

down-hard: ## Stop and remove containers, volumes and the PrestaShop installation
	@$(COMPOSE) down \
		--remove-orphans \
		--volumes \
		$(ARGS)
	@if [ -d "./.prestashop/$(PS_VERSION_TAG)" ]; then \
		rm -rf "./.prestashop/$(PS_VERSION_TAG)"; \
	fi

logs: ## Follow container logs
	@$(COMPOSE) logs \
		--follow \
		$(ARGS)

ps: ## Show container status
	@$(COMPOSE) ps \
		$(ARGS)

shell: ## Open a shell in the PrestaShop container
	@$(COMPOSE) exec \
		--user=www-data \
		--workdir=/var/www/html/modules/extsentry \
		prestashop bash

console: ## Run the Symfony console in the PrestaShop container
	@$(COMPOSE) exec \
		--user=www-data \
		prestashop bin/console \
		$(ARGS)

## —— 🧪 QA ————————————————————————————————————————————————————————————————————

phpcs: ## Run PHP_CodeSniffer
	@$(COMPOSE_MODULE) \
		vendor/bin/phpcs \
		$(ARGS)

phpcs-fix: ## Run PHP Code Beautifier and Fixer
	@$(COMPOSE_MODULE) \
		vendor/bin/phpcbf \
		$(ARGS)

phpmd: ## Run PHPMD
	phpmd config,sql,src,upgrade,views,$(module_name).php text phpmd.xml.dist

php-cs-fixer: ## Run PHP CS Fixer
	@$(COMPOSE_MODULE) \
		vendor/bin/php-cs-fixer fix \
		$(ARGS)

test-deps: ## Install test dependencies
	@$(COMPOSE_MODULE) \
		composer install \
		--working-dir=tests/ \
		--prefer-dist \
		--no-progress \
		--no-interaction

test: test-deps ## Run tests
	@$(COMPOSE_MODULE) \
		tests/vendor/bin/phpunit \
		$(ARGS)

## —— 🛠  Module ————————————————————————————————————————————————————————————————

composer: ## Run Composer in the module directory
	@$(COMPOSE_MODULE) \
		composer \
		$(ARGS)

composer-dev: ## Install module development dependencies
	@$(COMPOSE_MODULE) \
		composer install \
		--prefer-dist \
		--no-progress \
		--no-interaction

composer-prod: ## Install module production dependencies
	@$(COMPOSE_MODULE) \
		composer install \
		--prefer-dist \
		--no-progress \
		--no-interaction \
		--no-dev \
		--no-scripts \
		--optimize-autoloader

autoindex: composer-dev ## Run Auto Index
	@$(COMPOSE_MODULE) \
		vendor/bin/autoindex \
		--exclude=.docker,.prestashop,vendor,tests \
		$(ARGS)

## —— 📦 Release ———————————————————————————————————————————————————————————————

clean: ## Remove release build directory
	rm -rf $(build_dir)

release-build: composer-prod ## Prepare release files
	$(MAKE) clean
	mkdir -p $(build_dir)
	rsync -a \
		--exclude=*.swp \
		--exclude=.arcconfig \
		--exclude=.docker \
		--exclude=.env* \
		--exclude=.git \
		--exclude=.gitignore \
		--exclude=.php-cs-fixer.* \
		--exclude=.phpcs* \
		--exclude=.prestashop \
		--exclude=composer.* \
		--exclude=config.xml \
		--exclude=phpcs.xml* \
		--exclude=phpmd.xml* \
		--exclude=phpstan.neon \
		--exclude=$(build_dir) \
		--exclude=$(module_name).zip \
		--exclude=Makefile \
		--exclude=sentry.json \
		--exclude=tests \
		--exclude=phpunit.xml* \
		--exclude=.phpunit.result.cache \
		$(module_dir)/ $(build_dir)

archive: ## Generate release archive
	rm -rf $(module_name).zip
	zip $(module_name).zip $(build_dir) -r

release: release-build autoindex archive clean ## Create release package

release-git: ## Create Git release
	@echo "Version: ${version}"
	git add $(module_name).php
	git commit -m "release: $(version)"
	git tag -a $(version) -m "release: $(version)"
