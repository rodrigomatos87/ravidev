// handlers/message_status.go

package handlers

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"time"

	"go.mau.fi/whatsmeow/types/events"
)

// StatusUpdate representa atualização de status de mensagem
type StatusUpdate struct {
	MessageID string `json:"message_id"`
	Status    string `json:"status"` // enviada, entregue, lida, erro
	Timestamp string `json:"timestamp"`
}

// SendStatusCallback envia callback de status para o webhook PHP
func SendStatusCallback(messageID string, status string, webhookURL string, apiKey string, logger *log.Logger) error {
	callback := StatusUpdate{
		MessageID: messageID,
		Status:    status,
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	}

	jsonData, err := json.Marshal(callback)
	if err != nil {
		logger.Printf("Erro ao serializar callback de status: %v", err)
		return err
	}

	// Construir URL do webhook de status
	statusWebhookURL := webhookURL
	if len(webhookURL) > 0 {
		// Substituir webhook_whatsapp.php por webhook_status.php
		statusWebhookURL = webhookURL[:len(webhookURL)-len("webhook_whatsapp.php")] + "webhook_status.php"
	}

	req, err := http.NewRequest("POST", statusWebhookURL, bytes.NewBuffer(jsonData))
	if err != nil {
		logger.Printf("Erro ao criar requisição de callback: %v", err)
		return err
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-API-Key", apiKey)

	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		logger.Printf("Erro ao enviar callback de status: %v", err)
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		logger.Printf("Webhook de status retornou status %d para message_id %s", resp.StatusCode, messageID)
		return fmt.Errorf("status code: %d", resp.StatusCode)
	}

	logger.Printf("Callback de status enviado: message_id=%s, status=%s", messageID, status)
	return nil
}

// HandleReceiptEvent processa eventos de recibo (entregue, lida)
func HandleReceiptEvent(evt *events.Receipt, webhookURL string, apiKey string, logger *log.Logger) {
	if evt == nil {
		logger.Printf("Receipt event é nil, ignorando")
		return
	}

	logger.Printf("Processando Receipt: tipo=%s, sender=%s, message_ids=%v", 
		evt.Type, evt.Sender, evt.MessageIDs)

	// Determinar tipo de status
	var status string
	switch evt.Type {
	case events.ReceiptTypeDelivered:
		status = "entregue"
	case events.ReceiptTypeRead:
		status = "lida"
	default:
		logger.Printf("Tipo de receipt desconhecido: %s, ignorando", evt.Type)
		return
	}

	logger.Printf("Status mapeado: %s para %d mensagens", status, len(evt.MessageIDs))

	// Enviar callback para cada message_id no receipt
	for _, msgID := range evt.MessageIDs {
		go func(id string) {
			logger.Printf("Enviando callback: message_id=%s, status=%s", id, status)
			if err := SendStatusCallback(id, status, webhookURL, apiKey, logger); err != nil {
				logger.Printf("Erro ao enviar callback para %s: %v", id, err)
			}
		}(msgID)
	}
}

// HandleSentMessageEvent processa confirmação de mensagem enviada
func HandleSentMessageEvent(messageID string, webhookURL string, apiKey string, logger *log.Logger) {
	// Aguardar um pouco para garantir que mensagem foi realmente enviada
	time.Sleep(500 * time.Millisecond)

	go func() {
		if err := SendStatusCallback(messageID, "enviada", webhookURL, apiKey, logger); err != nil {
			logger.Printf("Erro ao enviar callback de enviada para %s: %v", messageID, err)
		}
	}()
}

// HandleErrorEvent processa erros de envio
func HandleErrorEvent(messageID string, webhookURL string, apiKey string, logger *log.Logger) {
	go func() {
		if err := SendStatusCallback(messageID, "erro", webhookURL, apiKey, logger); err != nil {
			logger.Printf("Erro ao enviar callback de erro para %s: %v", messageID, err)
		}
	}()
}