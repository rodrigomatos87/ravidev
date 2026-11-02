// handlers/session.go

package handlers

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	waLog "go.mau.fi/whatsmeow/util/log"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"google.golang.org/protobuf/proto"
	"github.com/disintegration/imaging"

	_ "github.com/mattn/go-sqlite3"
)

// Session representa uma sessão WhatsApp
type Session struct {
	UsuarioID  int
	ConexaoID  int
	Client     *whatsmeow.Client
	QRChan     chan string
	Connected  bool
	LastCheck  time.Time
}

// CreateSession cria nova sessão WhatsApp
func CreateSession(usuarioID, conexaoID int, sessionsDir string, logger *log.Logger) (*Session, string, error) {
	// Criar diretório específico para esta conexão
	sessionPath := filepath.Join(sessionsDir, fmt.Sprintf("user_%d_conn_%d", usuarioID, conexaoID))
	if err := os.MkdirAll(sessionPath, 0755); err != nil {
		return nil, "", fmt.Errorf("erro ao criar diretório de sessão: %v", err)
	}

	// Criar store SQLite
	dbPath := filepath.Join(sessionPath, "session.db")
	container, err := sqlstore.New(context.Background(), "sqlite3", fmt.Sprintf("file:%s?_foreign_keys=on", dbPath), waLog.Noop)
	if err != nil {
		return nil, "", fmt.Errorf("erro ao criar store: %v", err)
	}

	// Obter ou criar device
	deviceStore, err := container.GetFirstDevice(context.Background())
	if err != nil {
		return nil, "", fmt.Errorf("erro ao obter device: %v", err)
	}

	if deviceStore == nil {
		deviceStore = container.NewDevice()
	}

	// Criar cliente WhatsApp
	client := whatsmeow.NewClient(deviceStore, waLog.Noop)

	session := &Session{
		UsuarioID:  usuarioID,
		ConexaoID:  conexaoID,
		Client:     client,
		QRChan:     make(chan string, 1),
		Connected:  false,
		LastCheck:  time.Now(),
	}

	// Se já está logado, conectar
	if client.Store.ID != nil {
		logger.Printf("Sessão %d já possui credenciais, conectando...", conexaoID)

		// Adicionar handler temporário para reconexão
		client.AddEventHandler(func(evt interface{}) {
			switch evt.(type) {
			case *events.Connected:
				logger.Printf("Sessão %d reconectada com sucesso (já tinha credenciais)", conexaoID)
				session.Connected = true
			case *events.LoggedOut:
				logger.Printf("Sessão %d foi deslogada durante reconexão", conexaoID)
				session.Connected = false
			case *events.StreamReplaced:
				logger.Printf("Sessão %d: Stream substituído durante reconexão", conexaoID)
				session.Connected = false
			}
		})

		err := client.Connect()
		if err != nil {
			logger.Printf("Erro ao reconectar sessão %d: %v", conexaoID, err)
			// Continuar mesmo com erro, tentará gerar novo QR
		} else {
			// Aguardar um pouco para garantir que a conexão está estabelecida
			time.Sleep(2 * time.Second)
			if client.IsConnected() {
				session.Connected = true
				return session, "", nil
			}
			logger.Printf("Sessão %d não conectou após timeout, gerando QR Code", conexaoID)
		}
	}

	// Configurar handler de eventos de conexão (QR vem via GetQRChannel nas versoes recentes)
	qrCode := ""
	client.AddEventHandler(func(evt interface{}) {
		switch v := evt.(type) {
		case *events.Connected:
			// Após PairSuccess, o WhatsApp SEMPRE reconecta o websocket
			// Só podemos considerar conectado após essa reconexão
			if client.Store != nil && client.Store.ID != nil {
				logger.Printf("Sessão %d: WebSocket reconectado após pairing", conexaoID)
				session.Connected = true
			} else {
				logger.Printf("Sessão %d: WebSocket conectado (aguardando pairing)", conexaoID)
			}
		case *events.PairSuccess:
			logger.Printf("Sessão %d: Pairing completado com sucesso!", conexaoID)
			// NÃO marcar Connected aqui - aguardar reconexão WebSocket
			
			// Confirmar que estamos prontos para receber mensagens
			if client.Store != nil && client.Store.ID != nil {
				logger.Printf("Sessão %d autenticada como +%s", conexaoID, client.Store.ID.User)
			}
			logger.Printf("Sessão %d: Aguardando reconexão automática do WhatsApp...", conexaoID)
		case *events.LoggedOut:
			logger.Printf("Sessão %d deslogada - Razão: %+v", conexaoID, v.Reason)
			session.Connected = false
		case *events.HistorySync:
			logger.Printf("Sessão %d: Sincronizando histórico (tipo: %v)", conexaoID, v.Data.GetSyncType())
			// A sincronização de histórico é normal e não requer ação
		case *events.StreamReplaced:
			logger.Printf("Sessão %d: Stream substituído (outra conexão ativa)", conexaoID)
			session.Connected = false
		case *events.Disconnected:
			logger.Printf("Sessão %d: Desconectado", conexaoID)
			session.Connected = false
		case *events.TemporaryBan:
			logger.Printf("Sessão %d: Ban temporário - Código: %s", conexaoID, v.Code)
			session.Connected = false
		case *events.PushNameSetting:
			logger.Printf("Sessão %d: Nome do dispositivo configurado", conexaoID)
		}
	})

	// IMPORTANTE: Obter canal de QR ANTES de Connect() - API correta do whatsmeow atual
	// events.QR foi descontinuado; agora o QR vem via GetQRChannel
	logger.Printf("Sessao %d: solicitando canal de QR (Store.ID nil=%v)", conexaoID, client.Store.ID == nil)
	qrChan, qrErr := client.GetQRChannel(context.Background())
	if qrErr != nil {
		logger.Printf("Sessao %d: GetQRChannel retornou erro: %v", conexaoID, qrErr)
		return nil, "", fmt.Errorf("erro ao obter canal de QR: %v", qrErr)
	}
	logger.Printf("Sessao %d: canal de QR obtido com sucesso", conexaoID)

	// Conectar
	err = client.Connect()
	if err != nil {
		return nil, "", fmt.Errorf("erro ao conectar: %v", err)
	}
	logger.Printf("Sessao %d: Connect() OK, aguardando evento do QR channel...", conexaoID)

	// Aguardar eventos do QR channel (timeout 30s)
	qrTimeout := time.After(30 * time.Second)
QRLoop:
	for {
		select {
		case evt, ok := <-qrChan:
			if !ok {
				logger.Printf("Sessao %d: canal de QR foi fechado sem evento", conexaoID)
				break QRLoop
			}
			logger.Printf("Sessao %d: evento QR channel recebido: event=%s code_len=%d", conexaoID, evt.Event, len(evt.Code))
			switch evt.Event {
			case "code":
				qrCode = evt.Code
				select {
				case session.QRChan <- qrCode:
				default:
				}
				break QRLoop
			case "success":
				logger.Printf("Sessao %d: pairing sucesso via QR channel", conexaoID)
				session.Connected = true
				break QRLoop
			case "timeout":
				logger.Printf("Sessao %d: QR timeout reportado pelo whatsmeow", conexaoID)
				break QRLoop
			case "err-client-outdated":
				logger.Printf("Sessao %d: ERRO cliente whatsmeow desatualizado - atualizar biblioteca", conexaoID)
				break QRLoop
			default:
				logger.Printf("Sessao %d: evento desconhecido: %s", conexaoID, evt.Event)
			}
		case <-qrTimeout:
			if !session.Connected {
				logger.Printf("Timeout aguardando QR Code para sessao %d (30s)", conexaoID)
			}
			break QRLoop
		}
	}

	// Se já conectou, retornar sem QR
	if session.Connected {
		return session, "", nil
	}

	if qrCode == "" {
		logger.Printf("Sessao %d: AVISO - QR code vazio sera retornado", conexaoID)
	} else {
		logger.Printf("Sessao %d: QR code gerado com sucesso (%d chars)", conexaoID, len(qrCode))
	}

	// Retornar com QR Code em formato data URL
	qrDataURL := fmt.Sprintf("data:text/plain;base64,%s", base64.StdEncoding.EncodeToString([]byte(qrCode)))

	return session, qrDataURL, nil
}

