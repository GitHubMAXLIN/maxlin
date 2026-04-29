<?php

declare(strict_types=1);

final class Audit
{
    public static function event(?int $userId, string $eventType, string $riskLevel = 'normal', array $context = []): void
    {
        $pdo = Database::pdo();
        $safeContext = self::sanitizeContext($context);
        $stmt = $pdo->prepare(
            'INSERT INTO security_events (user_id, event_type, risk_level, ip_hash, ua_hash, context_json, created_at)
             VALUES (:user_id, :event_type, :risk_level, :ip_hash, :ua_hash, :context_json, NOW())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':event_type' => mb_substr($eventType, 0, 80, 'UTF-8'),
            ':risk_level' => mb_substr($riskLevel, 0, 20, 'UTF-8'),
            ':ip_hash' => Security::ipHash(client_ip()),
            ':ua_hash' => Security::userAgentHash(client_user_agent()),
            ':context_json' => json_encode($safeContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function sanitizeContext(array $context): array
    {
        $blockedKeys = ['password', 'new_password', 'old_password', 'csrf_token', 'token', 'reset_token', 'code', 'captcha'];
        $clean = [];
        foreach ($context as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $blockedKeys, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $stringValue = (string)$value;
                $clean[$key] = mb_substr(preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', ' ', $stringValue) ?? '', 0, 500, 'UTF-8');
            }
        }
        return $clean;
    }
}
