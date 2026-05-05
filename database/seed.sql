-- =============================================================================
-- AI Wrapper Premium — Seed Data
-- Datos iniciales requeridos para arrancar el sistema.
-- =============================================================================

-- Admin user (actualiza el email y el platform_id de Telegram antes de usar)
INSERT INTO users (
    uid,
    email,
    language,
    subscription_type,
    subscription_status,
    expiry_date,
    daily_limit_override,
    features_active
) VALUES (
    gen_random_uuid(),
    'admin@tudominio.com',
    'es',
    'admin',
    'active',
    NOW() + INTERVAL '100 years',
    9999,
    '{
        "deep_memory":    true,
        "creative_mode":  true,
        "dev_mode":       true,
        "vision":         true,
        "web_search":     true,
        "rag_files":      true,
        "image_gen":      true,
        "voice_reply":    true,
        "export_chat":    true,
        "link_shortener": true,
        "file_converter": true,
        "qr_generator":   true,
        "weather":        true,
        "currency_rates": true,
        "url_preview":    true,
        "calendar_basic": true
    }'::jsonb
) ON CONFLICT DO NOTHING;