// SendMessage envia mensagem de texto via WhatsApp
func SendMessage(session *Session, telefone, mensagem string, replyToMessageID string, logger *log.Logger) (string, error) {
	if session.Client == nil || !session.Client.IsConnected() {
		return "", fmt.Errorf("cliente não conectado")
	}

	cleanPhone := ""
	for _, c := range telefone {
		if c >= '0' && c <= '9' {
			cleanPhone += string(c)
		}
	}

	jid := types.NewJID(cleanPhone, types.DefaultUserServer)

	// Forcar resolucao do LID/PN antes do envio (whatsmeow novo exige isso)
	ctx := context.Background()

	// 1) IsOnWhatsApp: consulta servidor e popula LID cache
	respIsOnWA, err := session.Client.IsOnWhatsApp(ctx, []string{"+" + cleanPhone})
	if err != nil {
		logger.Printf("Aviso: IsOnWhatsApp falhou para %s: %v", cleanPhone, err)
	} else if len(respIsOnWA) > 0 {
		if !respIsOnWA[0].IsIn {
			return "", fmt.Errorf("numero %s nao esta no WhatsApp", cleanPhone)
		}
		// Se retornou um JID resolvido, usar ele (pode vir com LID associado)
		if !respIsOnWA[0].JID.IsEmpty() {
			jid = respIsOnWA[0].JID
			logger.Printf("JID resolvido para %s: %s", cleanPhone, jid.String())
		}
	}

	// 2) GetUserInfo: forca o lookup completo e popula o PN/LID store
	_, userInfoErr := session.Client.GetUserInfo(ctx, []types.JID{jid})
	if userInfoErr != nil {
		logger.Printf("Aviso: GetUserInfo falhou para %s: %v (tentando enviar mesmo assim)", jid.String(), userInfoErr)
	}

	var msg *waE2E.Message

	// Se for resposta, construir com ContextInfo
	if replyToMessageID != "" {
		logger.Printf("Enviando resposta à mensagem: %s", replyToMessageID)
		
		msg = &waE2E.Message{
			ExtendedTextMessage: &waE2E.ExtendedTextMessage{
				Text: &mensagem,
				ContextInfo: &waE2E.ContextInfo{
					StanzaID:      proto.String(replyToMessageID),
					Participant:   proto.String(jid.String()),
					QuotedMessage: &waE2E.Message{
						Conversation: proto.String(""),
					},
				},
			},
		}
	} else {
		// Mensagem normal
		msg = &waE2E.Message{
			Conversation: &mensagem,
		}
	}

	resp, err := session.Client.SendMessage(context.Background(), jid, msg)
	if err != nil {
		return "", fmt.Errorf("erro ao enviar mensagem: %v", err)
	}

	logger.Printf("Mensagem enviada para %s via sessão %d", telefone, session.ConexaoID)
	return resp.ID, nil
}

