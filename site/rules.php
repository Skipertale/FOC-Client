<?php
session_start();
require __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Правила сервера — Fair of Contradictions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page" id="top">
  <main class="rules-page">
    <div class="rules-container">
      <h1 class="rules-title">Правила сервера</h1>
      <p class="rules-subtitle">
        Базовые правила поведения на сервере и в игре. Текст можно редактировать под актуальный регламент.
      </p>

      <section class="rules-section">
        <h2 class="rules-section-title">1. Общее поведение</h2>
        <ul class="rules-list">
          <li>Запрещены оскорбления, травля, расизм, сексизм и другие формы дискриминации.</li>
          <li>Следуйте указаниям администрации и модераторов сервера.</li>
          <li>Не злоупотребляйте ООС в процессе игры, не сливайте спойлеры в общий чат.</li>
        </ul>
      </section>

      <section class="rules-section">
        <h2 class="rules-section-title">2. Игра и ролевой отыгрыш</h2>
        <ul class="rules-list">
          <li>Уважайте концепцию дела/игры, не ломайте атмосферу откровенным абсурдом без согласия организатора.</li>
          <li>Не метагеймите: не используйте ООС-информацию в IC (ин-си) отыгрыше.</li>
          <li>Слушайте КМа (game master/организатора) комнаты и следуйте его указаниям по структуре дела.</li>
        </ul>
      </section>

      <section class="rules-section">
        <h2 class="rules-section-title">3. Контент и ограничения</h2>
        <ul class="rules-list">
          <li>Запрещён откровенно NSFW-контент, хард-еротика и ERP/ЕРП в рамках сервера.</li>
          <li>Жёсткий хоррор/жесть обсуждается с участниками заранее и помечается соответствующим образом.</li>
          <li>Спойлеры к визуальным новеллам и играм желательно выносить в ООС с предупреждением.</li>
        </ul>
      </section>

      <section class="rules-section">
        <h2 class="rules-section-title">4. Техническая часть</h2>
        <ul class="rules-list">
          <li>Не ломайте клиент, не используйте читы и сторонние модификации, нарушающие работу сервера.</li>
          <li>При технических проблемах обращайтесь к администрации на сервере или через Discord/VK.</li>
        </ul>
      </section>

      <div class="rules-back">
        <a href="index.php" class="accent-btn">← На главную</a>
      </div>
    </div>
  </main>
</div>
</body>
</html>
