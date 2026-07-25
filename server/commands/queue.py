from server.exceptions import ClientError
from . import mod_only

__all__ = [
    "ooc_cmd_p",
    "ooc_cmd_p_send",
    "ooc_cmd_p_list",
    "ooc_cmd_p_remove",
    "ooc_cmd_p_sendone",
]


@mod_only(area_owners=True, hub_owners=True)
def ooc_cmd_p(client, arg):
    """
    Добавить сообщение в отложку.
    /p [сек] текст — в очередь
    /p_send — отправить всё
    /p_list — показать очередь
    /p_remove N — удалить N-е
    """
    qm = client.server.queue_manager
    if not qm:
        client.send_ooc("[Queue] Очередь не инициализирована.")
        return
    if not arg.strip():
        items = qm.list_items(client)
        if not items:
            client.send_ooc("[Queue] Очередь пуста.")
        else:
            lines = [f"[Queue] {i}: ({it['delay']}c) | {it['text'][:60]}" for i, it in enumerate(items)]
            client.send_ooc("\n".join(lines))
        return
    # Parse: /p [seconds] текст
    arg = arg.strip()
    delay = 0
    text = arg
    parts = arg.split(None, 1)
    if parts and parts[0].isdigit():
        delay = int(parts[0])
        text = parts[1] if len(parts) > 1 else ""
    if not text:
        client.send_ooc("[Queue] Укажи текст сообщения. /p [сек] текст")
        return
    qm.add(client, text, delay)
    if delay > 0:
        client.send_ooc(f"[Queue] Добавлено (отпр. через {delay}c): {text[:50]}")
    else:
        client.send_ooc(f"[Queue] Добавлено в очередь: {text[:50]}")


@mod_only(area_owners=True, hub_owners=True)
def ooc_cmd_p_send(client, arg):
    """
    Отправить все сообщения из очереди.
    /p_send — моментально
    /p_send N — с интервалом N сек между сообщениями
    """
    qm = client.server.queue_manager
    if not qm:
        return
    items = qm.list_items(client)
    if not items:
        client.send_ooc("[Queue] Очередь пуста.")
        return
    interval = 0
    if arg.strip():
        try:
            interval = float(arg.strip())
            if interval < 0:
                raise ValueError
        except ValueError:
            client.send_ooc("[Queue] /p_send [интервал] — число секунд.")
            return
    count = qm.flush(client, interval)
    if interval > 0:
        client.send_ooc(f"[Queue] Отправлено {count} сообщений (интервал {interval}c).")
    else:
        client.send_ooc(f"[Queue] Отправлено {count} сообщений.")


@mod_only(area_owners=True, hub_owners=True)
def ooc_cmd_p_list(client, arg):
    """
    Показать очередь сообщений.
    """
    qm = client.server.queue_manager
    if not qm:
        return
    items = qm.list_items(client)
    if not items:
        client.send_ooc("[Queue] Очередь пуста.")
        return
    lines = [f"[Queue] Очередь ({len(items)}):"]
    for i, it in enumerate(items):
        timer_tag = f" ⏱{it['delay']}c" if it['delay'] > 0 else ""
        folder_tag = f" [{it.get('folder','')}]" if it.get('folder') else ""
        sfx_tag = f" sfx:{it.get('sfx','')}" if it.get('sfx') else ""
        lines.append(f"  {i}:{folder_tag}{timer_tag}{sfx_tag} {it['text'][:60]}")
    client.send_ooc("\n".join(lines))


@mod_only(area_owners=True, hub_owners=True)
def ooc_cmd_p_remove(client, arg):
    """
    Удалить элемент из очереди.
    /p_remove N
    """
    qm = client.server.queue_manager
    if not qm:
        return
    try:
        idx = int(arg.strip())
    except ValueError:
        client.send_ooc("[Queue] Укажи номер. /p_list для просмотра.")
        return
    if qm.remove(client, idx):
        client.send_ooc(f"[Queue] Элемент {idx} удалён.")
    else:
        client.send_ooc(f"[Queue] Неверный номер {idx}.")


@mod_only(area_owners=True, hub_owners=True)
def ooc_cmd_p_sendone(client, arg):
    """
    Отправить конкретный элемент из очереди.
    /p_sendone N
    """
    qm = client.server.queue_manager
    if not qm:
        return
    try:
        idx = int(arg.strip())
    except ValueError:
        client.send_ooc("[Queue] Укажи номер. /p_list для просмотра.")
        return
    if qm.send_one(client, idx):
        client.send_ooc(f"[Queue] Элемент {idx} отправлен.")
    else:
        client.send_ooc(f"[Queue] Неверный номер {idx}.")