// SendMediaMessage envia mídia (imagem, vídeo, áudio, documento)
func SendMediaMessage(session *Session, telefone, tipoMidia, caminhoArquivo, legenda string, logger *log.Logger) (string, error) {
	if session.Client == nil || !session.Client.IsConnected() {
		return "", fmt.Errorf("cliente não conectado")
	}

	// Ler arquivo
	data, err := os.ReadFile(caminhoArquivo)
	if err != nil {
		return "", fmt.Errorf("erro ao ler arquivo: %v", err)
	}

	// Detectar mimetype dos bytes reais do arquivo (não da extensão)
	mimetype := http.DetectContentType(data)
	
	logger.Printf("=== ENVIO DE MÍDIA ===")
	logger.Printf("Arquivo: %s", caminhoArquivo)
	logger.Printf("Tamanho: %d bytes", len(data))
	logger.Printf("Tipo: %s", tipoMidia)
	logger.Printf("Mimetype: %s", mimetype)

	// Mapear tipo para MediaType do whatsmeow
	var mediaType whatsmeow.MediaType
	switch tipoMidia {
	case "image":
		mediaType = whatsmeow.MediaImage
	case "video":
		mediaType = whatsmeow.MediaVideo
	case "audio":
		mediaType = whatsmeow.MediaAudio
	case "document":
		mediaType = whatsmeow.MediaDocument
	default:
		return "", fmt.Errorf("tipo de mídia inválido: %s", tipoMidia)
	}

	// Upload
	logger.Printf("Iniciando upload...")
	uploaded, err := session.Client.Upload(context.Background(), data, mediaType)
	if err != nil {
		return "", fmt.Errorf("erro ao fazer upload: %v", err)
	}

	logger.Printf("Upload OK - URL: %s", uploaded.URL)
	logger.Printf("DirectPath: %s", uploaded.DirectPath)
	logger.Printf("FileLength: %d", uploaded.FileLength)

	// Preparar JID
	cleanPhone := ""
	for _, c := range telefone {
		if c >= '0' && c <= '9' {
			cleanPhone += string(c)
		}
	}
	jid := types.NewJID(cleanPhone, types.DefaultUserServer)

	var msg *waE2E.Message

	switch tipoMidia {
	case "image":
		// Gerar thumbnail EXATAMENTE como repositório original (salvar em arquivo)
		srcImage, err := imaging.Open(caminhoArquivo)
		if err != nil {
			return "", fmt.Errorf("falha ao abrir imagem para thumbnail: %v", err)
		}

		// Resize thumbnail
		resizedImage := imaging.Resize(srcImage, 100, 0, imaging.Lanczos)
		
		// IMPORTANTE: Salvar thumbnail em arquivo e depois ler (como repositório original)
		thumbPath := caminhoArquivo + "_thumb.jpg"
		if err = imaging.Save(resizedImage, thumbPath, imaging.JPEGQuality(50)); err != nil {
			return "", fmt.Errorf("falha ao salvar thumbnail: %v", err)
		}
		
		// Ler thumbnail do arquivo
		thumbBytes, err := os.ReadFile(thumbPath)
		if err != nil {
			os.Remove(thumbPath)
			return "", fmt.Errorf("falha ao ler thumbnail: %v", err)
		}
		
		// Limpar arquivo temporário de thumbnail
		defer os.Remove(thumbPath)

		// Construir mensagem EXATAMENTE como repositório original
		msg = &waE2E.Message{
			ImageMessage: &waE2E.ImageMessage{
				JPEGThumbnail: thumbBytes,
				Caption:       proto.String(legenda),
				URL:           proto.String(uploaded.URL),
				DirectPath:    proto.String(uploaded.DirectPath),
				MediaKey:      uploaded.MediaKey,
				Mimetype:      proto.String(mimetype),
				FileEncSHA256: uploaded.FileEncSHA256,
				FileSHA256:    uploaded.FileSHA256,
				FileLength:    proto.Uint64(uint64(len(data))),
			},
		}

	case "video":
		// Vídeos precisam de thumbnail - usar um thumbnail genérico simples
		thumbBytes := []byte{
			0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 
			0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01,
			0x00, 0x01, 0x00, 0x00, 0xFF, 0xDB, 0x00, 0x43,
			0x00, 0xFF, 0xC0, 0x00, 0x0B, 0x08, 0x00, 0x01,
			0x00, 0x01, 0x01, 0x01, 0x11, 0x00, 0xFF, 0xC4,
			0x00, 0x14, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00,
			0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
			0x00, 0x00, 0x00, 0x00, 0xFF, 0xDA, 0x00, 0x08,
			0x01, 0x01, 0x00, 0x00, 0x3F, 0x00, 0x7F, 0xFF,
			0xD9,
		}
		
		// Usar mimetype baseado em extensão para vídeo
		videoMimetype := detectMimeType(caminhoArquivo)
		videoMsg := &waE2E.VideoMessage{
			URL:                 proto.String(uploaded.URL),
			DirectPath:          proto.String(uploaded.DirectPath),
			MediaKey:            uploaded.MediaKey,
			Mimetype:            proto.String(videoMimetype),
			FileEncSHA256:       uploaded.FileEncSHA256,
			FileSHA256:          uploaded.FileSHA256,
			FileLength:          proto.Uint64(uploaded.FileLength),
			JPEGThumbnail:       thumbBytes,
			ThumbnailEncSHA256:  thumbBytes,
			ThumbnailSHA256:     thumbBytes,
			ThumbnailDirectPath: proto.String(uploaded.DirectPath),
		}
		
		if legenda != "" {
			videoMsg.Caption = proto.String(legenda)
		}
		
		msg = &waE2E.Message{
			VideoMessage: videoMsg,
		}

	case "audio":
		// Recebe OGG (convertido pelo PHP se necessário)
		msg = &waE2E.Message{
			AudioMessage: &waE2E.AudioMessage{
				URL:           proto.String(uploaded.URL),
				DirectPath:    proto.String(uploaded.DirectPath),
				Mimetype:      proto.String("audio/ogg; codecs=opus"),
				FileLength:    proto.Uint64(uploaded.FileLength),
				FileSHA256:    uploaded.FileSHA256,
				FileEncSHA256: uploaded.FileEncSHA256,
				MediaKey:      uploaded.MediaKey,
			},
		}

	case "document":
		fileName := filepath.Base(caminhoArquivo)
		docMsg := &waE2E.DocumentMessage{
			URL:           proto.String(uploaded.URL),
			DirectPath:    proto.String(uploaded.DirectPath),
			MediaKey:      uploaded.MediaKey,
			Mimetype:      proto.String(mimetype),
			FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256:    uploaded.FileSHA256,
			FileLength:    proto.Uint64(uint64(len(data))),
			FileName:      proto.String(fileName),
			Title:         proto.String(fileName), // Título do documento
			PageCount:     proto.Uint32(0),        // Número de páginas (0 = desconhecido)
		}
		
		if legenda != "" {
			docMsg.Caption = proto.String(legenda)
		}
		
		msg = &waE2E.Message{
			DocumentMessage: docMsg,
		}

	default:
		return "", fmt.Errorf("tipo de mídia não suportado: %s", tipoMidia)
	}

	// DEBUG: Logar estrutura completa da mensagem
	logger.Printf("=== ESTRUTURA DA MENSAGEM ===")
	if msg.ImageMessage != nil {
		im := msg.ImageMessage
		logger.Printf("ImageMessage presente:")
		logger.Printf("  URL: %s", im.GetURL())
		logger.Printf("  DirectPath: %s", im.GetDirectPath())
		logger.Printf("  MediaKey length: %d", len(im.MediaKey))
		logger.Printf("  Mimetype: %s", im.GetMimetype())
		logger.Printf("  FileLength: %d", im.GetFileLength())
		logger.Printf("  FileSHA256 length: %d", len(im.FileSHA256))
		logger.Printf("  FileEncSHA256 length: %d", len(im.FileEncSHA256))
		logger.Printf("  JPEGThumbnail length: %d", len(im.JPEGThumbnail))
		logger.Printf("  Caption: %s", im.GetCaption())
	}

	logger.Printf("Enviando mensagem...")

	// Enviar
	resp, err := session.Client.SendMessage(context.Background(), jid, msg)

	logger.Printf("Mídia (%s) enviada com sucesso! ID: %s", tipoMidia, resp.ID)
	return resp.ID, nil
}

