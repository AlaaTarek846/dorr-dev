# AI — Requirements

## Implemented

### Admin
- List AI providers
- Update provider configuration (API keys, models, enabled flag)
- Test provider connection
- Set default provider (one at a time)

### User
- Check AI chat availability/status
- Create/list/show/delete conversations
- Send messages and receive AI responses
- Conversations scoped to authenticated user

## Supported Providers (AiProviderKey)

- openai
- anthropic
- google
- groq

## NEEDS-DECISION

- Rate limits per user
- Message token limits
- Streaming responses
- Async queue processing for long responses
- Conversation sharing/export
