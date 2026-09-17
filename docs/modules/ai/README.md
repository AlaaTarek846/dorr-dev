# AI Module

**Path:** `Modules/AI/`  
**Namespace:** `Modules\AI\`  
**Purpose:** AI provider configuration (admin) and user AI chat conversations.

---

## Components

| Layer | Classes |
|-------|---------|
| Models | `AiProvider`, `AiConversation`, `AiMessage` |
| Controllers | `AiProviderController`, `AiChatController` |
| Services | `AiProviderService`, `AiChatService`, `AiGateway` |
| Connectors | OpenAI, Anthropic, Google, Groq |
| Enum | `AiProviderKey` |
| Routes | `routes/admin.php`, `routes/user.php` |

---

## Frontend

- Admin: `/admin/ai-settings` → `views/ai-settings/index.vue`
- User: `/user/chat` → `views/chat/index.vue` + `components/chat/*`

See [API.md](./API.md).