// detectMimeType detecta o mimetype baseado na extensão do arquivo
func detectMimeType(filePath string) string {
	ext := strings.ToLower(filepath.Ext(filePath))
	
	mimeTypes := map[string]string{
		".jpg":  "image/jpeg",
		".jpeg": "image/jpeg",
		".png":  "image/png",
		".gif":  "image/gif",
		".webp": "image/webp",
		".mp4":  "video/mp4",
		".3gp":  "video/3gpp",
		".mov":  "video/quicktime",
		".ogg":  "audio/ogg; codecs=opus",
		".mp3":  "audio/mpeg",
		".m4a":  "audio/mp4",
		".pdf":  "application/pdf",
		".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
		".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
		".txt":  "text/plain",
	}
	
	if mime, ok := mimeTypes[ext]; ok {
		return mime
	}
	
	return "application/octet-stream"
}

// HandleIncomingMessage processa mensagens recebidas (texto e mídia)
func HandleIncomingMessage(evt interface{}, session *Session, webhookURL string, apiKey string, logger *log.Logger) {
	switch v := evt.(type) {
	case *events.Message:
		if v.Info.IsFromMe {
			return
		}

		// FILTRO 1: Ignorar mensagens de grupos
		if v.Info.IsGroup {
			logger.Printf("Sessão %d: Ignorando mensagem de grupo %s", session.ConexaoID, v.Info.Chat.String())
			return
		}

		// FILTRO 2: Ignorar mensagens de canais/newsletters/broadcasts
		chatServer := v.Info.Chat.Server
		if chatServer != types.DefaultUserServer && chatServer != "lid" {
			logger.Printf("Sessão %d: Ignorando mensagem de canal/newsletter (server: %s)", session.ConexaoID, chatServer)
			return
		}

		// FILTRO 3: Ignorar mensagens antigas (mais de 2 minutos)
		// Isso evita processar mensagens da sincronização de histórico
		messageAge := time.Since(v.Info.Timestamp)
		if messageAge > 2*time.Minute {
			logger.Printf("Sessão %d: Ignorando mensagem antiga (%v atrás) de %s", 
				session.ConexaoID, messageAge.Round(time.Second), v.Info.Sender.User)
			return
		}

		// Extrair número de telefone do JID
		// Pode ser LID (Linked Identity) que precisa ser resolvido
		telefoneReal := ""
		
		// Se for LID (Server == "lid"), tentar resolver para número real
		if v.Info.Chat.Server == "lid" || v.Info.Sender.Server == "lid" {
			logger.Printf("Detectado LID, tentando resolver para número real...")
			
			// Usar GetPNForLID do LIDStore para resolver LID -> Phone Number
			if session.Client.Store != nil && session.Client.Store.LIDs != nil {
				pnJID, err := session.Client.Store.LIDs.GetPNForLID(context.Background(), v.Info.Chat)
				if err == nil && pnJID.Server == types.DefaultUserServer {
					telefoneReal = pnJID.User
					logger.Printf("Número resolvido via GetPNForLID: %s", telefoneReal)
				} else {
					logger.Printf("GetPNForLID falhou: %v (JID: %s)", err, pnJID.String())
					
					// Fallback: tentar com Sender se Chat falhou
					pnJID, err = session.Client.Store.LIDs.GetPNForLID(context.Background(), v.Info.Sender)
					if err == nil && pnJID.Server == types.DefaultUserServer {
						telefoneReal = pnJID.User
						logger.Printf("Número resolvido via GetPNForLID (Sender): %s", telefoneReal)
					} else {
						logger.Printf("GetPNForLID (Sender) também falhou: %v - IGNORANDO mensagem", err)
						return // CRÍTICO: Não processar se não conseguir resolver LID
					}
				}
			} else {
				logger.Printf("LIDStore não disponível - IGNORANDO mensagem de LID")
				return // CRÍTICO: Não processar se LIDStore não disponível
			}
		} else if v.Info.Chat.Server == types.DefaultUserServer {
			// JID normal (s.whatsapp.net), usar diretamente
			telefoneReal = v.Info.Chat.User
		} else {
			logger.Printf("Sessão %d: Server desconhecido (%s) - IGNORANDO", session.ConexaoID, v.Info.Chat.Server)
			return
		}

		// FILTRO 4: Validar que telefoneReal é um número válido (10-15 dígitos)
		if len(telefoneReal) < 10 || len(telefoneReal) > 15 {
			logger.Printf("Sessão %d: Número inválido (%s, %d dígitos) - IGNORANDO", 
				session.ConexaoID, telefoneReal, len(telefoneReal))
			return
		}
		
		// Verificar se contém apenas dígitos
		for _, c := range telefoneReal {
			if c < '0' || c > '9' {
				logger.Printf("Sessão %d: Número contém caracteres inválidos (%s) - IGNORANDO", 
					session.ConexaoID, telefoneReal)
				return
			}
		}
		
		logger.Printf("Mensagem recebida na sessão %d de %s (original: %s)", 
			session.ConexaoID, telefoneReal, v.Info.Sender.User)

		webhookData := map[string]interface{}{
			"conexao_id": session.ConexaoID,
			"telefone":   fmt.Sprintf("+%s", telefoneReal),
			"timestamp":  v.Info.Timestamp.Unix(),
			"message_id": v.Info.ID,
		}

		// Processar mensagem de texto
		if v.Message.Conversation != nil {
			webhookData["tipo"] = "texto"
			webhookData["mensagem"] = *v.Message.Conversation
			go sendWebhook(webhookURL, webhookData, apiKey, logger)
			return
		}

		logger.Printf("=== MENSAGEM RECEBIDA DEBUG ===")
		logger.Printf("Conversation: %v", v.Message.Conversation != nil)
		logger.Printf("ExtendedTextMessage: %v", v.Message.ExtendedTextMessage != nil)
		if v.Message.ExtendedTextMessage != nil {
			logger.Printf("ContextInfo existe: %v", v.Message.ExtendedTextMessage.ContextInfo != nil)
			if v.Message.ExtendedTextMessage.ContextInfo != nil {
				logger.Printf("StanzaID existe: %v", v.Message.ExtendedTextMessage.ContextInfo.StanzaID != nil)
			}
		}

		if v.Message.ExtendedTextMessage != nil && v.Message.ExtendedTextMessage.Text != nil {
			webhookData["tipo"] = "texto"
			webhookData["mensagem"] = *v.Message.ExtendedTextMessage.Text
			
			// Detectar se é resposta (reply)
			if v.Message.ExtendedTextMessage.ContextInfo != nil && v.Message.ExtendedTextMessage.ContextInfo.StanzaID != nil {
				webhookData["reply_to_message_id"] = *v.Message.ExtendedTextMessage.ContextInfo.StanzaID
				logger.Printf("Mensagem é uma resposta a: %s", *v.Message.ExtendedTextMessage.ContextInfo.StanzaID)
			}
			
			go sendWebhook(webhookURL, webhookData, apiKey, logger)
			return
		}

		// Processar imagem
		if v.Message.ImageMessage != nil {
			webhookData["tipo"] = "image"
			if v.Message.ImageMessage.Caption != nil {
				webhookData["legenda"] = *v.Message.ImageMessage.Caption
			}
			webhookData["mimetype"] = v.Message.ImageMessage.GetMimetype()
			
			// Download da mídia
			if mediaPath := downloadMedia(session, v.Message.ImageMessage, v.Info.ID, logger); mediaPath != "" {
				webhookData["arquivo"] = mediaPath
			}
			
			go sendWebhook(webhookURL, webhookData, apiKey, logger)
			return
		}

		// Processar vídeo
		if v.Message.VideoMessage != nil {
			webhookData["tipo"] = "video"
			if v.Message.VideoMessage.Caption != nil {
				webhookData["legenda"] = *v.Message.VideoMessage.Caption
			}
			webhookData["mimetype"] = v.Message.VideoMessage.GetMimetype()
			
			if mediaPath := downloadMedia(session, v.Message.VideoMessage, v.Info.ID, logger); mediaPath != "" {
				webhookData["arquivo"] = mediaPath
			}
			
			go sendWebhook(webhookURL, webhookData, apiKey, logger)
			return
		}

		// Processar áudio
		if v.Message.AudioMessage != nil {
			webhookData["tipo"] = "audio"
			webhookData["mimetype"] = v.Message.AudioMessage.GetMimetype()
			if v.Message.AudioMessage.PTT != nil && *v.Message.AudioMessage.PTT {
				webhookData["ptt"] = true
			}
			
			if mediaPath := downloadMedia(session, v.Message.AudioMessage, v.Info.ID, logger); mediaPath != "" {
				webhookData["arquivo"] = mediaPath
			}
			
			go sendWebhook(webhookURL, webhookData, apiKey, logger)
			return
		}

		// Processar documento
		if v.Message.DocumentMessage != nil {
			webhookData["tipo"] = "document"
			if v.Message.DocumentMessage.Caption != nil {
				webhookData["legenda"] = *v.Message.DocumentMessage.Caption
			}
			if v.Message.DocumentMessage.FileName != nil {
				webhookData["nome_arquivo"] = *v.Message.DocumentMessage.FileName
			}
			webhookData["mimetype"] = v.Message.DocumentMessage.GetMimetype()
			
			if mediaPath := downloadMedia(session, v.Message.DocumentMessage, v.Info.ID, logger); mediaPath != "" {
				webhookData["arquivo"] = mediaPath
			}
			
			go sendWebhook(webhookURL, webhookData, apiKey, logger)
			return
		}

		logger.Printf("Tipo de mensagem não suportado, ignorando")
	}
}

