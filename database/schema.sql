-- =============================================================================
-- AI Wrapper Premium — Schema Completo
-- PostgreSQL 16 + pgvector
-- =============================================================================

-- Activar extensión pgvector (requerida por user_memories)
CREATE EXTENSION IF NOT EXISTS vector;
-- UUID nativo de Postgres
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- =============================================================================
-- ENUMS
-- =============================================================================

CREATE TYPE subscription_type_enum AS ENUM ('standard', 'premium', 'admin');
CREATE TYPE subscription_status_enum AS ENUM ('active', 'expired', 'trial', 'cancelled');
CREATE TYPE platform_enum AS ENUM ('telegram', 'discord', 'web');
CREATE TYPE message_role_enum AS ENUM ('user', 'assistant', 'system');
CREATE TYPE payment_status_enum AS ENUM ('completed', 'failed', 'refunded', 'pending');
CREATE TYPE api_key_status_enum AS ENUM ('active', 'revoked');
CREATE TYPE feature_tier_enum AS ENUM ('tier1_php', 'tier2_mixed', 'tier3_ai');

-- =============================================================================
-- TABLA: users — Núcleo del cliente
-- =============================================================================

CREATE TABLE users (
    uid                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email                VARCHAR(320) UNIQUE,                              -- Puede ser nulo si solo usa Telegram
    language             VARCHAR(10) NOT NULL DEFAULT 'es',                -- Código ISO 639-1
    subscription_type    subscription_type_enum NOT NULL DEFAULT 'standard',
    subscription_status  subscription_status_enum NOT NULL DEFAULT 'trial',
    expiry_date          TIMESTAMPTZ,
    daily_limit_override INTEGER,                                           -- NULL = usa el límite del plan
    features_active      JSONB NOT NULL DEFAULT '{
        "deep_memory":    true,
        "creative_mode":  false,
        "dev_mode":       false,
        "vision":         false,
        "web_search":     false,
        "rag_files":      false,
        "image_gen":      false,
        "voice_reply":    false,
        "export_chat":    false,
        "link_shortener": false,
        "file_converter": false,
        "qr_generator":   false,
        "weather":        false,
        "currency_rates": false,
        "url_preview":    false,
        "calendar_basic": false
    }'::jsonb,
    persona_prompt       TEXT,                                              -- System prompt personalizado (Premium)
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Función + Trigger para actualizar updated_at automáticamente
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- Índice para búsquedas por estado de suscripción (reportes de admin)
CREATE INDEX idx_users_subscription ON users (subscription_status, subscription_type);

-- =============================================================================
-- TABLA: user_identities — Omnicanalidad (Telegram, Discord, Web)
-- =============================================================================

CREATE TABLE user_identities (
    id           BIGSERIAL PRIMARY KEY,
    uid          UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    platform     platform_enum NOT NULL,
    platform_id  VARCHAR(255) NOT NULL,                                     -- ID del usuario en esa plataforma
    linked_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (platform, platform_id)                                          -- Un platform_id solo puede pertenecer a un uid
);

CREATE INDEX idx_identities_lookup ON user_identities (platform, platform_id);
CREATE INDEX idx_identities_uid ON user_identities (uid);

-- =============================================================================
-- TABLA: user_bots — Plan Personal Bot (bot_token del usuario)
-- =============================================================================

