<?php
session_start();
require __DIR__ . '/db.php';

// Берём игры, отмеченные как "активные" для главной
$featuredGames = [];
try {
    $stmt = $pdo->query("
        SELECT
          id,
          title,
          description,
          game_type,
          status,
          starts_at,
          external_link,
          max_players,
          signups_open
        FROM games
        WHERE is_featured = 1
        ORDER BY status = 'active' DESC,
                 status = 'upcoming' DESC,
                 starts_at IS NULL,
                 starts_at ASC,
                 id DESC
        LIMIT 9
    ");
    $featuredGames = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $featuredGames = [];
}

// Берём все новости для главной / модалки / оверлея обновлений
$newsData = [];
try {
    $stmt = $pdo->query("
        SELECT n.id, n.title, n.content, n.type, n.created_at, n.download_link,
               u.nickname AS author_name
        FROM news n
        LEFT JOIN users u ON u.id = n.author_user_id
        ORDER BY n.created_at DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $isUpdate = ($row['type'] === 'update');
        $newsData[] = [
            'id'            => (int)$row['id'],
            'title'         => $row['title'],
            'type'          => $row['type'],
            'isUpdate'      => $isUpdate,
            'tag'           => $isUpdate ? 'ТЕХ. ОБНОВЛЕНИЕ' : 'НОВОСТЬ',
            'date'          => $row['created_at']
                ? (new DateTime($row['created_at']))->format('d.m.Y')
                : '',
            'author'        => $row['author_name'] ?: 'Администрация',
            'excerpt'       => mb_strimwidth(strip_tags($row['content']), 0, 140, "...", 'UTF-8'),
            'contentHtml'   => nl2br(strip_tags(
                $row['content'],
                '<b><strong><i><em><u><a><ul><ol><li><br><img>'
            )),
            'download_link' => $row['download_link'] ?? ''
        ];
    }
} catch (PDOException $e) {
    $newsData = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Fair of Contradictions — Attorney Online GIF-чат</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta
    name="description"
    content="Fair of Contradictions — сервер Attorney Online с атмосферными делами, ролевым отыгрышем и GIF-чатом."
  />
  <link rel="preconnect" href="https://fonts.gstatic.com" />
  <link
    href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="page" id="top">
    <!-- Навбар -->
    <header class="nav">
      <div class="nav-left">
        <div class="logo-circle"></div>
        <div>
          <div class="logo-text-main">Fair of Contradictions</div>
          <div class="logo-text-sub">Ярмарка противоречий — AO GIF-чат сервер</div>
        </div>
      </div>

      <nav class="nav-right">
        <div class="nav-tag">
          <div class="nav-dot"></div>
          <span>Сейчас ищем новых игроков</span>
        </div>

        <a href="#top" class="nav-link">Главная</a>
        <a href="#" class="nav-link js-open-overlay" data-overlay="faq">FAQ</a>
        <a href="#" class="nav-link js-open-overlay" data-overlay="updates">Обновления</a>
        <a href="#" class="nav-link js-open-overlay" data-overlay="rules">Правила</a>
        <a href="#" class="nav-link js-open-overlay" data-overlay="client">Клиент</a>

        <a href="https://discord.gg/n95zkcBE8h" class="nav-link nav-link-pill">Discord</a>
        <a href="https://vk.com/domfoc" class="nav-link nav-link-pill">VK</a>

        <button class="nav-profile" type="button" onclick="window.location.href='account.php'">
          <span class="nav-profile-avatar">AO</span>
          <span class="nav-profile-label">Профиль</span>
        </button>
      </nav>
    </header>

    <!-- Основной блок -->
    <main class="hero">
      <!-- Левая часть -->
      <section class="hero-left">
        <div class="pill">
          Attorney Online
          <span class="pill-badge">GIF-чат &amp; RP</span>
        </div>

        <h1 class="hero-title">
          Сервер <span class="main">«Ярмарка&nbsp;противоречий»</span><br />
          в <span class="ao">Attorney&nbsp;Online</span>
        </h1>

        <p class="hero-subtitle">
          Погрузись в атмосферу судебных баталий, гифок и
          <strong>живого ролевого отыгрыша</strong>. Всё, что нужно — клиент AO и
          немного желания кричать «Objection!».
        </p>

        <div class="hero-actions">
          <button class="btn-main js-open-overlay" type="button" data-overlay="client">
            Начать играть
          </button>

          <a href="http://web.aceattorneyonline.com/client.html?mode=join&connect=ws://aofoc.ru:50001&serverName=Fair%20of%20Contradictions[RU]" target="_blank" class="btn-secondary" style="text-decoration: none;">
            <span class="btn-secondary-dot"></span>
            Подключиться через веб-версию
          </a>
        </div>

        <div class="hero-meta">
          <div class="hero-meta-item">🎭 Авторские сценарии и дела</div>
          <div class="hero-meta-item">💬 Активный GIF-чат</div>
          <div class="hero-meta-item">🌙 Уютная вечерняя атмосфера</div>
        </div>
      </section>

      <!-- Правая часть -->
      <section class="hero-right" aria-label="Демонстрация игрового клиента">
        <article class="client-card">
          <div class="client-header">
            <div class="client-header-left">
              <div class="client-dots">
                <span class="client-dot"></span>
                <span class="client-dot"></span>
                <span class="client-dot"></span>
              </div>
              <div>
                <div class="client-title">Attorney Online Client</div>
                <div class="client-subtitle">
                  Fair of Contradictions / Ярмарка противоречий
                </div>
              </div>
            </div>
            <div class="client-status">Online • RP</div>
          </div>

          <div class="client-image-wrapper">
            <img
              src="client-demo.png"
              alt="Скриншот клиента Attorney Online — Fair of Contradictions"
              class="client-image"
            />

            <div class="client-overlay">
              <div class="client-bubble">
                <div class="client-bubble-title">
                  Подключайся, выбирай персонажа и вступай в дело.
                </div>
                <div class="client-bubble-sub">
                  Ярмарка противоречий • GIF-чат • RP-сцены
                </div>
              </div>
            </div>
          </div>
        </article>
      </section>
    </main>

    <!-- Краткий блок “как начать” -->
    <section class="steps" aria-label="Как начать играть" id="guide">
      <article class="step-card">
        <div class="step-label">Шаг 1</div>
        <div class="step-title">Скачай клиент AO</div>
        <p class="step-text">
          Скачать актуальную версию Attorney Online — ссылка в оверлее «Клиент» или в нашем Discord/VK.
        </p>
      </article>

      <article class="step-card">
        <div class="step-label">Шаг 2</div>
        <div class="step-title">Добавь сервер</div>
        <p class="step-text">
          Введи адрес сервера <strong>Fair of Contradictions</strong> в списке серверов или используй
          авто-подключение из нашего лаунчера (если есть).
        </p>
      </article>

      <article class="step-card">
        <div class="step-label">Шаг 3</div>
        <div class="step-title">Врывайся в Ярмарку</div>
        <p class="step-text">
          Выбери персонажа, присоединяйся к делу и наслаждайся уютным GIF-чатом и ролевыми сценами.
        </p>
      </article>
    </section>

    <!-- Обновления и новости -->
    <section class="home-news" id="updates">
      <div class="home-news-inner">
        <div class="home-news-header">
          <div>
            <h2 class="home-news-title">Обновления и новости</h2>
            <p class="home-news-subtitle">
              Техапдейты клиента и новости сервера Fair of Contradictions
            </p>
          </div>
        </div>

        <?php if (!$newsData): ?>
          <p class="home-news-empty">
            Новостей пока нет. Скоро здесь появятся анонсы и обновления.
          </p>
        <?php else: ?>
          <div class="home-news-list" id="home-news-list">
            <!-- Карточки новостей отрисуются через JS -->
          </div>

          <?php if (count($newsData) > 3): ?>
            <div class="home-news-controls">
              <button class="news-nav-btn" id="news-prev" disabled>&larr;</button>
              <span class="home-news-page" id="news-page-indicator"></span>
              <button class="news-nav-btn" id="news-next">&rarr;</button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- Модалка новости -->
    <div class="news-modal-backdrop" id="news-modal">
      <div class="news-modal-window">
        <button class="news-modal-close" id="news-modal-close">&times;</button>

        <div class="news-modal-tag" id="news-modal-tag"></div>
        <h3 class="news-modal-title" id="news-modal-title"></h3>
        <div class="news-modal-meta" id="news-modal-meta"></div>

        <div class="news-modal-content" id="news-modal-content"></div>
      </div>
    </div>

    <!-- Мини-игры сервера -->
    <section class="minigames" id="minigames">
      <div class="minigames-header">
        <h2 class="minigames-title">Мини-игры сервера</h2>
        <p class="minigames-subtitle">
          Дополнительные активности помимо основных дел. Нажми на название, чтобы ознакомиться с правилами.
        </p>
      </div>

      <div class="minigames-grid">
        <article class="minigame-card">
          <a href="https://docs.google.com/document/d/15XespF-NLNgUG5eBk_PlVuMNMVYPQkWCXxTPZofpeV4/edit" class="minigame-title">
            🎲 Зал Суда Удачи
          </a>
          <p class="minigame-text">
            Судебные заседания с элементами удачи и игровыми способностями.
          </p>
        </article>

        <article class="minigame-card">
          <a href="https://docs.google.com/document/d/16a6fM6JisaS2AfC4H-3dFAMYqbcRuIVljWuFsq6yI-0/edit" class="minigame-title">
            🍷 Мафия АО
          </a>
          <p class="minigame-text">
            Классическая мафия с набором ролей, где мирные жители вычисляют мафиозников.
          </p>
        </article>

        <article class="minigame-card">
          <a href="#" class="minigame-title">
            👁️‍🗨️ Правда или Правда
          </a>
          <p class="minigame-text">
            Вечерние посиделки в закрытой комнате, где игроки говорят правду и только правду. Если кто-то не хочет отвечать, то вступает в ряды монеточников (/coinflip)
          </p>
        </article>

        <article class="minigame-card">
          <a href="https://docs.google.com/document/d/1pOLLVbdsqGI79TW8ZKuSjC8XocyBYETOcEOniP1nJ0s/edit" class="minigame-title">
            💪 Протест или Действие
          </a>
          <p class="minigame-text">
            Активная игра, в которой придётся немного подвигаться
          </p>
        </article>

        <article class="minigame-card">
          <a href="https://docs.google.com/document/d/1RYg4v5tt6gKiGB4JJ812m41TEplf7oVMovopJIpbyRo/edit" class="minigame-title">
            💺 LoveSick
          </a>
          <p class="minigame-text">
            Улучшенная версия мафии в стиле школьной поездки.
          </p>
        </article>

        <article class="minigame-card">
          <a href="https://docs.google.com/document/d/1kCnUoDci3YHN5oQWWI9jETjTit0AabkBWY7nd_3vyks/edit" class="minigame-title">
            🐉 Dungeon and Dragons
          </a>
          <p class="minigame-text">
            Всеми известное подземелье и драконы с системами, подстроенные под НРИ. Хотелось бы насладиться настолкой в GIF-чате?
          </p>
        </article>
      </div>
    </section>

    <!-- Активные игры (из БД) -->
    <section class="active-games" id="active-games">
      <div class="active-games-header">
        <h2 class="active-games-title">Активные игры</h2>
        <p class="active-games-subtitle">
          Ивенты и дела, которые админы выделили как самые актуальные. Обрати внимание, если хочешь куда-нибудь записаться.
        </p>
      </div>

      <div class="active-games-grid">
        <?php if (!$featuredGames): ?>
          <article class="active-game-card">
            <div class="active-game-banner">
              <span class="active-game-tag">Информация</span>
              <div class="active-game-title-link">
                Пока нет выделенных игр
              </div>
            </div>
            <p class="active-game-text">
              Администраторы ещё не отметили игры как активные для главной страницы. Загляни в календарь — там всё актуальное расписание.
            </p>
            <div class="active-game-status-row">
              <span class="status-pill status-soon">Следи за объявлениями</span>
            </div>
          </article>
        <?php else: ?>
          <?php foreach ($featuredGames as $g): ?>
            <?php
              $tag = 'Игра';
              if ($g['game_type'] === 'case') {
                  $tag = 'Основное дело';
              } elseif ($g['game_type'] === 'minigame') {
                  $tag = 'Мини-игра';
              } elseif ($g['game_type'] === 'event') {
                  $tag = 'Ивент';
              }

              $statusMainClass = 'status-soon';
              $statusMainText  = 'Скоро';
              if ($g['status'] === 'active') {
                  $statusMainClass = 'status-live';
                  $statusMainText  = 'Игра идёт';
              } elseif ($g['status'] === 'finished') {
                  $statusMainClass = 'status-ended';
                  $statusMainText  = 'Завершено';
              } elseif ($g['status'] === 'cancelled') {
                  $statusMainClass = 'status-ended';
                  $statusMainText  = 'Отменено';
              }

              $signupsOpen = (int)$g['signups_open'] === 1;
              $statusSecondClass = $signupsOpen ? 'status-open' : 'status-closed';
              $statusSecondText  = $signupsOpen ? 'Набор открыт' : 'Набор закрыт';

              $dateText = '';
              if (!empty($g['starts_at'])) {
                  $dt = new DateTime($g['starts_at']);
                  $dateText = $dt->format('d.m.Y H:i');
              }

              $link = !empty($g['external_link']) ? $g['external_link'] : '#';
              $desc = $g['description'] ?? '';
              if ($desc === '') {
                  $desc = 'Описание игры ещё не заполнено. Подробнее можно узнать у администрации сервера или в анонсе.';
              }
            ?>
            <article class="active-game-card">
              <div class="active-game-banner">
                <span class="active-game-tag"><?= htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a href="<?= htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="active-game-title-link" target="<?= $link !== '#' ? '_blank' : '_self' ?>">
                  <?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
              </div>
              <p class="active-game-text">
                <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <?php if ($dateText): ?>
                  <br><span style="color:#b2a4dd;">Запланировано на <?= htmlspecialchars($dateText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
              </p>
              <div class="active-game-status-row">
                <span class="status-pill <?= $statusMainClass ?>"><?= htmlspecialchars($statusMainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="status-pill <?= $statusSecondClass ?>"><?= htmlspecialchars($statusSecondText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- Календарь игр/кейсов (динамический) -->
    <section class="games-calendar" id="calendar">
      <div class="calendar-header">
        <div class="calendar-title-block">
          <h2 class="calendar-title">Календарь игр и кейсов</h2>
          <p class="calendar-subtitle">
            Визуальное расписание активностей сервера. Выбирай день, смотри игры и записывайся через личный кабинет.
          </p>
        </div>
        <div class="calendar-nav">
          <button class="calendar-nav-btn" type="button" id="calPrev" aria-label="Предыдущий месяц">‹</button>
          <div class="calendar-month-label" id="calTitle"></div>
          <button class="calendar-nav-btn" type="button" id="calNext" aria-label="Следующий месяц">›</button>
        </div>
      </div>

      <div class="calendar-body">
        <div class="calendar-weekdays">
          <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
        </div>

        <div class="calendar-grid" id="calendarGrid"></div>

        <div class="calendar-day-details" id="calendarDayDetails">
          <h3 class="calendar-day-title">
            Игры на <span id="calendarDayLabel">—</span>
          </h3>
          <div id="calendarGamesList" class="calendar-games-list">
            <p class="calendar-empty-text">Выберите день в календаре, чтобы увидеть игры.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Оверлей FAQ -->
    <div class="page-overlay" id="overlay-faq">
      <div class="page-overlay-panel">
        <button class="page-overlay-close" type="button">&times;</button>
        <h2 class="overlay-title">FAQ / Помощь по серверу</h2>
        <p class="overlay-subtitle">
          Ответы на частые вопросы по серверу Fair of Contradictions и игре Attorney Online.
        </p>

        <div class="overlay-content">
          <section class="overlay-block">
            <h3 class="overlay-block-title">Q: В чём суть этой игры?</h3>
            <p>
              A: По сути это гифко-чат, который изначально создавался для того, чтобы люди могли проводить
              онлайн судебные дела по мотивам игр серии <strong>Ace Attorney</strong>. Однако, на данный момент
              эта игра является песочницей, в которой можно проводить ещё и судебные дела по мотивам игр серии
              <strong>Danganronpa</strong>, всякие мафии, ПП (Правда или правда), ЗСУ, СКУ и прочее.
            </p>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">Q: Что такое ООС?</h3>
            <p>
              A: Расшифровывается с английского как <strong>Out-Of-Characters</strong>. Является серым чатом,
              который, в зависимости от используемой вами темы, может находиться в разных углах окна. Чаще всего
              расположен в правом верхнем углу. Используется, к примеру, для того, чтобы поделиться какой-то ссылкой
              или обсуждать с другими людьми кейс / любую другую игру во время самого процесса его отыгрывания.
            </p>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">Q: Что это за комнаты и статусы?</h3>
            <p>
              A: Переход по комнатам осуществляется двойным нажатием ЛКМ на саму комнату. Разный статус определяет
              конкретное событие в этой руме:
            </p>
            <ul>
              <li><strong>IDLE</strong> (не показывается) — стандартный статус для всех комнат.</li>
              <li><strong>CASING</strong> — судебное дело.</li>
              <li><strong>RP</strong> — role-playing / ролевая игра.</li>
              <li><strong>LOCKED</strong> — закрыта для посещения людьми вне комнаты (по приглашению КМа или модера).</li>
              <li><strong>SPECTATING</strong> — можно писать только в ООС, в общий чат — по разрешению КМа/мода. Накладывается на статусы.</li>
            </ul>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">Q: Что это за разные слова?</h3>
            <p>A: Небольшой словарик терминов:</p>
            <ul>
              <li><strong>Кейс</strong> — case, судебное дело (по мотивам Ace Attorney или других игр).</li>
              <li><strong>Рыган</strong> — шуточное название комнаты Ryokan.</li>
              <li><strong>ЗСУ</strong> — Зал Суда Удачи, фирменная игра ФоКа.</li>
              <li><strong>СКУ</strong> — Смертельный Класс Удачи.</li>
              <li><strong>ДНД</strong> — Dungeons And Dragons (Подземелья и Драконы).</li>
              <li><strong>Выкрики / пузыри</strong> — “Objection!”, “Hold it!” и т.д.</li>
              <li><strong>КР</strong> — Courtroom.</li>
              <li><strong>ПГ</strong> — Playground.</li>
              <li><strong>ERP / ЕРП</strong> — Erotic Role-Playing (строго запрещено, может упоминаться только как шутка).</li>
            </ul>
          </section>
        </div>
      </div>
    </div>

    <!-- Оверлей технических обновлений -->
    <div class="page-overlay" id="overlay-updates">
      <div class="page-overlay-panel">
        <button class="page-overlay-close" type="button">&times;</button>
        <h2 class="overlay-title">Технические обновления</h2>
        <p class="overlay-subtitle">
          История техапдейтов клиента и ресурсов сервера Fair of Contradictions.
        </p>

        <div class="overlay-content">
          <?php
          $hasUpdates = false;
          foreach ($newsData as $post):
              if (empty($post['isUpdate'])) continue;
              if (!$hasUpdates):
                  $hasUpdates = true;
                  echo '<div class="overlay-updates-list">';
              endif;
          ?>
            <article class="overlay-update-card">
              <div class="overlay-update-top">
                <span class="overlay-update-tag">ТЕХ. ОБНОВЛЕНИЕ</span>
                <?php if (!empty($post['date'])): ?>
                  <span class="overlay-update-date"><?= htmlspecialchars($post['date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
              <h3 class="overlay-update-title">
                <?= htmlspecialchars($post['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </h3>
              <p class="overlay-update-excerpt">
                <?= htmlspecialchars($post['excerpt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </p>
              <div class="overlay-update-footer">
                <button
                  type="button"
                  class="overlay-update-open"
                  data-open-update-id="<?= (int)$post['id'] ?>"
                >
                  Открыть подробности →
                </button>
              </div>
            </article>
          <?php endforeach;
          if ($hasUpdates):
              echo '</div>';
          else: ?>
            <p class="overlay-empty">Технических обновлений пока нет.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Оверлей правил -->
    <div class="page-overlay" id="overlay-rules">
      <div class="page-overlay-panel">
        <button class="page-overlay-close" type="button">&times;</button>
        <h2 class="overlay-title">Правила сервера</h2>
        <p class="overlay-subtitle">
          Базовый свод правил поведения на сервере и в игре.
        </p>

        <div class="overlay-content">

          <section class="overlay-block">
            <h3 class="overlay-block-title">Администрация и общие положения</h3>
            <ul>
              <li><strong>@dexterst (Скипер)</strong> — Главный Администратор сервера и последняя инстанция при разборе конфликтов.</li>
              <li>Префикс <strong>[M]</strong> в ООС-имени (например, <strong>[M]Skipertale</strong>, <strong>[M]Kami</strong>) означает, что человек является администратором сервера.</li>
              <li>Данные правила распространяются на <strong>Hub 0</strong>.</li>
              <li>В зависимости от характера нарушения администрация может применить наказание и в других Hub.</li>
              <li>Локальные правила в других Hub могут дополняться владельцами этих Hub, за которыми они официально закреплены Администрацией сервера. Правила расположены на <a href="https://aofoc.ru/hubs/hubs.php">отдельной странице</a>.</li>
            </ul>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">I. Общие положения</h3>
            <ul>
              <li><strong>Язык общения.</strong> Основное общение на сервере ведётся на русском языке.</li>
              <li>Иностранные языки не запрещены, но злоупотребление ими (постоянные диалоги, спам и т.п.) может быть расценено как нарушение. Оценка ситуации остаётся за администрацией.</li>
              <li><strong>Тесты контента.</strong> Все тесты спрайтов, музыки, фонов и команд проводятся только в комнатах <strong>Ruins</strong> и <strong>Backstage</strong>.</li>
              <li>Разрешается закрыть персонально для себя отдельный пустой Courtroom, на который в ближайшее время не запланирован отыгрыш.</li>
              <li><strong>Неподобающее поведение.</strong> Если значительная часть сообщества недовольна игроком/группой и администрация регулярно получает жалобы, администрация может выдать таким игрокам перманентный бан даже без привязки к одному конкретному пункту.</li>
              <li><strong>Сторонний клиент.</strong> Использование стороннего клиента допускается, установка официального клиента не является обязательной, но могут возникнуть проблемы с отображением официального контента сервера.</li>
              <li>Рекомендуется использовать официальный клиент сервера, чтобы избежать проблем с отображением контента.</li>
            </ul>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">II. Назначение комнат</h3>
            <ul>
              <li>
                <strong>Ruins (главная комната)</strong> — щитпост, импровизации (импры), RP, общение, тест спрайтов, музыки, команд. 
                <br>Маты разрешены.
              </li>
              <li>
                <strong>Backstage</strong> — проверка контента (спрайты, музыка, команды), объяснение новичкам работы клиента, общение без щитпоста.
                <br>Маты разрешены.
              </li>
              <li>
                <strong>Courtroom 1–4 (деловые комнаты)</strong> — импровизации, RP, кейсы, закрытые беседы для групп игроков / разбор важных вопросов.
              </li>
              <li>
                <strong>Playground 1–2 (игровые комнаты)</strong> — проведение различных игр (ЗСУ, СКУ, мафии и т.п.).
              </li>
              <li>
                <strong>Ryokan (культурное общение)</strong> — спокойное культурное общение.
                <br><strong>Маты запрещены.</strong>
              </li>
              <li>
                <strong>VA-11 (кибер-бар)</strong> — комната для чилла, прослушивания музыки и RP (по желанию участников комнаты).
              </li>
            </ul>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">III. На сервере запрещено</h3>
            <ol>
              <li><strong>ERP / ЕРП</strong> в любом виде — в любом Courtroom, Playground, Ruins, Backstage, Ryokan, VA-11 и других комнатах.</li>
              <li>Вмешиваться в кейсы/игры, проходящие в комнатах (кроме случаев, когда стоит статус <strong>RECESS</strong>).</li>
              <li>Пытаться сорвать кейс, игру, отыгрыш или RP-сцену.</li>
              <li>Устраивать импровизации или фановые кейсы в <strong>Ryokan</strong>.</li>
              <li>Щитпостить или спамить в Courtroom/Playground, если в комнате стоит статус <strong>RP</strong>, <strong>Casing</strong> или <strong>Gaming</strong>.</li>
              <li>
                Делать <strong>INI-swap</strong>:
                <ul>
                  <li>на персонажей, которых нет в файлах официального клиента (доп. контент не учитывается);</li>
                  <li>на персонажей, которые свободны в списке персонажей.</li>
                </ul>
                Допустимо при тесте контента или для ГМа на кейсе/любом другом отыгрыше.
              </li>
              <li>
                Пропагандировать религиозные, политические, восстанческие, ксенофобные и подобные убеждения.
                <br>Отличайте пропаганду от простого высказывания собственного мнения.
              </li>
              <li>Выдавать себя за администрацию сервера. Проверить модераторов можно командой <strong>/mods</strong> в ООС-чате.</li>
              <li>Постоянно и/или беспричинно использовать кнопку <strong>Call mod</strong>.</li>
              <li>Злоупотреблять кнопками <strong>Witness Testimony / Cross-Examination</strong> и <strong>Guilty / Not Guilty</strong> вне Ruins.</li>
              <li>
                Использовать нецензурную лексику:
                <ul>
                  <li>в комнате <strong>Ryokan</strong>;</li>
                  <li>во время игр (кейсы, ЗСУ и пр.) — в основном IC-чате.</li>
                </ul>
              </li>
              <li>Рекламировать другие сервера и/или сторонние ресурсы без согласования с администрацией.</li>
              <li>Спамить или флудить сообщениями вне Ruins.</li>
              <li>Размещать материалы с порнографией, шок-контентом и/или жёсткими сценами насилия.</li>
              <li>Разжигать ненависть между людьми (по расе, полу, ориентации, возрасту, религии и т.п.).</li>
              <li>Отправлять вредоносные ссылки (вирусы, фишинг, скам и т.п.) в IC/OOC чатах.</li>
              <li>
                Засорять основной чат/ООС бесконечными вопросами, которые можно задать в специальных каналах/темах
                или лично администрации.
              </li>
              <li>
                Засорять основной чат/ООС постоянными предложениями по улучшению сервера — для этого используются
                отдельные каналы/темы для фидбэка.
              </li>
            </ol>
          </section>

          <section class="overlay-block">
            <h3 class="overlay-block-title">Обжалование наказаний</h3>
            <p>
              Если вы считаете, что были наказаны несправедливо, вы можете обратиться напрямую к
              <strong>Главному Администратору</strong> сервера <strong>@dexterst (Скипер)</strong> или к <strong>хосту</strong> сервера <strong>@___user___ (USER)</strong>.
            </p>
            <p>
              В обращении укажите время, комнату, участников и опишите ситуацию максимально спокойно и подробно.
            </p>
          </section>

        </div>
      </div>
    </div>

    <!-- Оверлей клиента -->
    <div class="page-overlay" id="overlay-client">
      <div class="page-overlay-panel">
        <button class="page-overlay-close" type="button">&times;</button>
        <h2 class="overlay-title">Клиент сервера</h2>
        <p class="overlay-subtitle">
          Выбери подходящую версию клиента Fair of Contradictions: полный пак или облегчённый вариант.
        </p>

        <div class="overlay-content">
        <div class="client-options">
          <article class="client-download-card">
              <h3 class="client-card-title">Full Client</h3>
              <p class="client-card-text">
                Полная версия клиента с максимально полным набором спрайтов, музыки и ресурсов сервера.
                Рекомендуется большинству игроков, если у тебя нормальный интернет и нет жёстких ограничений по месту.
              </p>
              <ul class="client-card-list">
                <li>Полный набор визуальных и аудио-ресурсов;</li>
                <li>Все официальные паки сервера;</li>
                <li>Оптимален для постоянной игры на Fair of Contradictions.</li>
              </ul>
              <a href="https://drive.google.com/file/d/1-Re_MVLE0Xu77asoRFb55HWyeLP7Q9yQ/view?usp=sharing" class="client-download-btn client-download-btn--ghost" target="_blank">
                Скачать Full Client
              </a>
            </article>

            <article class="client-download-card">
              <h3 class="client-card-title">Lite Client</h3>
              <p class="client-card-text">
                Облегчённая версия клиента с урезанным набором ресурсов. Подойдёт, если у тебя слабый интернет,
                мало места на диске или ты играешь не так часто.
              </p>
              <ul class="client-card-list">
                <li>Базовый набор спрайтов и музыки;</li>
                <li>Меньший размер загрузки;</li>
                <li>Подходит для слабых машин и редких заходов.</li>
              </ul>
              <a href="downloads/lite_client.zip" class="client-download-btn client-download-btn--ghost" target="_blank">
                Скачать Lite Client
              </a>
            </article>
          </div>

          <p class="client-note">
            Если сомневаешься, что выбрать — ставь <strong>Full Client</strong>. При проблемах с местом или скоростью загрузки бери <strong>Lite Client</strong>.
          </p>
        </div>
      </div>
    </div>

    <!-- Футер -->
    <footer class="footer">
      <div class="footer-inner">
        <div class="footer-main">
          <span>© 2025 Fair of Contradictions / Ярмарка противоречий.</span>
          <span>Проект является фанатским и не аффилирован с правообладателями Ace Attorney.</span>
        </div>
        <div class="footer-links">
          <a href="https://discord.gg/n95zkcBE8h">Связаться с админом</a>
        </div>
      </div>
    </footer>
  </div>

  <!-- Скрипт динамического календаря -->
  <script>
    (function() {
      const gridEl = document.getElementById('calendarGrid');
      const titleEl = document.getElementById('calTitle');
      const dayLabelEl = document.getElementById('calendarDayLabel');
      const gamesListEl = document.getElementById('calendarGamesList');
      const prevBtn = document.getElementById('calPrev');
      const nextBtn = document.getElementById('calNext');

      if (!gridEl || !titleEl || !dayLabelEl || !gamesListEl || !prevBtn || !nextBtn) return;

      const monthNames = [
        'январь','февраль','март','апрель','май','июнь',
        'июль','август','сентябрь','октябрь','ноябрь','декабрь'
      ];

      let now = new Date();
      let currentYear = now.getFullYear();
      let currentMonth = now.getMonth(); // 0-11
      let selectedDay = null;
      let gamesByDay = {};

      function fetchMonth(year, month) {
        const url = 'calendar_data.php?year=' + year + '&month=' + (month + 1);
        return fetch(url)
          .then(r => r.json())
          .then(data => {
            gamesByDay = data.games || {};
          })
          .catch(() => {
            gamesByDay = {};
          });
      }

      function drawCalendar(year, month) {
        titleEl.textContent = monthNames[month] + ' ' + year;

        gridEl.innerHTML = '';
        selectedDay = null;
        dayLabelEl.textContent = '—';
        gamesListEl.innerHTML = '<p class="calendar-empty-text">Выберите день в календаре, чтобы увидеть игры.</p>';

        const firstDayOfMonth = new Date(year, month, 1);
        let startWeekDay = firstDayOfMonth.getDay(); // 0 (вс) - 6 (сб)
        if (startWeekDay === 0) startWeekDay = 7;

        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysPrevMonth = new Date(year, month, 0).getDate();

        const totalCells = 42;
        let dayCounter = 1;
        let nextMonthDay = 1;

        for (let cell = 1; cell <= totalCells; cell++) {
          const cellEl = document.createElement('div');
          cellEl.className = 'calendar-day';

          let dayNumber;
          let inCurrentMonth = true;

          if (cell < startWeekDay) {
            dayNumber = daysPrevMonth - (startWeekDay - cell) + 1;
            inCurrentMonth = false;
          } else if (dayCounter > daysInMonth) {
            dayNumber = nextMonthDay++;
            inCurrentMonth = false;
          } else {
            dayNumber = dayCounter++;
          }

          const numEl = document.createElement('div');
          numEl.className = 'calendar-day-number';
          numEl.textContent = dayNumber;
          cellEl.appendChild(numEl);

          if (!inCurrentMonth) {
            cellEl.classList.add('calendar-day-other-month');
          } else {
            const dayGames = gamesByDay[dayNumber] || [];
            if (dayGames.length > 0) {
              cellEl.classList.add('calendar-day-has-games');
            }

            cellEl.dataset.day = String(dayNumber);
            cellEl.addEventListener('click', () => {
              document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('calendar-day-selected'));
              cellEl.classList.add('calendar-day-selected');
              selectedDay = dayNumber;
              renderDayGames(year, month, dayNumber);
            });
          }

          gridEl.appendChild(cellEl);
        }
      }

      function renderDayGames(year, month, day) {
        dayLabelEl.textContent = day + '.' + String(month + 1).padStart(2, '0') + '.' + year;
        const dayGames = gamesByDay[day] || [];

        gamesListEl.innerHTML = '';
        if (dayGames.length === 0) {
          gamesListEl.innerHTML = '<p class="calendar-empty-text">На этот день пока нет игр.</p>';
          return;
        }

        dayGames.forEach(g => {
          const card = document.createElement('article');
          card.className = 'calendar-game-card';

          const header = document.createElement('div');
          header.className = 'calendar-game-header';

          const title = document.createElement('div');
          title.className = 'calendar-game-title';
          title.textContent = g.title;

          const meta = document.createElement('div');
          meta.className = 'calendar-game-meta';

          let typeText = 'Игра';
          if (g.game_type === 'case') typeText = 'Кейс';
          else if (g.game_type === 'minigame') typeText = 'Мини-игра';
          else if (g.game_type === 'event') typeText = 'Ивент';

          meta.textContent = typeText + ' · ' + g.starts_at;

          header.appendChild(title);
          header.appendChild(meta);
          card.appendChild(header);

          const slots = document.createElement('div');
          slots.className = 'calendar-game-slots';
          const signed = g.signed_count || 0;
          const max = g.max_players !== null ? g.max_players : '∞';
          slots.textContent = 'Игроков: ' + signed + ' / ' + max;
          card.appendChild(slots);

          const actions = document.createElement('div');
          actions.className = 'calendar-game-actions';

          if (g.external_link) {
            const a = document.createElement('a');
            a.className = 'calendar-game-btn calendar-game-btn-link';
            a.href = g.external_link;
            a.target = '_blank';
            a.textContent = 'Описание / правила';
            actions.appendChild(a);
          }

          if (g.signups_open && (g.max_players === null || signed < g.max_players)) {
            const form = document.createElement('form');
            form.className = 'calendar-game-signup-form';
            form.method = 'post';
            form.action = 'join_game.php';

            const hiddenId = document.createElement('input');
            hiddenId.type = 'hidden';
            hiddenId.name = 'game_id';
            hiddenId.value = g.id;
            form.appendChild(hiddenId);

            if (g.game_type === 'case') {
              const roleSelect = document.createElement('select');
              roleSelect.name = 'role';
              roleSelect.className = 'calendar-role-select';

              const roles = [
                'Адвокат',
                'Прокурор',
                'Судья',
                'Присяжный',
                'Следователь',
                'Свидетель',
                'Игрок'
              ];

              roles.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r;
                opt.textContent = r;
                roleSelect.appendChild(opt);
              });

              form.appendChild(roleSelect);
            } else {
              const hiddenRole = document.createElement('input');
              hiddenRole.type = 'hidden';
              hiddenRole.name = 'role';
              hiddenRole.value = 'Игрок';
              form.appendChild(hiddenRole);
            }

            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.className = 'calendar-game-btn calendar-game-btn-primary';
            btn.textContent = 'Записаться через ЛК';

            form.appendChild(btn);
            actions.appendChild(form);
          } else {
            const noBtn = document.createElement('div');
            noBtn.className = 'calendar-game-slots';
            if (!g.signups_open) {
              noBtn.textContent = 'Набор закрыт';
            } else {
              noBtn.textContent = 'Лимит игроков достигнут';
            }
            actions.appendChild(noBtn);
          }

          card.appendChild(actions);
          gamesListEl.appendChild(card);
        });
      }

      function reload() {
        fetchMonth(currentYear, currentMonth).then(() => {
          drawCalendar(currentYear, currentMonth);
        });
      }

      prevBtn.addEventListener('click', () => {
        currentMonth--;
        if (currentMonth < 0) {
          currentMonth = 11;
          currentYear--;
        }
        reload();
      });

      nextBtn.addEventListener('click', () => {
        currentMonth++;
        if (currentMonth > 11) {
          currentMonth = 0;
          currentYear++;
        }
        reload();
      });

      reload();
    })();
  </script>

  <!-- Скрипт для новостей (листать + модалка + оверлеи) -->
  <script>
    window.NEWS_DATA = <?= json_encode($newsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    window.NEWS_PER_PAGE = 3;
  </script>

  <!-- ФИКС: оверлеи и модалка инициализируются всегда; новости — только если они есть -->
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const data       = window.NEWS_DATA || [];
    const perPage    = window.NEWS_PER_PAGE || 3;
    const total      = data.length;
    const totalPages = total ? Math.ceil(total / perPage) : 0;

    const listEl   = document.getElementById('home-news-list');
    const prevEl   = document.getElementById('news-prev');
    const nextEl   = document.getElementById('news-next');
    const pageEl   = document.getElementById('news-page-indicator');

    const modalEl        = document.getElementById('news-modal');
    const modalTagEl     = document.getElementById('news-modal-tag');
    const modalTitleEl   = document.getElementById('news-modal-title');
    const modalMetaEl    = document.getElementById('news-modal-meta');
    const modalContentEl = document.getElementById('news-modal-content');
    const modalCloseEl   = document.getElementById('news-modal-close');

    // --- Оверлеи FAQ / Обновления / Правила / Клиент ---
    var overlayMap = {
      faq: document.getElementById('overlay-faq'),
      updates: document.getElementById('overlay-updates'),
      rules: document.getElementById('overlay-rules'),
      client: document.getElementById('overlay-client')
    };

    function closeOverlay(ov) {
      if (!ov) return;
      ov.classList.remove('is-open');
      var anyOpenOverlay = document.querySelector('.page-overlay.is-open');
      var newsOpen = document.querySelector('.news-modal-backdrop.is-open');
      if (!anyOpenOverlay && !newsOpen) {
        document.body.style.overflow = '';
      }
    }

    document.querySelectorAll('.js-open-overlay').forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        var key = this.dataset.overlay;
        var ov = overlayMap[key];
        if (!ov) return;

        document.querySelectorAll('.page-overlay.is-open').forEach(function(o) {
          o.classList.remove('is-open');
        });

        ov.classList.add('is-open');
        document.body.style.overflow = 'hidden';
      });
    });

    document.querySelectorAll('.page-overlay-close').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var ov = this.closest('.page-overlay');
        closeOverlay(ov);
      });
    });

    document.querySelectorAll('.page-overlay').forEach(function(ov) {
      ov.addEventListener('click', function(e) {
        if (e.target === ov) {
          closeOverlay(ov);
        }
      });
    });

    // --- Модалка новости ---
    function escapeHtml(str) {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }

    function openModal(item) {
      if (!modalEl) return;

      modalTagEl.textContent  = item.isUpdate ? 'Техническое обновление' : 'Новость сервера';
      modalTagEl.className    = 'news-modal-tag ' + (item.isUpdate ? 'news-modal-tag--update' : 'news-modal-tag--news');
      modalTitleEl.textContent = item.title;
      modalMetaEl.textContent  =
        (item.author ? item.author : 'Администрация') +
        (item.date ? ' • ' + item.date : '');

      let html = item.contentHtml || '';

      if (item.isUpdate && item.download_link) {
        html += `
          <div class="news-modal-download">
            <div class="news-modal-download-text">
              <div class="news-modal-download-title">Доступны файлы обновления</div>
              <div class="news-modal-download-sub">
                Это техническое обновление клиента/ресурсов сервера.
                Нажми кнопку, чтобы скачать архив или открыть инструкцию.
              </div>
            </div>
            <a href="${item.download_link}" target="_blank" class="news-modal-download-btn">
              Скачать
            </a>
          </div>
        `;
      }

      modalContentEl.innerHTML = html;

      modalEl.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      if (!modalEl) return;
      modalEl.classList.remove('is-open');

      var anyOpenOverlay = document.querySelector('.page-overlay.is-open');
      if (!anyOpenOverlay) {
        document.body.style.overflow = '';
      }
    }

    if (modalCloseEl) {
      modalCloseEl.addEventListener('click', closeModal);
    }
    if (modalEl) {
      modalEl.addEventListener('click', function (e) {
        if (e.target === modalEl) closeModal();
      });
    }

    // --- Открытие модалки из списка тех.обновлений в оверлее ---
    document.querySelectorAll('.overlay-update-open').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        var id = parseInt(this.dataset.openUpdateId, 10);
        if (!id) return;
        var item = data.find(function(n) { return n.id === id; });
        if (item) openModal(item);
      });
    });

    // --- ESC закрывает и модалку, и оверлеи ---
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;

      closeModal();
      document.querySelectorAll('.page-overlay.is-open').forEach(function(ov) {
        closeOverlay(ov);
      });
    });

    // --- Новости на главной (листать + открыть модалку) ---
    if (listEl && data.length) {
      let currentPage = 1;

      function renderPage(page) {
        const start = (page - 1) * perPage;
        const slice = data.slice(start, start + perPage);

        listEl.innerHTML = '';

        slice.forEach(item => {
          const card = document.createElement('a');
          card.href = 'javascript:void(0)';
          card.className = 'home-news-item news-card-anim' + (item.isUpdate ? ' home-news-item--update' : '');
          card.dataset.id = item.id;

          card.innerHTML = `
            <div class="home-news-meta">
              <span class="home-news-tag ${item.isUpdate ? 'home-news-tag--update' : 'home-news-tag--news'}">
                ${item.tag}
              </span>
              <span class="home-news-date">${item.date}</span>
            </div>
            <h3 class="home-news-item-title">${escapeHtml(item.title)}</h3>
            <p class="home-news-item-excerpt">${escapeHtml(item.excerpt)}</p>
            <div class="home-news-footer">
              <span class="home-news-more">Читать подробнее →</span>
            </div>
          `;

          card.addEventListener('click', function () {
            openModal(item);
          });

          listEl.appendChild(card);
        });

        if (pageEl) pageEl.textContent = totalPages > 1 ? (page + ' / ' + totalPages) : '';
        if (prevEl) prevEl.disabled = (page <= 1);
        if (nextEl) nextEl.disabled = (page >= totalPages);
      }

      if (prevEl) {
        prevEl.addEventListener('click', function () {
          if (currentPage > 1) {
            currentPage--;
            renderPage(currentPage);
          }
        });
      }

      if (nextEl) {
        nextEl.addEventListener('click', function () {
          if (currentPage < totalPages) {
            currentPage++;
            renderPage(currentPage);
          }
        });
      }

      renderPage(currentPage);
    }
  });
  </script>
</body>
</html>
