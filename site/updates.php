<?php
session_start();
require __DIR__ . '/db.php';

$news = [];
try {
    $stmt = $pdo->query("
        SELECT id, title, content, type, created_at
        FROM news
        ORDER BY created_at DESC
    ");
    $news = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $news = [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Обновления и новости — Fair of Contradictions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page" id="top">
  <main class="updates-page">
    <div class="updates-container">
      <h1 class="updates-title">Обновления и новости</h1>
      <p class="updates-subtitle">
        Полный список новостей и технических обновлений сервера Fair of Contradictions.
      </p>

      <?php if (!$news): ?>
        <p class="updates-empty">Новостей пока нет.</p>
      <?php else: ?>
        <div class="updates-list">
          <?php foreach ($news as $post): ?>
            <?php
              $isUpdate = ($post['type'] === 'update');
              $tagText  = $isUpdate ? 'ТЕХ. ОБНОВЛЕНИЕ' : 'НОВОСТЬ';
              $tagClass = $isUpdate ? 'update-card-tag--update' : 'update-card-tag--news';
              $date     = $post['created_at']
                ? (new DateTime($post['created_at']))->format('d.m.Y')
                : '';
              $excerpt  = mb_strimwidth(strip_tags($post['content']), 0, 220, '...', 'UTF-8');
            ?>
            <article class="update-card">
              <div class="update-card-top">
                <span class="update-card-tag <?= $tagClass ?>"><?= $tagText ?></span>
                <?php if ($date): ?>
                  <span class="update-card-date"><?= $date ?></span>
                <?php endif; ?>
              </div>
              <h2 class="update-card-title">
                <a href="news.php?id=<?= (int)$post['id'] ?>">
                  <?= htmlspecialchars($post['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
              </h2>
              <p class="update-card-excerpt">
                <?= htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </p>
              <div class="update-card-footer">
                <a href="news.php?id=<?= (int)$post['id'] ?>" class="update-card-more">
                  Читать полностью →
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="updates-back">
        <a href="index.php#updates" class="ghost-btn">← Вернуться к блоку новостей на главной</a>
      </div>
    </div>
  </main>
</div>
</body>
</html>
