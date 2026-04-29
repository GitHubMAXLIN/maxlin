<?php

declare(strict_types=1);

final class ContentAudit
{
    public static function event(?int $userId, string $action, string $targetType, ?int $targetId = null, array $detail = []): void
    {
        try {
            $safeDetail = self::sanitizeDetail($detail);
            $stmt = Database::pdo()->prepare(
                'INSERT INTO content_audit_logs
                 (user_id, action, target_type, target_id, ip_hash, user_agent_hash, detail_json, created_at)
                 VALUES
                 (:user_id, :action, :target_type, :target_id, :ip_hash, :user_agent_hash, :detail_json, NOW())'
            );
            $json = $safeDetail === [] ? null : json_encode($safeDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => self::cleanText($action, 80),
                ':target_type' => self::cleanText($targetType, 50),
                ':target_id' => $targetId,
                ':ip_hash' => Security::ipHash(client_ip()),
                ':user_agent_hash' => Security::userAgentHash(client_user_agent()),
                ':detail_json' => $json,
            ]);
        } catch (Throwable $e) {
            error_log('content_audit_error: ' . $e->getMessage());
        }
    }

    private static function sanitizeDetail(array $detail): array
    {
        $out = [];
        foreach ($detail as $key => $value) {
            $safeKey = self::cleanText((string)$key, 50);
            if ($safeKey === '') {
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $out[$safeKey] = $value;
            } elseif (is_string($value)) {
                $out[$safeKey] = self::cleanText($value, 200);
            }
        }
        return $out;
    }

    private static function cleanText(string $value, int $max): string
    {
        $value = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }
}