// downloadMedia faz download de mídia recebida
func downloadMedia(session *Session, downloadable whatsmeow.DownloadableMessage, messageID string, logger *log.Logger) string {
	data, err := session.Client.Download(context.Background(), downloadable)
	if err != nil {
		logger.Printf("Erro ao baixar mídia: %v", err)
		return ""
	}

	// Determinar extensão baseada no mimetype
	ext := ".bin"
	mimetype := ""
	
	switch msg := downloadable.(type) {
	case *waE2E.ImageMessage:
		mimetype = msg.GetMimetype()
	case *waE2E.VideoMessage:
		mimetype = msg.GetMimetype()
	case *waE2E.AudioMessage:
		mimetype = msg.GetMimetype()
	case *waE2E.DocumentMessage:
		mimetype = msg.GetMimetype()
	}

	switch mimetype {
	case "image/jpeg", "image/jpg":
		ext = ".jpg"
	case "image/png":
		ext = ".png"
	case "image/gif":
		ext = ".gif"
	case "image/webp":
		ext = ".webp"
	case "video/mp4":
		ext = ".mp4"
	case "video/3gpp":
		ext = ".3gp"
	case "audio/ogg; codecs=opus", "audio/ogg":
		ext = ".ogg"
	case "audio/mpeg":
		ext = ".mp3"
	case "audio/mp4":
		ext = ".m4a"
	case "application/pdf":
		ext = ".pdf"
	case "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
		ext = ".docx"
	case "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet":
		ext = ".xlsx"
	}

	// Criar diretório de downloads se não existir
	downloadDir := "./downloads"
	if err := os.MkdirAll(downloadDir, 0755); err != nil {
		logger.Printf("Erro ao criar diretório de downloads: %v", err)
		return ""
	}

	// Salvar arquivo
	fileName := fmt.Sprintf("%s_%d%s", messageID, time.Now().Unix(), ext)
	filePath := filepath.Join(downloadDir, fileName)
	
	if err := os.WriteFile(filePath, data, 0644); err != nil {
		logger.Printf("Erro ao salvar mídia: %v", err)
		return ""
	}

	logger.Printf("Mídia salva: %s", filePath)
	return filePath
}

// sendWebhook envia dados para webhook PHP
func sendWebhook(url string, data map[string]interface{}, apiKey string, logger *log.Logger) {
	jsonData, err := json.Marshal(data)
	if err != nil {
		logger.Printf("Erro ao serializar webhook: %v", err)
		return
	}

	req, err := http.NewRequest("POST", url, bytes.NewBuffer(jsonData))
	if err != nil {
		logger.Printf("Erro ao criar requisição de webhook: %v", err)
		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-API-Key", apiKey)

	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		logger.Printf("Erro ao enviar webhook: %v", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		logger.Printf("Webhook retornou status %d", resp.StatusCode)
		return
	}

	logger.Printf("Webhook enviado com sucesso para %s", url)
}