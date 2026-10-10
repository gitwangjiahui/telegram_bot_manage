// Package client implements the Telegram Bot API methods used by botd.
package client

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"

	"github.com/jh/telegram-bots/botd/internal/tg/transport"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

const baseURL = "https://api.telegram.org"

// APIError is a business-level Telegram error (HTTP 4xx or ok=false).
type APIError struct {
	Code        int
	Description string
	RetryAfter  int // 0 unless error_code=429
}

func (e *APIError) Error() string {
	return fmt.Sprintf("telegram api error %d: %s", e.Code, e.Description)
}

// ProxySource returns the current http_proxy value.
type ProxySource func(ctx context.Context) (string, error)

// Client calls the Telegram API for a single bot token.
type Client struct {
	token    string
	tm       *transport.Manager
	proxySrc ProxySource
}

// New creates a bot client.
func New(token string, tm *transport.Manager, proxySrc ProxySource) *Client {
	return &Client{token: token, tm: tm, proxySrc: proxySrc}
}

// Token returns the bot token (callers must not log it).
func (c *Client) Token() string { return c.token }

type envelope struct {
	OK          bool            `json:"ok"`
	Result      json.RawMessage `json:"result"`
	ErrorCode   int             `json:"error_code"`
	Description string          `json:"description"`
	Parameters  struct {
		RetryAfter int `json:"retry_after"`
	} `json:"parameters"`
}

// do performs a request, retrying on 429. body is a prepared *http.Request body
// builder that produces a fresh request on each attempt.
func (c *Client) do(ctx context.Context, method string, build func() (string, io.Reader, string, error)) (json.RawMessage, error) {
	for {
		proxy, err := c.proxySrc(ctx)
		if err != nil {
			return nil, err
		}
		httpClient, err := c.tm.Client(proxy)
		if err != nil {
			return nil, err
		}

		url, body, contentType, err := build()
		if err != nil {
			return nil, err
		}
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, body)
		if err != nil {
			return nil, err
		}
		req.Header.Set("Content-Type", contentType)

		resp, err := httpClient.Do(req)
		if err != nil {
			// Transport-level failure: network error, caller classifies.
			return nil, err
		}
		raw, readErr := io.ReadAll(resp.Body)
		resp.Body.Close()
		if readErr != nil {
			return nil, readErr
		}

		var env envelope
		if jsonErr := json.Unmarshal(raw, &env); jsonErr != nil {
			// Non-JSON response: treat as network/transient.
			return nil, fmt.Errorf("non-json response (status %d): %s", resp.StatusCode, truncate(string(raw), 200))
		}

		if env.OK {
			return env.Result, nil
		}

		if env.ErrorCode == 429 {
			wait := env.Parameters.RetryAfter
			if wait <= 0 {
				wait = 1
			}
			if err := sleepCtx(ctx, wait); err != nil {
				return nil, err
			}
			continue
		}

		return nil, &APIError{
			Code:        errorCode(env.ErrorCode, resp.StatusCode),
			Description: env.Description,
			RetryAfter:  env.Parameters.RetryAfter,
		}
	}
}

func errorCode(envCode, httpStatus int) int {
	if envCode != 0 {
		return envCode
	}
	return httpStatus
}

func jsonBody(payload any) func() (string, io.Reader, string, error) {
	return func() (string, io.Reader, string, error) {
		b, err := json.Marshal(payload)
		if err != nil {
			return "", nil, "", err
		}
		return "", bytes.NewReader(b), "application/json", nil
	}
}

// GetUpdates calls getUpdates.
func (c *Client) GetUpdates(ctx context.Context, offset int64, timeout int) ([]types.Update, error) {
	payload := map[string]any{
		"offset":          offset,
		"timeout":         timeout,
		"limit":           100,
		"allowed_updates": []string{"message", "my_chat_member"},
	}
	res, err := c.doWithURL(ctx, "getUpdates", jsonBody(payload), 60)
	if err != nil {
		return nil, err
	}
	var updates []types.Update
	if err := json.Unmarshal(res, &updates); err != nil {
		return nil, err
	}
	return updates, nil
}

// SendMessage sends a text message, returning message_id.
func (c *Client) SendMessage(ctx context.Context, chatID int64, text, parseMode string) (int64, error) {
	payload := map[string]any{"chat_id": chatID, "text": text}
	if parseMode != "" {
		payload["parse_mode"] = parseMode
	}
	res, err := c.doWithURL(ctx, "sendMessage", jsonBody(payload), 20)
	if err != nil {
		return 0, err
	}
	var m types.Message
	if err := json.Unmarshal(res, &m); err != nil {
		return 0, err
	}
	return m.MessageID, nil
}

