# Ravi Developer - Makefile
# =============================
# Comandos úteis para desenvolvimento e produção

.PHONY: help up down restart logs logs-php logs-ws logs-nginx shell mariadb \
        db-init db-migrate db-seed db-status db-reset db-backup criar-admin rebuild build cleanup test generate-key \
        install dev prod sandbox-build sandbox-shell

# Cores para output
GREEN := \033[0;32m
YELLOW := \033[0;33m
RED := \033[0;31m
NC := \033[0m # No Color

# Diretório do Docker
DOCKER_DIR := docker

# =============================
# AJUDA
# =============================

help: ## Mostra esta ajuda
	@echo ""
	@echo "$(GREEN)🌟 Ravi Developer - Comandos Disponíveis$(NC)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(YELLOW)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""

# =============================
# DOCKER COMPOSE
# =============================

up: ## Inicia todos os containers
	@echo "$(GREEN)🚀 Iniciando containers...$(NC)"
	cd $(DOCKER_DIR) && docker-compose up -d
	@echo "$(GREEN)✅ Containers iniciados!$(NC)"
	@echo ""
	@echo "  📱 Aplicação: http://localhost:8080"
	@echo "  🔌 WebSocket: ws://localhost:8081"
	@echo "  🗄️  phpMyAdmin: http://localhost:8082 (use 'make up-dev')"
	@echo ""

up-dev: ## Inicia containers com ferramentas de desenvolvimento
	@echo "$(GREEN)🚀 Iniciando containers (modo dev)...$(NC)"
	cd $(DOCKER_DIR) && docker-compose --profile dev up -d
	@echo "$(GREEN)✅ Containers iniciados!$(NC)"
	@echo ""
	@echo "  📱 Aplicação:  http://localhost:8080"
	@echo "  🔌 WebSocket:  ws://localhost:8081"
	@echo "  🗄️  phpMyAdmin: http://localhost:8082"
	@echo "  📧 Mailhog:    http://localhost:8025"
	@echo ""

down: ## Para todos os containers
	@echo "$(YELLOW)🛑 Parando containers...$(NC)"
	cd $(DOCKER_DIR) && docker-compose --profile dev down
	@echo "$(GREEN)✅ Containers parados!$(NC)"

restart: ## Reinicia todos os containers
	@echo "$(YELLOW)🔄 Reiniciando containers...$(NC)"
	cd $(DOCKER_DIR) && docker-compose restart
	@echo "$(GREEN)✅ Containers reiniciados!$(NC)"

# =============================
# LOGS
# =============================

logs: ## Mostra logs de todos os containers
	cd $(DOCKER_DIR) && docker-compose logs -f

logs-php: ## Mostra logs do PHP
	cd $(DOCKER_DIR) && docker-compose logs -f php

logs-ws: ## Mostra logs do WebSocket
	cd $(DOCKER_DIR) && docker-compose logs -f websocket

logs-nginx: ## Mostra logs do Nginx
	cd $(DOCKER_DIR) && docker-compose logs -f nginx

logs-mariadb: ## Mostra logs do MariaDB
	cd $(DOCKER_DIR) && docker-compose logs -f mariadb

# =============================
# SHELL ACCESS
# =============================

shell: ## Acessa shell do container PHP
	cd $(DOCKER_DIR) && docker-compose exec php bash

shell-root: ## Acessa shell do container PHP como root
	cd $(DOCKER_DIR) && docker-compose exec -u root php bash

mariadb: ## Acessa o cliente do MariaDB (pede a senha do usuário do banco)
	cd $(DOCKER_DIR) && docker-compose exec mariadb sh -c 'mariadb -u "$$MARIADB_USER" -p "$$MARIADB_DATABASE"'

# =============================
# BANCO DE DADOS
# =============================

db-init: ## Inicializa o banco de dados (migrations pendentes + seeds)
	@echo "$(GREEN)🗄️  Inicializando banco de dados...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/db-init.php
	@echo "$(GREEN)✅ Banco de dados inicializado!$(NC)"

db-migrate: ## Aplica só as migrations pendentes
	@echo "$(GREEN)🗄️  Executando migrations...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/db-init.php --migrate
	@echo "$(GREEN)✅ Migrations executadas!$(NC)"

db-seed: ## Executa apenas seeds
	@echo "$(GREEN)🗄️  Executando seeds...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/db-init.php --seed
	@echo "$(GREEN)✅ Seeds executados!$(NC)"

db-status: ## Lista as migrations e quais estão pendentes
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/db-init.php --status

criar-admin: ## Cria o primeiro administrador (pergunta nome, e-mail e senha)
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/criar-admin.php

db-reset: ## Reseta o banco de dados (DROP de tudo + migrations + seeds)
	@echo "$(RED)⚠️  Resetando banco de dados...$(NC)"
	@read -p "Tem certeza? Isso apagará todos os dados! [y/N] " confirm; \
	if [ "$$confirm" = "y" ] || [ "$$confirm" = "Y" ]; then \
		cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/db-init.php --fresh --force; \
		echo "$(GREEN)✅ Banco de dados resetado!$(NC)"; \
	else \
		echo "$(YELLOW)Operação cancelada.$(NC)"; \
	fi

db-backup: ## Faz backup do banco de dados
	@echo "$(GREEN)💾 Fazendo backup...$(NC)"
	@mkdir -p backups
	cd $(DOCKER_DIR) && docker-compose exec -T mariadb sh -c 'mariadb-dump -u root -p"$$MARIADB_ROOT_PASSWORD" "$$MARIADB_DATABASE"' > ../backups/backup_$$(date +%Y%m%d_%H%M%S).sql
	@echo "$(GREEN)✅ Backup salvo em backups/$(NC)"

# =============================
# BUILD
# =============================

build: ## Build dos containers
	@echo "$(GREEN)🔨 Building containers...$(NC)"
	cd $(DOCKER_DIR) && docker-compose build
	@echo "$(GREEN)✅ Build concluído!$(NC)"

rebuild: ## Rebuild forçado dos containers
	@echo "$(GREEN)🔨 Rebuilding containers...$(NC)"
	cd $(DOCKER_DIR) && docker-compose build --no-cache
	cd $(DOCKER_DIR) && docker-compose up -d --force-recreate
	@echo "$(GREEN)✅ Rebuild concluído!$(NC)"

sandbox-build: ## Build da imagem do sandbox
	@echo "$(GREEN)🔨 Building sandbox image...$(NC)"
	cd $(DOCKER_DIR) && docker-compose --profile build up sandbox-builder
	@echo "$(GREEN)✅ Sandbox image pronta!$(NC)"

sandbox-shell: ## Acessa shell do sandbox (para debug)
	docker run -it --rm -v $(PWD)/storage/sandbox:/workspace ravi-sandbox:latest bash

# =============================
# COMPOSER
# =============================

install: ## Instala dependências do Composer
	@echo "$(GREEN)📦 Instalando dependências...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php composer install
	@echo "$(GREEN)✅ Dependências instaladas!$(NC)"

update: ## Atualiza dependências do Composer
	@echo "$(GREEN)📦 Atualizando dependências...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php composer update
	@echo "$(GREEN)✅ Dependências atualizadas!$(NC)"

autoload: ## Regenera autoload do Composer
	cd $(DOCKER_DIR) && docker-compose exec php composer dump-autoload -o

# =============================
# UTILITÁRIOS
# =============================

generate-key: ## Gera uma nova APP_KEY
	@echo "$(GREEN)🔑 Gerando APP_KEY...$(NC)"
	@NEW_KEY=$$(openssl rand -base64 32); \
	echo ""; \
	echo "Nova APP_KEY gerada:"; \
	echo "$(YELLOW)$$NEW_KEY$(NC)"; \
	echo ""; \
	echo "Adicione ao seu .env:"; \
	echo "APP_KEY=$$NEW_KEY"

cleanup: ## Limpa containers antigos do sandbox
	@echo "$(YELLOW)🧹 Limpando containers antigos...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/cleanup-sandboxes.php
	@echo "$(GREEN)✅ Limpeza concluída!$(NC)"

cleanup-docker: ## Limpa recursos Docker não utilizados
	@echo "$(YELLOW)🧹 Limpando recursos Docker...$(NC)"
	docker system prune -f
	docker volume prune -f
	@echo "$(GREEN)✅ Limpeza Docker concluída!$(NC)"

cache-clear: ## Limpa cache da aplicação
	@echo "$(YELLOW)🧹 Limpando cache...$(NC)"
	rm -rf storage/cache/*
	rm -rf storage/sessions/*
	@echo "$(GREEN)✅ Cache limpo!$(NC)"

permissions: ## Corrige permissões do storage
	@echo "$(GREEN)🔧 Corrigindo permissões...$(NC)"
	chmod -R 775 storage/
	cd $(DOCKER_DIR) && docker-compose exec -u root php chown -R www:www /var/www/html/storage
	@echo "$(GREEN)✅ Permissões corrigidas!$(NC)"

# =============================
# TESTES
# =============================

test: ## Executa testes PHPUnit
	@echo "$(GREEN)🧪 Executando testes...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php ./vendor/bin/phpunit
	@echo "$(GREEN)✅ Testes concluídos!$(NC)"

test-coverage: ## Executa testes com coverage
	@echo "$(GREEN)🧪 Executando testes com coverage...$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec php ./vendor/bin/phpunit --coverage-html coverage/
	@echo "$(GREEN)✅ Relatório gerado em coverage/$(NC)"

# =============================
# PRODUÇÃO
# =============================

prod: ## Prepara para produção
	@echo "$(GREEN)🚀 Preparando para produção...$(NC)"
	@echo "1. Verificando .env..."
	@if [ ! -f .env ]; then echo "$(RED)❌ Arquivo .env não encontrado!$(NC)"; exit 1; fi
	@echo "2. Instalando dependências (prod)..."
	cd $(DOCKER_DIR) && docker-compose exec php composer install --no-dev --optimize-autoloader
	@echo "3. Limpando cache..."
	rm -rf storage/cache/*
	@echo "4. Corrigindo permissões..."
	chmod -R 775 storage/
	@echo "$(GREEN)✅ Pronto para produção!$(NC)"
	@echo ""
	@echo "$(YELLOW)⚠️  Lembre-se de:$(NC)"
	@echo "  - Definir APP_ENV=production no .env"
	@echo "  - Definir APP_DEBUG=false no .env"
	@echo "  - Configurar SSL/HTTPS"
	@echo "  - Configurar backups automáticos"

# =============================
# DESENVOLVIMENTO
# =============================

dev: up-dev ## Alias para up-dev (modo desenvolvimento)

watch-logs: ## Acompanha logs em tempo real (todos)
	cd $(DOCKER_DIR) && docker-compose logs -f --tail=100

status: ## Mostra status dos containers
	@echo "$(GREEN)📊 Status dos Containers$(NC)"
	@echo ""
	cd $(DOCKER_DIR) && docker-compose ps
	@echo ""

stats: ## Mostra estatísticas de recursos dos containers
	docker stats --no-stream $$(cd $(DOCKER_DIR) && docker-compose ps -q)

# =============================
# WEBSOCKET
# =============================

ws-restart: ## Reinicia apenas o WebSocket
	@echo "$(YELLOW)🔄 Reiniciando WebSocket...$(NC)"
	cd $(DOCKER_DIR) && docker-compose restart websocket
	@echo "$(GREEN)✅ WebSocket reiniciado!$(NC)"

ws-status: ## Verifica status do WebSocket
	@echo "$(GREEN)🔌 Status do WebSocket$(NC)"
	cd $(DOCKER_DIR) && docker-compose exec websocket ps aux | grep websocket || echo "WebSocket está rodando via docker-compose"

# =============================
# QUEUE (se implementar filas)
# =============================

queue-start: ## Inicia o worker de filas
	cd $(DOCKER_DIR) && docker-compose exec php php /var/www/html/bin/queue-worker.php

queue-restart: ## Reinicia o worker de filas
	@echo "$(YELLOW)🔄 Reiniciando queue worker...$(NC)"
	# Implementar conforme necessidade

# =============================
# DEFAULT
# =============================

.DEFAULT_GOAL := help