CREATE TABLE user_bots (
    bot_id               BIGSERIAL PRIMARY KEY,
    uid                  UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    bot_token_encrypted  TEXT NOT NULL,                                     -- AES-256 con MASTER_KEY
    bot_username         VARCHAR(255),                                      -- @username del bot para referencia
    webhook_registered   BOOLEAN NOT NULL DEFAULT FALSE,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_bots_uid ON user_bots (uid);
CREATE INDEX idx_bots_active ON user_bots (is_active);

-- =============================================================================
-- TABLA: chat_messages — Historial de conversación (Capa Caliente)
-- Mensajes > 90 días → job nocturno los archiva en S3 y los borra aquí.
-- =============================================================================

CREATE TABLE chat_messages (
    id           BIGSERIAL PRIMARY KEY,
    uid          UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    role         message_role_enum NOT NULL,
    content      TEXT NOT NULL,
    tokens_used  INTEGER,                                                   -- Solo para role = 'assistant'
    archived     BOOLEAN NOT NULL DEFAULT FALSE,                            -- TRUE cuando está en S3
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Índice compuesto para Context Manager: busca los últimos N mensajes por usuario
CREATE INDEX idx_messages_uid_created ON chat_messages (uid, created_at DESC)
    WHERE archived = FALSE;

-- =============================================================================
-- TABLA: user_memories — Memoria de Largo Plazo (RAG)
-- =============================================================================

CREATE TABLE user_memories (
    id          BIGSERIAL PRIMARY KEY,
    uid         UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    content     TEXT NOT NULL,                                              -- Hecho clave en lenguaje natural
    embedding   vector(1536),                                               -- Embedding de OpenAI text-embedding-3-small
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Índice HNSW para búsqueda semántica por similitud coseno.
-- Recomendado sobre IVFFlat para escalabilidad a 10k usuarios.
CREATE INDEX idx_memories_hnsw ON user_memories
    USING hnsw (embedding vector_cosine_ops)
    WITH (m = 16, ef_construction = 64);

CREATE INDEX idx_memories_uid ON user_memories (uid);

-- =============================================================================
-- TABLA: payments — Registro contable (Ledger)
-- =============================================================================

CREATE TABLE payments (
    payment_id    BIGSERIAL PRIMARY KEY,
    uid           UUID NOT NULL REFERENCES users(uid) ON DELETE SET NULL,
    platform_ref  VARCHAR(255) NOT NULL,                                    -- ID de Stripe / Telegram Payments
    amount        NUMERIC(10, 2) NOT NULL,
    currency      VARCHAR(3) NOT NULL DEFAULT 'USD',
    plan_type     VARCHAR(50) NOT NULL,                                     -- 'standard', 'premium'
    status        payment_status_enum NOT NULL DEFAULT 'pending',
    raw_response  JSONB NOT NULL DEFAULT '{}',                              -- Log íntegro de la pasarela
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payments_uid ON payments (uid, created_at DESC);
CREATE INDEX idx_payments_ref ON payments (platform_ref);

-- =============================================================================
-- TABLA: api_keys — Acceso externo (Web App / API REST)
-- El hash se guarda; nunca la key en claro.
-- =============================================================================

CREATE TABLE api_keys (
    key_id      BIGSERIAL PRIMARY KEY,
    hash_key    VARCHAR(64) NOT NULL UNIQUE,                                -- SHA-256 hex de la key real
    uid         UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    label       VARCHAR(100),                                               -- Nombre descriptivo (ej. "Web App")
    status      api_key_status_enum NOT NULL DEFAULT 'active',
    last_used   TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_apikeys_uid ON api_keys (uid);

-- =============================================================================
-- TABLA: feature_usage — Contadores de uso (Tier 2 y Tier 3 con sub-límite)
-- Un registro por usuario por feature por período.
-- =============================================================================

CREATE TABLE feature_usage (
    id           BIGSERIAL PRIMARY KEY,
    uid          UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    feature_key  VARCHAR(50) NOT NULL,                                      -- ej. 'vision', 'web_search', 'link_shortener'
    tier         feature_tier_enum NOT NULL,
    used_count   INTEGER NOT NULL DEFAULT 0,
    period_start DATE NOT NULL DEFAULT CURRENT_DATE,                        -- Inicio del período (diario o mensual)
    last_used_at TIMESTAMPTZ,
    UNIQUE (uid, feature_key, period_start)
);

CREATE INDEX idx_usage_uid_feature ON feature_usage (uid, feature_key, period_start);

-- =============================================================================
-- TABLA: admin_logs — Registro de acciones administrativas
-- Para auditoría del panel /admin
-- =============================================================================

CREATE TABLE admin_logs (
    id          BIGSERIAL PRIMARY KEY,
    admin_uid   UUID REFERENCES users(uid) ON DELETE SET NULL,
    action      VARCHAR(100) NOT NULL,
    target_uid  UUID REFERENCES users(uid) ON DELETE SET NULL,
    details     JSONB DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_adminlogs_created ON admin_logs (created_at DESC);

-- =============================================================================
-- TABLA: link_shortener — Feature Tier 1
-- Gestionada 100% por PHP, sin IA.
-- =============================================================================

CREATE TABLE short_links (
    id          BIGSERIAL PRIMARY KEY,
    uid         UUID NOT NULL REFERENCES users(uid) ON DELETE CASCADE,
    short_code  VARCHAR(10) NOT NULL UNIQUE,
    original_url TEXT NOT NULL,
    clicks      INTEGER NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_shortlinks_uid ON short_links (uid);
CREATE INDEX idx_shortlinks_code ON short_links (short_code);
