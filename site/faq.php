<?php
session_start();
require __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>FAQ — Fair of Contradictions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page" id="top">
  <!-- можно при желании скопировать шапку с index.php, но оставлю только контент -->
  <main class="faq-page">
    <div class="faq-container">
      <h1 class="faq-title">FAQ / Помощь по серверу</h1>
      <p class="faq-subtitle">
        Ответы на самые частые вопросы по серверу Fair of Contradictions и игре Attorney Online.
      </p>

      <section class="faq-list">

        <article class="faq-item">
          <h2 class="faq-question">Q: В чём суть этой игры?</h2>
          <p class="faq-answer">
            A: По сути это гифко-чат, который изначально создавался для того, чтобы люди могли проводить онлайн судебные дела
            по мотивам игр серии <strong>Ace Attorney</strong>. Однако, на данный момент эта игра является песочницей, в которой можно
            проводить ещё и судебные дела по мотивам игр серии <strong>Danganronpa</strong>, всякие мафии, ПП (Правда или правда),
            ЗСУ, СКУ и прочее.
          </p>
        </article>

        <article class="faq-item">
          <h2 class="faq-question">Q: Что такое ООС?</h2>
          <p class="faq-answer">
            A: Расшифровывается с английского как <strong>Out-Of-Characters</strong>. Является серым чатом, который, в зависимости от
            используемой вами темы, может находиться в разных углах окна. Чаще всего расположен в правом верхнем углу.
            Используется, к примеру, для того, чтобы поделиться какой-то ссылкой или обсуждать с другими людьми кейс /
            любую другую игру во время самого процесса его отыгрывания.
          </p>
        </article>

        <article class="faq-item">
          <h2 class="faq-question">Q: Что это за комнаты и статусы?</h2>
          <p class="faq-answer">
            A: Переход по комнатам осуществляется двойным нажатием ЛКМ на комнату. Разный статус определяет конкретное событие в этой руме:
          </p>
          <ul class="faq-list-bullets">
            <li><strong>IDLE</strong> (не показывается) — стандартный статус для всех комнат.</li>
            <li><strong>CASING</strong> — судебное дело.</li>
            <li><strong>RP</strong> — role-playing / ролевая игра.</li>
            <li><strong>LOCKED</strong> — закрыта для посещения людьми вне комнаты. Можно зайти только по приглашению КМа комнаты или модератора.</li>
            <li><strong>SPECTATING</strong> — недоступен для общения в общем чате, только в ООС. Писать в общий чат могут только те, кому выдал разрешение КМ комнаты или модератор. Накладывается на статусы.</li>
          </ul>
        </article>

        <article class="faq-item">
          <h2 class="faq-question">Q: Что это за разные слова?</h2>
          <p class="faq-answer">
            A: Небольшой словарик терминов, которые вы можете встретить на сервере:
          </p>
          <ul class="faq-list-bullets">
            <li><strong>Кейс</strong> — case, судебное дело. Может быть по мотивам Ace Attorney или других серий игр.</li>
            <li><strong>Рыган</strong> — шуточное название комнаты Ryokan.</li>
            <li><strong>ЗСУ</strong> — Зал Суда Удачи, фирменная игра ФоКа.</li>
            <li><strong>СКУ</strong> — Смертельный Класс Удачи, тоже одна из игр.</li>
            <li><strong>ДНД</strong> — Dungeons and Dragons (Подземелья и драконы), настольная/ролевая игра.</li>
            <li><strong>Выкрики / пузыри</strong> — те самые выкрикивания “Objection!”, “Hold it!” и т.п.</li>
            <li><strong>КР</strong> — сокращение от Courtroom (зал суда).</li>
            <li><strong>ПГ</strong> — сокращение от Playground.</li>
            <li><strong>ERP / ЕРП</strong> — Erotic Role-Playing / Эротическая ролевая игра (строго запрещена, но может упоминаться в качестве шутки).</li>
          </ul>
        </article>

      </section>

      <div class="faq-back">
        <a href="index.php" class="accent-btn">← На главную</a>
      </div>
    </div>
  </main>
</div>
</body>
</html>
