import asyncio
import logging

logger = logging.getLogger("queue_manager")


class QueueManager:
    def __init__(self, server):
        self.server = server
        self._queues = {}

    def add(self, client, text, delay=0, name="", folder="", sfx="", sfx_delay=0,
            anim="normal", pos="", pre=0, emote_mod=0, button=0,
            evidence=0, flip=0, ding=0, color=0):
        if client not in self._queues:
            self._queues[client] = []
        item = {
            "text": text, "delay": delay, "name": name,
            "folder": folder, "sfx": sfx, "sfx_delay": sfx_delay,
            "anim": anim, "pos": pos, "pre": pre,
            "emote_mod": emote_mod, "button": button,
            "evidence": evidence, "flip": flip, "ding": ding, "color": color,
            "timer": None,
        }
        if delay > 0:
            loop = self.server.loop or asyncio.get_event_loop()
            item["timer"] = loop.call_later(delay, self._send_one, client, item)
        self._queues[client].append(item)
        return item

    def flush(self, client, interval=0):
        items = self._queues.pop(client, [])
        if not items:
            return 0
        if interval > 0:
            loop = self.server.loop or asyncio.get_event_loop()
            for i, item in enumerate(items):
                if item["timer"]:
                    item["timer"].cancel()
                loop.call_later(i * interval, self._do_send, client, item)
        else:
            for item in items:
                if item["timer"]:
                    item["timer"].cancel()
                self._do_send(client, item)
        return len(items)

    def send_one(self, client, index):
        items = self._queues.get(client)
        if not items or index < 0 or index >= len(items):
            return False
        item = items.pop(index)
        if item["timer"]:
            item["timer"].cancel()
        self._do_send(client, item)
        return True

    def remove(self, client, index):
        items = self._queues.get(client)
        if not items or index < 0 or index >= len(items):
            return False
        item = items.pop(index)
        if item["timer"]:
            item["timer"].cancel()
        return True

    def list_items(self, client):
        return self._queues.get(client, [])

    def clear(self, client):
        items = self._queues.pop(client, [])
        for item in items:
            if item["timer"]:
                item["timer"].cancel()

    def _send_one(self, client, item):
        if client not in self._queues:
            return
        items = self._queues[client]
        if item in items:
            items.remove(item)
        self._do_send(client, item)

    def _do_send(self, client, item):
        if not client or not client.area:
            return
        c = client
        cid = c.char_id if c.char_id is not None else -1
        showname = getattr(c, "showname", "") or c.char_name or ""
        folder = item.get("folder") or c.char_name or ""
        anim = item.get("anim", "normal")
        if c.narrator:
            anim = ""
        # Temporarily clear sfx_url so send_ic uses our saved sfx
        saved_sfx_url = getattr(c, "sfx_url", None)
        if saved_sfx_url:
            c.sfx_url = None
        try:
            c.area.send_ic(
                client=c,
                msg=item["text"],
                cid=cid,
                showname=showname,
                folder=folder,
                anim=anim,
                sfx=item.get("sfx", ""),
                sfx_delay=item.get("sfx_delay", 0),
                pos=item.get("pos", ""),
                pre=item.get("pre", 0),
                emote_mod=item.get("emote_mod", 0),
                button=item.get("button", 0),
                evidence=item.get("evidence", 0),
                flip=item.get("flip", 0),
                ding=item.get("ding", 0),
                color=item.get("color", 0),
            )
        finally:
            if saved_sfx_url:
                c.sfx_url = saved_sfx_url
