<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit('Method Not Allowed');
}
Security::requirePostCsrf();

$postedArticleId = filter_var($_POST['article_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$articleIdForRedirect = is_int($postedArticleId) ? $postedArticleId : 0;

try {
    $articleId = filter_var($_POST['article_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reactionType = filter_var($_POST['reaction_type'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2]]);
    if (!is_int($articleId) || !is_int($reactionType)) {
        throw new InvalidArgumentException('参数不合法。');
    }

    $pdo = Database::pdo();
    $pdo->beginTransaction();
    $articleStmt = $pdo->prepare('SELECT id FROM articles WHERE id = :id AND status = 1 AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
    $articleStmt->execute([':id' => $articleId]);
    if (!$articleStmt->fetchColumn()) {
        throw new InvalidArgumentException('文章不存在。');
    }

    $insert = $pdo->prepare(
        'INSERT INTO article_reactions
         (article_id, ip_hash, user_agent_hash, reaction_type, created_at, updated_at)
         VALUES
         (:article_id, :ip_hash, :user_agent_hash, :reaction_type, NOW(), NOW())'
    );
    $insert->execute([
        ':article_id' => $articleId,
        ':ip_hash' => Security::ipHash(client_ip()),
        ':user_agent_hash' => Security::userAgentHash(client_user_agent()),
        ':reaction_type' => $reactionType,
    ]);

    if ($reactionType === 1) {
        $update = $pdo->prepare('UPDATE articles SET like_count = like_count + 1, updated_at = NOW() WHERE id = :id AND status = 1 AND deleted_at IS NULL');
    } else {
        $update = $pdo->prepare('UPDATE articles SET dislike_count = dislike_count + 1, updated_at = NOW() WHERE id = :id AND status = 1 AND deleted_at IS NULL');
    }
    $update->execute([':id' => $articleId]);
    $pdo->commit();
    flash_set('success', '操作成功。');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_set('warning', '你已经操作过。');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('article_react_error: ' . $e->getMessage());
    flash_set('danger', '操作失败，请稍后重试。');
}
redirect('article.php?id=' . $articleIdForRedirect);
