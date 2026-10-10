// Package types defines Telegram Bot API DTOs used by botd.
package types

// Update represents an incoming update.
type Update struct {
	UpdateID     int64              `json:"update_id"`
	Message      *Message           `json:"message,omitempty"`
	MyChatMember *ChatMemberUpdated `json:"my_chat_member,omitempty"`
}

// Message represents a Telegram message.
type Message struct {
	MessageID         int64           `json:"message_id"`
	From              *User           `json:"from,omitempty"`
	SenderChat        *Chat           `json:"sender_chat,omitempty"`
	Date              int64           `json:"date"`
	Chat              Chat            `json:"chat"`
	Text              string          `json:"text,omitempty"`
	Caption           string          `json:"caption,omitempty"`
	ReplyToMessage    *Message        `json:"reply_to_message,omitempty"`
	ForwardFrom       *User           `json:"forward_from,omitempty"`
	ForwardSenderName string          `json:"forward_sender_name,omitempty"`
	Photo             []PhotoSize     `json:"photo,omitempty"`
	Voice             *Voice          `json:"voice,omitempty"`
	Video             *Video          `json:"video,omitempty"`
	VideoNote         *VideoNote      `json:"video_note,omitempty"`
	Document          *Document       `json:"document,omitempty"`
	Sticker           *Sticker        `json:"sticker,omitempty"`
	Animation         *Animation      `json:"animation,omitempty"`
	Audio             *Audio          `json:"audio,omitempty"`
	Contact           *Contact        `json:"contact,omitempty"`
	Location          *Location       `json:"location,omitempty"`
	Entities          []MessageEntity `json:"entities,omitempty"`
	CaptionEntities   []MessageEntity `json:"caption_entities,omitempty"`
}

// User is a Telegram user.
type User struct {
	ID           int64  `json:"id"`
	IsBot        bool   `json:"is_bot"`
	FirstName    string `json:"first_name"`
	LastName     string `json:"last_name,omitempty"`
	Username     string `json:"username,omitempty"`
	LanguageCode string `json:"language_code,omitempty"`
}

// Chat is a Telegram chat.
type Chat struct {
	ID        int64  `json:"id"`
	Type      string `json:"type"`
	Title     string `json:"title,omitempty"`
	Username  string `json:"username,omitempty"`
	FirstName string `json:"first_name,omitempty"`
	LastName  string `json:"last_name,omitempty"`
}

// PhotoSize is a photo size.
type PhotoSize struct {
	FileID       string `json:"file_id"`
	FileUniqueID string `json:"file_unique_id"`
	Width        int    `json:"width"`
	Height       int    `json:"height"`
	FileSize     int64  `json:"file_size,omitempty"`
}

// Voice is a voice message.
type Voice struct {
	FileID   string `json:"file_id"`
	Duration int    `json:"duration"`
}

// Video is a video.
type Video struct {
	FileID   string `json:"file_id"`
	Width    int    `json:"width"`
	Height   int    `json:"height"`
	Duration int    `json:"duration"`
}

// VideoNote is a video note.
type VideoNote struct {
	FileID   string `json:"file_id"`
	Duration int    `json:"duration"`
}

// Document is a document.
type Document struct {
	FileID string `json:"file_id"`
}

// Sticker is a sticker.
type Sticker struct {
	FileID string `json:"file_id"`
}

// Animation is an animation.
type Animation struct {
	FileID string `json:"file_id"`
}

// Audio is an audio file.
type Audio struct {
	FileID string `json:"file_id"`
}

// Contact is a shared contact.
type Contact struct {
	PhoneNumber string `json:"phone_number"`
	FirstName   string `json:"first_name"`
}

// Location is a location point.
type Location struct {
	Longitude float64 `json:"longitude"`
	Latitude  float64 `json:"latitude"`
}

// MessageEntity is a message entity.
type MessageEntity struct {
	Type   string `json:"type"`
	Offset int    `json:"offset"`
	Length int    `json:"length"`
}

// ChatMemberUpdated describes my_chat_member changes.
type ChatMemberUpdated struct {
	Chat          Chat       `json:"chat"`
	From          User       `json:"from"`
	NewChatMember ChatMember `json:"new_chat_member"`
}

// ChatMember is a chat member.
type ChatMember struct {
	Status string `json:"status"`
	User   User   `json:"user"`
}
