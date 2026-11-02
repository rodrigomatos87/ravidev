package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"sync"
	"syscall"
	"time"

	"ravidev-whatsapp/handlers"

	"github.com/gorilla/mux"
	_ "github.com/mattn/go-sqlite3"
	"go.mau.fi/whatsmeow/types/events"
)

// Config estrutura de configuracao
type Config struct {
	Port              int    `json:"port"`
	APIKey            string `json:"api_key"`
	SessionsDir       string `json:"sessions_dir"`
	WebhookURL        string `json:"webhook_url"`
	StatusWebhookURL  string `json:"status_webhook_url"`
	LogFile           string `json:"log_file"`
	MaxSessions       int    `json:"max_sessions"`
	ReconnectInterval int    `json:"reconnect_interval"`
	QRTimeout         int    `json:"qr_timeout"`
}

// App estrutura principal da aplicacao
type App struct {
	Config   *Config
	Sessions map[int]*handlers.Session // map[conexao_id]*Session
	Mutex    sync.RWMutex
	Logger   *log.Logger
}

var app *App

// setupEventHandlers configura handlers de eventos para uma sessao
func setupEventHandlers(session *handlers.Session) {
	session.Client.AddEventHandler(func(evt interface{}) {
		switch v := evt.(type) {
		case *events.Message:
			// Mensagem recebida
			handlers.HandleIncomingMessage(evt, session, app.Config.WebhookURL, app.Config.APIKey, app.Logger)
		case *events.Receipt:
			// Status de entrega/leitura
			app.Logger.Printf("Receipt recebido: tipo=%s, message_ids=%v", v.Type, v.MessageIDs)
			handlers.HandleReceiptEvent(v, app.Config.StatusWebhookURL, app.Config.APIKey, app.Logger)
		}
	})
}

func main() {
	// Carregar configuracao
	config, err := loadConfig("config.json")
	if err != nil {
		log.Fatalf("Erro ao carregar configuracao: %v", err)
	}

	// Configurar logger
	logFile, err := os.OpenFile(config.LogFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
	if err != nil {
		log.Fatalf("Erro ao abrir arquivo de log: %v", err)
	}
	defer logFile.Close()

	logger := log.New(logFile, "[RaviDev-WhatsApp] ", log.LstdFlags|log.Lshortfile)

	// Criar diretorios necessarios
	if err := os.MkdirAll(config.SessionsDir, 0755); err != nil {
		logger.Fatalf("Erro ao criar diretorio de sessoes: %v", err)
	}
	if err := os.MkdirAll("./downloads", 0755); err != nil {
		logger.Fatalf("Erro ao criar diretorio de downloads: %v", err)
	}
	if err := os.MkdirAll("./temp_uploads", 0755); err != nil {
		logger.Fatalf("Erro ao criar diretorio de uploads temporarios: %v", err)
	}

	// Inicializar app
	app = &App{
		Config:   config,
		Sessions: make(map[int]*handlers.Session),
		Logger:   logger,
	}

	logger.Println("Servico WhatsApp RaviDev iniciado")
	logger.Printf("Porta: %d", config.Port)
	logger.Printf("Diretorio de sessoes: %s", config.SessionsDir)

	// Restaurar sessoes existentes a partir dos diretorios de sessao
	go restoreSessions()

	// Configurar rotas
	router := mux.NewRouter()
	router.Use(authMiddleware)

	router.HandleFunc("/health", healthHandler).Methods("GET")
	router.HandleFunc("/conectar", conectarHandler).Methods("POST")
	router.HandleFunc("/status/{conexao_id}", statusHandler).Methods("GET")
	router.HandleFunc("/enviar", enviarHandler).Methods("POST")
	router.HandleFunc("/enviar-midia", enviarMidiaHandler).Methods("POST")
	router.HandleFunc("/desconectar", desconectarHandler).Methods("POST")

	// Servidor HTTP
	srv := &http.Server{
		Addr:         fmt.Sprintf("127.0.0.1:%d", config.Port),
		Handler:      router,
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 15 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	// Iniciar servidor em goroutine
	go func() {
		logger.Printf("Servidor HTTP iniciado na porta %d", config.Port)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Fatalf("Erro ao iniciar servidor: %v", err)
		}
	}()

	// Aguardar sinal de interrupcao
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	logger.Println("Encerrando servidor...")

	// Desconectar todas as sessoes
	app.Mutex.Lock()
	for _, session := range app.Sessions {
		if session.Client != nil && session.Client.IsConnected() {
			session.Client.Disconnect()
		}
	}
	app.Mutex.Unlock()

	// Shutdown gracioso
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		logger.Printf("Erro ao encerrar servidor: %v", err)
	}

	logger.Println("Servidor encerrado")
}

