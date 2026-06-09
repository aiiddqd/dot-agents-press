# MVP - dot agents press - as OpenClaw Alternative - powered by WordPress

**RFC: WordPress Agent Gateway Plugin (WAGP)**  
**Title:** WP Agent Entry Point + Telegram Bridge (with OpenClaw / .agents Protocol support)  
**Version:** 0.1 (Draft)  
**Date:** June 9, 2026  
**Status:** Proposal

### 1. Introduction & Motivation

**OpenClaw** is an open, self-hosted AI agent (a personal "AI butler") that runs locally or on a VPS, connects to messengers (Telegram, WhatsApp, etc.), and can perform real actions: working with files, browser, email, calendar, etc. It actively uses file-based conventions for agent configuration.

**.agents Protocol** is an open standard (draft 2026) for AI agent configuration in the form of a single `~/.agents/` directory (or `/.agents/` in a project). It includes: `agents.md`, `system-prompt.md`, `mcp.json`, skills/, sub-agents/, tasks/, memories/, etc. It enables portable, Git-versioned, vendor-independent agents.

**WordPress 7** introduced a powerful native AI infrastructure:  
- WP AI Client (provider-agnostic PHP API)  
- Connectors API (centralized key management for OpenAI/Anthropic/Google and others)  
- Abilities API  

The idea: Create a **WP plugin as a single entry point** for working with agents (including OpenClaw-style), where WordPress acts as the "brain" / RAG storage / management interface, and communication happens through familiar channels (Telegram and others).

The plugin turns any WordPress site into a hub for personal/team agents with access to site content, media files, knowledge bases, and shared OpenClaw/OpenCode files.

### 2. Plugin Goals

- Become the **single entry point** for creating, managing, and communicating with AI agents.
- Support the **.agents Protocol** (import/export/sync of configs).
- Provide a **Telegram bot** (and other channels) as the primary communication interface.
- Implement **RAG** over WordPress content (posts, pages, media, custom types).
- Support a **personal agent** with shared OpenClaw/OpenCode files.
- Maximize use of native WP 7 AI features.

### 3. Key Components & Features

1. **Core Agent Engine**
   - Integration with WP AI Client + Connectors.
   - Multi-model / multi-provider support.
   - Prompt system based on .agents (system-prompt.md, agents.md).

2. **.agents Sync Module**
   - Import/export .agents/ directory (via ZIP or Git).
   - Sync skills, memories, sub-agents into WP (as custom post types or options).
   - Automatic creation/update of files in wp-content/agents/.

3. **Telegram Bridge (and other messengers)**
   - Library (e.g., Telegram Bot API + webhook/long-polling).
   - Message routing → specific agent (personal / team / project).
   - Voice, file, and inline button support.

4. **RAG & Knowledge Base**
   - Indexing of all WP content (posts, pages, comments, ACF, media descriptions).
   - Vector store (Pinecone, Qdrant, Chroma, or WP-native via embeddings from AI Client).
   - Retrieval for prompts + context from .agents/memories/.

5. **File & OpenClaw Integration**
   - Access to shared OpenClaw files (workspace).
   - MCP (Model Context Protocol) proxy for tools.
   - File upload/management via chat.

6. **Multi-Agent & Routing**
   - Personal agent + specialized sub-agents (from .agents/agents/).
   - Routing by keywords, users, or projects.

7. **Security & Privacy**
   - WordPress roles and capabilities.
   - Encryption of sensitive data.
   - Rate limiting, audit log.
   - Optional — self-hosted LLM.

8. **Admin Dashboard**
   - Manage agents, skills, tasks.
   - View chats, memories.
   - Prompt testing.

### 4. Use Cases

- **Personal AI Assistant**  
  User communicates with the agent via Telegram. The agent has access to site knowledge (docs, blog, products), shared OpenClaw files, and can perform tasks (content generation, analysis, reminders).

- **Team / Project Hub**  
  For a development team: .agents/ with code-review, deploy, etc. skills. The agent analyzes WP posts/pages, generates documentation, answers project questions.

- **Content & SEO Agent**  
  Automated post generation/optimization, comment replies, RAG search across the site archive.

- **Support / Customer Agent**  
  On-site agent (chat widget) + Telegram, responds to customers using the WP knowledge base.

- **Workflow Automation**  
  Scheduled tasks (from .agents/tasks/) — daily digest, site monitoring, report generation.

- **Learning / Knowledge Management**  
  User uploads documents → they are indexed in RAG + saved to memories/. The agent helps with learning and search.

- **OpenClaw → WP Bridge**  
  Sync OpenClaw workspace with WP. WP becomes the "central brain" for multiple OpenClaw devices/instances.

### 5. Technical Architecture (High-Level)

- **Hooks & Extensibility**: WordPress actions/filters + REST API endpoints for external clients.
- **Storage**: Custom Post Types (`wp_agent`, `wp_skill`, `wp_memory`), options + file system.
- **Background Jobs**: WP-Cron + Action Scheduler for tasks and indexing.
- **Dependencies**: 
  - Official WP AI Client/Connectors.
  - Telegram Bot library (or universal like BotMan).
  - Vector DB (optional via Composer).
- **Compatibility**: WP 7.0+, PHP 8.1+.

### 6. Roadmap (Tentative)

**MVP (v0.1)**:  
- Telegram bot + basic chat.  
- WP AI Client integration.  
- Basic .agents/ import.

**v0.2**: Full .agents support, multi-agent, file access.  
**v0.3**: MCP tools, advanced skills, admin UI.  
**v1.0**: Open-source release, documentation, agent template hub.
**v2.0**: Additional messengers, advanced RAG, self-hosted LLM support.
- RAG over posts/pages.

### 7. Risks & Considerations

- **Performance**: Indexing a large site → requires a solid vector DB.
- **Cost**: LLM API keys.
- **Security**: Agent access to files/actions requires strict sandboxing.
- **Competition**: Existing AI plugins for WP — focus on differentiation via .agents + OpenClaw.

### Next Steps

1. Create plugin repository.
2. Implement MVP (Telegram + AI Client + simple RAG).
3. Test with a real OpenClaw workspace.
4. Publish on GitHub + WordPress.org.

This plugin can become a powerful bridge between the self-hosted agent ecosystem (OpenClaw + .agents) and the world's most popular CMS.
