<?php
require __DIR__ . '/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('
    SELECT n.*, u.nickname AS author_name
    FROM news n
    LEFT JOIN users u ON u.id = n.author_user_id
    WHERE n.id = :id
    LIMIT 1
');
$stmt->execute(['id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
      <meta charset="UTF-8">
      <title>Новость не найдена — Fair of Contradictions</title>
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet" />
      <link rel="stylesheet" href="style.css" />
      <style>
        body { font-family: "Nunito", system-ui, sans-serif; }
        .news-page { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .news-container {
            background: rgba(13, 4, 32, 0.9);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.8);
            text-align: center;
        }
        .news-title { font-size: 24px; font-weight: 800; margin-bottom: 10px; }
        .news-text { font-size: 14px; color: #9ca3af; margin-bottom: 20px; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #e5e7eb;
            text-decoration: none;
            margin-bottom: 16px;
            opacity: 0.8;
        }
        .back-link:hover { opacity: 1; }
        .back-link::before { content: "←"; }
      </style>
    </head>
    <body>
      <div class="news-page">
        <a href="index.php" class="back-link">На главную</a>
        <article class="news-container">
          <h1 class="news-title">Новость не найдена</h1>
          <p class="news-text">
            Возможно, эта новость была удалена или вы перешли по старой ссылке.
          </p>
          <a href="index.php" class="accent-btn">Вернуться на главную</a>
        </article>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$isUpdate = ($post['type'] === 'update');
$date = $post['created_at']
    ? (new DateTime($post['created_at']))->format('d.m.Y')
    : '';
$author = $post['author_name'] ?: 'Администрация';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($post['title']) ?> — Fair of Contradictions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <style>
  .news-page {
      max-width: 960px;
      margin: 40px auto;
      padding: 0 24px;
  }
  .news-container {
      background: rgba(13, 4, 32, 0.9);
      border-radius: 28px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      padding: 30px 30px 26px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.8);
      min-height: 420px;          /* чтобы обычная новость и апдейт были одного масштаба */
      display: flex;
      flex-direction: column;
  }
  .news-header {
      margin-bottom: 20px;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      padding-bottom: 16px;
  }
  .news-tag {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      margin-bottom: 10px;
  }
  .tag-update {
      background: rgba(37, 99, 235, 0.18);
      color: #60a5fa;
      border: 1px solid rgba(96, 165, 250, 0.6);
  }
  .tag-news {
      background: rgba(147, 51, 234, 0.18);
      color: #e9d5ff;
      border: 1px solid rgba(216, 180, 254, 0.7);
  }

  .news-title {
      font-size: 28px;
      font-weight: 800;
      line-height: 1.2;
      color: #fff;
  }
  .news-meta {
      font-size: 13px;
      color: #9ca3af;
      margin-top: 8px;
  }

  .back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: #e5e7eb;
      text-decoration: none;
      margin-bottom: 16px;
      opacity: 0.8;
  }
  .back-link:hover { opacity: 1; }
  .back-link::before { content: "←"; }

  .news-content {
      font-size: 15px;
      line-height: 1.7;
      color: #e2e8f0;
      margin-bottom: 30px;
      flex: 1;
  }
  .news-content img {
      max-width: 100%;
      border-radius: 12px;
      margin: 10px 0;
  }

  .download-block {
      background: linear-gradient(135deg, rgba(30, 58, 138, 0.45), rgba(17, 24, 39, 0.8));
      border: 1px solid rgba(59, 130, 246, 0.7);
      padding: 20px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
  }
  .download-block-text-title {
      font-weight: 700;
      font-size: 16px;
      color: #fff;
      margin-bottom: 4px;
  }
  .download-block-text-sub {
      font-size: 12px;
      color: #93c5fd;
  }

  .dl-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 22px;
      border-radius: 999px;
      border: none;
      background: #2563eb;
      color: #fff;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: background 0.15s ease-out, transform 0.15s ease-out;
      white-space: nowrap;
  }
  .dl-btn:hover {
      background: #1d4ed8;
      transform: translateY(-1px);
  }

  @media (max-width: 640px) {
      .news-container { padding: 20px; border-radius: 22px; }
      .news-title { font-size: 22px; }
      .download-block {
          flex-direction: column;
          align-items: flex-start;
      }
  }
  </style>
</head>
<body>
  <div class="news-page">
    <a href="index.php#updates" class="back-link">К обновлениям</a>

    <article class="news-container">
      <header class="news-header">
        <span class="news-tag <?= $isUpdate ? 'tag-update' : 'tag-news' ?>">
            <?= $isUpdate ? 'Техническое обновление' : 'Новость сервера' ?>
        </span>
        <h1 class="news-title"><?= htmlspecialchars($post['title']) ?></h1>
        <div class="news-meta">
            Опубликовал: <?= htmlspecialchars($author) ?><?= $date ? ' • ' . $date : '' ?>
        </div>
      </header>

      <div class="news-content">
        <?= nl2br(strip_tags($post['content'], '<b><strong><i><em><u><a><ul><ol><li><br><img>')) ?>
      </div>

      <?php if ($isUpdate && !empty($post['download_link'])): ?>
          <div class="download-block">
            <div>
              <div class="download-block-text-title">Доступны файлы обновления</div>
              <div class="download-block-text-sub">
                Это техническое обновление сервера. Нажми кнопку, чтобы скачать архив или перейти к инструкции.
              </div>
            </div>
            <a href="<?= htmlspecialchars($post['download_link']) ?>" target="_blank" class="dl-btn">
              Скачать
            </a>
          </div>
      <?php endif; ?>
    </article>
  </div>
</body>
</html>