// loadConfig carrega configuracao do arquivo JSON
func loadConfig(filename string) (*Config, error) {
	file, err := os.Open(filename)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	config := &Config{}
	decoder := json.NewDecoder(file)
	if err := decoder.Decode(config); err != nil {
		return nil, err
	}

	return config, nil
}

// authMiddleware valida API key
func authMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Ignorar autenticacao para health check
		if r.URL.Path == "/health" {
			next.ServeHTTP(w, r)
			return
		}

		apiKey := r.Header.Get("X-API-Key")
		if apiKey != app.Config.APIKey {
			app.Logger.Printf("Tentativa de acesso nao autorizado de %s", r.RemoteAddr)
			http.Error(w, "Nao autorizado", http.StatusUnauthorized)
			return
		}

		next.ServeHTTP(w, r)
	})
}

// healthHandler retorna status do servico
func healthHandler(w http.ResponseWriter, r *http.Request) {
	app.Mutex.RLock()
	totalSessions := len(app.Sessions)
	connectedSessions := 0
	for _, session := range app.Sessions {
		if session.Client != nil && session.Client.IsConnected() {
			connectedSessions++
		}
	}
	app.Mutex.RUnlock()

	response := map[string]interface{}{
		"status":             "ok",
		"total_sessions":     totalSessions,
		"connected_sessions": connectedSessions,
		"max_sessions":       app.Config.MaxSessions,
		"timestamp":          time.Now().Unix(),
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(response)
}

// conectarHandler inicia nova conexao WhatsApp
func conectarHandler(w http.ResponseWriter, r *http.Request) {
	var req struct {
		UsuarioID int `json:"usuario_id"`
		ConexaoID int `json:"conexao_id"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		app.Logger.Printf("Erro ao decodificar requisicao: %v", err)
		http.Error(w, "Requisicao invalida", http.StatusBadRequest)
		return
	}

	app.Logger.Printf("Iniciando conexao para usuario %d, conexao %d", req.UsuarioID, req.ConexaoID)

	// Verificar limite de sessoes
	app.Mutex.RLock()
	if len(app.Sessions) >= app.Config.MaxSessions {
		app.Mutex.RUnlock()
		app.Logger.Printf("Limite de sessoes atingido: %d", app.Config.MaxSessions)
		http.Error(w, "Limite de sessoes atingido", http.StatusTooManyRequests)
		return
	}
	app.Mutex.RUnlock()

	// Verificar se ja existe sessao CONECTADA
	app.Mutex.RLock()
	existingSession, exists := app.Sessions[req.ConexaoID]
	app.Mutex.RUnlock()

	if exists && existingSession.Client != nil && existingSession.Client.IsConnected() && existingSession.Client.Store.ID != nil {
		app.Logger.Printf("Sessao %d ja esta conectada e autenticada", req.ConexaoID)
		respondJSON(w, map[string]interface{}{
			"success": true,
			"message": "Ja conectado",
			"status":  "conectado",
		})
		return
	}

	// Se existe sessao antiga mas nao conectada, avisar (mas permitir criar nova)
	if exists {
		app.Logger.Printf("Sessao %d existe mas nao esta conectada - criando nova", req.ConexaoID)
	}

	// Criar nova sessao
	session, qrCode, err := handlers.CreateSession(req.UsuarioID, req.ConexaoID, app.Config.SessionsDir, app.Logger)
	if err != nil {
		app.Logger.Printf("Erro ao criar sessao: %v", err)
		http.Error(w, fmt.Sprintf("Erro ao criar sessao: %v", err), http.StatusInternalServerError)
		return
	}

	// Armazenar sessao
	app.Mutex.Lock()
	app.Sessions[req.ConexaoID] = session
	app.Mutex.Unlock()

	// Configurar handlers de eventos
	setupEventHandlers(session)

	app.Logger.Printf("Sessao %d criada com sucesso, aguardando QR Code", req.ConexaoID)

	respondJSON(w, map[string]interface{}{
		"success": true,
		"qr_code": qrCode,
		"message": "QR Code gerado. Escaneie com WhatsApp.",
	})
}

// statusHandler retorna status da conexao
func statusHandler(w http.ResponseWriter, r *http.Request) {
	vars := mux.Vars(r)
	conexaoID := 0
	fmt.Sscanf(vars["conexao_id"], "%d", &conexaoID)

	app.Mutex.RLock()
	session, exists := app.Sessions[conexaoID]
	app.Mutex.RUnlock()

	if !exists || session.Client == nil {
		respondJSON(w, map[string]interface{}{
			"success": true,
			"status":  "desconectado",
		})
		return
	}

	// Verificar se esta conectado E autenticado (tem ID)
	isConnected := session.Client.IsConnected()
	isAuthenticated := session.Client.Store != nil && session.Client.Store.ID != nil

	status := "desconectado"
	if isConnected && isAuthenticated {
		status = "conectado"
	} else if isConnected && !isAuthenticated {
		status = "conectando" // Aguardando scan do QR Code
	}

	response := map[string]interface{}{
		"success": true,
		"status":  status,
	}

	// So adicionar numero e nome se realmente autenticado
	if isAuthenticated {
		jid := session.Client.Store.ID
		response["numero"] = fmt.Sprintf("+%s", jid.User)
		response["nome"] = session.Client.Store.PushName
	}

	respondJSON(w, response)
}

// enviarHandler envia mensagem
func enviarHandler(w http.ResponseWriter, r *http.Request) {
	var req struct {
		ConexaoID        int    `json:"conexao_id"`
		Telefone         string `json:"telefone"`
		Mensagem         string `json:"mensagem"`
		ReplyToMessageID string `json:"reply_to_message_id"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		app.Logger.Printf("Erro ao decodificar requisicao: %v", err)
		http.Error(w, "Requisicao invalida", http.StatusBadRequest)
		return
	}

	if req.ReplyToMessageID != "" {
		app.Logger.Printf("Reply detectado: %s", req.ReplyToMessageID)
	}

	app.Mutex.RLock()
	session, exists := app.Sessions[req.ConexaoID]
	app.Mutex.RUnlock()

	if !exists || session.Client == nil || !session.Client.IsConnected() {
		app.Logger.Printf("Sessao %d nao esta conectada", req.ConexaoID)
		respondJSON(w, map[string]interface{}{
			"success": false,
			"error":   "WhatsApp nao conectado",
		})
		return
	}

	// Enviar mensagem (com ou sem resposta)
	messageID, err := handlers.SendMessage(session, req.Telefone, req.Mensagem, req.ReplyToMessageID, app.Logger)
	if err != nil {
		app.Logger.Printf("Erro ao enviar mensagem: %v", err)
		respondJSON(w, map[string]interface{}{
			"success": false,
			"error":   err.Error(),
		})
		return
	}

	app.Logger.Printf("Mensagem enviada com sucesso para %s (ID: %s)", req.Telefone, messageID)

	// Enviar callback de status "enviada"
	go handlers.HandleSentMessageEvent(messageID, app.Config.StatusWebhookURL, app.Config.APIKey, app.Logger)

	respondJSON(w, map[string]interface{}{
		"success":    true,
		"message_id": messageID,
	})
}

// desconectarHandler desconecta sessao
func desconectarHandler(w http.ResponseWriter, r *http.Request) {
	var req struct {
		ConexaoID int `json:"conexao_id"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		app.Logger.Printf("Erro ao decodificar requisicao: %v", err)
		http.Error(w, "Requisicao invalida", http.StatusBadRequest)
		return
	}

	app.Mutex.Lock()
	session, exists := app.Sessions[req.ConexaoID]
	if exists {
		if session.Client != nil && session.Client.IsConnected() {
			session.Client.Disconnect()
		}
		delete(app.Sessions, req.ConexaoID)
	}
	app.Mutex.Unlock()

	app.Logger.Printf("Sessao %d desconectada", req.ConexaoID)

	respondJSON(w, map[string]interface{}{
		"success": true,
		"message": "Desconectado com sucesso",
	})
}

// restoreSessions restaura sessoes existentes a partir dos diretorios de sessao locais
func restoreSessions() {
	app.Logger.Println("Restaurando sessoes existentes...")
	time.Sleep(2 * time.Second)

	// Escanear diretorio de sessoes para encontrar sessoes salvas
	entries, err := os.ReadDir(app.Config.SessionsDir)
	if err != nil {
		app.Logger.Printf("Erro ao ler diretorio de sessoes: %v", err)
		return
	}

	restored := 0
	failed := 0

	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}

		// Extrair usuario_id e conexao_id do nome do diretorio (user_X_conn_Y)
		var usuarioID, conexaoID int
		_, err := fmt.Sscanf(entry.Name(), "user_%d_conn_%d", &usuarioID, &conexaoID)
		if err != nil {
			continue
		}

		// Verificar se o banco de dados da sessao existe
		dbPath := filepath.Join(app.Config.SessionsDir, entry.Name(), "session.db")
		if _, err := os.Stat(dbPath); os.IsNotExist(err) {
			continue
		}

		app.Logger.Printf("Tentando restaurar sessao %d...", conexaoID)

		session, _, err := handlers.CreateSession(usuarioID, conexaoID, app.Config.SessionsDir, app.Logger)
		if err != nil {
			app.Logger.Printf("Erro ao criar sessao %d: %v", conexaoID, err)
			failed++
			continue
		}

		// Verificar se realmente conectou
		if session.Client != nil && session.Client.IsConnected() && session.Client.Store.ID != nil {
			app.Mutex.Lock()
			app.Sessions[conexaoID] = session
			app.Mutex.Unlock()

			setupEventHandlers(session)
			restored++
			app.Logger.Printf("Sessao %d restaurada com sucesso", conexaoID)
		} else {
			app.Logger.Printf("Sessao %d nao conectou - usuario precisara reconectar", conexaoID)
			failed++
		}
	}

	app.Logger.Printf("Restauracao concluida: %d OK, %d falhas", restored, failed)
}

// enviarMidiaHandler envia midia (imagem, video, audio, documento)
func enviarMidiaHandler(w http.ResponseWriter, r *http.Request) {
	// Parse multipart form (limite de 50MB)
	if err := r.ParseMultipartForm(50 << 20); err != nil {
		app.Logger.Printf("Erro ao fazer parse do form: %v", err)
		http.Error(w, "Erro ao processar upload", http.StatusBadRequest)
		return
	}

	conexaoID := 0
	fmt.Sscanf(r.FormValue("conexao_id"), "%d", &conexaoID)
	telefone := r.FormValue("telefone")
	tipoMidia := r.FormValue("tipo") // image, video, audio, document
	legenda := r.FormValue("legenda")

	if conexaoID == 0 || telefone == "" || tipoMidia == "" {
		app.Logger.Printf("Parametros invalidos")
		http.Error(w, "Parametros obrigatorios: conexao_id, telefone, tipo", http.StatusBadRequest)
		return
	}

	// Obter arquivo do upload
	file, header, err := r.FormFile("arquivo")
	if err != nil {
		app.Logger.Printf("Erro ao obter arquivo: %v", err)
		http.Error(w, "Arquivo nao encontrado", http.StatusBadRequest)
		return
	}
	defer file.Close()

	app.Mutex.RLock()
	session, exists := app.Sessions[conexaoID]
	app.Mutex.RUnlock()

	if !exists || session.Client == nil || !session.Client.IsConnected() {
		app.Logger.Printf("Sessao %d nao esta conectada", conexaoID)
		respondJSON(w, map[string]interface{}{
			"success": false,
			"error":   "WhatsApp nao conectado",
		})
		return
	}

	// Salvar arquivo temporariamente
	tempDir := "./temp_uploads"
	if err := os.MkdirAll(tempDir, 0755); err != nil {
		app.Logger.Printf("Erro ao criar diretorio temporario: %v", err)
		http.Error(w, "Erro interno", http.StatusInternalServerError)
		return
	}

	tempPath := filepath.Join(tempDir, fmt.Sprintf("%d_%s", time.Now().Unix(), header.Filename))
	tempFile, err := os.Create(tempPath)
	if err != nil {
		app.Logger.Printf("Erro ao criar arquivo temporario: %v", err)
		http.Error(w, "Erro interno", http.StatusInternalServerError)
		return
	}

	_, err = io.Copy(tempFile, file)
	tempFile.Close()
	if err != nil {
		os.Remove(tempPath)
		app.Logger.Printf("Erro ao salvar arquivo: %v", err)
		http.Error(w, "Erro ao salvar arquivo", http.StatusInternalServerError)
		return
	}

	// Enviar midia
	messageID, err := handlers.SendMediaMessage(session, telefone, tipoMidia, tempPath, legenda, app.Logger)

	// Remover arquivo temporario
	os.Remove(tempPath)

	if err != nil {
		app.Logger.Printf("Erro ao enviar midia: %v", err)
		respondJSON(w, map[string]interface{}{
			"success": false,
			"error":   err.Error(),
		})
		return
	}

	app.Logger.Printf("Midia enviada com sucesso para %s (ID: %s)", telefone, messageID)

	// Callback de status
	go handlers.HandleSentMessageEvent(messageID, app.Config.StatusWebhookURL, app.Config.APIKey, app.Logger)

	respondJSON(w, map[string]interface{}{
		"success":    true,
		"message_id": messageID,
	})
}

// respondJSON envia resposta JSON
func respondJSON(w http.ResponseWriter, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(data)
}