// SendPhotoFileID sends a photo by file_id (JSON body).
func (c *Client) SendPhotoFileID(ctx context.Context, chatID int64, fileID, caption string) (int64, error) {
	payload := map[string]any{"chat_id": chatID, "photo": fileID}
	if caption != "" {
		payload["caption"] = caption
	}
	res, err := c.doWithURL(ctx, "sendPhoto", jsonBody(payload), 20)
	if err != nil {
		return 0, err
	}
	var m types.Message
	if err := json.Unmarshal(res, &m); err != nil {
		return 0, err
	}
	return m.MessageID, nil
}

// SendPhotoUpload uploads a PNG, returning message_id and the largest PhotoSize.
func (c *Client) SendPhotoUpload(ctx context.Context, chatID int64, png []byte, caption string) (int64, types.PhotoSize, error) {
	build := func() (string, io.Reader, string, error) {
		var buf bytes.Buffer
		w := multipart.NewWriter(&buf)
		if err := w.WriteField("chat_id", fmt.Sprintf("%d", chatID)); err != nil {
			return "", nil, "", err
		}
		if caption != "" {
			if err := w.WriteField("caption", caption); err != nil {
				return "", nil, "", err
			}
		}
		part, err := w.CreateFormFile("photo", "captcha.png")
		if err != nil {
			return "", nil, "", err
		}
		if _, err := part.Write(png); err != nil {
			return "", nil, "", err
		}
		if err := w.Close(); err != nil {
			return "", nil, "", err
		}
		return "", &buf, w.FormDataContentType(), nil
	}
	res, err := c.doWithURL(ctx, "sendPhoto", build, 30)
	if err != nil {
		return 0, types.PhotoSize{}, err
	}
	var m types.Message
	if err := json.Unmarshal(res, &m); err != nil {
		return 0, types.PhotoSize{}, err
	}
	var largest types.PhotoSize
	if len(m.Photo) > 0 {
		largest = m.Photo[len(m.Photo)-1]
	}
	return m.MessageID, largest, nil
}

// ForwardMessage forwards a message, returning the new message_id.
func (c *Client) ForwardMessage(ctx context.Context, chatID, fromChatID, messageID int64) (int64, error) {
	payload := map[string]any{
		"chat_id":      chatID,
		"from_chat_id": fromChatID,
		"message_id":   messageID,
	}
	res, err := c.doWithURL(ctx, "forwardMessage", jsonBody(payload), 20)
	if err != nil {
		return 0, err
	}
	var m types.Message
	if err := json.Unmarshal(res, &m); err != nil {
		return 0, err
	}
	return m.MessageID, nil
}

// DeleteMessage deletes a message.
func (c *Client) DeleteMessage(ctx context.Context, chatID, messageID int64) error {
	payload := map[string]any{"chat_id": chatID, "message_id": messageID}
	_, err := c.doWithURL(ctx, "deleteMessage", jsonBody(payload), 15)
	return err
}

// GetMe calls getMe (best-effort validation).
func (c *Client) GetMe(ctx context.Context) (types.User, error) {
	res, err := c.doWithURL(ctx, "getMe", func() (string, io.Reader, string, error) {
		return "", nil, "application/json", nil
	}, 20)
	if err != nil {
		return types.User{}, err
	}
	var u types.User
	if err := json.Unmarshal(res, &u); err != nil {
		return types.User{}, err
	}
	return u, nil
}

// doWithURL wraps do, supplying the full method URL and per-call overall timeout.
func (c *Client) doWithURL(ctx context.Context, method string, build func() (string, io.Reader, string, error), timeoutS int) (json.RawMessage, error) {
	wrapped := func() (string, io.Reader, string, error) {
		_, body, ct, err := build()
		if err != nil {
			return "", nil, "", err
		}
		return fmt.Sprintf("%s/bot%s/%s", baseURL, c.token, method), body, ct, nil
	}
	callCtx := ctx
	var cancel context.CancelFunc
	if timeoutS > 0 {
		callCtx, cancel = context.WithTimeout(ctx, duration(timeoutS))
		defer cancel()
	}
	return c.do(callCtx, method, wrapped)
}
